<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

use CommerceKings\Notification\Api\Data\Subscription;

/**
 * Read subscriptions. Rows exist only with a permission; validity at send time is
 * SubscriptionGuardInterface's job.
 *
 * @api
 */
interface SubscriptionRepositoryInterface
{
    public function get(int $subscriptionId): ?Subscription;

    /** @return Subscription[] */
    public function find(string $type, int $referenceId): array;

    /** The guest contact's email address, or the customer's current account email; null if neither exists. */
    public function getEmail(Subscription $subscription): ?string;

    /**
     * Where the subscriber is reached, on any channel: the guest contact's channel and address, or the customer's
     * account email; null if neither exists (a customer's phone number is the feature's to find).
     *
     * @return array{channel: string, recipient: string}|null
     */
    public function getRecipient(Subscription $subscription): ?array;
}
