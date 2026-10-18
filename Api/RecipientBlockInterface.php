<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * Do not contact: recipients who stopped marketing notifications from a signed link in one of them. Blocked
 * recipients are not subscribed again and get no marketing deliveries; each sign-up attempt restarts the lift timer,
 * and a cron lifts a block after the configured days without attempts. Replaceable with a di.xml preference.
 *
 * @api
 */
interface RecipientBlockInterface
{
    public function isBlocked(string $channel, string $recipient): bool;

    /** Blocks the recipient and removes their subscriptions. */
    public function block(string $channel, string $recipient): void;

    public function unblock(string $channel, string $recipient): void;

    /** Lifts blocks without a sign-up attempt for the configured days; returns how many. */
    public function cleanUp(): int;

    /** A sign-up attempt for a blocked recipient: restarts the lift timer. False when not blocked. */
    public function touch(string $channel, string $recipient): bool;

    /** The signed "stop these emails" link for an email in this store; '' when the link is turned off. */
    public function getBlockUrl(string $channel, string $recipient, int $storeId): string;

    /**
     * The channel and recipient a signed link was made for, or null for an altered or foreign link.
     *
     * @return array{channel: string, recipient: string}|null
     */
    public function fromLink(string $recipient, string $signature): ?array;
}
