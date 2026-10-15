<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\Data\Permission;
use CommerceKings\Notification\Api\Data\Subscription;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;

/**
 * Notification on its own (opt-out): every authorized subscription is allowed and lasts until it is removed. It
 * cannot tell who subscribed, so features hand nothing the subscriber owns to a visitor. A permission module
 * (e.g. commercekings/module-consent-notification) replaces it.
 */
class OpenPermission implements SubscriptionPermissionInterface
{
    public function check(SubscriptionTypeInterface $type, Target $target): Permission
    {
        return Permission::granted();
    }

    public function validCondition(string $referenceColumn): string
    {
        return '1 = 1';
    }

    public function identifiesVisitor(): bool
    {
        return false;
    }

    public function isCurrentVisitor(string $reference): bool
    {
        return false;
    }

    public function isValidFor(string $reference, string $purpose, int $storeId): bool
    {
        return true;
    }

    /** None: an opt-out module supplies the unsubscribe page (Api\SubscriptionRemovalInterface::remove()). */
    public function getUnsubscribeUrl(Subscription $subscription): ?string
    {
        return null;
    }
}
