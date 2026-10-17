<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * Delivery channels. Email ships with Notification. Any other channel is a code its provider module chooses
 * (getChannel()) - the core only compares codes.
 *
 * @api
 */
final class Channel
{
    public const EMAIL = 'email';

    private function __construct()
    {
    }
}
