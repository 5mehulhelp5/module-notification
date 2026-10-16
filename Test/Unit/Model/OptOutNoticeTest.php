<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Model\Config\Source\NoticeMode;
use CommerceKings\Notification\Model\OpenPermission;
use CommerceKings\Notification\Model\OptOutNotice;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class OptOutNoticeTest extends TestCase
{
    private const GROUP = 'commercekings_feature/notice';
    private const TEXT = 'We may email you once.';

    /** @return array<string, array{?string, bool, ?string}> */
    public static function modes(): array
    {
        return [
            'automatic, opt-out' => [NoticeMode::AUTOMATIC, true, self::TEXT],
            'automatic, with consent' => [NoticeMode::AUTOMATIC, false, null],
            'never saved = automatic, opt-out' => [null, true, self::TEXT],
            'never saved = automatic, with consent' => [null, false, null],
            'yes, opt-out' => [NoticeMode::YES, true, self::TEXT],
            'yes, with consent' => [NoticeMode::YES, false, self::TEXT],
            'no, opt-out' => [NoticeMode::NO, true, null],
            'no, with consent' => [NoticeMode::NO, false, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function testShowFollowsTheSettingAndAutomaticFollowsTheMode(?string $show, bool $optOut, ?string $expected): void
    {
        $this->assertSame($expected, $this->notice(['show' => $show, 'text' => self::TEXT], $optOut)->getText(self::GROUP, 3));
    }

    public function testAnEmptyTextShowsNothing(): void
    {
        $this->assertNull($this->notice(['show' => NoticeMode::YES, 'text' => "  \n "], true)->getText(self::GROUP, 3));
        $this->assertNull($this->notice(['show' => NoticeMode::YES, 'text' => null], true)->getText(self::GROUP, 3));
    }

    public function testBothValuesAreReadForTheStore(): void
    {
        $read = [];
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(function ($path, $scope, $store) use (&$read) {
            $read[] = [$path, $scope, $store];
            return str_ends_with($path, '/show') ? NoticeMode::YES : ' ' . self::TEXT . ' ';
        });
        $this->assertSame(self::TEXT, (new OptOutNotice($scope, new OpenPermission()))->getText(self::GROUP, 5), 'trimmed');
        $this->assertSame([[self::GROUP . '/show', 'store', 5], [self::GROUP . '/text', 'store', 5]], $read);
    }

    public function testNotificationAloneIsOptOut(): void
    {
        $this->assertTrue((new OpenPermission())->isOptOut());
    }

    /** @param array{show: ?string, text: ?string} $values */
    private function notice(array $values, bool $optOut): OptOutNotice
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn ($path) => match ($path) {
            self::GROUP . '/show' => $values['show'],
            self::GROUP . '/text' => $values['text'],
            default => null,
        });
        $permission = $this->createStub(SubscriptionPermissionInterface::class);
        $permission->method('isOptOut')->willReturn($optOut);
        return new OptOutNotice($scope, $permission);
    }
}
