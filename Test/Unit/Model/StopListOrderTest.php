<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Model\AddressConsentRepository;
use CommerceKings\Notification\Model\Config;
use CommerceKings\Notification\Model\ContactRepository;
use CommerceKings\Notification\Model\KeyedHash;
use CommerceKings\Notification\Model\RecipientNormalizerPool;
use CommerceKings\Notification\Model\StopList;
use CommerceKings\Notification\Model\SubscriptionTypePool;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;

/**
 * Replies can arrive out of order (a provider retries a webhook): a stop keeps its latest time, and a resume with the
 * time it was asked for lifts only an older stop.
 */
class StopListOrderTest extends TestCase
{
    private const HASH = 'hhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhh';

    /** @var array<int, array{string, array}> */
    private array $queries = [];
    /** @var array<int, array> where of every delete from the stop table */
    private array $stopDeletes = [];

    private function stopList(): StopList
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $db = $this->createStub(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        $db->method('fetchCol')->willReturn([]);
        $db->method('fetchPairs')->willReturn([]);
        $db->method('quoteIdentifier')->willReturnCallback(fn ($name) => '`' . $name . '`');
        $db->method('quoteInto')->willReturnCallback(fn ($text, $value) =>
            str_replace('?', is_array($value) ? implode(',', $value) : "'" . $value . "'", $text));
        $db->method('query')->willReturnCallback(function ($sql, $bind = []) {
            $this->queries[] = [$sql, $bind];
            return $this->createStub(\Zend_Db_Statement_Interface::class);
        });
        $db->method('delete')->willReturnCallback(function ($table, $where) {
            if ($table === StopList::TABLE) {
                $this->stopDeletes[] = $where;
            }
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        $hashes = $this->createStub(KeyedHash::class);
        $hashes->method('all')->willReturn([self::HASH]);
        return new StopList($resource, $this->createStub(EncryptorInterface::class),
            $this->createStub(UrlInterface::class), $this->createStub(Config::class), new RecipientNormalizerPool(),
            $this->createStub(SubscriptionPermissionInterface::class), $hashes, new SubscriptionTypePool([]),
            new ContactRepository($resource, new RecipientNormalizerPool()),
            $this->createStub(AddressConsentRepository::class));
    }

    public function testAStopKeepsItsLatestTimeSoTheSameStopTwiceChangesNothing(): void
    {
        $stops = $this->stopList();
        $at = new \DateTimeImmutable('2026-10-19 12:00:05', new \DateTimeZone('Europe/Belgrade'));
        $stops->stop(Channel::SMS, '+381601234567', StopList::REASON_REPLY, $at);
        $stops->stop(Channel::SMS, '+381601234567', StopList::REASON_REPLY, $at);
        [$sql, $bind] = $this->queries[0];
        $this->assertStringContainsString('VALUES (?, ?, LEAST(COALESCE(?, NOW()), NOW()), NOW())', $sql,
            'the requested time, never in the future');
        $this->assertStringContainsString('stopped_at = GREATEST(stopped_at, VALUES(stopped_at))', $sql,
            'a replayed or older stop never moves the time back');
        $this->assertSame([self::HASH, 'reply', '2026-10-19 10:00:05'], $bind, 'stored in UTC');
        $this->assertSame($this->queries[0], $this->queries[1], 'a replay is the same idempotent upsert');
    }

    public function testWithoutATimeTheStopIsNow(): void
    {
        $this->stopList()->stop(Channel::EMAIL, 'jose@example.test');
        $this->assertSame([self::HASH, 'link', null], $this->queries[0][1]);
    }

    public function testAResumeWithATimeLiftsOnlyAnOlderStop(): void
    {
        $this->stopList()->resume(Channel::SMS, '+381601234567',
            new \DateTimeImmutable('2026-10-19T10:00:04+00:00'));
        $this->assertSame(['recipient_hash IN (?)' => [self::HASH],
            "stopped_at < LEAST(COALESCE('2026-10-19 10:00:04', NOW()), NOW())"], $this->stopDeletes[0],
            'a START older than the stop (or in the same second) leaves it');
    }

    public function testAResumeWithoutATimeLiftsAnyStop(): void
    {
        $this->stopList()->resume(Channel::SMS, '+381601234567');
        $this->assertSame([['recipient_hash IN (?)' => [self::HASH]]], $this->stopDeletes);
    }
}
