<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

use CommerceKings\Notification\Api\Data\Subscription;

/**
 * Read subscriptions. Rows exist only with a consent; validity at send time is
 * SubscriptionGuardInterface's job.
 *
 * @api
 */
interface SubscriptionRepositoryInterface
{
    public function get(int $subscriptionId): ?Subscription;

    /** @return Subscription[] */
    public function find(string $type, int $referenceId): array;

    /** The guest contact's address, or the customer's current account email; null if neither exists. */
    public function getEmail(Subscription $subscription): ?string;
}
