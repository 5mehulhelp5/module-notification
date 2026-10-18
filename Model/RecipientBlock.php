<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\RecipientBlockInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;

/**
 * Do not contact, keyed hashes only. A link carries the recipient encrypted with the deployment key plus a keyed
 * signature, so blocking can find the recipient's subscriptions without storing the address anywhere.
 */
class RecipientBlock implements RecipientBlockInterface
{
    public const TABLE = 'ck_notification_recipient_block';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EncryptorInterface $encryptor,
        private readonly UrlInterface $url,
        private readonly Config $config,
        private readonly RecipientNormalizerPool $normalizers,
        private readonly KeyFingerprint $fingerprint
    ) {
    }

    public function isBlocked(string $channel, string $recipient): bool
    {
        $db = $this->resource->getConnection();
        return (bool)$db->fetchOne($db->select()->from($this->resource->getTableName(self::TABLE), 'recipient_hash')
            ->where('recipient_hash = ?', $this->hash($channel, $recipient)));
    }

    public function block(string $channel, string $recipient): void
    {
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $db->insertOnDuplicate($this->resource->getTableName(self::TABLE), [
                'recipient_hash' => $this->hash($channel, $recipient),
                'blocked_at' => new \Zend_Db_Expr('NOW()'),
                'last_attempt_at' => new \Zend_Db_Expr('NOW()'),
                'key_id' => $this->fingerprint->current(),
            ], ['last_attempt_at', 'key_id']);
            if ($channel === Channel::EMAIL) {
                $this->removeSubscriptions($recipient);
            }
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }

    public function unblock(string $channel, string $recipient): void
    {
        $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE),
            ['recipient_hash = ?' => $this->hash($channel, $recipient)]);
    }

    public function touch(string $channel, string $recipient): bool
    {
        return $this->resource->getConnection()->update($this->resource->getTableName(self::TABLE),
            ['last_attempt_at' => new \Zend_Db_Expr('NOW()')],
            ['recipient_hash = ?' => $this->hash($channel, $recipient)]) > 0
            || $this->isBlocked($channel, $recipient); // touched twice within a second: no row change, still blocked
    }

    public function getBlockUrl(string $channel, string $recipient, int $storeId): string
    {
        if (!$this->config->isBlockLinkEnabled($storeId)) {
            return '';
        }
        $cipher = base64_encode($this->encryptor->encrypt($channel . '|' . $recipient));
        $encrypted = rtrim(strtr($cipher, '+/', '-_'), '=');
        return $this->url->getUrl('cknotification/block', [
            '_scope' => $storeId,
            '_query' => ['r' => $encrypted, 's' => $this->sign($encrypted)],
            '_nosid' => true,
        ]);
    }

    public function fromLink(string $recipient, string $signature): ?array
    {
        if ($recipient === '' || strlen($recipient) > 1024 || !hash_equals($this->sign($recipient), $signature)) {
            return null;
        }
        $decrypted = $this->encryptor->decrypt((string)base64_decode(strtr($recipient, '-_', '+/'), true));
        $parts = explode('|', (string)$decrypted, 2);
        return count($parts) === 2 && $parts[0] !== '' && $parts[1] !== ''
            ? ['channel' => $parts[0], 'recipient' => $parts[1]] : null;
    }

    /** Lifts blocks without a sign-up attempt for the configured days. */
    public function cleanUp(): int
    {
        return $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE),
            ['last_attempt_at < NOW() - INTERVAL ? DAY' => $this->config->getBlockLiftDays()]);
    }

    /** Keyed, purpose-separated; email in Notification's one normalization, other channels by their normalizer. */
    private function hash(string $channel, string $recipient): string
    {
        $recipient = $this->normalizers->normalize($channel, $recipient);
        if ($channel === Channel::EMAIL) {
            $recipient = ContactRepository::normalize($recipient);
        }
        return $this->encryptor->hash('commercekings_notification_block|' . $channel . '|' . $recipient);
    }

    private function sign(string $encrypted): string
    {
        return $this->encryptor->hash('commercekings_notification_block_link|' . $encrypted);
    }

    /** The address's guest contacts and customer accounts, on every website: no subscription survives the block. */
    private function removeSubscriptions(string $email): void
    {
        $db = $this->resource->getConnection();
        $contacts = $db->select()->from($this->resource->getTableName(ContactRepository::TABLE), 'contact_id')
            ->where('email_key = ?', ContactRepository::key($email));
        $customers = $db->select()->from($this->resource->getTableName('customer_entity'), 'entity_id')
            ->where('email = ?', trim($email));
        $db->delete($this->resource->getTableName(SubscriptionManagement::TABLE),
            'contact_id IN (' . $contacts . ') OR customer_id IN (' . $customers . ')');
    }
}
