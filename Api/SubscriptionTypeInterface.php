<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

use CommerceKings\Notification\Api\Data\Target;

/**
 * Who may subscribe to what, for one notification type. Registered by the feature in di.xml
 * (Model\SubscriptionTypePool "types", same code as its NotificationTypeInterface). Notification
 * owns the rest: address rules, the permission for getPurpose(), the contact and the subscription row.
 *
 * Messages of a subscription type are queued with the subscription id; the consumer only hands over
 * subscriptions that still exist with a valid permission. Send history belongs to the subscription key (type,
 * reference, subscriber), not to the subscription id, which a withdrawal deletes: DeliveryStateInterface keeps it so.
 *
 * @api
 */
interface SubscriptionTypeInterface
{
    /** What the subscription is for, e.g. "cart_reminder" (with the consent bridge: the Consent purpose). */
    public function getPurpose(): string;

    /**
     * Who may subscribe to this type, when it is not the shop-wide rule – e.g. an SMS type whose consent is a checkbox
     * next to the phone number. null: the shop-wide permission (opt-out, or the cookie banner with the consent bridge).
     * Notification does not care how the permission is collected.
     */
    public function getPermission(): ?SubscriptionPermissionInterface;

    /**
     * The subscriber behind this type's own permission (recognizing the visitor, the unsubscribe link); comes with
     * getPermission(). null: the shop-wide subscriber.
     */
    public function getSubscriber(): ?SubscriberInterface;

    /**
     * The channel its subscribers are reached on: Channel::EMAIL, SMS, WHATSAPP or VIBER; a module's own code only for
     * another channel.
     */
    public function getChannel(): string;

    /** true: one subscription per reference (a new address replaces it); false: one per person. */
    public function isSingle(): bool;

    /**
     * Can the recipient stop it with Do not contact? The feature decides: true e.g. for a cart reminder – a stop
     * removes the subscription and drops its deliveries; false for a service the recipient asked for, which a stop
     * leaves alone. Its deliveries pass the same value as Delivery::$stoppable.
     */
    public function isStoppable(): bool;

    /**
     * May this visitor subscribe to $reference? Runs before the permission is read or anything is written.
     * @param string $reference as sent by the storefront (e.g. a masked cart id)
     * @param int $customerId logged-in customer, 0 for a guest
     * @return Target|null null: not theirs or does not exist - refused, nothing changes
     */
    public function authorize(string $reference, int $customerId): ?Target;

    /**
     * SQL that is true when the referenced item no longer exists (e.g. Magento deleted the cart), so the daily
     * cleanup removes its subscriptions - and with them the guest's address. Notification cannot hold a foreign key
     * to every feature's table; this replaces it.
     * @param string $referenceIdColumn trusted SQL identifier of the subscription's reference_id
     */
    public function orphanCondition(string $referenceIdColumn): string;
}
