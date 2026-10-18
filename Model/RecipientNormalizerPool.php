<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\RecipientNormalizerInterface;

/**
 * Modules add theirs in di.xml (argument "normalizers": channel code => RecipientNormalizerInterface).
 *
 * @api the "normalizers" di.xml argument is a stable extension point
 */
class RecipientNormalizerPool
{
    /** @param array<string, RecipientNormalizerInterface> $normalizers */
    public function __construct(private readonly array $normalizers = [])
    {
        foreach ($normalizers as $normalizer) {
            if (!$normalizer instanceof RecipientNormalizerInterface) {
                throw new \InvalidArgumentException('Normalizers must implement RecipientNormalizerInterface.');
            }
        }
    }

    /** Whether the channel has a normalizer – required to accept sign-ups on a channel other than email. */
    public function has(string $channel): bool
    {
        return isset($this->normalizers[$channel]);
    }

    /** The channel's normalizer, else the recipient trimmed. */
    public function normalize(string $channel, string $recipient): string
    {
        return isset($this->normalizers[$channel])
            ? $this->normalizers[$channel]->normalize($recipient) : trim($recipient);
    }
}
