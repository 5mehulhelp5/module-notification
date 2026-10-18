<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Model\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function numbers(): array
    {
        return [
            'E.164' => ['+381601234567', '+381601234567'],
            'spaces, dashes, brackets, dots' => [' +1 (212) 555-01.00 ', '+12125550100'],
            '8 digits' => ['+12345678', '+12345678'],
            '15 digits' => ['+123456789012345', '+123456789012345'],
            'no plus: a national number' => ['0601234567', ''],
            '00 prefix' => ['00381601234567', ''],
            'country code 0' => ['+0601234567', ''],
            '7 digits' => ['+1234567', ''],
            '16 digits' => ['+1234567890123456', ''],
            'letters' => ['+38160ABC4567', ''],
            'provider prefix' => ['whatsapp:+381601234567', ''],
            'two plus signs' => ['++381601234567', ''],
            'non-ASCII digits' => ['+٣٨١٦٠١٢٣٤٥٦٧', ''],
            'newline at the end' => ["+381601234567\n+1", ''],
            'empty' => ['', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('numbers')]
    public function testStrictE164(string $input, string $expected): void
    {
        $this->assertSame($expected, (new PhoneNormalizer())->normalize($input));
    }

    public function testNormalizingTwiceChangesNothing(): void
    {
        $normalizer = new PhoneNormalizer();
        $once = $normalizer->normalize('+1 (212) 555-0100');
        $this->assertSame($once, $normalizer->normalize($once));
    }
}
