<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\Data\Permission;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;

/**
 * Notification on its own (opt-out): every authorized subscription is allowed and lasts until it is removed. A
 * permission module (e.g. commercekings/module-consent-notification) replaces it.
 */
class OpenPermission implements SubscriptionPermissionInterface
{
    public function check(SubscriptionTypeInterface $type, Target $target, ?string $recipient = null): Permission
    {
        return Permission::granted();
    }

    /**
     * Only subscriptions made in opt-out mode (no reference). One that still carries a permission module's reference
     * (e.g. a consent from before switching to opt-out) may have expired or been withdrawn: it does not come back to
     * life; the shopper's next email entry subscribes again under opt-out.
     */
    public function validCondition(string $referenceColumn): string
    {
        return $referenceColumn . " = ''";
    }

    public function isValidFor(string $reference, string $purpose, int $storeId): bool
    {
        return $reference === '';
    }

    public function isOptOut(): bool
    {
        return true;
    }
}
