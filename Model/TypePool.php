<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\NotificationTypeInterface;

/** Notification types, registered by the modules that own them (di.xml array argument "types"). */
class TypePool
{
    /** @param array<string, NotificationTypeInterface> $types */
    public function __construct(
        private readonly array $types = []
    ) {
    }

    public function get(string $code): ?NotificationTypeInterface
    {
        $type = $this->types[$code] ?? null;
        return $type instanceof NotificationTypeInterface ? $type : null;
    }
}
