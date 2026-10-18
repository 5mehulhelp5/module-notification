<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\Notification\NotifierInterface;
use Psr\Log\LoggerInterface;

/**
 * Removes do-not-contact blocks and cooldown rows made with an earlier encryption key (they can no longer match)
 * and tells the shop owner once, in the admin notifications, when blocks were lifted that way. Run on purpose, after
 * `encryption:key:change`, by `notification:key-change:cleanup` – never automatically, so a server with the wrong
 * key cannot remove anything on its own.
 */
class KeyChangeCleanup
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly KeyFingerprint $fingerprint,
        private readonly NotifierInterface $notifier,
        private readonly LoggerInterface $logger
    ) {
    }

    private const BATCH = 1000;

    /** @return array{blocks: int, cooldowns: int} rows of an earlier key, nothing removed */
    public function count(): array
    {
        $current = $this->fingerprint->current();
        $db = $this->resource->getConnection();
        $count = fn (string $table) => (int)$db->fetchOne($this->old($table, $current)->reset(Select::COLUMNS)
            ->columns(new \Zend_Db_Expr('COUNT(*)')));
        return [
            'blocks' => $count($this->resource->getTableName(RecipientBlock::TABLE)),
            'cooldowns' => $count($this->resource->getTableName('ck_notification_cooldown')),
        ];
    }

    /**
     * Only rows that carry another key's fingerprint are removed. Rows without one (written by code from before the
     * fingerprint, e.g. a consumer not yet restarted after a deploy) are never treated as old: they age out through
     * the normal lift and cooldown cleanups.
     *
     * @return array{blocks: int, cooldowns: int} rows removed
     */
    public function execute(): array
    {
        $current = $this->fingerprint->current();
        $removed = [
            'blocks' => $this->purge($this->resource->getTableName(RecipientBlock::TABLE), $current),
            'cooldowns' => $this->purge($this->resource->getTableName('ck_notification_cooldown'), $current),
        ];
        if ($removed['blocks'] + $removed['cooldowns'] > 0) {
            $this->logger->warning(sprintf('CommerceKings_Notification: encryption key changed; removed %d'
                . ' do-not-contact block(s) and %d cooldown row(s)', $removed['blocks'], $removed['cooldowns']));
        }
        if ($removed['blocks'] > 0) {
            try {
                $this->notifier->addMajor(
                    (string)__('CommerceKings Notifications: the encryption key was changed'),
                    (string)__('%1 do-not-contact block(s) were lifted and the email cooldown was reset. Recipients'
                        . ' who had stopped your emails may get them again until they press "Stop" once more. Links in'
                        . ' emails sent before the change no longer work.', $removed['blocks'])
                );
            } catch (\Throwable $error) {
                // the warning above is the record; a failing notice must not fail the cleanup
                $this->logger->error('CommerceKings_Notification: the key-change admin notice could not be posted.',
                    ['exception_class' => get_class($error)]);
            }
        }
        return $removed;
    }

    /** Rows with another key's fingerprint; rows without one never count as old. */
    private function old(string $table, string $current): Select
    {
        return $this->resource->getConnection()->select()->from($table, 'recipient_hash')
            ->where('key_id IS NOT NULL')->where('key_id <> ?', $current);
    }

    /**
     * Deletes in primary-key batches. The delete checks the fingerprint again, so a row rewritten with the current
     * key since the lookup stays; a batch that removes nothing ends the loop.
     */
    private function purge(string $table, string $current): int
    {
        $db = $this->resource->getConnection();
        $old = $this->old($table, $current)->limit(self::BATCH);
        $removed = 0;
        while ($hashes = $db->fetchCol($old)) {
            $deleted = $db->delete($table, ['recipient_hash IN (?)' => $hashes, 'key_id IS NOT NULL',
                'key_id <> ?' => $current]);
            if ($deleted === 0) {
                break;
            }
            $removed += $deleted;
        }
        return $removed;
    }
}
