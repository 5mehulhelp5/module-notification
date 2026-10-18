<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Notification\NotifierInterface;
use Psr\Log\LoggerInterface;

/**
 * After `encryption:key:change`, run on purpose by `notification:key-change:cleanup` – never automatically.
 *
 * Do-not-contact blocks of an older key still listed in env.php keep working: they are found under that key and moved
 * to the newest one (RecipientBlock), so they are never removed here. Only blocks of a key that is no longer listed
 * ("unknown") can no longer match; they are removed on request, and the shop owner is told once, in the admin
 * notifications. Cooldown rows of older keys are removed by default (the cooldown only uses the newest key).
 * Rows without a fingerprint are never removed: they age out through the normal cleanups. A server with a wrong
 * env.php sees every row as unknown, so by default it removes no block.
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
     * @param bool $unknown also remove rows of keys no longer listed in env.php (blocks and cooldowns)
     * @return array{blocks: int, cooldowns: int, notified: bool} rows removed; whether the admin notice was posted
     */
    public function execute(bool $unknown = false): array
    {
        $current = $this->fingerprint->current();
        $known = [$current, ...$this->fingerprint->earlier()];
        $removed = [
            'blocks' => $unknown ? $this->purge($this->resource->getTableName(RecipientBlock::TABLE),
                ['key_id IS NOT NULL', 'key_id NOT IN (?)' => $known]) : 0,
            'cooldowns' => $this->purge($this->resource->getTableName(Cooldown::TABLE), $unknown
                ? ['key_id IS NOT NULL', 'key_id <> ?' => $current]
                : ['key_id IN (?)' => array_slice($known, 1)]),
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
                    (string)__('%1 do-not-contact block(s) of encryption keys no longer in env.php were lifted.'
                        . ' Recipients who had stopped your emails may get them again until they press "Stop" once'
                        . ' more. Links in emails signed with those keys no longer work.', $removed['blocks'])
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
     * Deletes in primary-key batches. The delete checks the fingerprint again, so a row rewritten with another key
     * since the lookup stays; a batch that removes nothing ends the loop.
     *
     * @param array<int|string, mixed> $condition where conditions on key_id
     */
    private function purge(string $table, array $condition): int
    {
        if (in_array([], $condition, true)) {
            return 0;   // e.g. no older key listed
        }
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
