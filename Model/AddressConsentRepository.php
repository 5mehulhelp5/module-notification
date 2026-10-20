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

    /**
     * The newest active consent's reference for the address, type and website; null when there is none. With a
     * grant: only one given for it (another cart's consent for the same number is not this one's), or one from before
     * 0.1.1 (no grant on it).
     */
    public function findActive(string $type, string $channel, string $recipient, int $websiteId,
        ?int $grantReferenceId = null): ?string
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from($this->table(), 'reference')
            ->where('type = ?', $type)->where('channel = ?', $channel)
            ->where('recipient_key IN (?)', $this->keys($channel, $recipient))
            ->where('website_id = ?', $websiteId)->where('withdrawn_at IS NULL');
        if ($grantReferenceId !== null) {
            $select->where('grant_reference_id = ? OR grant_reference_id IS NULL', $grantReferenceId)
                ->order(new \Zend_Db_Expr('grant_reference_id IS NULL')); // its own first
        }
        $reference = $db->fetchOne($select->order('consent_id DESC')->limit(1));
        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * Records a consent; an identical active one (same wording, given for the same grant) is kept instead of a
     * duplicate.
     *
     * @param int|null $grantReferenceId what it is given for (the type's reference id, e.g. the quote): only a
     *     withdrawal for it (withdrawGranted()) or for the whole address (withdraw(), a stop) ends it
     * @return string the consent's reference
     */
    public function record(string $type, string $channel, string $recipient, int $websiteId, int $storeId,
        string $wording, string $ipHash, ?int $grantReferenceId = null): string
    {
        $db = $this->resource->getConnection();
        $wordingHash = hash('sha256', $wording);
        $existing = $db->fetchOne($db->select()->from($this->table(), 'reference')
            ->where('type = ?', $type)->where('channel = ?', $channel)
            ->where('recipient_key IN (?)', $this->keys($channel, $recipient))
            ->where('website_id = ?', $websiteId)->where('wording_hash = ?', $wordingHash)
            ->where($grantReferenceId === null ? 'grant_reference_id IS NULL' : $db->quoteInto(
                'grant_reference_id = ?', $grantReferenceId))
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
            'grant_reference_id' => $grantReferenceId,
        ]);
        return $reference;
    }

    /**
     * Withdraws the address's active consents for one type and website given for one grant (a checkbox unticked in
     * that cart) - and one from before 0.1.1 (no grant on it) given from the same IP. Another cart's consent for the
     * same number stays: only the number itself (a stop, withdraw()) ends all of them.
     *
     * @return string[] the withdrawn consents' references
     */
    public function withdrawGranted(string $channel, string $recipient, string $type, int $websiteId,
        int $grantReferenceId, string $ipHash): array
    {
        $db = $this->resource->getConnection();
        $mine = $db->quoteInto('grant_reference_id = ?', $grantReferenceId)
            . ($ipHash !== '' ? $db->quoteInto(' OR (grant_reference_id IS NULL AND ip_hash = ?)', $ipHash) : '');
        $references = array_map('strval', $db->fetchCol($db->select()->from($this->table(), 'reference')
            ->where('channel = ?', $channel)->where('recipient_key IN (?)', $this->keys($channel, $recipient))
            ->where('type = ?', $type)->where('website_id = ?', $websiteId)->where('withdrawn_at IS NULL')
            ->where($mine)));
        if ($references) {
            $db->update($this->table(), ['withdrawn_at' => new \Zend_Db_Expr('NOW()')],
                ['reference IN (?)' => $references, 'withdrawn_at IS NULL']);
        }
        return $references;
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
