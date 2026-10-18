<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Model\KeyChangeCleanup;
use CommerceKings\Notification\Model\KeyFingerprint;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
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
    private array $counts = [];

    /**
     * @param array<string, string[][]> $batches per table: the old-key hashes each lookup returns
     * @param ?int $deleted rows each delete reports; null = the whole batch
     */
    private function cleanup(array $batches, NotifierInterface $notifier, ?int $deleted = null): KeyChangeCleanup
    {
        $select = $this->createStub(Select::class);
        $table = '';
        $select->method('from')->willReturnCallback(function ($name) use (&$table, $select) { $table = $name; return $select; });
        $select->method('where')->willReturnCallback(function ($condition, $value = null) use ($select) {
            $this->wheres[] = [$condition, $value];
            return $select;
        });
        $select->method('limit')->willReturnCallback(function ($limit) use ($select) { $this->limits[] = $limit; return $select; });
        $select->method('reset')->willReturnSelf();
        $select->method('columns')->willReturnSelf();
        $db = $this->createStub(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        $db->method('fetchCol')->willReturnCallback(function () use (&$batches, &$table) {
            return array_shift($batches[$table]) ?? [];
        });
        $db->method('fetchOne')->willReturnCallback(function () use (&$table) { return (string)($this->counts[$table] ?? 0); });
        $db->method('delete')->willReturnCallback(function ($name, $where) use ($deleted) {
            $this->deletes[] = [$name, $where];
            return $deleted ?? count($where['recipient_hash IN (?)']);
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        $fingerprint = $this->createStub(KeyFingerprint::class);
        $fingerprint->method('current')->willReturn('current-key-id');
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) { $this->warnings[] = $message; });
        $logger->method('error')->willReturnCallback(function ($message) { $this->errors[] = $message; });
        return new KeyChangeCleanup($resource, $fingerprint, $notifier, $logger);
    }

    private function hashes(int $count): array
    {
        return array_map(fn ($i) => 'h' . $i, range(1, $count));
    }

    public function testLiftedBlocksAreLoggedAndTheAdminIsNotifiedInBatches(): void
    {
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->once())->method('addMajor')
            ->with($this->stringContains('encryption key was changed'), $this->stringContains('37 do-not-contact block(s)'));
        $removed = $this->cleanup(['ck_notification_recipient_block' => [$this->hashes(37)],
            'ck_notification_cooldown' => [$this->hashes(1000), $this->hashes(200)]], $notifier)->execute();
        $this->assertSame(['blocks' => 37, 'cooldowns' => 1200], $removed);
        $this->assertSame([1000, 1000], $this->limits, 'each table is read in batches of 1000');
        $this->assertSame(['CommerceKings_Notification: encryption key changed; removed 37 do-not-contact block(s)'
            . ' and 1200 cooldown row(s)'], $this->warnings);
    }

    public function testRowsWithoutAFingerprintAreNeverTreatedAsOld(): void
    {
        $this->cleanup(['ck_notification_recipient_block' => [], 'ck_notification_cooldown' => []],
            $this->createStub(NotifierInterface::class))->execute();
        $this->assertSame(array_fill(0, 2, ['key_id IS NOT NULL', null]),
            array_values(array_filter($this->wheres, fn ($w) => $w[0] === 'key_id IS NOT NULL')), 'both tables');
        $this->assertSame(array_fill(0, 2, ['key_id <> ?', 'current-key-id']),
            array_values(array_filter($this->wheres, fn ($w) => $w[0] === 'key_id <> ?')));
    }

    public function testTheDeleteChecksTheFingerprintAgain(): void
    {
        $this->cleanup(['ck_notification_recipient_block' => [['h1', 'h2']], 'ck_notification_cooldown' => []],
            $this->createStub(NotifierInterface::class))->execute();
        $this->assertSame([['ck_notification_recipient_block', ['recipient_hash IN (?)' => ['h1', 'h2'],
            'key_id IS NOT NULL', 'key_id <> ?' => 'current-key-id']]], $this->deletes,
            'a row rewritten with the current key since the lookup stays');
    }

    public function testABatchThatRemovesNothingEndsTheLoop(): void
    {
        $same = array_fill(0, 50, ['h1']);   // the lookup keeps returning a row the delete no longer matches
        $removed = $this->cleanup(['ck_notification_recipient_block' => $same, 'ck_notification_cooldown' => []],
            $this->createStub(NotifierInterface::class), 0)->execute();
        $this->assertSame(0, $removed['blocks']);
        $this->assertCount(1, $this->deletes);
    }

    public function testCountRemovesNothing(): void
    {
        $this->counts = ['ck_notification_recipient_block' => 3, 'ck_notification_cooldown' => 8];
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->never())->method('addMajor');
        $this->assertSame(['blocks' => 3, 'cooldowns' => 8], $this->cleanup([], $notifier)->count());
        $this->assertSame([], $this->deletes);
        $this->assertContains(['key_id IS NOT NULL', null], $this->wheres, 'counts the same rows the cleanup removes');
        $this->assertContains(['key_id <> ?', 'current-key-id'], $this->wheres);
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
        $this->assertSame(['blocks' => 0, 'cooldowns' => 0], $this->cleanup(['ck_notification_recipient_block' => [],
            'ck_notification_cooldown' => []], $notifier)->execute());
        $this->assertSame([], $this->warnings);
    }

    public function testAFailingNoticeIsLoggedAndDoesNotFailTheCleanup(): void
    {
        $notifier = $this->createStub(NotifierInterface::class);
        $notifier->method('addMajor')->willThrowException(new \RuntimeException('inbox down'));
        $removed = $this->cleanup(['ck_notification_recipient_block' => [$this->hashes(2)], 'ck_notification_cooldown' => []],
            $notifier)->execute();
        $this->assertSame(2, $removed['blocks']);
        $this->assertCount(1, $this->warnings, 'the log line is the record');
        $this->assertStringContainsString('could not be posted', $this->errors[0]);
    }
}
