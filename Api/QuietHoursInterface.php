<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * Quiet hours: when stoppable deliveries on a channel are held. The default reads the store's settings in the store's
 * time zone and ignores the recipient; replace it with a di.xml preference for another rule (e.g. the recipient's
 * time zone, as US state laws such as Florida's FTSA require).
 *
 * $recipient is the delivery's address on the channel (Delivery::$recipient): the sender passes it normalized
 * (RecipientNormalizerInterface), a feature passes the address it delivered to - an implementation that relies on its
 * exact form normalizes it first (normalizing twice changes nothing).
 *
 * null asks about the whole store: true only when every recipient on the channel is held right now. A finder uses it
 * to leave out a store before it knows the recipients; the sender always asks with the recipient. So an
 * implementation that cannot answer for everyone (e.g. one that goes by the recipient's time zone) answers false for
 * null - always correct, it only means the finder queues and each delivery is held on its own. Answering true would
 * delay recipients whose quiet hours are over.
 *
 * nextAllowedAt() is null exactly when isQuiet() is false for the same arguments.
 *
 * @api
 */
interface QuietHoursInterface
{
    /** Whether stoppable deliveries on this channel are held in this store right now: for the recipient, or for all. */
    public function isQuiet(int $storeId, string $channel, ?string $recipient = null): bool;

    /** When the current quiet window ends (UTC), for the recipient or for all; null when not quiet. */
    public function nextAllowedAt(int $storeId, string $channel, ?string $recipient = null): ?\DateTimeImmutable;
}
