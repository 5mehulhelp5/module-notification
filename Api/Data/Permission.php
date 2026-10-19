<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api\Data;

/**
 * The answer of SubscriptionPermissionInterface: granted (with a reference stored on the subscription),
 * absent (the visitor has no permission: a guest's subscription to a single type is removed, as with an empty
 * recipient) or refused (not for this request: nothing changes).
 *
 * @api
 */
class Permission
{
    private function __construct(
        public readonly ?string $reference,
        public readonly bool $removesExisting
    ) {
    }

    /** @param string $reference stored with the subscription and passed to validCondition(); '' when none */
    public static function granted(string $reference = ''): self
    {
        return new self($reference, false);
    }

    public static function absent(): self
    {
        return new self(null, true);
    }

    public static function refused(): self
    {
        return new self(null, false);
    }

    public function isGranted(): bool
    {
        return $this->reference !== null;
    }
}
