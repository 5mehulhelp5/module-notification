<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * Removes subscriptions: when a permission is revoked, or when the shopper unsubscribes. Repeating it is harmless.
 *
 * @api
 */
interface SubscriptionRemovalInterface
{
    /**
     * @param string[] $references permission references (SubscriptionPermissionInterface); '' is ignored
     * @return int subscriptions removed
     */
    public function removeByPermission(array $references): int;

    public function remove(int $subscriptionId): bool;
}
