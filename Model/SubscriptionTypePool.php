<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\SubscriptionTypeInterface;

/**
 * Subscription types, registered by the modules that own them (di.xml array argument "types").
 *
 * @api the "types" di.xml argument is a stable extension point
 */
class SubscriptionTypePool
{
    /** @param array<string, SubscriptionTypeInterface> $types */
    public function __construct(
        private readonly array $types = []
    ) {
    }

    public function get(string $code): ?SubscriptionTypeInterface
    {
        $type = $this->types[$code] ?? null;
        return $type instanceof SubscriptionTypeInterface ? $type : null;
    }

    /** @return array<string, SubscriptionTypeInterface> code => type */
    public function getAll(): array
    {
        return array_filter($this->types, fn ($type) => $type instanceof SubscriptionTypeInterface);
    }
}
