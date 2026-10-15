<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /** @return array<string, array{mixed, int}> */
    public static function values(): array
    {
        return ['default' => ['7', 7], 'off' => ['0', 0], 'never negative' => ['-3', 0], 'empty' => [null, 0]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('values')]
    public function testCooldownDays(mixed $stored, int $expected): void
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn ($path) => $path === 'commercekings_notification/general/cooldown_days' ? $stored : null);
        $this->assertSame($expected, (new Config($scope))->getCooldownDays());
    }

    public function testOneClickFlagIsReadForTheMessagesStore(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->expects($this->exactly(2))->method('isSetFlag')
            ->with('commercekings_notification/general/one_click_unsubscribe', 'store', 3)->willReturnOnConsecutiveCalls(false, true);
        $config = new Config($scope);
        $this->assertFalse($config->isOneClickUnsubscribeEnabled(3));
        $this->assertTrue($config->isOneClickUnsubscribeEnabled(3));
    }
}
