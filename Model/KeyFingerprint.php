<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * A short, one-way marker of Magento's current encryption key, stored with every keyed row (do-not-contact blocks,
 * cooldown). After encryption:key:change it tells rows of an older key still in env.php from rows of a key no longer
 * listed, which the cleanup can remove. One-way: it does not expose the key.
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
     * Fingerprints of the older keys still listed in env.php (Magento keeps them after encryption:key:change), newest
     * first. Empty when they cannot be confirmed against the newest key (see KeyedHash).
     *
     * @return string[]
     */
    public function earlier(): array
    {
        $all = (new KeyedHash($this->encryptor, $this->deploymentConfig))->all(self::SUBJECT);
        return array_values(array_unique(array_map(fn (string $hash) => substr($hash, 0, 16), array_slice($all, 1))));
    }
}
