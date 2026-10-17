<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * Delivery channels. Email ships with Notification; a provider for another channel (e.g. SMS) declares its own
 * code here by value - the core only compares codes.
 *
 * @api
 */
final class Channel
{
    public const EMAIL = 'email';
    public const SMS = 'sms';

    private function __construct()
    {
    }
}
