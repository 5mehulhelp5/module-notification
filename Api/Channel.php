<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * Delivery channels. Email ships with Notification, with a provider; SMS, WhatsApp and Viber are named here, with one
 * phone number form (Model\PhoneNormalizer), so every provider of them shares one code - contacts, the stop list,
 * cooldown and quiet hours then work across providers. Any other channel is a code its provider module chooses
 * (getChannel()) - the core only compares codes.
 *
 * @api
 */
class Channel
{
    public const EMAIL = 'email';
    /** Text messages to a phone number (E.164, Model\PhoneNormalizer), whichever provider sends them. */
    public const SMS = 'sms';
    /** WhatsApp messages to a phone number (E.164, Model\PhoneNormalizer), whichever provider sends them. */
    public const WHATSAPP = 'whatsapp';
    /** Viber messages to a phone number (E.164, Model\PhoneNormalizer), whichever provider sends them. */
    public const VIBER = 'viber';

    private function __construct()
    {
    }
}
