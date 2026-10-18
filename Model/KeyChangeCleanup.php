<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Notification\NotifierInterface;
use Psr\Log\LoggerInterface;

/**
 * Removes do-not-contact blocks and cooldown rows made with an earlier encryption key (they can no longer match)
 * and tells the shop owner once, in the admin notifications, when blocks were lifted that way. Run on purpose, after
 * `encryption:key:change`, by `notification:key-change:cleanup` – never automatically.
 *
 * By default only rows of an older key still listed in env.php are removed: a server with a wrong env.php knows
 * none of the shop's keys, so it removes nothing. Rows of a key that is no longer listed ("unknown") are removed
 * only on request. Rows without a fingerprint are never removed: they age out through the normal cleanups.
 */
class KeyChangeCleanup
{
    private const BATCH = 1000;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly KeyFingerprint $fingerprint,
        private readonly NotifierInterface $notifier,
        private readonly LoggerInterface $logger,
        private readonly ModuleManager $modules
    ) {
    }

    /**
     * Rows per key, nothing removed.
     *
     * @return array{blocks: array<string, int>, cooldowns: array<string, int>} current, earlier, unknown, unmarked
     */
    public function count(): array
    {
        $db = $this->resource->getConnection();
        $current = $this->fingerprint->current();
        $earlier = $this->fingerprint->earlier();
        $kind = 'CASE WHEN key_id IS NULL THEN \'unmarked\''
            . $db->quoteInto(' WHEN key_id = ? THEN \'current\'', $current)
            . ($earlier ? $db->quoteInto(' WHEN key_id IN (?) THEN \'earlier\'', $earlier) : '')
            . ' ELSE \'unknown\' END';
        $count = function (string $table) use ($db, $kind): array {
            $found = $db->fetchPairs($db->select()->from($this->resource->getTableName($table),
                ['kind' => new \Zend_Db_Expr($kind), 'rows' => new \Zend_Db_Expr('COUNT(*)')])->group('kind'));
            return array_map('intval', $found + ['current' => 0, 'earlier' => 0, 'unknown' => 0, 'unmarked' => 0]);
        };
        return ['blocks' => $count(RecipientBlock::TABLE), 'cooldowns' => $count(Cooldown::TABLE)];
    }

    /**
     * @param bool $unknown also remove rows of keys no longer listed in env.php
     * @return array{blocks: int, cooldowns: int, notified: bool} rows removed; whether the admin notice was posted
     */
    public function execute(bool $unknown = false): array
    {
        $keys = $unknown ? null : $this->fingerprint->earlier();
        $current = $this->fingerprint->current();
        $removed = [
            'blocks' => $this->purge($this->resource->getTableName(RecipientBlock::TABLE), $current, $keys),
            'cooldowns' => $this->purge($this->resource->getTableName(Cooldown::TABLE), $current, $keys),
            'notified' => false,
        ];
        if ($removed['blocks'] + $removed['cooldowns'] > 0) {
            $this->logger->warning(sprintf('CommerceKings_Notification: encryption key changed; removed %d'
                . ' do-not-contact block(s) and %d cooldown row(s)', $removed['blocks'], $removed['cooldowns']));
        }
        if ($removed['blocks'] > 0 && $this->modules->isEnabled('Magento_AdminNotification')) {
            try {
                $this->notifier->addMajor(
                    (string)__('CommerceKings Notifications: the encryption key was changed'),
                    (string)__('%1 do-not-contact block(s) were lifted and the email cooldown was reset. Recipients'
                        . ' who had stopped your emails may get them again until they press "Stop" once more. Links in'
                        . ' emails sent before the change no longer work.', $removed['blocks'])
                );
                $removed['notified'] = true;
            } catch (\Throwable $error) {
                // the warning above is the record; a failing notice must not fail the cleanup
                $this->logger->error('CommerceKings_Notification: the key-change admin notice could not be posted.',
                    ['exception_class' => get_class($error)]);
            }
        }
        return $removed;
    }

    /**
     * Deletes in primary-key batches. The delete checks the fingerprint again, so a row rewritten with the current
     * key since the lookup stays; a batch that removes nothing ends the loop.
     *
     * @param string[]|null $keys the fingerprints to remove; null = every fingerprint but the current one
     */
    private function purge(string $table, string $current, ?array $keys): int
    {
        if ($keys === []) {
            return 0;
        }
        $condition = $keys === null ? ['key_id IS NOT NULL', 'key_id <> ?' => $current] : ['key_id IN (?)' => $keys];
        $db = $this->resource->getConnection();
        $old = $db->select()->from($table, 'recipient_hash')->limit(self::BATCH);
        foreach ($condition as $where => $value) {
            is_int($where) ? $old->where($value) : $old->where($where, $value);
        }
        $removed = 0;
        while ($hashes = $db->fetchCol($old)) {
            $deleted = $db->delete($table, ['recipient_hash IN (?)' => $hashes] + $condition);
            if ($deleted === 0) {
                break;
            }
            $removed += $deleted;
        }
        return $removed;
    }
}
