<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

use CommerceKings\Notification\Api\Data\Subscription;

/**
 * The subscriber behind a permission: can the storefront recognize them, and how do they unsubscribe? Provided by
 * the same module as SubscriptionPermissionInterface (its reference identifies the permission); replace both through
 * DI preferences.
 *
 * @api
 */
interface SubscriberInterface
{
    /**
     * Can isCurrentVisitor() tell who gave a permission? A feature hands a visitor something the subscriber owns
     * (e.g. a saved cart) only when it can.
     */
    public function identifiesVisitor(): bool;

    /** Is the current visitor the one who gave this permission? false when it cannot tell. */
    public function isCurrentVisitor(string $reference): bool;

    /** A link that ends this subscription (e.g. a signed withdrawal page), null when there is none. */
    public function getUnsubscribeUrl(Subscription $subscription): ?string;
}
