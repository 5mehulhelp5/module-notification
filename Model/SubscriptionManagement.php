<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\ContactRepositoryInterface;
use CommerceKings\Notification\Api\StopListInterface;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\SubscriptionManagementInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Filter\FilterManager;
use Magento\Framework\Validator\EmailAddress;
use Magento\Framework\Validator\StringLength;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Subscribe in this order, nothing written before the last step: email rules, the type's authorize(),
 * the permission (SubscriptionPermissionInterface), the guest contact, then one INSERT ... SELECT whose WHERE
 * holds the permission's condition and the type's condition. Direct SQL - no models are saved.
 * A recipient on the do-not-contact list is not stored; the answer is the same as a stored subscription, so the
 * list cannot be probed, and the attempt restarts the stop's lift timer. A written row is announced with
 * EVENT_SAVED after the commit.
 */
class SubscriptionManagement implements SubscriptionManagementInterface
{
    public const TABLE = 'ck_notification_subscription';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SubscriptionTypePool $types,
        private readonly PermissionResolver $permissions,
        private readonly ContactRepositoryInterface $contacts,
        private readonly StoreManagerInterface $stores,
        private readonly EmailAddress $emailValidator,
        private readonly StringLength $emailLengthValidator,
        private readonly FilterManager $filterManager,
        private readonly StopListInterface $stopList,
        private readonly CustomerRepositoryInterface $customers,
        private readonly RecipientNormalizerPool $normalizers,
        private readonly ManagerInterface $events,
        private readonly LoggerInterface $logger
    ) {
    }

    public function subscribeGuest(string $type, string $reference, mixed $recipient): bool
    {
        $subscriptionType = $this->type($type);
        $channel = $subscriptionType->getChannel();
        $address = $this->recipient($channel, $recipient);
        $target = $subscriptionType->authorize($reference, 0);
        if ($target === null) {
            return false;
        }
        $permission = $target->accepting && $address !== ''
            ? $this->permissions->forType($subscriptionType)->check($subscriptionType, $target,
                $this->normalizers->normalize($channel, $address)) : null;
        if ($permission === null || $permission->removesExisting) {
            $this->unsubscribeGuest($type, $subscriptionType, $target);
            return false;
        }
        if (!$permission->isGranted()) {
            return false;
        }
        if ($this->stopList->touch($channel, $address)) {
            // a changed address must not leave the previous recipient's subscription behind; the answer stays the same
            $this->unsubscribeGuest($type, $subscriptionType, $target);
            // the contact read a stored sign-up does, so a stopped address does not answer measurably faster
            $this->contacts->find($this->websiteId($target), $address, $channel);
            return true;
        }
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            // The contact upsert holds its row lock until the subscription exists, including reused contacts.
            $contactId = $this->contacts->save($this->websiteId($target), $address, $channel);
            $result = $this->store($type, $subscriptionType, $target, (string)$permission->reference, $contactId, null);
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
        $this->saved($result, $type, $subscriptionType, $target, $contactId, null);
        return $result['stored'];
    }

    public function subscribeCustomer(int $customerId, string $type, string $reference, mixed $recipient = null): bool
    {
        $subscriptionType = $this->type($type);
        $channel = $subscriptionType->getChannel();
        $address = $this->customerRecipient($channel, $recipient);
        if ($customerId <= 0) {
            return false;
        }
        $target = $subscriptionType->authorize($reference, $customerId);
        if ($target === null || !$target->accepting) {
            return false;
        }
        // the address the subscription is stored with: the account email on email types, else the given recipient
        $stored = $channel === Channel::EMAIL ? $this->accountEmail($customerId) : $address;
        $permission = $this->permissions->forType($subscriptionType)->check($subscriptionType, $target,
            $stored === '' ? null : $this->normalizers->normalize($channel, $stored));
        if (!$permission->isGranted()) {
            return false;
        }
        if ($channel === Channel::EMAIL) {
            // email: the account email, the same stop check as a guest's address
            if ($stored !== '' && $this->stopList->touch(Channel::EMAIL, $stored)) {
                return true;
            }
            $contactId = null;
            $result = $this->store($type, $subscriptionType, $target, (string)$permission->reference, null, $customerId,
                $stored);
        } else {
            // other channels: the given recipient, checked and stored as a contact next to the customer, like a guest's
            if ($this->stopList->touch($channel, $address)) {
                $this->contacts->find($this->websiteId($target), $address, $channel);
                return true;
            }
            $db = $this->resource->getConnection();
            $db->beginTransaction();
            try {
                $contactId = $this->contacts->save($this->websiteId($target), $address, $channel);
                $result = $this->store($type, $subscriptionType, $target, (string)$permission->reference, $contactId,
                    $customerId);
                $db->commit();
            } catch (\Throwable $error) {
                $db->rollBack();
                throw $error;
            }
        }
        $this->saved($result, $type, $subscriptionType, $target, $contactId, $customerId);
        return $result['stored'];
    }

    /** Email types take no recipient (the account email); other channels need one, validated like a guest's. */
    private function customerRecipient(string $channel, mixed $recipient): string
    {
        if ($channel === Channel::EMAIL) {
            if ($recipient !== null) {
                throw new InputException(__('This notification uses the account email.'));
            }
            return '';
        }
        $address = $recipient === null ? '' : $this->recipient($channel, $recipient);
        if ($address === '') {
            throw new InputException(__('A recipient is required for this notification.'));
        }
        return $address;
    }

    /**
     * EVENT_SAVED for a written row, after the commit; an observer's error is logged, never the sign-up's.
     *
     * @param array{stored: bool, id: ?int, created: bool, previous_contact_id: ?int, first_for_address: bool} $result
     */
    private function saved(array $result, string $code, SubscriptionTypeInterface $type, Target $target, ?int $contactId,
        ?int $customerId): void
    {
        if ($result['id'] === null) {
            return;
        }
        try {
            $this->events->dispatch(self::EVENT_SAVED, [
                'subscription_id' => $result['id'],
                'type' => $code,
                'channel' => $type->getChannel(),
                'store_id' => $target->storeId,
                'contact_id' => $contactId,
                'customer_id' => $customerId,
                'created' => $result['created'],
                'previous_contact_id' => $result['previous_contact_id'],
                'first_for_address' => $result['first_for_address'],
            ]);
        } catch (\Throwable $error) {
            $this->logger->error('CommerceKings_Notification: subscription saved event failed after commit.', [
                'exception_class' => get_class($error),
            ]);
        }
    }

    /** The account email; '' for a customer that no longer exists (the guarded write refuses it). */
    private function accountEmail(int $customerId): string
    {
        try {
            return trim((string)$this->customers->getById($customerId)->getEmail());
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            return '';
        }
    }

    /**
     * The address for the type's channel: trimmed, or '' (unsubscribe); anything else is Magento's 400 without echoing
     * the input. Other channels than email need a normalizer.
     */
    private function recipient(string $channel, mixed $recipient): string
    {
        if ($channel === Channel::EMAIL) {
            return $this->email($recipient);
        }
        $exception = new InputException();
        if (!$this->normalizers->has($channel)) {
            $exception->addError(__('This notification does not take this kind of address.'));
            throw $exception;
        }
        $value = $this->filterManager->get('stringTrim')->filter($recipient);
        if ($value === '') {
            return '';
        }
        if (!is_string($value) || strlen($value) > 255 || $this->normalizers->normalize($channel, $value) === '') {
            $exception->addError(__('The recipient has an invalid format.'));
            throw $exception;
        }
        return $value;
    }

    /** Trimmed email address or '' (unsubscribe); anything else is Magento's 400 without echoing the input. */
    private function email(mixed $email): string
    {
        $email = $this->filterManager->get('stringTrim')->filter($email);
        // Only the explicit empty string unsubscribes; native validators reject false and other raw types.
        if ($email === '') {
            return '';
        }
        $exception = new InputException();
        if (!$this->emailLengthValidator->isValid($email)) {
            $exception->addError(__('The email must be a string within the allowed length.'));
        }
        if (!$this->emailValidator->isValid($email)) {
            $exception->addError(__('The email address has an invalid format.'));
        }
        if ($exception->wasErrorAdded()) {
            throw $exception;
        }
        return (string)$email;
    }

    private function type(string $code): SubscriptionTypeInterface
    {
        $type = $this->types->get($code);
        if ($type === null) {
            throw new InputException(__('Unknown notification type.'));
        }
        return $type;
    }

    private function websiteId(Target $target): int
    {
        return (int)$this->stores->getStore($target->storeId)->getWebsiteId();
    }

    /** One per reference for single types, otherwise one per customer (whatever their address) or guest contact. */
    private function subjectKey(SubscriptionTypeInterface $type, ?int $contactId, ?int $customerId): string
    {
        return $type->isSingle() ? '' : ($customerId !== null ? 'u:' . $customerId : 'c:' . $contactId);
    }

    /** @return string[] */
    private function conditions(SubscriptionTypeInterface $type, string $reference, Target $target): array
    {
        $conditions = [$this->permissions->forType($type)->validCondition($this->resource->getConnection()->quote($reference))];
        if ($target->condition !== '') {
            $conditions[] = '(' . $target->condition . ')';
        }
        return $conditions;
    }

    /**
     * @param string $accountEmail a customer's account email, when it is the address (email types without a contact)
     * @return array{stored: bool, id: ?int, created: bool, previous_contact_id: ?int, first_for_address: bool} stored
     *         also for an identical retry; id only when a row was written (created: inserted, else changed;
     *         previous_contact_id: the changed row's contact before the write; first_for_address: no subscription of
     *         any type had this address in the website before)
     */
    private function store(string $code, SubscriptionTypeInterface $type, Target $target, string $reference,
        ?int $contactId, ?int $customerId, string $accountEmail = ''): array
    {
        $db = $this->resource->getConnection();
        $subjectKey = $this->subjectKey($type, $contactId, $customerId);
        $conditions = $this->conditions($type, $reference, $target);
        // the row's contact before this write (for EVENT_SAVED): '' when there is no row yet or it has no contact
        $previous = (string)$db->fetchOne($db->select()->from($this->resource->getTableName(self::TABLE), 'contact_id')
            ->where('type = ?', $code)->where('reference_id = ?', $target->referenceId)
            ->where('subject_key = ?', $subjectKey));
        $first = !$this->addressSubscribed($target, $contactId, $customerId, $accountEmail);
        $statement = $db->query(sprintf(
            'INSERT INTO %s (type, reference_id, store_id, subject_key, contact_id, customer_id, permission_ref, captured_at)'
            . ' SELECT ?, ?, ?, ?, ?, ?, ?, NOW() FROM DUAL WHERE %s ON DUPLICATE KEY UPDATE'
            // MySQL assigns in order: compare the old values before replacing them. A change restarts the delay.
            . ' captured_at = IF(contact_id <=> ? AND customer_id <=> ? AND permission_ref = ?, captured_at, NOW()),'
            . ' store_id = ?, contact_id = ?, customer_id = ?, permission_ref = ?,'
            // makes LAST_INSERT_ID() the existing row's id on an update too; assigning it changes nothing
            . ' subscription_id = LAST_INSERT_ID(subscription_id)',
            $db->quoteIdentifier($this->resource->getTableName(self::TABLE)),
            implode(' AND ', $conditions)
        ), [
            $code, $target->referenceId, $target->storeId, $subjectKey, $contactId, $customerId, $reference,
            $contactId, $customerId, $reference,
            $target->storeId, $contactId, $customerId, $reference,
        ]);
        // MySQL: 1 = inserted, 2 = an existing row changed, 0 = unchanged or refused
        $rows = $statement->rowCount();
        if ($rows > 0) {
            return ['stored' => true, 'id' => (int)$db->fetchOne('SELECT LAST_INSERT_ID()'), 'created' => $rows === 1,
                'previous_contact_id' => $rows === 1 || $previous === '' ? null : (int)$previous,
                'first_for_address' => $first];
        }
        // Zero rows: refused, or an identical retry of an acknowledged-but-lost response.
        $select = $db->select()->from($this->resource->getTableName(self::TABLE), 'subscription_id')
            ->where('type = ?', $code)->where('reference_id = ?', $target->referenceId)
            ->where('subject_key = ?', $subjectKey)->where('permission_ref = ?', $reference)
            ->where($contactId === null ? 'contact_id IS NULL' : $db->quoteInto('contact_id = ?', $contactId))
            ->where($customerId === null ? 'customer_id IS NULL' : $db->quoteInto('customer_id = ?', $customerId));
        foreach ($conditions as $condition) {
            $select->where($condition);
        }
        return ['stored' => (bool)$db->fetchOne($select), 'id' => null, 'created' => false, 'previous_contact_id' => null,
            'first_for_address' => false];
    }

    /**
     * Did any subscription (any type) have this address in the target's website before this write? A contact is one
     * address per website and channel; a customer's account email also counts as the email contact of that address.
     */
    private function addressSubscribed(Target $target, ?int $contactId, ?int $customerId, string $accountEmail): bool
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from($this->resource->getTableName(self::TABLE), 'subscription_id')->limit(1);
        if ($contactId !== null) {
            return (bool)$db->fetchOne($select->where('contact_id = ?', $contactId));
        }
        $contact = $accountEmail === '' ? null : $this->contacts->find($this->websiteId($target), $accountEmail);
        $select->where('customer_id = ?', (int)$customerId)->where('contact_id IS NULL');
        if ($contact !== null) {
            $select->orWhere('contact_id = ?', $contact);
        }
        return (bool)$db->fetchOne($select);
    }

    /** A guest can only remove the one subscription of a single type; per-person ones need the withdrawal link. */
    private function unsubscribeGuest(string $code, SubscriptionTypeInterface $type, Target $target): void
    {
        if (!$type->isSingle()) {
            return;
        }
        $where = ['type = ?' => $code, 'reference_id = ?' => $target->referenceId, "subject_key = ''", 'customer_id IS NULL'];
        if ($target->condition !== '') {
            $where[] = '(' . $target->condition . ')';
        }
        $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE), $where);
    }
}
