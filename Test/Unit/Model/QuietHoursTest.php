<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Model\QuietHours;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;

/** Quiet hours in the store's time zone (here Berlin: clocks go forward on 2026-03-29, back on 2026-10-25). */
class QuietHoursTest extends TestCase
{
    /** settings per store id: enabled, from, to, channels */
    private array $settings = [];
    private array $zones = [];
    /** zone providers by channel (di.xml "zones") */
    private array $zoneProviders = [];

    private function quietHours(string $nowUtc): QuietHours
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(fn ($path, $type, $store) => $path === 'commercekings_notification/quiet_hours/enabled'
            && (bool)($this->settings[$store]['enabled'] ?? false));
        $scope->method('getValue')->willReturnCallback(function ($path, $type, $store) {
            $field = substr($path, strlen('commercekings_notification/quiet_hours/'));
            return $this->settings[$store][$field] ?? null;
        });
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturnCallback(fn ($type, $store) => $this->zones[$store] ?? 'Europe/Berlin');
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn((new \DateTimeImmutable($nowUtc, new \DateTimeZone('UTC')))->getTimestamp());
        return new QuietHours($scope, $timezone, $clock, $this->zoneProviders);
    }

    private function window(string $from, string $to, ?string $channels = null, int $store = 1): void
    {
        $this->settings[$store] = ['enabled' => true, 'from' => $from, 'to' => $to, 'channels' => $channels];
    }

    /** nextAllowedAt for the sms channel in store 1, as a UTC string; null when not quiet */
    private function next(string $nowUtc, string $channel = 'sms'): ?string
    {
        $quietHours = $this->quietHours($nowUtc);
        $next = $quietHours->nextAllowedAt(1, $channel);
        $this->assertSame($next !== null, $quietHours->isQuiet(1, $channel), 'isQuiet agrees with nextAllowedAt');
        $this->assertSame('UTC', $next?->getTimezone()->getName() ?? 'UTC');
        return $next?->format('Y-m-d H:i');
    }

    public function testAWindowCrossingMidnight(): void
    {
        $this->window('21,00,00', '08,00,00');
        $this->assertNull($this->next('2026-06-10 18:59'), '20:59 in Berlin');
        $this->assertSame('2026-06-11 06:00', $this->next('2026-06-10 19:00'), '21:00 in Berlin: until 08:00 tomorrow');
        $this->assertSame('2026-06-11 06:00', $this->next('2026-06-10 22:30'), 'after midnight in Berlin');
        $this->assertSame('2026-06-11 06:00', $this->next('2026-06-11 05:59'));
        $this->assertNull($this->next('2026-06-11 06:00'), '08:00 in Berlin: over');
    }

    public function testAWindowWithinADay(): void
    {
        $this->window('12,00,00', '14,30,00');
        $this->assertNull($this->next('2026-06-10 09:59'));
        $this->assertSame('2026-06-10 12:30', $this->next('2026-06-10 11:00'));
        $this->assertNull($this->next('2026-06-10 12:30'));
    }

    public function testOffEmptyOrInvalidSettingsHoldNothing(): void
    {
        $now = '2026-06-10 22:00';
        $this->assertNull($this->next($now), 'not configured');
        $this->window('21,00,00', '08,00,00');
        $this->settings[1]['enabled'] = false;
        $this->assertNull($this->next($now), 'disabled');
        $this->window('21,00,00', '21,00,00');
        $this->assertNull($this->next($now), 'from equals to: no window');
        $this->window('25,00,00', '08,00,00');
        $this->assertNull($this->next($now), 'invalid time');
        $this->window('', '08,00,00');
        $this->assertNull($this->next($now), 'empty time');
    }

    public function testTheStoresOwnTimeZoneAndSettings(): void
    {
        $this->window('21,00,00', '08,00,00');
        $this->window('21:00', '08:00', store: 2);
        $this->zones[2] = 'America/New_York';
        // 22:00 UTC: midnight in Berlin, 18:00 in New York
        $quietHours = $this->quietHours('2026-06-10 22:00');
        $this->assertTrue($quietHours->isQuiet(1, 'sms'));
        $this->assertFalse($quietHours->isQuiet(2, 'sms'));
        $this->assertFalse($quietHours->isQuiet(3, 'sms'), 'a store without the setting');
        $this->assertSame('2026-06-11 12:00', $this->quietHours('2026-06-11 02:00')->nextAllowedAt(2, 'sms')?->format('Y-m-d H:i'),
            '22:00 in New York: until 08:00 there, in UTC');
    }

    /** The store's window is the same for everyone: asked with a recipient or for the whole store, one answer. */
    public function testTheStoreWindowIgnoresTheRecipient(): void
    {
        $this->window('21,00,00', '08,00,00');
        $quietHours = $this->quietHours('2026-06-10 22:00');
        $this->assertTrue($quietHours->isQuiet(1, 'sms', '+381601234567'));
        $this->assertTrue($quietHours->isQuiet(1, 'sms', null));
        $this->assertEquals($quietHours->nextAllowedAt(1, 'sms'), $quietHours->nextAllowedAt(1, 'sms', '+12125550100'));
        $this->assertFalse($this->quietHours('2026-06-10 12:00')->isQuiet(1, 'sms', '+381601234567'));
    }

    /** @param array<string, string[]> $zones zone names by recipient ('' for null) */
    private function zoneProvider(array $zones, array &$asked = []): \CommerceKings\Notification\Api\QuietHoursZoneInterface
    {
        $provider = $this->createStub(\CommerceKings\Notification\Api\QuietHoursZoneInterface::class);
        $provider->method('getZones')->willReturnCallback(function ($store, $channel, $recipient) use ($zones, &$asked) {
            $asked[] = [$store, $channel, $recipient];
            return array_map(fn ($name) => new \DateTimeZone($name), $zones[$recipient ?? ''] ?? []);
        });
        return $provider;
    }

    /** A channel's zone provider puts the store's window into the recipient's time zone. */
    public function testTheWindowInTheRecipientsZone(): void
    {
        $this->window('21,00,00', '08,00,00');
        $asked = [];
        $this->zoneProviders['sms'] = $this->zoneProvider(['+12125550100' => ['America/New_York'],
            '+381601234567' => ['Europe/Belgrade']], $asked);
        // 02:00 UTC: 22:00 in New York, 04:00 in Belgrade; 14:00 UTC: 10:00 and 16:00
        $quietHours = $this->quietHours('2026-06-11 02:00');
        $this->assertSame('2026-06-11 12:00', $quietHours->nextAllowedAt(1, 'sms', '+12125550100')?->format('Y-m-d H:i'));
        $this->assertSame('2026-06-11 06:00', $quietHours->nextAllowedAt(1, 'sms', '+381601234567')?->format('Y-m-d H:i'));
        $this->assertFalse($this->quietHours('2026-06-11 14:00')->isQuiet(1, 'sms', '+12125550100'));
        $this->assertSame([1, 'sms', '+12125550100'], $asked[0], 'asked with the store, channel and recipient');
        $this->assertFalse($quietHours->isQuiet(1, 'sms', '+999'), 'a number it cannot place: not quiet');
        $this->assertFalse($quietHours->isQuiet(1, 'sms'), 'the whole store: [] - each delivery is held on its own');
        $this->assertNull($quietHours->nextAllowedAt(1, 'sms'));
        $this->assertTrue($quietHours->isQuiet(1, 'whatsapp', '+12125550100'), 'another channel keeps the store zone');
    }

    /** A number whose area spans zones: quiet while any of them is, until the latest end. */
    public function testSeveralZonesHoldUntilTheLatestEnd(): void
    {
        $this->window('21,00,00', '08,00,00');
        $this->zoneProviders['sms'] = $this->zoneProvider(['+1' => ['America/Chicago', 'America/Denver']]);
        // 03:30 UTC: 22:30 in Chicago, 21:30 in Denver; 13:30 UTC: 08:30 and 07:30; 14:00 UTC: 09:00 and 08:00
        $this->assertSame('2026-06-11 14:00', $this->quietHours('2026-06-11 03:30')->nextAllowedAt(1, 'sms', '+1')
            ?->format('Y-m-d H:i'), 'until 08:00 in Denver');
        $this->assertSame('2026-06-11 14:00', $this->quietHours('2026-06-11 13:30')->nextAllowedAt(1, 'sms', '+1')
            ?->format('Y-m-d H:i'), 'over in Chicago, still on in Denver');
        $this->assertFalse($this->quietHours('2026-06-11 14:00')->isQuiet(1, 'sms', '+1'));
        // 02:30 UTC: 21:30 in Chicago, 20:30 in Denver - quiet in one is enough
        $this->assertSame('2026-06-11 13:00', $this->quietHours('2026-06-11 02:30')->nextAllowedAt(1, 'sms', '+1')
            ?->format('Y-m-d H:i'));
    }

    public function testZoneProvidersAreChecked(): void
    {
        $this->window('21,00,00', '08,00,00');
        $this->zoneProviders['sms'] = $this->zoneProvider([]);
        $this->assertFalse($this->quietHours('2026-06-10 22:00')->isQuiet(1, 'sms', '+1'), 'disabled: zones not needed');

        $bad = $this->createStub(\CommerceKings\Notification\Api\QuietHoursZoneInterface::class);
        $bad->method('getZones')->willReturn(['America/New_York']);
        $this->zoneProviders['sms'] = $bad;
        try {
            $this->quietHours('2026-06-10 22:00')->isQuiet(1, 'sms', '+1');
            $this->fail('a zone name instead of a DateTimeZone');
        } catch (\UnexpectedValueException) {
        }
        $this->zoneProviders = ['sms' => new \stdClass()];
        $this->expectException(\InvalidArgumentException::class);
        $this->quietHours('2026-06-10 22:00');
    }

    public function testChannels(): void
    {
        $this->window('21,00,00', '08,00,00');
        $now = '2026-06-10 22:00';
        $this->assertNotNull($this->next($now, 'sms'), 'none selected: every channel');
        $this->assertNotNull($this->next($now, 'whatsapp'));
        $this->assertNull($this->next($now, Channel::EMAIL), '... except email');
        $this->window('21,00,00', '08,00,00', 'email, sms');
        $this->assertNotNull($this->next($now, Channel::EMAIL), 'email when selected');
        $this->assertNotNull($this->next($now, 'sms'));
        $this->assertNull($this->next($now, 'whatsapp'), 'not selected');
    }

    /** Clocks go forward at 02:00 (to 03:00): a time in the skipped hour means the first time after it. */
    public function testSpringForwardFromInTheSkippedHour(): void
    {
        $this->window('02,30,00', '08,00,00');
        $this->assertNull($this->next('2026-03-29 00:59'), '01:59 winter time');
        $this->assertSame('2026-03-29 06:00', $this->next('2026-03-29 01:00'), '03:00 summer time: the window started');
    }

    public function testSpringForwardToInTheSkippedHour(): void
    {
        $this->window('21,00,00', '02,30,00');
        $this->assertSame('2026-03-29 01:00', $this->next('2026-03-28 21:00'), 'ends at 03:00 summer time, when the gap does');
        $this->assertSame('2026-03-29 01:00', $this->next('2026-03-29 00:50'));
        $this->assertNull($this->next('2026-03-29 01:00'));
    }

    /** Clocks go back at 03:00 (to 02:00): the night is an hour longer; a time in the repeated hour is its first one. */
    public function testFallBack(): void
    {
        $this->window('21,00,00', '08,00,00');
        $this->assertSame('2026-10-25 07:00', $this->next('2026-10-24 20:00'), '22:00 summer time until 08:00 winter time');
        $this->assertSame('2026-10-25 07:00', $this->next('2026-10-25 01:15'), '02:15 winter time');

        $this->window('21,00,00', '02,30,00');
        $this->assertSame('2026-10-25 00:30', $this->next('2026-10-25 00:15'), '02:15 summer time: until 02:30 summer time');
        $this->assertNull($this->next('2026-10-25 00:40'), '02:40 summer time');
        $this->assertNull($this->next('2026-10-25 01:15'), '02:15 again, winter time: the window has ended');
    }

    public function testFallBackFromInTheRepeatedHourIsNotInterrupted(): void
    {
        $this->window('02,30,00', '08,00,00');
        $this->assertNull($this->next('2026-10-25 00:29'), '02:29 summer time');
        $this->assertSame('2026-10-25 07:00', $this->next('2026-10-25 00:30'), '02:30 summer time');
        $this->assertSame('2026-10-25 07:00', $this->next('2026-10-25 01:10'), '02:10 winter time: still quiet');
        $this->assertSame('2026-10-25 07:00', $this->next('2026-10-25 01:30'), '02:30 winter time');
    }
}
