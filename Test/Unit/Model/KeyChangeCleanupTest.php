<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Model\KeyChangeCleanup;
use CommerceKings\Notification\Model\KeyFingerprint;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Module\Manager;
use Magento\Framework\Notification\NotifierInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class KeyChangeCleanupTest extends TestCase
{
    private array $warnings = [];
    private array $errors = [];
    private array $wheres = [];
    private array $limits = [];
    private array $deletes = [];
    private array $pairs = [];
    private string $countSql = '';

    /**
     * @param array<string, string[][]> $batches per table: the hashes each lookup returns
     * @param ?int $deleted rows each delete reports; null = the whole batch
     */
    private function cleanup(array $batches, NotifierInterface $notifier, ?int $deleted = null, array $earlier = ['old-key-id'],
        bool $inbox = true): KeyChangeCleanup
    {
        $select = $this->createStub(Select::class);
        $table = '';
        $select->method('from')->willReturnCallback(function ($name, $columns = []) use (&$table, $select) {
            $table = $name;
            if (is_array($columns) && isset($columns['kind'])) {
                $this->countSql = (string)$columns['kind'];
            }
            return $select;
        });
        $select->method('where')->willReturnCallback(function ($condition, $value = null) use ($select, &$table) {
            $this->wheres[$table][] = [$condition, $value];
            return $select;
        });
        $select->method('limit')->willReturnCallback(function ($limit) use ($select) { $this->limits[] = $limit; return $select; });
        $select->method('group')->willReturnSelf();
        $db = $this->createStub(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        $db->method('quoteInto')->willReturnCallback(fn ($text, $value) =>
            str_replace('?', is_array($value) ? "'" . implode("','", $value) . "'" : "'$value'", $text));
        $db->method('fetchCol')->willReturnCallback(function () use (&$batches, &$table) {
            return array_shift($batches[$table]) ?? [];
        });
        $db->method('fetchPairs')->willReturnCallback(function () use (&$table) { return $this->pairs[$table] ?? []; });
        $db->method('delete')->willReturnCallback(function ($name, $where) use ($deleted) {
            $this->deletes[] = [$name, $where];
            return $deleted ?? count($where['recipient_hash IN (?)']);
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        $fingerprint = $this->createStub(KeyFingerprint::class);
        $fingerprint->method('current')->willReturn('current-key-id');
        $fingerprint->method('earlier')->willReturn($earlier);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) { $this->warnings[] = $message; });
        $logger->method('error')->willReturnCallback(function ($message) { $this->errors[] = $message; });
        $modules = $this->createStub(Manager::class);
        $modules->method('isEnabled')->willReturnCallback(fn ($name) => $name === 'Magento_AdminNotification' && $inbox);
        return new KeyChangeCleanup($resource, $fingerprint, $notifier, $logger, $modules);
    }

    private function hashes(int $count): array
    {
        return array_map(fn ($i) => 'h' . $i, range(1, $count));
    }

    public function testByDefaultOnlyEarlierKeyCooldownsAreRemovedAndBlocksStay(): void
    {
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->never())->method('addMajor');
        $removed = $this->cleanup(['ck_notification_recipient_block' => [['b1']],
            'ck_notification_cooldown' => [$this->hashes(1000), $this->hashes(200)]], $notifier)->execute();
        $this->assertSame(['blocks' => 0, 'cooldowns' => 1200, 'notified' => false], $removed,
            'blocks of an earlier key still in env.php keep working, so they are never removed here');
        $this->assertArrayNotHasKey('ck_notification_recipient_block', $this->wheres, 'the block table is not even read');
        $this->assertSame([['key_id IN (?)', ['old-key-id']]], $this->wheres['ck_notification_cooldown']);
        $this->assertSame([1000], $this->limits, 'read in batches of 1000');
        $this->assertSame(['CommerceKings_Notification: encryption key changed; removed 0 do-not-contact block(s)'
            . ' and 1200 cooldown row(s)'], $this->warnings);
    }

    public function testForceRemovesBlocksOfKeysNoLongerListedAndTellsTheAdmin(): void
    {
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->once())->method('addMajor')
            ->with($this->stringContains('encryption key was changed'), $this->stringContains('37 do-not-contact block(s)'));
        $removed = $this->cleanup(['ck_notification_recipient_block' => [$this->hashes(37)],
            'ck_notification_cooldown' => [$this->hashes(5)]], $notifier)->execute(true);
        $this->assertSame(['blocks' => 37, 'cooldowns' => 5, 'notified' => true], $removed);
        $this->assertSame([['key_id IS NOT NULL', null], ['key_id NOT IN (?)', ['current-key-id', 'old-key-id']]],
            $this->wheres['ck_notification_recipient_block'], 'never the current or an earlier key still listed');
        $this->assertSame([['key_id IS NOT NULL', null], ['key_id <> ?', 'current-key-id']],
            $this->wheres['ck_notification_cooldown'], 'every cooldown row but the current key\'s');
    }

    public function testTheDeleteChecksTheFingerprintAgain(): void
    {
        $this->cleanup(['ck_notification_recipient_block' => [['h1', 'h2']], 'ck_notification_cooldown' => []],
            $this->createStub(NotifierInterface::class))->execute(true);
        $this->assertSame([['ck_notification_recipient_block', ['recipient_hash IN (?)' => ['h1', 'h2'],
            'key_id IS NOT NULL', 'key_id NOT IN (?)' => ['current-key-id', 'old-key-id']]]], $this->deletes,
            'a row rewritten with a listed key since the lookup stays');
    }

    public function testWithoutEarlierKeysTheDefaultRemovesNothing(): void
    {
        $removed = $this->cleanup(['ck_notification_recipient_block' => [['h1']], 'ck_notification_cooldown' => [['h2']]],
            $this->createStub(NotifierInterface::class), null, [])->execute();
        $this->assertSame(['blocks' => 0, 'cooldowns' => 0, 'notified' => false], $removed,
            'a server with a wrong env.php knows none of the shop\'s keys');
        $this->assertSame([], $this->deletes);
    }

    public function testABatchThatRemovesNothingEndsTheLoop(): void
    {
        $same = array_fill(0, 50, ['h1']);   // the lookup keeps returning a row the delete no longer matches
        $removed = $this->cleanup(['ck_notification_recipient_block' => $same, 'ck_notification_cooldown' => []],
            $this->createStub(NotifierInterface::class), 0)->execute(true);
        $this->assertSame(0, $removed['blocks']);
        $this->assertCount(1, $this->deletes);
    }

    public function testCountGroupsRowsPerKeyAndRemovesNothing(): void
    {
        $this->pairs = ['ck_notification_recipient_block' => ['current' => '1', 'earlier' => '3', 'unmarked' => '4'],
            'ck_notification_cooldown' => ['unknown' => '2']];
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->never())->method('addMajor');
        $this->assertSame([
            'blocks' => ['current' => 1, 'earlier' => 3, 'unmarked' => 4, 'unknown' => 0],
            'cooldowns' => ['unknown' => 2, 'current' => 0, 'earlier' => 0, 'unmarked' => 0],
        ], $this->cleanup([], $notifier)->count());
        $this->assertSame([], $this->deletes);
        $this->assertSame("CASE WHEN key_id IS NULL THEN 'unmarked' WHEN key_id = 'current-key-id' THEN 'current'"
            . " WHEN key_id IN ('old-key-id') THEN 'earlier' ELSE 'unknown' END", $this->countSql);
    }

    public function testWithoutAdminNotificationNoNoticeIsClaimed(): void
    {
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->never())->method('addMajor');
        $removed = $this->cleanup(['ck_notification_recipient_block' => [['h1']], 'ck_notification_cooldown' => []],
            $notifier, null, ['old-key-id'], false)->execute(true);
        $this->assertSame(['blocks' => 1, 'cooldowns' => 0, 'notified' => false], $removed);
        $this->assertCount(1, $this->warnings, 'the log still records it');
    }

    public function testACooldownOnlyResetIsLoggedWithoutAnAdminNotice(): void
    {
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->never())->method('addMajor');
        $this->cleanup(['ck_notification_recipient_block' => [], 'ck_notification_cooldown' => [$this->hashes(5)]], $notifier)
            ->execute();
        $this->assertCount(1, $this->warnings);
    }

    public function testNothingOldChangesNothing(): void
    {
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->never())->method('addMajor');
        $this->assertSame(['blocks' => 0, 'cooldowns' => 0, 'notified' => false], $this->cleanup([
            'ck_notification_recipient_block' => [], 'ck_notification_cooldown' => []], $notifier)->execute());
        $this->assertSame([], $this->warnings);
    }

    public function testAFailingNoticeIsLoggedAndReportedAsNotPosted(): void
    {
        $notifier = $this->createStub(NotifierInterface::class);
        $notifier->method('addMajor')->willThrowException(new \RuntimeException('inbox down'));
        $removed = $this->cleanup(['ck_notification_recipient_block' => [$this->hashes(2)], 'ck_notification_cooldown' => []],
            $notifier)->execute(true);
        $this->assertSame(['blocks' => 2, 'cooldowns' => 0, 'notified' => false], $removed);
        $this->assertCount(1, $this->warnings, 'the log line is the record');
        $this->assertStringContainsString('could not be posted', $this->errors[0]);
    }
}
