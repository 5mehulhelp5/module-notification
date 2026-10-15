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

    /** Atomic: concurrent saves of one address return the same contact (unique website + email). */
    public function save(int $websiteId, string $email): int
    {
        $email = trim($email);
        if ($websiteId <= 0 || $email === '') {
            throw new \InvalidArgumentException('A contact needs a website and an email address.');
        }
        $db = $this->resource->getConnection();
        $table = $db->quoteIdentifier($this->resource->getTableName(self::TABLE));
        // an existing address is kept as it is; the unique key (website, email) ignores letter case
        $db->query("INSERT INTO {$table} (website_id, email) VALUES (?, ?)"
            . ' ON DUPLICATE KEY UPDATE contact_id = contact_id', [$websiteId, $email]);
        return (int)$db->fetchOne($db->select()->from($this->resource->getTableName(self::TABLE), 'contact_id')
            ->where('website_id = ?', $websiteId)->where('email = ?', $email));
    }

    public function getEmail(int $contactId): ?string
    {
        $db = $this->resource->getConnection();
        $email = $db->fetchOne($db->select()->from($this->resource->getTableName(self::TABLE), 'email')
            ->where('contact_id = ?', $contactId));
        return is_string($email) && $email !== '' ? $email : null;
    }
}
