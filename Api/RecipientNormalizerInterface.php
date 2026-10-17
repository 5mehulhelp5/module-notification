<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * One canonical form for a channel's recipients (e.g. "+381601234567" for a phone number), registered per channel in
 * Model\RecipientNormalizerPool. The sender uses it for the cooldown key and hands the result to the provider.
 *
 * @api
 */
interface RecipientNormalizerInterface
{
    public function normalize(string $recipient): string;
}
