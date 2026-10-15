<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

use CommerceKings\Notification\Api\Data\Target;

/**
 * Who may subscribe to what, for one notification type. Registered by the feature in di.xml
 * (Model\SubscriptionTypePool "types", same code as its NotificationTypeInterface). Notification
 * owns the rest: email rules, consent for getPurpose(), the contact and the subscription row.
 *
 * Messages of a subscription type are queued with the subscription id; the consumer only hands over
 * subscriptions that still exist with a valid consent. A feature keeps its own send history keyed by
 * what "once" means for it (e.g. the cart), not by the subscription, which a withdrawal deletes.
 *
 * @api
 */
interface SubscriptionTypeInterface
{
    /** CommerceKings_Consent purpose the visitor must have consented to, e.g. "cart_reminder". */
    public function getPurpose(): string;

    /** true: one subscription per reference (a new email replaces it); false: one per person. */
    public function isSingle(): bool;

    /**
     * May this visitor subscribe to $reference? Runs before consent is read or anything is written.
     * @param string $reference as sent by the storefront (e.g. a masked cart id)
     * @param int $customerId logged-in customer, 0 for a guest
     * @return Target|null null: not theirs or does not exist - refused, nothing changes
     */
    public function authorize(string $reference, int $customerId): ?Target;
}
