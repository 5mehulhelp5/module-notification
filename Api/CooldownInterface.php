<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * "Notified recently?" per address and channel, across notifications and stores. Replaceable through a di.xml
 * preference; the default keeps keyed hashes only.
 *
 * @api
 */
interface CooldownInterface
{
    /** The email channel's check; isRecentFor() for any channel. */
    public function isRecent(string $email): bool;

    /**
     * Has the recipient had a notification on this channel within the cooldown? The same key the sender claims, so a
     * finder can leave out a recipient before it queues anything (e.g. a phone number on Channel::SMS).
     *
     * @param string $recipient in the channel's normalized form, as the sender keys it (a stored contact's recipient
     *     is in that form; email: the address)
     */
    public function isRecentFor(string $channel, string $recipient): bool;

    /**
     * Atomic across workers. Returns the claim token, or null while the address is still in cooldown.
     *
     * @param string $email the sender's key: Cooldown::key($channel, $recipient)
     */
    public function claim(string $email): ?string;

    /** Undo a claim whose notification was not sent. Never removes another worker's claim. */
    public function release(string $email, string $token): void;
}
