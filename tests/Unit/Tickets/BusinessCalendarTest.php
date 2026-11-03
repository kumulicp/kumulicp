<?php

use App\Services\Tickets\BusinessCalendar;
use Carbon\CarbonImmutable;

// 2026-11-02 is a Monday

function cal_at(string $time, string $timezone = 'UTC'): CarbonImmutable
{
    return CarbonImmutable::parse($time, $timezone);
}

function cal_shown(CarbonImmutable $time, string $timezone = 'UTC'): string
{
    return $time->setTimezone($timezone)->format('D Y-m-d H:i');
}

function cal_office(array $overrides = []): BusinessCalendar
{
    return new BusinessCalendar(array_merge(['timezone' => 'UTC', 'days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '17:00', 'holidays' => []], $overrides));
}

it('counts around the clock when there are no business hours', function () {
    $calendar = new BusinessCalendar(null);

    expect($calendar->isContinuous())->toBeTrue()
        ->and(cal_shown($calendar->addMinutes(cal_at('2026-11-02 10:00'), 90)))->toBe('Mon 2026-11-02 11:30')
        ->and($calendar->minutesBetween(cal_at('2026-11-02 10:00'), cal_at('2026-11-03 10:00')))->toBe(1440);
});

it('adds working minutes', function (string $from, int $minutes, string $expected) {
    expect(cal_shown(cal_office()->addMinutes(cal_at($from), $minutes)))->toBe($expected);
})->with([
    'inside the day' => ['2026-11-02 10:00', 60, 'Mon 2026-11-02 11:00'],
    'exactly to closing time' => ['2026-11-02 16:00', 60, 'Mon 2026-11-02 17:00'],
    'rolls to the next morning' => ['2026-11-02 16:30', 60, 'Tue 2026-11-03 09:30'],
    'started after hours' => ['2026-11-02 20:00', 30, 'Tue 2026-11-03 09:30'],
    'started before opening' => ['2026-11-02 06:00', 30, 'Mon 2026-11-02 09:30'],
    'skips the weekend' => ['2026-11-06 16:00', 120, 'Mon 2026-11-09 10:00'],
    'started on a Saturday' => ['2026-11-07 12:00', 60, 'Mon 2026-11-09 10:00'],
    'a full working day' => ['2026-11-02 09:00', 480, 'Mon 2026-11-02 17:00'],
    'two working days' => ['2026-11-02 09:00', 960, 'Tue 2026-11-03 17:00'],
    'zero minutes is now' => ['2026-11-07 12:00', 0, 'Sat 2026-11-07 12:00'],
    'a long target' => ['2026-11-06 15:00', 1440, 'Wed 2026-11-11 15:00'],
]);

it('skips holidays', function () {
    $calendar = cal_office(['holidays' => ['2026-11-03']]);

    expect(cal_shown($calendar->addMinutes(cal_at('2026-11-02 16:30'), 60)))->toBe('Wed 2026-11-04 09:30')
        ->and($calendar->minutesBetween(cal_at('2026-11-02 16:00'), cal_at('2026-11-04 10:00')))->toBe(120);
});

it('measures only working time between two moments', function (string $from, string $to, int $expected) {
    expect(cal_office()->minutesBetween(cal_at($from), cal_at($to)))->toBe($expected);
})->with([
    'same day' => ['2026-11-02 10:00', '2026-11-02 12:30', 150],
    'overnight' => ['2026-11-02 16:00', '2026-11-03 10:00', 120],
    'over a weekend' => ['2026-11-06 16:00', '2026-11-09 10:00', 120],
    'all outside hours' => ['2026-11-07 08:00', '2026-11-08 20:00', 0],
    'backwards is zero' => ['2026-11-02 12:00', '2026-11-02 10:00', 0],
]);

it('agrees with itself: adding then measuring gives the same minutes', function () {
    $calendar = cal_office();
    $from = cal_at('2026-11-05 15:07');

    expect($calendar->minutesBetween($from, $calendar->addMinutes($from, 1234)))->toBe(1234);
});

it('works in the calendar\'s own time zone, across a clock change', function () {
    $madrid = new BusinessCalendar(['timezone' => 'Europe/Madrid', 'days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '17:00']);

    // CET is UTC+1: 07:30 UTC is 08:30 in Madrid, before opening
    expect(cal_shown($madrid->addMinutes(cal_at('2026-11-02 07:30'), 60)))->toBe('Mon 2026-11-02 09:00')
        // the time zone it was given comes back
        ->and($madrid->addMinutes(cal_at('2026-11-02 12:00', 'America/Denver'), 30)->getTimezone()->getName())->toBe('America/Denver')
        // the clocks went back on 2026-10-25; the local window stays 09:00-17:00
        ->and(cal_shown($madrid->addMinutes(cal_at('2026-10-23 15:00', 'Europe/Madrid'), 240), 'Europe/Madrid'))->toBe('Mon 2026-10-26 11:00')
        ->and($madrid->minutesBetween(cal_at('2026-10-25 00:00', 'Europe/Madrid'), cal_at('2026-10-27 00:00', 'Europe/Madrid')))->toBe(480);
});

it('refuses hours it cannot make sense of', function (array $hours) {
    new BusinessCalendar($hours);
})->with([
    'no days' => [['days' => [], 'start' => '09:00', 'end' => '17:00']],
    'ends before it starts' => [['days' => [1], 'start' => '17:00', 'end' => '09:00']],
    'empty window' => [['days' => [1], 'start' => '09:00', 'end' => '09:00']],
])->throws(RuntimeException::class);
