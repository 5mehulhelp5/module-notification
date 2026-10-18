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
use Magento\Framework\Exception\InputException;
use Magento\Framework\Filter\FilterManager;
use Magento\Framework\Validator\EmailAddress;
use Magento\Framework\Validator\StringLength;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Subscribe in this order, nothing written before the last step: email rules, the type's authorize(),
 * the permission (SubscriptionPermissionInterface), the guest contact, then one INSERT ... SELECT whose WHERE
 * holds the permission's condition and the type's condition. Direct SQL - no models are saved.
 * A recipient on the do-not-contact list is not stored; the answer is the same as a stored subscription, so the
 * list cannot be probed, and the attempt restarts the stop's lift timer.
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
        private readonly RecipientNormalizerPool $normalizers
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
            ? $this->permissions->forType($subscriptionType)->check($subscriptionType, $target) : null;
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
            $stored = $this->store($type, $subscriptionType, $target, (string)$permission->reference, $contactId, null);
            $db->commit();
            return $stored;
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }

    public function subscribeCustomer(int $customerId, string $type, string $reference): bool
    {
        $subscriptionType = $this->type($type);
        if ($customerId <= 0) {
            return false;
        }
        $target = $subscriptionType->authorize($reference, $customerId);
        if ($target === null || !$target->accepting) {
            return false;
        }
        $permission = $this->permissions->forType($subscriptionType)->check($subscriptionType, $target);
        if (!$permission->isGranted()) {
            return false;
        }
        // a customer is known by the account email; on other channels the sender checks the stop list at send time
        if ($subscriptionType->getChannel() === Channel::EMAIL && $this->isStoppedCustomer($customerId)) {
            return true;
        }
        return $this->store($type, $subscriptionType, $target, (string)$permission->reference, null, $customerId);
    }

    /** A customer that no longer exists has no address to check: the guarded write refuses it as before. */
    private function isStoppedCustomer(int $customerId): bool
    {
        try {
            $email = (string)$this->customers->getById($customerId)->getEmail();
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            return false;
        }
        return $email !== '' && $this->stopList->touch(Channel::EMAIL, $email);
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

    /** One per reference for single types, otherwise one per contact or customer. */
    private function subjectKey(SubscriptionTypeInterface $type, ?int $contactId, ?int $customerId): string
    {
        return $type->isSingle() ? '' : ($contactId !== null ? 'c:' . $contactId : 'u:' . $customerId);
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

    private function store(string $code, SubscriptionTypeInterface $type, Target $target, string $reference,
        ?int $contactId, ?int $customerId): bool
    {
        $db = $this->resource->getConnection();
        $subjectKey = $this->subjectKey($type, $contactId, $customerId);
        $conditions = $this->conditions($type, $reference, $target);
        $statement = $db->query(sprintf(
            'INSERT INTO %s (type, reference_id, store_id, subject_key, contact_id, customer_id, permission_ref, captured_at)'
            . ' SELECT ?, ?, ?, ?, ?, ?, ?, NOW() FROM DUAL WHERE %s ON DUPLICATE KEY UPDATE'
            // MySQL assigns in order: compare the old values before replacing them. A change restarts the delay.
            . ' captured_at = IF(contact_id <=> ? AND customer_id <=> ? AND permission_ref = ?, captured_at, NOW()),'
            . ' store_id = ?, contact_id = ?, customer_id = ?, permission_ref = ?',
            $db->quoteIdentifier($this->resource->getTableName(self::TABLE)),
            implode(' AND ', $conditions)
        ), [
            $code, $target->referenceId, $target->storeId, $subjectKey, $contactId, $customerId, $reference,
            $contactId, $customerId, $reference,
            $target->storeId, $contactId, $customerId, $reference,
        ]);
        if ($statement->rowCount() > 0) {
            return true;
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
