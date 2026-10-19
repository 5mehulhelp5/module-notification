<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

use CommerceKings\Notification\Api\Data\Delivery;

/** @api */
interface SenderInterface
{
    public const SENT = 'sent';
    /** The recipient got a notification on this channel within the cooldown; nothing was sent. */
    public const COOLDOWN = 'recipient cooldown';
    /** Delivery::$isRecipientCurrent said no after the cooldown claim; nothing was sent. */
    public const RECIPIENT_CHANGED = 'recipient changed';
    /** A stoppable delivery to a recipient on the do-not-contact list; nothing was sent. */
    public const STOPPED = 'recipient stopped';
    /**
     * A stoppable delivery in quiet hours for its recipient (QuietHoursInterface): held, not dropped - nothing was
     * sent or claimed; send it again at QuietHoursInterface::nextAllowedAt() for the same store, channel and recipient.
     */
    public const QUIET_HOURS = 'quiet hours';

    /**
     * Drops stoppable deliveries to recipients on the do-not-contact list, holds stoppable deliveries in quiet hours,
     * claims the recipient cooldown (if the delivery uses it and it is on), re-checks the recipient, builds the
     * content in the delivery's store and hands it to the provider selected for the channel. A failure before
     * the provider accepted it releases the cooldown claim and is rethrown; a failure after it is rethrown as
     * \CommerceKings\Notification\Exception\MessageSentException - it is out, never send it again.
     *
     * @return string self::SENT, self::COOLDOWN, self::RECIPIENT_CHANGED, self::STOPPED or self::QUIET_HOURS
     * @throws \InvalidArgumentException when the recipient is not valid for the channel (normalizes to ''), before any
     *     claim
     * @throws \CommerceKings\Notification\Exception\MessageSentException when a step failed after the provider
     *     accepted it
     * @throws \CommerceKings\Notification\Exception\TemporaryFailureException when the provider refused it for now
     *     (nothing sent, the cooldown claim released): send it again after a short wait
     */
    public function send(Delivery $delivery): string;
}
