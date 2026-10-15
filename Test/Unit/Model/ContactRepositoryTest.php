<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\ContactReferenceInterface;
use CommerceKings\Notification\Cron\CleanUpContacts;
use CommerceKings\Notification\Model\ContactReferencePool;
use CommerceKings\Notification\Model\ContactRepository;
use CommerceKings\Notification\Test\Unit\DbTestTrait;
use PHPUnit\Framework\TestCase;

class ContactRepositoryTest extends TestCase
{
    use DbTestTrait;

    public function testSaveIsOneAtomicUpsertReturningTheContact(): void
    {
        $this->fetchOneResult = '5';
        $this->assertSame(5, (new ContactRepository($this->resource()))->save(1, '  Guest@Example.Test '));
        [$sql, $bind] = $this->queries[0];
        $this->assertStringContainsString('INSERT INTO ck_notification_contact (website_id, email) VALUES (?, ?)', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE contact_id = contact_id', $sql);
        $this->assertSame([1, 'Guest@Example.Test'], $bind, 'trimmed; case is kept, the unique key ignores it');
        $this->assertContains(['website_id = ?', 1], $this->selectWhere);
        $this->assertContains(['email = ?', 'Guest@Example.Test'], $this->selectWhere);
    }

    public function testSaveNeedsAWebsiteAndAnAddress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ContactRepository($this->resource()))->save(1, '   ');
    }

    public function testGetEmail(): void
    {
        $this->fetchOneResult = 'guest@example.test';
        $this->assertSame('guest@example.test', (new ContactRepository($this->resource()))->getEmail(5));
        $this->assertContains(['contact_id = ?', 5], $this->selectWhere);
        $this->fetchOneResult = false;
        $this->assertNull((new ContactRepository($this->resource()))->getEmail(6));
    }

    public function testCleanupRemovesOnlyContactsNoModuleUsesAfterAGracePeriod(): void
    {
        $reference = $this->createStub(ContactReferenceInterface::class);
        $reference->method('usedCondition')->willReturnCallback(fn ($column) => "USED_BY_CARTS($column)");
        $this->queryRowCounts = [2];
        $this->assertSame(2, (new CleanUpContacts($this->resource(), new ContactReferencePool(['carts' => $reference])))->execute());
        $this->assertSame('DELETE n FROM ck_notification_contact AS n WHERE n.created_at < NOW() - INTERVAL 24 HOUR'
            . ' AND NOT ((USED_BY_CARTS(n.contact_id)))', $this->queries[0][0]);
    }

    public function testWithoutRegisteredReferencesNothingIsRemoved(): void
    {
        $this->assertSame(0, (new CleanUpContacts($this->resource(), new ContactReferencePool([])))->execute());
        $this->assertSame([], $this->queries);
    }
}
