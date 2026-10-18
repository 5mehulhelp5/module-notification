<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\ContactRepositoryInterface;
use Magento\Framework\App\ResourceConnection;

/** Guest recipients, one row per website, channel and address; customers keep using their account. */
class ContactRepository implements ContactRepositoryInterface
{
    public const TABLE = 'ck_notification_contact';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly RecipientNormalizerPool $normalizers
    ) {
    }

    /**
     * Atomic: concurrent saves of one address return the same contact (unique website + channel + recipient_key). The
     * address is kept as first entered; identity is recipient_key, never the recipient column, whose collation
     * ignores case and accents.
     */
    public function save(int $websiteId, string $recipient, string $channel = Channel::EMAIL): int
    {
        $recipient = trim($recipient);
        if ($websiteId <= 0 || $recipient === '' || $channel === '') {
            throw new \InvalidArgumentException('A contact needs a website, a channel and a recipient.');
        }
        $key = $this->keyFor($channel, $recipient);
        $db = $this->resource->getConnection();
        $table = $db->quoteIdentifier($this->resource->getTableName(self::TABLE));
        $db->query("INSERT INTO {$table} (website_id, channel, recipient, recipient_key) VALUES (?, ?, ?, ?)"
            . ' ON DUPLICATE KEY UPDATE contact_id = contact_id', [$websiteId, $channel, $recipient, $key]);
        // Use a current read: guest capture must not pin an older snapshot before its guarded subscription INSERT.
        return (int)$db->fetchOne($db->select()->from($this->resource->getTableName(self::TABLE), 'contact_id')
            ->where('website_id = ?', $websiteId)->where('channel = ?', $channel)->where('recipient_key = ?', $key)
            ->forUpdate(true));
    }

    /**
     * The one normalization of an address in Notification (contacts and the recipient cooldown): trimmed, Unicode NFC
     * (a composed and a decomposed "é" are the same address), lower case. Accents and every other character count.
     */
    public static function normalize(string $email): string
    {
        $email = trim($email);
        $normalized = \Normalizer::normalize($email, \Normalizer::FORM_C);
        return mb_strtolower(is_string($normalized) ? $normalized : $email, 'UTF-8');
    }

    /** An email address's exact identity: sha256 of normalize(). Hex, so any collation compares it exactly. */
    public static function key(string $email): string
    {
        return hash('sha256', self::normalize($email));
    }

    /** A recipient's identity on its channel: email as key(), other channels by their normalizer. */
    public function keyFor(string $channel, string $recipient): string
    {
        return $channel === Channel::EMAIL ? self::key($recipient)
            : hash('sha256', $this->normalizers->normalize($channel, $recipient));
    }

    public function find(int $websiteId, string $recipient, string $channel = Channel::EMAIL): ?int
    {
        $db = $this->resource->getConnection();
        $id = (string)$db->fetchOne($db->select()->from($this->resource->getTableName(self::TABLE), 'contact_id')
            ->where('website_id = ?', $websiteId)->where('channel = ?', $channel)
            ->where('recipient_key = ?', $this->keyFor($channel, $recipient)));
        return $id === '' ? null : (int)$id;
    }

    public function getEmail(int $contactId): ?string
    {
        $contact = $this->getRecipient($contactId);
        return $contact !== null && $contact['channel'] === Channel::EMAIL ? $contact['recipient'] : null;
    }

    public function getRecipient(int $contactId): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName(self::TABLE), ['channel', 'recipient'])
            ->where('contact_id = ?', $contactId));
        return is_array($row) && (string)$row['recipient'] !== ''
            ? ['channel' => (string)$row['channel'], 'recipient' => (string)$row['recipient']] : null;
    }
}
