<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\RecipientNormalizerInterface;

/**
 * Phone numbers in strict E.164 ("+381601234567"), registered for Channel::SMS and Channel::WHATSAPP: spaces, dashes,
 * dots and brackets are left out; a leading "+", a country code not starting with 0 and 8-15 digits in all are
 * required, anything else is '' (refused). No national formats or "00" prefixes - which country a local number means
 * needs a phone library. It owns both channels: provider modules do not register a normalizer for them (every provider
 * of a channel must see one form, and a replacement changes the keys of stored contacts and stops); only a project
 * replaces it, on purpose (RecipientNormalizerPool).
 *
 * @api
 */
class PhoneNormalizer implements RecipientNormalizerInterface
{
    public function normalize(string $recipient): string
    {
        $number = preg_replace('/[\s\-.()]+/u', '', trim($recipient));
        return is_string($number) && preg_match('/^\+[1-9][0-9]{7,14}$/D', $number) ? $number : '';
    }
}
