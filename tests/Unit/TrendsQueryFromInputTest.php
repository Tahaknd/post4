<?php

use App\Domain\Insights\Trends\InvalidDateSelection;
use App\Domain\Insights\Trends\InvalidTrendsQuery;
use App\Domain\Insights\Trends\TrendsQuery;
use Illuminate\Support\Carbon;

function fromInput(array $input, string $zone = 'UTC', string $now = '2026-03-15 10:00:00'): TrendsQuery
{
    return TrendsQuery::fromInput($input, new DateTimeZone($zone), Carbon::parse($now, 'UTC'));
}

it('assembles a definition with the defaults and the project zone', function () {
    $query = fromInput(['events' => ['a'], 'date_from' => '2026-03-01', 'date_to' => '2026-03-02'], 'Europe/Istanbul');

    expect($query->canonical())->toBe([
        'v' => TrendsQuery::VERSION, 'events' => ['a'], 'metric' => 'total_events',
        'range' => ['from' => '2026-02-28T21:00:00.000Z', 'to' => '2026-03-02T21:00:00.000Z'],
        'interval' => 'day', 'timezone' => 'Europe/Istanbul', 'breakdown' => null, 'filters' => [],
    ]);
});

it('gives equal input of one local day the same hash and a new day a new one', function () {
    $input = ['events' => ['a'], 'date_preset' => 'last_7_days'];

    expect(fromInput($input, 'Europe/Istanbul', '2026-03-09 21:00:00')->hash())->toBe(fromInput($input, 'Europe/Istanbul', '2026-03-10 20:59:59')->hash())
        ->and(fromInput($input, 'Europe/Istanbul', '2026-03-10 21:00:00')->hash())->not->toBe(fromInput($input, 'Europe/Istanbul', '2026-03-10 20:59:59')->hash());
});

it('lets an explicit timezone replace the default and defaults the breakdown size', function () {
    $query = fromInput(['events' => ['a'], 'timezone' => 'Asia/Tokyo', 'date_preset' => 'this_month', 'breakdown' => ['property' => 'plan'], 'filters' => [['key' => 'k', 'operator' => 'is_set']]], 'UTC');

    expect($query->timezone())->toBe('Asia/Tokyo')->and($query->breakdown->topN)->toBe(10)->and($query->filters)->toHaveCount(1);
});

it('names the date problems with their own exception type', function (array $input) {
    expect(fn () => fromInput(['events' => ['a'], ...$input]))->toThrow(InvalidDateSelection::class);
})->with([
    'no range' => [[]],
    'unknown preset' => [['date_preset' => 'yesterday']],
    'preset not text' => [['date_preset' => ['x']]],
    'impossible day' => [['date_from' => '2026-02-30', 'date_to' => '2026-03-01']],
    'end before start' => [['date_from' => '2026-03-05', 'date_to' => '2026-03-01']],
    'too long' => [['date_from' => '2024-01-01', 'date_to' => '2026-03-01']],
    'only one end' => [['date_from' => '2026-03-01']],
]);

it('rejects everything else through the constructor rules as InvalidTrendsQuery, never a TypeError', function (array $input) {
    expect(fn () => fromInput(['events' => ['a'], 'date_preset' => 'last_7_days', ...$input]))->toThrow(InvalidTrendsQuery::class);
})->with([
    'no events' => [['events' => []]],
    'zone' => [['timezone' => 'Mars/Base']],
    'zone type' => [['timezone' => 5]],
    'metric' => [['metric' => 'bogus']],
    'metric type' => [['metric' => ['x']]],
    'interval' => [['interval' => 'decade']],
    'breakdown type' => [['breakdown' => 'x']],
    'breakdown both' => [['breakdown' => ['column' => 'browser', 'property' => 'p']]],
    'breakdown top_n' => [['breakdown' => ['column' => 'browser', 'top_n' => 99]]],
    'filters type' => [['filters' => 'x']],
    'filters map' => [['filters' => ['a' => ['key' => 'k', 'operator' => 'is_set']]]],
    'filter scalar' => [['filters' => ['x']]],
    'filter operator' => [['filters' => [['key' => 'k', 'operator' => 'nope']]]],
    'rows' => [['events' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'], 'interval' => 'hour', 'date_preset' => 'last_90_days']],
]);
