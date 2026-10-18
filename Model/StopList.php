<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\StopListInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;

/**
 * Do not contact, keyed hashes only. A link carries the recipient encrypted with the deployment key plus a keyed
 * signature, so stopping can find the recipient's subscriptions without storing the address anywhere.
 */
class StopList implements StopListInterface
{
    public const TABLE = 'ck_notification_stop';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EncryptorInterface $encryptor,
        private readonly UrlInterface $url,
        private readonly Config $config,
        private readonly RecipientNormalizerPool $normalizers,
        private readonly SubscriptionPermissionInterface $permission,
        private readonly KeyedHash $keyedHash,
        private readonly SubscriptionTypePool $types,
        private readonly ContactRepository $contacts
    ) {
    }

    public function isStopped(string $channel, string $recipient): bool
    {
        return $this->find($this->hashes($channel, $recipient)) !== null;
    }

    public function stop(string $channel, string $recipient): void
    {
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $hashes = $this->hashes($channel, $recipient);
            $db->insertOnDuplicate($this->resource->getTableName(self::TABLE), [
                'recipient_hash' => $hashes[0],
                'stopped_at' => new \Zend_Db_Expr('NOW()'),
                'last_attempt_at' => new \Zend_Db_Expr('NOW()'),
            ], ['last_attempt_at']);
            if (count($hashes) > 1) {
                // the same recipient stopped under an older key: one row, under the newest key
                $db->delete($this->resource->getTableName(self::TABLE), ['recipient_hash IN (?)' => array_slice($hashes, 1)]);
            }
            $this->removeSubscriptions($channel, $recipient);
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }

    public function resume(string $channel, string $recipient): void
    {
        $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE),
            ['recipient_hash IN (?)' => $this->hashes($channel, $recipient)]);
    }

    public function touch(string $channel, string $recipient): bool
    {
        $hashes = $this->hashes($channel, $recipient);
        if ($this->find($hashes) === null) {
            return false;
        }
        $this->resource->getConnection()->update($this->resource->getTableName(self::TABLE),
            ['last_attempt_at' => new \Zend_Db_Expr('NOW()')], ['recipient_hash = ?' => $hashes[0]]);
        return true;
    }

    public function getStopUrl(string $channel, string $recipient, int $storeId): string
    {
        // in opt-out mode the link is the only way to stop the emails: it is always offered there
        if (!$this->config->isStopLinkEnabled($storeId) && !$this->permission->isOptOut()) {
            return '';
        }
        $cipher = base64_encode($this->encryptor->encrypt($channel . '|' . $recipient));
        $encrypted = rtrim(strtr($cipher, '+/', '-_'), '=');
        return $this->url->getUrl('cknotification/stop', [
            '_scope' => $storeId,
            '_query' => ['r' => $encrypted, 's' => $this->sign($encrypted)],
            '_nosid' => true,
        ]);
    }

    public function fromLink(string $recipient, string $signature): ?array
    {
        if ($recipient === '' || strlen($recipient) > 1024) {
            return null;
        }
        // links in emails sent before encryption:key:change still verify with an older key listed in env.php
        $current = null;
        foreach ($this->keyedHash->all('commercekings_notification_stop_link|' . $recipient) as $index => $expected) {
            if (hash_equals($expected, $signature)) {
                $current = $index === 0;
                break;
            }
        }
        if ($current === null) {
            return null;
        }
        $decrypted = $this->encryptor->decrypt((string)base64_decode(strtr($recipient, '-_', '+/'), true));
        $parts = explode('|', (string)$decrypted, 2);
        return count($parts) === 2 && $parts[0] !== '' && $parts[1] !== ''
            ? ['channel' => $parts[0], 'recipient' => $parts[1], 'current' => $current] : null;
    }

    /** Lifts stops without a sign-up attempt for the configured days. */
    public function cleanUp(): int
    {
        return $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE),
            ['last_attempt_at < NOW() - INTERVAL ? DAY' => $this->config->getStopLiftDays()]);
    }

    /**
     * Keyed, purpose-separated; email in Notification's one normalization, other channels by their normalizer. The
     * newest key's hash first (the one written), then the older keys' still listed in env.php.
     *
     * @return string[]
     */
    private function hashes(string $channel, string $recipient): array
    {
        $recipient = $this->normalizers->normalize($channel, $recipient);
        if ($channel === Channel::EMAIL) {
            $recipient = ContactRepository::normalize($recipient);
        }
        return $this->keyedHash->all('commercekings_notification_stop|' . $channel . '|' . $recipient);
    }

    /**
     * The stored hash of a stop under any listed key. A stop stored under an older key is moved to the newest key
     * when found, so it keeps working after the older key leaves env.php.
     *
     * @param string[] $hashes newest key first
     */
    private function find(array $hashes): ?string
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $found = $db->fetchCol($db->select()->from($table, 'recipient_hash')->where('recipient_hash IN (?)', $hashes));
        if ($found === []) {
            return null;
        }
        if (!in_array($hashes[0], $found, true)) {
            $db->query("INSERT IGNORE INTO {$table} (recipient_hash, stopped_at, last_attempt_at)"
                . " SELECT ?, stopped_at, last_attempt_at FROM {$table} WHERE recipient_hash = ?", [$hashes[0], $found[0]]);
        }
        $older = array_values(array_diff($found, [$hashes[0]]));
        if ($older) {
            $db->delete($table, ['recipient_hash IN (?)' => $older]);
        }
        return $hashes[0];
    }

    private function sign(string $encrypted): string
    {
        return $this->keyedHash->current('commercekings_notification_stop_link|' . $encrypted);
    }

    /**
     * The recipient's stoppable subscriptions on this channel, as guest contact (any channel) or customer account
     * (email), on every website. Contacts and customers match exactly (ContactRepository::keyFor()): the email column's
     * collation would also match other letter cases and accents. Subscriptions of a registered type that is not
     * stoppable stay.
     */
    private function removeSubscriptions(string $channel, string $recipient): void
    {
        $db = $this->resource->getConnection();
        $key = $this->contacts->keyFor($channel, $recipient);
        $contacts = $db->select()->from($this->resource->getTableName(ContactRepository::TABLE), 'contact_id')
            ->where('channel = ?', $channel)->where('recipient_key = ?', $key);
        $customers = [];
        if ($channel === Channel::EMAIL) {
            $candidates = $db->fetchPairs($db->select()->from($this->resource->getTableName('customer_entity'),
                ['entity_id', 'email'])->where('email = ?', trim($recipient)));
            $customers = array_keys(array_filter($candidates, fn ($candidate) => ContactRepository::key((string)$candidate) === $key));
        }
        $where = $customers
            ? $db->quoteInto('(contact_id IN (' . $contacts . ') OR customer_id IN (?))', $customers)
            : 'contact_id IN (' . $contacts . ')';
        $kept = array_keys(array_filter($this->types->getAll(), fn ($type) => !$type->isStoppable()));
        $db->delete($this->resource->getTableName(SubscriptionManagement::TABLE),
            $kept ? [$where, 'type NOT IN (?)' => $kept] : [$where]);
    }
}
