<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Model\Config;
use CommerceKings\Notification\Model\Cooldown;
use CommerceKings\Notification\Test\Unit\DbTestTrait;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Math\Random;
use PHPUnit\Framework\TestCase;

class CooldownTest extends TestCase
{
    use DbTestTrait;

    private function cooldown(int $days = 7): Cooldown
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('hash')->willReturnCallback(function ($input) {
            $this->assertSame('commercekings_notification_recipient|guest@example.test', $input);
            return str_repeat('a', 64);
        });
        $random = $this->createStub(Random::class);
        $random->method('getRandomString')->willReturn(str_repeat('b', 32));
        $config = $this->createStub(Config::class);
        $config->method('getCooldownDays')->willReturn($days);
        return new Cooldown($this->resource(), $encryptor, $random, $config);
    }

    public function testNormalizationUsesTheNativeKeyedHash(): void
    {
        $this->assertSame(str_repeat('a', 64), $this->cooldown()->hash('  Guest@Example.Test  '));
    }

    /** One contact is one cooldown: the cooldown hashes exactly what the contact key normalizes. */
    public function testTheCooldownNormalizesLikeContacts(): void
    {
        $inputs = [];
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('hash')->willReturnCallback(function ($input) use (&$inputs) {
            $inputs[] = $input;
            return md5($input);
        });
        $cooldown = new Cooldown($this->resource(), $encryptor, $this->createStub(Random::class), $this->createStub(Config::class));
        $composed = "Jos\u{00E9}@Example.Test";      // é as one code point
        $decomposed = " jose\u{0301}@example.test ";  // e + combining acute
        $this->assertSame($cooldown->hash($composed), $cooldown->hash($decomposed));
        $this->assertSame(\CommerceKings\Notification\Model\ContactRepository::key($composed),
            \CommerceKings\Notification\Model\ContactRepository::key($decomposed), 'the same contact');
        $this->assertSame('commercekings_notification_recipient|' . \CommerceKings\Notification\Model\ContactRepository::normalize($composed),
            $inputs[0]);
        $this->assertNotSame($cooldown->hash('jose@example.test'), $cooldown->hash($composed), 'accents still count');
    }

    public function testClaimChecksOwnershipRatherThanAffectedRowCount(): void
    {
        $this->queryRowCounts = [1, 1]; // CLIENT_FOUND_ROWS may report an unchanged row as affected.
        $cooldown = $this->cooldown();
        $this->fetchOneResult = str_repeat('c', 32);
        $this->assertNull($cooldown->claim('guest@example.test'));
        $this->fetchOneResult = str_repeat('b', 32);
        $this->assertSame(str_repeat('b', 32), $cooldown->claim('guest@example.test'));
        $this->assertSame([str_repeat('a', 64), str_repeat('b', 32)], $this->queries[0][1], 'hash and claim token');
        $this->assertStringContainsString('INTERVAL 7 DAY', $this->queries[0][0]);
    }

    public function testReleaseRequiresTheClaimToken(): void
    {
        $this->cooldown()->release('guest@example.test', 'owner-token');
        $this->assertSame(['recipient_hash = ?' => str_repeat('a', 64), 'claim_token = ?' => 'owner-token'],
            $this->deletes[0]['where']);
    }

    public function testRecentChecksTheConfiguredWindow(): void
    {
        $this->fetchOneResult = str_repeat('a', 64);
        $this->assertTrue($this->cooldown()->isRecent('guest@example.test'));
        $this->assertContains(['sent_at >= NOW() - INTERVAL ? DAY', 7], $this->selectWhere);
    }

    public function testTurnedOffNothingIsRecent(): void
    {
        $this->fetchOneResult = str_repeat('a', 64);
        $this->assertFalse($this->cooldown(0)->isRecent('guest@example.test'));
        $this->assertSame([], $this->selectWhere, 'no query when the cooldown is off');
    }

    public function testCleanupUsesTheConfiguredRetention(): void
    {
        $this->assertSame(3, $this->cooldown()->cleanUp());
        $this->assertSame(['sent_at < NOW() - INTERVAL ? DAY' => 7], $this->deletes[0]['where']);
    }
}
