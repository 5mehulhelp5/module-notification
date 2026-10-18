<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\ContactRepositoryInterface;
use CommerceKings\Notification\Api\RecipientBlockInterface;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\SubscriptionManagementInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
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
 * list cannot be probed, and the attempt restarts the block's lift timer.
 */
class SubscriptionManagement implements SubscriptionManagementInterface
{
    public const TABLE = 'ck_notification_subscription';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SubscriptionTypePool $types,
        private readonly SubscriptionPermissionInterface $permission,
        private readonly ContactRepositoryInterface $contacts,
        private readonly StoreManagerInterface $stores,
        private readonly EmailAddress $emailValidator,
        private readonly StringLength $emailLengthValidator,
        private readonly FilterManager $filterManager,
        private readonly RecipientBlockInterface $blocks,
        private readonly CustomerRepositoryInterface $customers
    ) {
    }

    public function subscribeGuest(string $type, string $reference, mixed $email): bool
    {
        $email = $this->email($email);
        $subscriptionType = $this->type($type);
        $target = $subscriptionType->authorize($reference, 0);
        if ($target === null) {
            return false;
        }
        $permission = $target->accepting && $email !== '' ? $this->permission->check($subscriptionType, $target) : null;
        if ($permission === null || $permission->removesExisting) {
            $this->unsubscribeGuest($type, $subscriptionType, $target);
            return false;
        }
        if (!$permission->isGranted()) {
            return false;
        }
        if ($this->blocks->touch(Channel::EMAIL, $email)) {
            // a changed address must not leave the previous recipient's subscription behind; the answer stays the same
            $this->unsubscribeGuest($type, $subscriptionType, $target);
            $this->lookUpContact($this->websiteId($target), $email);
            return true;
        }
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            // The contact upsert holds its row lock until the subscription exists, including reused contacts.
            $contactId = $this->contacts->save($this->websiteId($target), $email);
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
        $permission = $this->permission->check($subscriptionType, $target);
        if (!$permission->isGranted()) {
            return false;
        }
        if ($this->isBlockedCustomer($customerId)) {
            return true;
        }
        return $this->store($type, $subscriptionType, $target, (string)$permission->reference, null, $customerId);
    }

    /** The contact read a stored sign-up does, so a blocked address does not answer measurably faster. */
    private function lookUpContact(int $websiteId, string $email): void
    {
        $db = $this->resource->getConnection();
        $db->fetchOne($db->select()->from($this->resource->getTableName(ContactRepository::TABLE), 'contact_id')
            ->where('website_id = ?', $websiteId)->where('email_key = ?', ContactRepository::key($email)));
    }

    /** A customer that no longer exists has no address to check: the guarded write refuses it as before. */
    private function isBlockedCustomer(int $customerId): bool
    {
        try {
            $email = (string)$this->customers->getById($customerId)->getEmail();
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            return false;
        }
        return $email !== '' && $this->blocks->touch(Channel::EMAIL, $email);
    }

    /** Trimmed address or '' (unsubscribe); anything else is Magento's 400 without echoing the input. */
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
    private function conditions(string $reference, Target $target): array
    {
        $conditions = [$this->permission->validCondition($this->resource->getConnection()->quote($reference))];
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
        $conditions = $this->conditions($reference, $target);
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
