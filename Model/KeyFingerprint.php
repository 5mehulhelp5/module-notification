<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use Magento\Framework\Encryption\EncryptorInterface;

/**
 * A short, one-way marker of Magento's current encryption key, stored with every keyed row (do-not-contact blocks,
 * cooldown). After encryption:key:change the rows made with the old key can no longer match; the marker lets the
 * cleanup find and remove them. One-way: it does not expose the key.
 */
class KeyFingerprint
{
    public function __construct(private readonly EncryptorInterface $encryptor)
    {
    }

    public function current(): string
    {
        return substr($this->encryptor->hash('commercekings_notification_key_id'), 0, 16);
    }
}
