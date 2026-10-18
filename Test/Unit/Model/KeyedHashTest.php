<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Model\KeyedHash;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Encryption\KeyValidator;
use Magento\Framework\Math\Random;
use PHPUnit\Framework\TestCase;

/** Magento's keyed hash under every key in env.php: the newest first, the older ones only for matching. */
class KeyedHashTest extends TestCase
{
    private function hashes(string $keys, ?EncryptorInterface $encryptor = null): KeyedHash
    {
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(fn ($path) => $path === 'crypt/key' ? $keys : null);
        $validator = $this->createStub(KeyValidator::class);
        $validator->method('isValid')->willReturn(true);
        return new KeyedHash($encryptor ?? new Encryptor($this->createStub(Random::class), $config, $validator), $config);
    }

    public function testNewestKeyFirstThenOlderKeysAsMagentoHashedWithThem(): void
    {
        $encoded = 'base64' . base64_encode('second-key-0123456789abcdef01234');
        $all = $this->hashes("first-key\n$encoded\nthird-key")->all('data');
        $this->assertSame([
            hash_hmac('sha256', 'data', 'third-key'),
            hash_hmac('sha256', 'data', 'second-key-0123456789abcdef01234'),
            hash_hmac('sha256', 'data', 'first-key'),
        ], $all);
        $this->assertSame($all[0], $this->hashes("first-key\n$encoded\nthird-key")->current('data'), 'new data: newest key');
        $this->assertSame([hash_hmac('sha256', 'data', 'only-key')], $this->hashes('only-key')->all('data'));
    }

    public function testOlderKeysAreIgnoredWhenTheEncryptorHashesDifferently(): void
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('hash')->willReturn(str_repeat('f', 64));
        $this->assertSame([str_repeat('f', 64)], $this->hashes("old-key\nnew-key", $encryptor)->all('data'));
    }
}
