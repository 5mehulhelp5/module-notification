<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\SubscriberInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;

/**
 * The subscriber behind a type's permission: its own (SubscriptionTypeInterface::getSubscriber()), else the shop-wide
 * one (the SubscriberInterface preference: anonymous, or the consent bridge's browser).
 */
class SubscriberResolver
{
    public function __construct(
        private readonly SubscriberInterface $shopWide,
        private readonly SubscriptionTypePool $types
    ) {
    }

    public function forType(SubscriptionTypeInterface $type): SubscriberInterface
    {
        return $type->getSubscriber() ?? $this->shopWide;
    }

    public function forCode(string $typeCode): SubscriberInterface
    {
        $type = $this->types->get($typeCode);
        return $type !== null ? $this->forType($type) : $this->shopWide;
    }
}
