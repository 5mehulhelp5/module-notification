<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * Do not contact, on every channel: recipients who get no stoppable deliveries and are not subscribed again (answered
 * as stored). The reason decides how a stop ends: a stop link's and an undeliverable number's lift after the configured
 * days without sign-up attempts (each attempt restarts the timer); a STOP reply's only by resume() (e.g. a START reply).
 * One stop per recipient keeps its strongest reason: reply, then link, then undeliverable. Replaceable with a di.xml
 * preference.
 *
 * @api
 */
interface StopListInterface
{
    /** The signed stop link (or its one-click POST): withdraws the address's consents; lifts after the configured days. */
    public const REASON_LINK = 'link';
    /** A STOP reply or button (carrier rules, e.g. TCPA): withdraws the address's consents; lifts only by resume(). */
    public const REASON_REPLY = 'reply';
    /** The service reported the address undeliverable: suppresses it, keeps its consents; lifts after the configured days. */
    public const REASON_UNDELIVERABLE = 'undeliverable';

    /**
     * @throws \InvalidArgumentException when the recipient is not valid for the channel (normalizes to '')
     */
    public function isStopped(string $channel, string $recipient): bool;

    /**
     * Why the recipient is stopped (a REASON_* constant), or null when not stopped.
     *
     * @throws \InvalidArgumentException when the recipient is not valid for the channel (normalizes to '')
     */
    public function getReason(string $channel, string $recipient): ?string;

    /**
     * Stops the recipient and removes their stoppable subscriptions; a link or reply stop also withdraws the address's
     * checkbox consents (and the subscriptions on them). A stronger reason already stored is kept, and so is the later
     * stop time: the same stop delivered twice changes nothing.
     *
     * @param string $reason a REASON_* constant
     * @param \DateTimeImmutable|null $requestedAt when the person asked (e.g. the provider's receive time of a STOP
     *     reply); null: now. A time in the future counts as now.
     * @throws \InvalidArgumentException for an unknown reason, or a recipient not valid for the channel (normalizes to '')
     */
    public function stop(
        string $channel,
        string $recipient,
        string $reason = self::REASON_LINK,
        ?\DateTimeImmutable $requestedAt = null
    ): void;

    /**
     * Lifts the stop, whatever its reason (e.g. a START reply). Removed subscriptions do not come back. With
     * $requestedAt, only a stop older than it is lifted: a START that arrives late, after a newer STOP, leaves the
     * recipient stopped (webhooks can be retried and arrive out of order).
     *
     * @param \DateTimeImmutable|null $requestedAt when the person asked (e.g. the provider's receive time of a START
     *     reply); null: lift whatever stop there is. A time in the future counts as now.
     * @throws \InvalidArgumentException when the recipient is not valid for the channel (normalizes to '')
     */
    public function resume(string $channel, string $recipient, ?\DateTimeImmutable $requestedAt = null): void;

    /** Lifts link and undeliverable stops without a sign-up attempt for the configured days; returns how many. */
    public function cleanUp(): int;

    /**
     * A sign-up attempt for a stopped recipient: restarts the lift timer. False when not stopped.
     *
     * @throws \InvalidArgumentException when the recipient is not valid for the channel (normalizes to '')
     */
    public function touch(string $channel, string $recipient): bool;

    /**
     * The signed stop link for a recipient on a channel in this store; '' when the link is turned off.
     *
     * @throws \InvalidArgumentException when the recipient is not valid for the channel (normalizes to '')
     */
    public function getStopUrl(string $channel, string $recipient, int $storeId): string;

    /**
     * The channel and recipient a signed link was made for, or null for an altered or foreign link. A link signed
     * before encryption:key:change still verifies while its key stays in env.php; `current` is false then – such a
     * link may stop, but never undo.
     *
     * @return array{channel: string, recipient: string, current: bool}|null
     */
    public function fromLink(string $recipient, string $signature): ?array;
}
