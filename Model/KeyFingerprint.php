<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * A short, one-way marker of Magento's current encryption key, stored with every keyed row (do-not-contact blocks,
 * cooldown). After encryption:key:change the rows made with the old key can no longer match; the marker lets the
 * cleanup find and remove them. One-way: it does not expose the key.
 */
class KeyFingerprint
{
    private const SUBJECT = 'commercekings_notification_key_id';

    public function __construct(
        private readonly EncryptorInterface $encryptor,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    public function current(): string
    {
        return substr($this->encryptor->hash(self::SUBJECT), 0, 16);
    }

    /**
     * Fingerprints of the older keys still listed in env.php (Magento keeps them after encryption:key:change).
     * Computed like Encryptor::hash(); empty when that cannot be confirmed against the newest key, so the cleanup
     * then treats every other fingerprint as unknown.
     *
     * @return string[]
     */
    public function earlier(): array
    {
        $keys = preg_split('/\s+/s', trim((string)$this->deploymentConfig->get('crypt/key')));
        $fingerprints = array_map(fn (string $key) => substr(hash_hmac('sha256', self::SUBJECT, $this->decode($key)), 0, 16),
            array_filter($keys, 'strlen'));
        if (array_pop($fingerprints) !== $this->current()) {
            return [];
        }
        return array_values(array_unique(array_diff($fingerprints, [$this->current()])));
    }

    /** Like Encryptor::decodeKey(): keys made by Magento 2.4.7+ are stored as "base64" + the encoded key. */
    private function decode(string $key): string
    {
        return str_starts_with($key, 'base64') ? (string)base64_decode(substr($key, 6)) : $key;
    }
}
