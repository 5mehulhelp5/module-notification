<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Model\KeyFingerprint;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Math\Random;
use PHPUnit\Framework\TestCase;

/** The fingerprint follows Magento's newest key only - the key that also makes every stored hash. */
class KeyFingerprintTest extends TestCase
{
    private function fingerprint(string $keys): string
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(fn ($path) => $path === 'crypt/key' ? $keys : null);
        $validator = $this->createStub(\Magento\Framework\Encryption\KeyValidator::class);
        $validator->method('isValid')->willReturn(true);
        return (new KeyFingerprint(new Encryptor($this->createStub(Random::class), $config, $validator)))->current();
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
}
