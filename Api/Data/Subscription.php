<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api\Data;

/**
 * One visitor's subscription to one reference of a type, with the permission it was given under.
 * A guest has a contact, a customer a customer id (their current account email on email types; on other
 * channels the address they gave).
 *
 * @api
 */
class Subscription
{
    public function __construct(
        public readonly int $subscriptionId,
        public readonly string $type,
        public readonly int $referenceId,
        public readonly int $storeId,
        public readonly ?int $contactId,
        public readonly ?int $customerId,
        /** SubscriptionPermissionInterface's reference (e.g. a consent id), '' when none */
        public readonly string $permissionRef,
        public readonly string $capturedAt
    ) {
    }
}
