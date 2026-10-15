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
        $this->assertStringContainsString('INSERT INTO ck_notification_contact (website_id, email, email_key) VALUES (?, ?, ?)', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE contact_id = contact_id', $sql);
        $key = hash('sha256', 'guest@example.test');
        $this->assertSame([1, 'Guest@Example.Test', $key], $bind, 'trimmed and kept as entered; identity is the key');
        $this->assertContains(['website_id = ?', 1], $this->selectWhere);
        $this->assertContains(['email_key = ?', $key], $this->selectWhere, 'looked up by key, never by the email column');
    }

    public function testKeyIgnoresCaseAndUnicodeFormButNotAccents(): void
    {
        $this->assertSame(ContactRepository::key('Person@Example.com'), ContactRepository::key(' person@example.com '));
        $this->assertNotSame(ContactRepository::key('person@example.com'), ContactRepository::key('person@exámple.com'));
        // the same accented address, precomposed (NFC) and decomposed (NFD), is one identity
        $this->assertSame(ContactRepository::key("person@ex\u{00E1}mple.com"), ContactRepository::key("person@exa\u{0301}mple.com"));
        $this->assertSame(ContactRepository::key('PERSON@EXÁMPLE.COM'), ContactRepository::key('person@exámple.com'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', ContactRepository::key('person@example.com'));
    }

    public function testSaveNeedsAWebsiteAndAnAddress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ContactRepository($this->resource()))->save(1, '   ');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidWebsiteIds')]
    public function testInvalidWebsiteWritesNoContact(int $websiteId): void
    {
        try {
            (new ContactRepository($this->resource()))->save($websiteId, 'guest@example.test');
            $this->fail('An invalid website must not create a contact.');
        } catch (\InvalidArgumentException) {
            $this->assertSame([], $this->queries);
            $this->assertSame([], $this->inserts);
            $this->assertSame([], $this->updates);
        }
    }

    public static function invalidWebsiteIds(): array
    {
        return [[0], [-1]];
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
