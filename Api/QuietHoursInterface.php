<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * Quiet hours: when stoppable deliveries on a channel are held. The default reads the store's settings in the store's
 * time zone; replace it with a di.xml preference for another rule (e.g. the recipient's time zone).
 *
 * @api
 */
interface QuietHoursInterface
{
    /** Whether stoppable deliveries on this channel are held in this store right now. */
    public function isQuiet(int $storeId, string $channel): bool;

    /** When the current quiet window ends (UTC); null when not quiet. */
    public function nextAllowedAt(int $storeId, string $channel): ?\DateTimeImmutable;
}
