<?php

use App\Domain\Insights\Query\DatePreset;
use Illuminate\Support\Carbon;

/** Resolves a preset at a UTC instant in a zone and returns the window as `from|to` in UTC. */
function presetWindow(DatePreset $preset, string $nowUtc, string $zone = 'UTC'): string
{
    $range = $preset->resolve(Carbon::parse($nowUtc, 'UTC'), new DateTimeZone($zone));

    return $range->from->format('Y-m-d H:i').'|'.$range->to->format('Y-m-d H:i');
}

it('resolves the rolling presets to whole days ending with today', function (DatePreset $preset, string $expected) {
    expect(presetWindow($preset, '2026-03-15 10:00:00'))->toBe($expected);
})->with([
    [DatePreset::Last7Days, '2026-03-09 00:00|2026-03-16 00:00'],
    [DatePreset::Last30Days, '2026-02-14 00:00|2026-03-16 00:00'],
    [DatePreset::Last90Days, '2025-12-16 00:00|2026-03-16 00:00'],
    [DatePreset::ThisMonth, '2026-03-01 00:00|2026-03-16 00:00'],
    [DatePreset::LastMonth, '2026-02-01 00:00|2026-03-01 00:00'],
]);

it('handles month ends and the first of a month', function (DatePreset $preset, string $now, string $expected) {
    expect(presetWindow($preset, $now))->toBe($expected);
})->with([
    'last month on the 31st' => [DatePreset::LastMonth, '2026-03-31 12:00:00', '2026-02-01 00:00|2026-03-01 00:00'],
    'last month in January' => [DatePreset::LastMonth, '2026-01-31 12:00:00', '2025-12-01 00:00|2026-01-01 00:00'],
    'last month after a leap February' => [DatePreset::LastMonth, '2024-03-10 00:00:00', '2024-02-01 00:00|2024-03-01 00:00'],
    'this month on the 1st' => [DatePreset::ThisMonth, '2026-03-01 00:00:00', '2026-03-01 00:00|2026-03-02 00:00'],
    'seven days across a month' => [DatePreset::Last7Days, '2026-03-03 00:00:00', '2026-02-25 00:00|2026-03-04 00:00'],
    'seven days across a year' => [DatePreset::Last7Days, '2026-01-02 23:59:59', '2025-12-27 00:00|2026-01-03 00:00'],
]);

it('takes today in the zone, not in UTC', function () {
    // 2026-03-10 22:00 UTC is already 2026-03-11 01:00 in Istanbul (+3): the window ends at the next Istanbul midnight
    expect(presetWindow(DatePreset::Last7Days, '2026-03-10 22:00:00', 'Europe/Istanbul'))->toBe('2026-03-04 21:00|2026-03-11 21:00')
        ->and(presetWindow(DatePreset::Last7Days, '2026-03-10 22:00:00', 'UTC'))->toBe('2026-03-04 00:00|2026-03-11 00:00')
        // west of UTC the local day is still the previous one
        ->and(presetWindow(DatePreset::Last7Days, '2026-03-10 02:00:00', 'America/Los_Angeles'))->toBe('2026-03-03 08:00|2026-03-10 07:00');
});

it('keeps one window for the whole local day and moves it at local midnight', function () {
    $window = fn (string $now) => presetWindow(DatePreset::Last30Days, $now, 'Europe/Istanbul');

    expect($window('2026-03-09 21:00:00'))->toBe($window('2026-03-10 05:00:00'))->toBe($window('2026-03-10 20:59:59'))
        ->and($window('2026-03-10 21:00:00'))->not->toBe($window('2026-03-10 20:59:59'));
});

it('returns local midnights across a DST change (offsets differ at the two ends)', function () {
    // New York springs forward on 2026-03-08: local midnights before it are 05:00 UTC, after it 04:00 UTC
    expect(presetWindow(DatePreset::Last7Days, '2026-03-12 15:00:00', 'America/New_York'))->toBe('2026-03-06 05:00|2026-03-13 04:00');
});

it('works in a zone where local midnight does not exist on some days', function () {
    // Santiago skips 00:00 on 2023-09-03 (the day starts at 01:00); the window still starts at that day's first instant
    $range = DatePreset::Last7Days->resolve(Carbon::parse('2023-09-05 12:00:00', 'UTC'), new DateTimeZone('America/Santiago'));

    expect($range->to->getTimestamp() - $range->from->getTimestamp())->toBeGreaterThan(6 * 86400)->toBeLessThan(8 * 86400);
});
