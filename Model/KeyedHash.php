<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Magento's keyed hash under every key in env.php, newest first. New data is always hashed with the newest key
 * (EncryptorInterface::hash()); the older keys – Magento keeps them after encryption:key:change – only let data
 * stored or links signed before a key change still match. They are used only when computing the newest key's hash
 * here reproduces the encryptor's own (otherwise only the newest key counts).
 */
class KeyedHash
{
    public function __construct(
        private readonly EncryptorInterface $encryptor,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    public function current(string $data): string
    {
        return $this->encryptor->hash($data);
    }

    /** @return string[] the newest key's hash first, then the older keys' (newest to oldest) */
    public function all(string $data): array
    {
        $current = $this->current($data);
        $keys = array_values(array_filter(preg_split('/\s+/', trim((string)$this->deploymentConfig->get('crypt/key'))) ?: [],
            'strlen'));
        $hashes = array_map(fn (string $key) => hash_hmac('sha256', $data, $this->decode($key)), $keys);
        if (array_pop($hashes) !== $current) {
            return [$current];
        }
        return array_values(array_unique([$current, ...array_reverse($hashes)]));
    }

    /** Like Encryptor::decodeKey(): keys made by Magento 2.4.7+ are stored as "base64" + the encoded key. */
    private function decode(string $key): string
    {
        return str_starts_with($key, 'base64') ? (string)base64_decode(substr($key, 6)) : $key;
    }
}
