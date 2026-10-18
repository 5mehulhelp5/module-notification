<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\QuietHoursInterface;
use CommerceKings\Notification\Api\QuietHoursZoneInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The store's quiet hours (Stores > Configuration > CommerceKings > Notifications > Quiet hours), in the store's time
 * zone: a daily window from - to that may cross midnight, for the selected channels (none selected: every channel
 * except email). The window is kept in the store's time zone, or in the zones the channel's zone provider gives
 * (di.xml "zones", e.g. the recipient's): quiet when it is on in any of them, until the latest end - a recipient the
 * provider cannot place keeps the store's time zone. Wall-clock times:
 * on the day clocks go forward, a time in the skipped hour means the first time after it; on the day they go back, a
 * time in the repeated hour means its first occurrence.
 *
 * @api the "zones" di.xml argument is a stable extension point
 */
class QuietHours implements QuietHoursInterface
{
    private const PATH = 'commercekings_notification/quiet_hours/';

    /** @param array<string, QuietHoursZoneInterface> $zones by channel code */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TimezoneInterface $timezone,
        private readonly DateTime $dateTime,
        private readonly array $zones = []
    ) {
        foreach ($zones as $provider) {
            if (!$provider instanceof QuietHoursZoneInterface) {
                throw new \InvalidArgumentException('Quiet hours zones must implement QuietHoursZoneInterface.');
            }
        }
    }

    public function isQuiet(int $storeId, string $channel, ?string $recipient = null): bool
    {
        return $this->windowEnd($storeId, $channel, $recipient) !== null;
    }

    public function nextAllowedAt(int $storeId, string $channel, ?string $recipient = null): ?\DateTimeImmutable
    {
        return $this->windowEnd($storeId, $channel, $recipient)?->setTimezone(new \DateTimeZone('UTC'));
    }

    /** The latest end of the quiet windows on now in the recipient's zones; null when not quiet in any. */
    private function windowEnd(int $storeId, string $channel, ?string $recipient): ?\DateTimeImmutable
    {
        if (!$this->scopeConfig->isSetFlag(self::PATH . 'enabled', ScopeInterface::SCOPE_STORE, $storeId)
            || !$this->appliesTo($storeId, $channel)) {
            return null;
        }
        $from = $this->minutes($storeId, 'from');
        $to = $this->minutes($storeId, 'to');
        if ($from === null || $to === null || $from === $to) {
            return null;
        }
        $now = new \DateTimeImmutable('@' . $this->dateTime->gmtTimestamp());
        $latest = null;
        foreach ($this->zonesFor($storeId, $channel, $recipient) as $zone) {
            $end = $this->endIn($now->setTimezone($zone), $from, $to);
            if ($end !== null && ($latest === null || $end > $latest)) {
                $latest = $end;
            }
        }
        return $latest;
    }

    /**
     * @return \DateTimeZone[] the channel's zone provider's, else the store's time zone - also for a recipient the
     *     provider cannot place ([]): a number of unknown zone is never "not quiet". Only the whole store (a null
     *     recipient) may get none from the provider: each delivery is then held on its own.
     */
    private function zonesFor(int $storeId, string $channel, ?string $recipient): array
    {
        if (!isset($this->zones[$channel])) {
            return [$this->storeZone($storeId)];
        }
        $zones = $this->zones[$channel]->getZones($storeId, $channel, $recipient);
        if ($zones === [] && $recipient !== null) {
            return [$this->storeZone($storeId)];
        }
        foreach ($zones as $zone) {
            if (!$zone instanceof \DateTimeZone) {
                throw new \UnexpectedValueException(sprintf('Quiet hours zones for "%s" must be DateTimeZone objects.',
                    $channel));
            }
        }
        return $zones;
    }

    private function storeZone(int $storeId): \DateTimeZone
    {
        $zone = (string)$this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE, (string)$storeId);
        return new \DateTimeZone($zone !== '' ? $zone : 'UTC');
    }

    /** The end of the window on at $now (in its zone), in that zone; null when it is not on. */
    private function endIn(\DateTimeImmutable $now, int $from, int $to): ?\DateTimeImmutable
    {
        // Compared as instants, not wall-clock minutes, so a window is never interrupted when clocks go back.
        // Yesterday's window still runs after midnight when it crosses midnight.
        foreach ([$now->modify('-1 day'), $now] as $day) {
            $start = $this->at($day, $from);
            $end = $this->at($from < $to ? $day : $day->modify('+1 day'), $to);
            if ($start <= $now && $now < $end) {
                return $end;
            }
        }
        return null;
    }

    /**
     * That day's wall time: a time in the hour skipped when clocks go forward is the first time after it, a time in the
     * hour repeated when they go back is its first occurrence.
     */
    private function at(\DateTimeImmutable $day, int $minutes): \DateTimeImmutable
    {
        // from midnight: setTime() on the current time would keep its offset, giving the second occurrence
        $time = $day->setTime(0, 0)->setTime(intdiv($minutes, 60), $minutes % 60);
        if ((int)$time->format('G') * 60 + (int)$time->format('i') === $minutes) {
            return $time;
        }
        // skipped: PHP moved it forward by the gap; the first valid time is the transition itself
        $transitions = $time->getTimezone()->getTransitions($time->getTimestamp() - 10800, $time->getTimestamp());
        $last = end($transitions);
        return $last !== false && count($transitions) > 1 ? $time->setTimestamp($last['ts']) : $time;
    }

    private function appliesTo(int $storeId, string $channel): bool
    {
        $channels = trim((string)$this->scopeConfig->getValue(
            self::PATH . 'channels',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
        return $channels === '' ? $channel !== Channel::EMAIL
            : in_array($channel, array_map('trim', explode(',', $channels)), true);
    }

    /** Minutes after midnight of a "time" setting (stored as "21,00,00"); null when not set or invalid. */
    private function minutes(int $storeId, string $field): ?int
    {
        $value = (string)$this->scopeConfig->getValue(self::PATH . $field, ScopeInterface::SCOPE_STORE, $storeId);
        if (!preg_match('/^(\d{1,2})[,:](\d{1,2})/', $value, $match) || (int)$match[1] > 23 || (int)$match[2] > 59) {
            return null;
        }
        return (int)$match[1] * 60 + (int)$match[2];
    }
}
