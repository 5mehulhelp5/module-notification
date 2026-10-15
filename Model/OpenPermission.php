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
    public function check(SubscriptionTypeInterface $type, Target $target): Permission
    {
        return Permission::granted();
    }

    public function validCondition(string $referenceColumn): string
    {
        return '1 = 1';
    }

    public function isValidFor(string $reference, string $purpose, int $storeId): bool
    {
        return true;
    }
}
