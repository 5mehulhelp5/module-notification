<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

use CommerceKings\Notification\Api\Data\Message;

/** @api */
interface MailerInterface
{
    public const SENT = 'sent';
    /** The recipient got an email within the cooldown; nothing was sent. */
    public const COOLDOWN = 'recipient cooldown';
    /** Message::$isRecipientCurrent said no after the cooldown claim; nothing was sent. */
    public const RECIPIENT_CHANGED = 'recipient changed';

    /**
     * Claims the recipient cooldown (if the message uses it and it is on), re-checks the recipient, builds
     * the content in the message's store and sends. A failure before the mail server accepted the email
     * releases the cooldown claim and is rethrown; a failure after it is rethrown wrapped in
     * \CommerceKings\Notification\Exception\MessageSentException - the email is out, never send it again.
     *
     * @return string self::SENT, self::COOLDOWN or self::RECIPIENT_CHANGED
     */
    public function send(Message $message): string;
}
