<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\Data\Permission;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Address consent: a type's own permission (SubscriptionTypeInterface::getPermission()) that grants a subscription only
 * for an address with a recorded checkbox consent (Api\AddressConsentManagementInterface) for this type, on its channel,
 * in the store's website. A consent for one number grants only that number. The reference stored on the subscription
 * is the consent's ("ac:..."), so withdrawing it (unchecked, or a stop) ends the subscription at the next check.
 *
 * Per type, through a di.xml virtual type with the argument "typeCode", injected into the type and returned by its
 * getPermission().
 */
class AddressConsent implements SubscriptionPermissionInterface
{
    public function __construct(
        private readonly AddressConsentRepository $consents,
        private readonly StoreManagerInterface $stores,
        private readonly string $typeCode
    ) {
    }

    public function getTypeCode(): string
    {
        return $this->typeCode;
    }

    /** No recorded consent for this exact address: absent (a guest's previous single subscription is removed). */
    public function check(SubscriptionTypeInterface $type, Target $target, ?string $recipient = null): Permission
    {
        if ($recipient === null || $recipient === '') {
            return Permission::absent();
        }
        $reference = $this->consents->findActive($this->typeCode, $type->getChannel(), $recipient,
            (int)$this->stores->getStore($target->storeId)->getWebsiteId());
        return $reference === null ? Permission::absent() : Permission::granted($reference);
    }

    public function validCondition(string $referenceColumn): string
    {
        return $this->consents->activeCondition($referenceColumn);
    }

    public function isValidFor(string $reference, string $purpose, int $storeId): bool
    {
        return $this->consents->isActive($reference, (int)$this->stores->getStore($storeId)->getWebsiteId());
    }

    public function isOptOut(): bool
    {
        return false;
    }
}
