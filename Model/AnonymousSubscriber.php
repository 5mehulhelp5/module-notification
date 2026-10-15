<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\Data\Subscription;
use CommerceKings\Notification\Api\SubscriberInterface;

/**
 * Notification on its own: the subscriber cannot be recognized in the storefront and there is no unsubscribe link. A
 * permission module replaces it (e.g. the consent bridge; an opt-out module supplies its unsubscribe page).
 */
class AnonymousSubscriber implements SubscriberInterface
{
    public function identifiesVisitor(): bool
    {
        return false;
    }

    public function isCurrentVisitor(string $reference): bool
    {
        return false;
    }

    public function getUnsubscribeUrl(Subscription $subscription): ?string
    {
        return null;
    }
}
