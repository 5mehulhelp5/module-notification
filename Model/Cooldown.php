<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\CooldownInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Math\Random;

/** Short-lived recipient cooldown, independent of any feature's data and of consent identity. */
class Cooldown implements CooldownInterface
{
    public const TABLE = 'ck_notification_cooldown';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EncryptorInterface $encryptor,
        private readonly Random $random,
        private readonly Config $config
    ) {
    }

    /**
     * Keyed, purpose-separated hash of the normalized address (ContactRepository::normalize(), so one contact is one
     * cooldown); no email or deployment key is written to this table.
     */
    public function hash(string $email): string
    {
        return $this->encryptor->hash('commercekings_notification_recipient|' . ContactRepository::normalize($email));
    }

    public function isRecent(string $email): bool
    {
        $days = $this->config->getCooldownDays();
        if ($days === 0) {
            return false;
        }
        $db = $this->resource->getConnection();
        return (bool)$db->fetchOne($db->select()
            ->from($this->resource->getTableName(self::TABLE), 'recipient_hash')
            ->where('recipient_hash = ?', $this->hash($email))
            ->where('sent_at >= NOW() - INTERVAL ? DAY', $days));
    }

    /** Atomic across notifications/workers. The token owns this claim, including any later release. */
    public function claim(string $email): ?string
    {
        $db = $this->resource->getConnection();
        $table = $db->quoteIdentifier($this->resource->getTableName(self::TABLE));
        $hash = $this->hash($email);
        $token = $this->random->getRandomString(32, '0123456789abcdef');
        $days = $this->config->getCooldownDays();
        // Assign the token before the timestamp: both conditions must inspect the old timestamp.
        $db->query("INSERT INTO {$table} (recipient_hash, sent_at, claim_token) VALUES (?, NOW(), ?)"
            . " ON DUPLICATE KEY UPDATE"
            . " claim_token = IF(sent_at < NOW() - INTERVAL {$days} DAY, VALUES(claim_token), claim_token),"
            . " sent_at = IF(sent_at < NOW() - INTERVAL {$days} DAY, NOW(), sent_at)",
            [$hash, $token]);
        $owner = $db->fetchOne($db->select()->from($this->resource->getTableName(self::TABLE), 'claim_token')
            ->where('recipient_hash = ?', $hash));
        return is_string($owner) && hash_equals($token, $owner) ? $token : null;
    }

    /** Failed before SMTP handoff. Never remove a replacement worker's claim. */
    public function release(string $email, string $token): void
    {
        $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE),
            ['recipient_hash = ?' => $this->hash($email), 'claim_token = ?' => $token]);
    }

    public function cleanUp(): int
    {
        return $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE),
            ['sent_at < NOW() - INTERVAL ? DAY' => $this->config->getCooldownDays()]);
    }
}
