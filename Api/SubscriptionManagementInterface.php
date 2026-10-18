<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * The only way a visitor subscribes to a notification. Order: email rules, the type's authorize(),
 * the permission (SubscriptionPermissionInterface, read server-side), the contact, then one INSERT that
 * also checks the permission is still valid - a revocation at the same moment wins.
 *
 * @api
 */
interface SubscriptionManagementInterface
{
    /**
     * Dispatched after a subscription row was written and Notification's own transaction committed – not for a refusal,
     * a stopped recipient or an identical retry that changed nothing. Data: subscription_id, type, channel, store_id,
     * contact_id (or null), customer_id (or null), created (true: a new row; false: an existing one changed, e.g. its
     * address or permission). An observer error is logged and never fails the sign-up.
     */
    public const EVENT_SAVED = 'commercekings_notification_subscription_saved';

    /**
     * Guest, on the type's channel (SubscriptionTypeInterface::getChannel()). An empty address, an absent permission
     * or a type that is not accepting removes the guest's subscription of a single type (a type with one subscription
     * per person keeps it; withdrawal links remove those).
     *
     * @param string $type notification type code
     * @param string $reference as the type expects it (e.g. a masked cart id)
     * @param mixed $recipient the address on the type's channel: an email address, or e.g. a phone number for an "sms"
     *                         type (raw input; must be a string, an empty string unsubscribes)
     * @return bool true when stored, including an identical successful retry; false when refused or removed
     * @throws \Magento\Framework\Exception\InputException
     */
    public function subscribeGuest(string $type, string $reference, mixed $recipient): bool;

    /**
     * Logged-in customer. Email types use the account email (no recipient); other channels need the recipient (e.g.
     * the phone number), validated like a guest's and stored as the customer's contact on that channel. Without a
     * permission nothing changes (e.g. a consent from another device stays); revoking the permission removes it.
     *
     * @param int $customerId forced from the session or token
     * @param string $type notification type code
     * @param string $reference as the type expects it
     * @param mixed $recipient the address on the type's channel; required for non-email types, not taken by email types
     * @return bool true when stored, including an identical successful retry
     * @throws \Magento\Framework\Exception\InputException
     */
    public function subscribeCustomer(int $customerId, string $type, string $reference, mixed $recipient = null): bool;
}
