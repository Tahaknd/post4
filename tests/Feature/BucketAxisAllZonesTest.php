<?php

use App\Domain\Insights\Trends\DateRange;
use App\Domain\Insights\Trends\Interval;
use Tests\Support\AxisOracle;
use Tests\Support\ClickHouseIntegration as CH;

/*
 * Every PHP time zone identifier against ClickHouse's toStartOfInterval, hour/day/week/month, over a spring and
 * an autumn window of 2023 (thousands of queries: minutes). Opt-in and NOT part of any default run: group "slow",
 * excluded in phpunit.xml. Run with
 *
 *   docker compose exec -T app composer test:slow
 *
 * Known, documented differences are listed in KNOWN below; anything else fails with the zone, interval and the
 * bucket starts only one side has.
 */

pest()->group('slow');

it('agrees with toStartOfInterval for every PHP time zone', function () {
    // zone|interval => why. Lord_Howe: ClickHouse keeps the half hour UTC grid after the 30 minute DST shift.
    $known = ['Australia/Lord_Howe|hour' => true];
    $windows = [['2023-03-01', '2023-04-15'], ['2023-09-15', '2023-11-15']];
    $admin = CH::connect()->admin;

    $failures = [];
    foreach (DateTimeZone::listIdentifiers() as $zone) {
        foreach ($windows as [$from, $to]) {
            $range = new DateRange(new DateTimeImmutable($from.' UTC'), new DateTimeImmutable($to.' UTC'));
            foreach (Interval::cases() as $interval) {
                if (isset($known["{$zone}|{$interval->value}"])) {
                    continue;
                }
                try {
                    $diff = AxisOracle::diff($admin, $range, $interval, $zone);
                } catch (Throwable $e) {
                    $failures["{$zone} {$interval->value} {$from}"] = 'ClickHouse error: '.substr($e->getMessage(), 0, 120);

                    continue;
                }
                if ($diff['only_php'] !== [] || $diff['only_clickhouse'] !== []) {
                    $failures["{$zone} {$interval->value} {$from}"] = ['only_php' => array_slice($diff['only_php'], 0, 3), 'only_clickhouse' => array_slice($diff['only_clickhouse'], 0, 3)];
                }
            }
        }
    }

    expect($failures)->toBe([]);
});
