<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Model\KeyFingerprint;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Math\Random;
use PHPUnit\Framework\TestCase;

/** The fingerprint follows Magento's newest key only - the key that also makes every stored hash. */
class KeyFingerprintTest extends TestCase
{
    private function make(string $keys): KeyFingerprint
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(fn ($path) => $path === 'crypt/key' ? $keys : null);
        $validator = $this->createStub(\Magento\Framework\Encryption\KeyValidator::class);
        $validator->method('isValid')->willReturn(true);
        return new KeyFingerprint(new Encryptor($this->createStub(Random::class), $config, $validator), $config);
    }

    private function fingerprint(string $keys): string
    {
        return $this->make($keys)->current();
    }

    public function testIsShortStableAndDoesNotChangeWhenOldKeysAreKept(): void
    {
        $new = str_repeat('b', 32);
        $value = $this->fingerprint($new);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', $value);
        $this->assertSame($value, $this->fingerprint($new), 'deterministic');
        $this->assertSame($value, $this->fingerprint(str_repeat('a', 32) . "\n" . $new), 'old keys kept after a change');
        $this->assertNotSame($value, $this->fingerprint(str_repeat('a', 32)), 'another key, another fingerprint');
        $this->assertStringNotContainsString($new, $value);
    }

    public function testEarlierListsTheOlderKeysAsTheyWereCurrent(): void
    {
        $old = str_repeat('a', 32);
        $encoded = 'base64' . base64_encode(random_bytes(32));     // the 2.4.7+ key format
        $new = str_repeat('c', 32);
        $earlier = $this->make("$old\n$encoded\n$new")->earlier();
        $this->assertSame([$this->fingerprint($encoded), $this->fingerprint($old)], $earlier,
            'each older key gives the fingerprint its rows were stored with, newest first');
        $this->assertSame([], $this->make($new)->earlier(), 'one key: nothing earlier');
    }

    public function testEarlierIsEmptyWhenTheComputationDoesNotMatchTheEncryptor(): void
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturn(str_repeat('a', 32) . "\n" . str_repeat('b', 32));
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('hash')->willReturn(str_repeat('f', 64));   // e.g. a changed Encryptor
        $this->assertSame([], (new KeyFingerprint($encryptor, $config))->earlier(),
            'unconfirmed: nothing counts as an earlier key');
    }
}
