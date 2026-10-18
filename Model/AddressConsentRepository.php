<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\Channel;
use Magento\Framework\App\ResourceConnection;

/**
 * Address consent evidence (ck_notification_address_consent). The address is stored only as a keyed hash
 * (Model\KeyedHash, so it still matches after encryption:key:change); the store view and its website are kept without
 * a foreign key, so the evidence outlives a deleted store view.
 */
class AddressConsentRepository
{
    public const TABLE = 'ck_notification_address_consent';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly KeyedHash $keyedHash
    ) {
    }

    /** The newest active consent's reference for the address, type and website; null when there is none. */
    public function findActive(string $type, string $channel, string $recipient, int $websiteId): ?string
    {
        $db = $this->resource->getConnection();
        $reference = $db->fetchOne($db->select()->from($this->table(), 'reference')
            ->where('type = ?', $type)->where('channel = ?', $channel)
            ->where('recipient_key IN (?)', $this->keys($channel, $recipient))
            ->where('website_id = ?', $websiteId)->where('withdrawn_at IS NULL')
            ->order('consent_id DESC')->limit(1));
        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * Records a consent; an identical active one (same wording) is kept instead of a duplicate.
     *
     * @return string the consent's reference
     */
    public function record(string $type, string $channel, string $recipient, int $websiteId, int $storeId,
        string $wording, string $ipHash): string
    {
        $db = $this->resource->getConnection();
        $wordingHash = hash('sha256', $wording);
        $existing = $db->fetchOne($db->select()->from($this->table(), 'reference')
            ->where('type = ?', $type)->where('channel = ?', $channel)
            ->where('recipient_key IN (?)', $this->keys($channel, $recipient))
            ->where('website_id = ?', $websiteId)->where('wording_hash = ?', $wordingHash)
            ->where('withdrawn_at IS NULL')->limit(1));
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }
        $reference = 'ac:' . bin2hex(random_bytes(12));
        $db->insert($this->table(), [
            'reference' => $reference,
            'type' => $type,
            'channel' => $channel,
            'recipient_key' => $this->keyedHash->current($this->data($channel, $recipient)),
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'wording_hash' => $wordingHash,
            'wording' => $wording,
            'ip_hash' => $ipHash,
        ]);
        return $reference;
    }

    /**
     * Withdraws the address's active consents: for one type and website, or (both null) all of them, e.g. on a stop.
     *
     * @return string[] the withdrawn consents' references
     */
    public function withdraw(string $channel, string $recipient, ?string $type = null, ?int $websiteId = null): array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from($this->table(), 'reference')->where('channel = ?', $channel)
            ->where('recipient_key IN (?)', $this->keys($channel, $recipient))->where('withdrawn_at IS NULL');
        if ($type !== null) {
            $select->where('type = ?', $type);
        }
        if ($websiteId !== null) {
            $select->where('website_id = ?', $websiteId);
        }
        $references = array_map('strval', $db->fetchCol($select));
        if ($references) {
            $db->update($this->table(), ['withdrawn_at' => new \Zend_Db_Expr('NOW()')],
                ['reference IN (?)' => $references, 'withdrawn_at IS NULL']);
        }
        return $references;
    }

    /** Is the consent behind a reference active for the website? */
    public function isActive(string $reference, int $websiteId): bool
    {
        $db = $this->resource->getConnection();
        return (bool)$db->fetchOne($db->select()->from($this->table(), 'consent_id')
            ->where('reference = ?', $reference)->where('website_id = ?', $websiteId)->where('withdrawn_at IS NULL'));
    }

    /** SQL: the reference in $referenceColumn is an active address consent. */
    public function activeCondition(string $referenceColumn): string
    {
        return sprintf('EXISTS (SELECT 1 FROM %s AS ck_ac WHERE ck_ac.reference = %s AND ck_ac.withdrawn_at IS NULL)',
            $this->resource->getConnection()->quoteIdentifier($this->table()), $referenceColumn);
    }

    /**
     * Deletes the evidence of consents withdrawn more than $days ago in the given store views.
     *
     * @param int[] $storeIds
     */
    public function deleteWithdrawn(array $storeIds, int $days): int
    {
        if (!$storeIds) {
            return 0;
        }
        return $this->resource->getConnection()->delete($this->table(), [
            'store_id IN (?)' => $storeIds,
            'withdrawn_at < NOW() - INTERVAL ' . max(1, $days) . ' DAY',
        ]);
    }

    /** The address's keys under every key in env.php, newest first. */
    private function keys(string $channel, string $recipient): array
    {
        return $this->keyedHash->all($this->data($channel, $recipient));
    }

    /** The hashed form: email addresses ignore letter case, like contacts; other channels arrive normalized. */
    private function data(string $channel, string $recipient): string
    {
        $address = $channel === Channel::EMAIL ? mb_strtolower(trim($recipient)) : $recipient;
        return 'commercekings_notification_address_consent|' . $channel . '|' . $address;
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
