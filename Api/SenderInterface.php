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

    /**
     * Claims the recipient cooldown (if the delivery uses it and it is on), re-checks the recipient, builds the
     * content in the delivery's store and hands it to the provider selected for the channel. A failure before
     * the provider accepted it releases the cooldown claim and is rethrown; a failure after it is rethrown as
     * \CommerceKings\Notification\Exception\MessageSentException - it is out, never send it again.
     *
     * @return string self::SENT, self::COOLDOWN or self::RECIPIENT_CHANGED
     */
    public function send(Delivery $delivery): string;
}
