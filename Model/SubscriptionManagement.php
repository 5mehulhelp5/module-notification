<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\ConsentGuardInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use CommerceKings\Consent\Api\Data\Consent;
use CommerceKings\Notification\Api\ContactRepositoryInterface;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\SubscriptionManagementInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Filter\FilterManager;
use Magento\Framework\Validator\EmailAddress;
use Magento\Framework\Validator\StringLength;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Subscribe in this order, nothing written before the last step: email rules, the type's authorize(),
 * consent for its purpose and website, the guest contact, then one INSERT ... SELECT whose WHERE holds
 * the consent guard and the type's condition. Direct SQL - no models are saved.
 */
class SubscriptionManagement implements SubscriptionManagementInterface
{
    public const TABLE = 'ck_notification_subscription';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SubscriptionTypePool $types,
        private readonly ConsentInterface $consent,
        private readonly ConsentGuardInterface $guard,
        private readonly BrowserIdentityInterface $browser,
        private readonly ContactRepositoryInterface $contacts,
        private readonly StoreManagerInterface $stores,
        private readonly EmailAddress $emailValidator,
        private readonly StringLength $emailLengthValidator,
        private readonly FilterManager $filterManager
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
        $consent = $target->accepting && $email !== '' ? $this->consent->getCurrent($subscriptionType->getPurpose()) : null;
        if ($consent === null) {
            $this->unsubscribeGuest($type, $subscriptionType, $target);
            return false;
        }
        if (!$this->matches($consent, $subscriptionType, $target)) {
            return false;
        }
        $contactId = $this->contacts->save($this->websiteId($target), $email);
        return $this->store($type, $subscriptionType, $target, $consent->consentId, $contactId, null);
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
        $consent = $this->consent->getCurrent($subscriptionType->getPurpose());
        if ($consent === null || !$this->matches($consent, $subscriptionType, $target)) {
            return false;
        }
        return $this->store($type, $subscriptionType, $target, $consent->consentId, null, $customerId);
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

    private function matches(Consent $consent, SubscriptionTypeInterface $type, Target $target): bool
    {
        return $consent->purpose === $type->getPurpose() && $consent->websiteId === $this->websiteId($target);
    }

    /** One per reference for single types, otherwise one per contact or customer. */
    private function subjectKey(SubscriptionTypeInterface $type, ?int $contactId, ?int $customerId): string
    {
        return $type->isSingle() ? '' : ($contactId !== null ? 'c:' . $contactId : 'u:' . $customerId);
    }

    /** @return string[] */
    private function conditions(string $consentId, Target $target): array
    {
        $conditions = [$this->guard->validCondition($this->resource->getConnection()->quote($consentId))];
        if ($target->condition !== '') {
            $conditions[] = '(' . $target->condition . ')';
        }
        return $conditions;
    }

    private function store(string $code, SubscriptionTypeInterface $type, Target $target, string $consentId,
        ?int $contactId, ?int $customerId): bool
    {
        $db = $this->resource->getConnection();
        $subjectKey = $this->subjectKey($type, $contactId, $customerId);
        $browserHash = $this->browser->hash();
        $conditions = $this->conditions($consentId, $target);
        $statement = $db->query(sprintf(
            'INSERT INTO %s (type, reference_id, store_id, subject_key, contact_id, customer_id, consent_id, browser_hash, captured_at)'
            . ' SELECT ?, ?, ?, ?, ?, ?, ?, ?, NOW() FROM DUAL WHERE %s ON DUPLICATE KEY UPDATE'
            // MySQL assigns in order: compare the old values before replacing them. A change restarts the delay.
            . ' captured_at = IF(contact_id <=> ? AND customer_id <=> ? AND consent_id = ? AND browser_hash = ?, captured_at, NOW()),'
            . ' store_id = ?, contact_id = ?, customer_id = ?, consent_id = ?, browser_hash = ?',
            $db->quoteIdentifier($this->resource->getTableName(self::TABLE)),
            implode(' AND ', $conditions)
        ), [
            $code, $target->referenceId, $target->storeId, $subjectKey, $contactId, $customerId, $consentId, $browserHash,
            $contactId, $customerId, $consentId, $browserHash,
            $target->storeId, $contactId, $customerId, $consentId, $browserHash,
        ]);
        if ($statement->rowCount() > 0) {
            return true;
        }
        // Zero rows: refused, or an identical retry of an acknowledged-but-lost response.
        $select = $db->select()->from($this->resource->getTableName(self::TABLE), 'subscription_id')
            ->where('type = ?', $code)->where('reference_id = ?', $target->referenceId)
            ->where('subject_key = ?', $subjectKey)->where('consent_id = ?', $consentId)
            ->where('browser_hash = ?', $browserHash)
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
