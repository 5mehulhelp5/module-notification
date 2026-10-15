<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * "Emailed recently?" per address, across notifications and stores. Replaceable through a di.xml
 * preference; the default keeps keyed hashes only.
 *
 * @api
 */
interface CooldownInterface
{
    public function isRecent(string $email): bool;

    /** Atomic across workers. Returns the claim token, or null while the address is still in cooldown. */
    public function claim(string $email): ?string;

    /** Undo a claim whose email was not sent. Never removes another worker's claim. */
    public function release(string $email, string $token): void;
}
