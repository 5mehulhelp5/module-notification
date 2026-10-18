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

    private function repository(): ContactRepository
    {
        // a test phone normalizer: digits only, a leading 0 becomes the +381 country code
        $phone = new class implements \CommerceKings\Notification\Api\RecipientNormalizerInterface {
            public function normalize(string $recipient): string
            {
                $digits = preg_replace('/\D/', '', $recipient);
                return $digits === '' ? '' : '+' . (str_starts_with($digits, '0') ? '381' . substr($digits, 1) : $digits);
            }
        };
        return new ContactRepository($this->resource(),
            new \CommerceKings\Notification\Model\RecipientNormalizerPool(['sms' => $phone, 'whatsapp' => clone $phone]));
    }

    public function testSaveIsOneAtomicUpsertReturningTheContact(): void
    {
        $this->fetchOneResult = '5';
        $this->assertSame(5, $this->repository()->save(1, '  Guest@Example.Test '));
        [$sql, $bind] = $this->queries[0];
        $this->assertStringContainsString('INSERT INTO ck_notification_contact (website_id, channel, recipient, recipient_key) VALUES (?, ?, ?, ?)', $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE contact_id = contact_id', $sql);
        $key = hash('sha256', 'guest@example.test');
        $this->assertSame([1, 'email', 'Guest@Example.Test', $key], $bind, 'trimmed and kept as entered; identity is the key');
        $this->assertContains(['website_id = ?', 1], $this->selectWhere);
        $this->assertContains(['channel = ?', 'email'], $this->selectWhere);
        $this->assertContains(['recipient_key = ?', $key], $this->selectWhere, 'looked up by key, never by the address');
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
        $this->repository()->save(1, '   ');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidWebsiteIds')]
    public function testInvalidWebsiteWritesNoContact(int $websiteId): void
    {
        try {
            $this->repository()->save($websiteId, 'guest@example.test');
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

    public function testGetEmailAndRecipient(): void
    {
        $this->fetchRowResult = ['channel' => 'email', 'recipient' => 'guest@example.test'];
        $this->assertSame('guest@example.test', $this->repository()->getEmail(5));
        $this->assertContains(['contact_id = ?', 5], $this->selectWhere);
        $this->fetchRowResult = ['channel' => 'sms', 'recipient' => '+381601234567'];
        $this->assertNull($this->repository()->getEmail(6), 'a phone contact has no email');
        $this->assertSame(['channel' => 'sms', 'recipient' => '+381601234567'], $this->repository()->getRecipient(6));
        $this->fetchRowResult = false;
        $this->assertNull($this->repository()->getRecipient(7));
    }

    /** Another channel: the same table, its own identity by the channel's normalizer. */
    public function testAPhoneContactIsKeyedByItsChannelsNormalizer(): void
    {
        $this->fetchOneResult = '9';
        $this->assertSame(9, $this->repository()->save(1, ' 060 123 4567 ', 'sms'));
        [, $bind] = $this->queries[0];
        $this->assertSame([1, 'sms', '060 123 4567', hash('sha256', '+381601234567')], $bind);
        $this->assertContains(['channel = ?', 'sms'], $this->selectWhere,
            'the channel column keeps one number on SMS and on WhatsApp apart');
        $this->assertSame($this->repository()->keyFor('sms', '+381 60 123 4567'), $this->repository()->keyFor('sms', '0601234567'),
            'one number, however typed');
    }

    public function testFindNeverCreates(): void
    {
        $this->fetchOneResult = '4';
        $this->assertSame(4, $this->repository()->find(1, 'guest@example.test'));
        $this->assertSame([], $this->queries, 'no INSERT');
        $this->fetchOneResult = false;
        $this->assertNull($this->repository()->find(1, 'nobody@example.test'));
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
