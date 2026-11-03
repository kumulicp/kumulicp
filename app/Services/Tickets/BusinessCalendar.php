<?php

namespace App\Services\Tickets;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Counts and adds working minutes. Without business hours every minute counts.
 *
 * Hours are one daily window on chosen weekdays, in a time zone, minus holidays:
 *   {timezone: "Europe/Madrid", days: [1,2,3,4,5], start: "09:00", end: "17:30", holidays: ["2026-12-25"]}
 *
 * Pure: it knows nothing about tickets, so it is easy to reason about and test.
 */
final class BusinessCalendar
{
    /** Far more days than any realistic target needs; guards against a calendar with no working days. */
    private const MAX_DAYS = 3660;

    private string $timezone;

    /** @var list<int> */
    private array $days;

    private string $start;

    private string $end;

    /** @var array<string, true> */
    private array $holidays;

    private bool $continuous;

    /**
     * @param  array{timezone?: string, days?: list<int>, start?: string, end?: string, holidays?: list<string>}|null  $hours
     */
    public function __construct(?array $hours)
    {
        $this->continuous = empty($hours);
        $this->timezone = $hours['timezone'] ?? 'UTC';
        $this->days = array_values($hours['days'] ?? [1, 2, 3, 4, 5]);
        $this->start = $hours['start'] ?? '09:00';
        $this->end = $hours['end'] ?? '17:00';
        $this->holidays = array_fill_keys($hours['holidays'] ?? [], true);

        if (! $this->continuous && ($this->days === [] || $this->start >= $this->end)) {
            throw new RuntimeException('Business hours need at least one working day and a start before the end.');
        }
    }

    public function isContinuous(): bool
    {
        return $this->continuous;
    }

    /**
     * The moment $minutes working minutes after $from.
     */
    public function addMinutes(CarbonInterface $from, int $minutes): CarbonImmutable
    {
        $from = CarbonImmutable::instance($from);

        if ($this->continuous || $minutes <= 0) {
            return $from->addMinutes(max($minutes, 0));
        }

        $cursor = $from->setTimezone($this->timezone);
        $remaining = $minutes;

        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            $window = $this->window($cursor);

            if ($window !== null) {
                [$open, $close] = $window;
                $cursor = $cursor->lt($open) ? $open : $cursor;

                if ($cursor->lt($close)) {
                    $available = (int) $cursor->diffInMinutes($close, true);

                    if ($remaining <= $available) {
                        return $cursor->addMinutes($remaining)->setTimezone($from->getTimezone());
                    }

                    $remaining -= $available;
                }
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        throw new RuntimeException('Could not find enough working time.');
    }

    /**
     * Working minutes between two moments (0 if $to is not after $from).
     */
    public function minutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $from = CarbonImmutable::instance($from);
        $to = CarbonImmutable::instance($to);

        if ($to->lte($from)) {
            return 0;
        }

        if ($this->continuous) {
            return (int) $from->diffInMinutes($to, true);
        }

        $cursor = $from->setTimezone($this->timezone);
        $end = $to->setTimezone($this->timezone);
        $total = 0;

        for ($i = 0; $i < self::MAX_DAYS && $cursor->lt($end); $i++) {
            $window = $this->window($cursor);

            if ($window !== null) {
                $open = $cursor->gt($window[0]) ? $cursor : $window[0];
                $close = $end->lt($window[1]) ? $end : $window[1];

                if ($close->gt($open)) {
                    $total += (int) $open->diffInMinutes($close, true);
                }
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        return $total;
    }

    /**
     * The working window on the day of $moment, or null on a day off.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function window(CarbonImmutable $moment): ?array
    {
        if (! in_array($moment->dayOfWeekIso, $this->days, true) || isset($this->holidays[$moment->toDateString()])) {
            return null;
        }

        return [
            $moment->setTimeFromTimeString($this->start),
            $moment->setTimeFromTimeString($this->end),
        ];
    }
}
