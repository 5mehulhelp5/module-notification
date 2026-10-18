<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\Data\Subscription;
use CommerceKings\Notification\Api\SubscriberInterface;

/**
 * One type's subscriber as a SubscriberInterface: its own (getSubscriber()) or the shop-wide one. A feature injects it
 * for its type with a di.xml virtual type (argument "typeCode"), like TypePermission.
 */
class TypeSubscriber implements SubscriberInterface
{
    public function __construct(
        private readonly SubscriberResolver $resolver,
        private readonly string $typeCode
    ) {
    }

    public function identifiesVisitor(): bool
    {
        return $this->subscriber()->identifiesVisitor();
    }

    public function isCurrentVisitor(string $reference): bool
    {
        return $this->subscriber()->isCurrentVisitor($reference);
    }

    public function getUnsubscribeUrl(Subscription $subscription): ?string
    {
        return $this->subscriber()->getUnsubscribeUrl($subscription);
    }

    /** Resolved on use: the type pool may still be built when this is created. */
    private function subscriber(): SubscriberInterface
    {
        return $this->resolver->forCode($this->typeCode);
    }
}
