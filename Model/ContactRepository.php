<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\ContactRepositoryInterface;
use Magento\Framework\App\ResourceConnection;

/** Guest email addresses, one row per website and address; customers keep using their account email. */
class ContactRepository implements ContactRepositoryInterface
{
    public const TABLE = 'ck_notification_contact';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Atomic: concurrent saves of one address return the same contact (unique website + email_key). The address is
     * kept as first entered; identity is email_key, never the email column, whose collation ignores case and accents.
     */
    public function save(int $websiteId, string $email): int
    {
        $email = trim($email);
        if ($websiteId <= 0 || $email === '') {
            throw new \InvalidArgumentException('A contact needs a website and an email address.');
        }
        $key = self::key($email);
        $db = $this->resource->getConnection();
        $table = $db->quoteIdentifier($this->resource->getTableName(self::TABLE));
        $db->query("INSERT INTO {$table} (website_id, email, email_key) VALUES (?, ?, ?)"
            . ' ON DUPLICATE KEY UPDATE contact_id = contact_id', [$websiteId, $email, $key]);
        return (int)$db->fetchOne($db->select()->from($this->resource->getTableName(self::TABLE), 'contact_id')
            ->where('website_id = ?', $websiteId)->where('email_key = ?', $key));
    }

    /**
     * An address's exact identity: sha256 of the trimmed, Unicode-NFC, lower-cased address (letter case is ignored,
     * as Magento and the recipient cooldown do; accents and every other character count). Hex, so any collation
     * compares it exactly.
     */
    public static function key(string $email): string
    {
        $email = trim($email);
        $normalized = \Normalizer::normalize($email, \Normalizer::FORM_C);
        return hash('sha256', mb_strtolower(is_string($normalized) ? $normalized : $email, 'UTF-8'));
    }

    public function getEmail(int $contactId): ?string
    {
        $db = $this->resource->getConnection();
        $email = $db->fetchOne($db->select()->from($this->resource->getTableName(self::TABLE), 'email')
            ->where('contact_id = ?', $contactId));
        return is_string($email) && $email !== '' ? $email : null;
    }
}
