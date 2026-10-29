<?php

use App\Domain\Insights\InsightFailed;
use App\Domain\Insights\Retention\RetentionPeriod;
use App\Domain\Insights\Retention\RetentionResult;
use App\Domain\Insights\Retention\RetentionType;
use App\Infrastructure\ClickHouse\ClickHouseErrorKind;
use Tests\Support\RetentionFixtures as R;

/** Default query: 7 daily cohorts 2026-03-02..08 (UTC), 3 follow-up periods. */
function retentionResult(array $rows, string $now = '2026-03-05 12:00:00 UTC', array $o = []): RetentionResult
{
    return RetentionResult::fromRows(R::query(o: $o), $rows, new DateTimeImmutable($now));
}

describe('the table', function () {
    it('has one row per cohort with the size first and a rate for every period that has started', function () {
        $result = retentionResult(R::rows([0 => [10, [5, 3, 2]], 1 => [8, [4, 2, 1]], 2 => [6, [3, 1, 0]], 3 => [4, [2, 0, 0]]]));

        expect($result->cohorts)->toHaveCount(7)
            ->and($result->cohorts[0])->toBe([
                'start' => strtotime('2026-03-02 UTC'), 'label' => '2026-03-02T00:00:00+00:00', 'size' => 10,
                'values' => [10, 5, 3, 2], 'rates' => [1.0, 0.5, 0.3, 0.2],
            ])
            ->and($result->period)->toBe('day')->and($result->periods)->toBe(3)->and($result->retentionType)->toBe('first_time')->and($result->timezone)->toBe('UTC');
    });

    it('makes a period that has not started null, not 0, in values and rates', function () {
        // now is 03-05 12:00: the periods of 03-02..03-05 have started, 03-06 and later have not
        $result = retentionResult(R::rows([0 => [10, [5, 3, 2]], 1 => [8, [4, 2, 1]], 2 => [6, [3, 1, 0]], 3 => [4, [2, 0, 0]]]));

        expect($result->cohorts[1]['values'])->toBe([8, 4, 2, null])
            ->and($result->cohorts[1]['rates'])->toBe([1.0, 0.5, 0.25, null])
            ->and($result->cohorts[2]['values'])->toBe([6, 3, null, null])
            ->and($result->cohorts[3]['values'])->toBe([4, null, null, null])
            ->and($result->cohorts[3]['rates'])->toBe([1.0, null, null, null])
            // the cohorts that have not started: everything is unknown
            ->and($result->cohorts[4]['values'])->toBe([null, null, null, null])->and($result->cohorts[6]['rates'])->toBe([null, null, null, null]);
    });

    it('counts the period that is running now with what has happened so far', function () {
        // 03-05 00:00 <= now: the cohort of 03-05 has its period 0 and nothing else
        $result = retentionResult(R::rows([3 => [4, [0, 0, 0]]]), '2026-03-05 00:00:00 UTC');

        expect($result->cohorts[3]['values'])->toBe([4, null, null, null]);
        // exactly at the start of the next period it has started
        $later = retentionResult(R::rows([3 => [4, [1, 0, 0]]]), '2026-03-06 00:00:00 UTC');
        expect($later->cohorts[3]['values'])->toBe([4, 1, null, null]);
    });

    it('gives a cohort without people null rates, zeros for the periods that have started, and nulls after', function () {
        $result = retentionResult(R::rows([0 => [10, [5, 3, 2]]]), '2026-03-20 00:00:00 UTC');

        expect($result->cohorts[1])->toMatchArray(['size' => 0, 'values' => [0, 0, 0, 0], 'rates' => [null, null, null, null]]);

        $early = retentionResult(R::rows([0 => [10, [5, 3, 2]]]), '2026-03-04 00:00:00 UTC');
        expect($early->cohorts[1])->toMatchArray(['size' => 0, 'values' => [0, 0, 0, null], 'rates' => [null, null, null, null]]);
    });

    it('is all empty, never an error, when no row came back', function () {
        $result = retentionResult([], '2026-03-20 00:00:00 UTC');

        expect($result->cohorts)->toHaveCount(7)
            ->and(array_unique(array_column($result->cohorts, 'size')))->toBe([0])
            ->and($result->averages)->toBe(['rates' => [null, null, null, null], 'cohorts' => [0, 0, 0, 0]]);
    });

    it('rounds rates to 4 decimals', function () {
        $result = retentionResult(R::rows([0 => [3, [1, 2, 3]]]), '2026-03-20 00:00:00 UTC');

        expect($result->cohorts[0]['rates'])->toBe([1.0, 0.3333, 0.6667, 1.0]);
    });

    it('labels every cohort with the instant in the zone of the query, an offset that follows daylight saving time', function () {
        $q = R::query(o: ['zone' => 'America/New_York', 'from' => '2026-03-07', 'to' => '2026-03-10', 'periods' => 1]);
        $result = RetentionResult::fromRows($q, [], new DateTimeImmutable('2026-04-01 UTC'));

        expect(array_column($result->cohorts, 'label'))->toBe(['2026-03-07T00:00:00-05:00', '2026-03-08T00:00:00-05:00', '2026-03-09T00:00:00-04:00'])
            ->and(array_column($result->cohorts, 'start'))->toBe(array_slice($q->starts, 0, 3));
    });

    it('uses the clock only through its argument, the real time by default', function () {
        $q = R::query(o: ['from' => '2020-01-01', 'to' => '2020-01-04']);

        $result = RetentionResult::fromRows($q, R::rows([0 => [2, [1, 1, 1]]]));

        expect($result->cohorts[0]['values'])->toBe([2, 1, 1, 1]);
    });
});

describe('the averages row', function () {
    it('pools the cohorts that have people and whose period has started', function () {
        $result = retentionResult(R::rows([0 => [10, [5, 3, 2]], 1 => [8, [4, 2, 1]], 2 => [6, [3, 1, 0]], 3 => [4, [2, 0, 0]]]));

        expect($result->averages)->toBe([
            'rates' => [1.0, 0.5, 0.2778, 0.2],   // 28/28, 12/24, 5/18, 2/10
            'cohorts' => [4, 3, 2, 1],
        ]);
    });

    it('lets a big cohort weigh more than a small one', function () {
        $result = retentionResult(R::rows([0 => [100, [50, 50, 50]], 1 => [2, [0, 0, 0]]]), '2026-03-20 00:00:00 UTC');

        expect($result->averages['rates'][1])->toBe(round(50 / 102, 4));
    });

    it('skips cohorts without people, and is null where no cohort qualifies', function () {
        $result = retentionResult(R::rows([6 => [5, [1, 1, 1]]]), '2026-03-20 00:00:00 UTC');

        expect($result->averages)->toBe(['rates' => [1.0, 0.2, 0.2, 0.2], 'cohorts' => [1, 1, 1, 1]]);
        $none = retentionResult(R::rows([0 => [5, [1, 1, 1]]]), '2026-03-02 00:00:00 UTC');
        // only the first period of the first cohort has started at 03-02 00:00
        expect($none->averages)->toBe(['rates' => [1.0, null, null, null], 'cohorts' => [1, 0, 0, 0]]);
    });
});

describe('rows that cannot be right', function () {
    it('fails with the unknown kind instead of showing a wrong table', function (array $rows) {
        try {
            retentionResult($rows);
            $this->fail('malformed rows must not become a result');
        } catch (InsightFailed $e) {
            expect($e->kind)->toBe(ClickHouseErrorKind::Unknown);
        }
    })->with([
        'a cohort after the last' => [[['cohort' => 7, 'size' => 1, 'retained' => [0, 0, 0]]]],
        'a negative cohort' => [[['cohort' => -1, 'size' => 1, 'retained' => [0, 0, 0]]]],
        'a cohort twice' => [R::rows([0 => [1, [0, 0, 0]]]) + [1 => ['cohort' => 0, 'size' => 1, 'retained' => [0, 0, 0]]]],
        'too few counts' => [[['cohort' => 0, 'size' => 5, 'retained' => [1, 1]]]],
        'too many counts' => [[['cohort' => 0, 'size' => 5, 'retained' => [1, 1, 1, 1]]]],
        'more retained than people' => [[['cohort' => 0, 'size' => 2, 'retained' => [1, 3, 1]]]],
        'a negative count' => [[['cohort' => 0, 'size' => 2, 'retained' => [1, -1, 1]]]],
        'a count as text' => [[['cohort' => 0, 'size' => 2, 'retained' => [1, '1', 1]]]],
        'retained a map' => [[['cohort' => 0, 'size' => 2, 'retained' => ['a' => 1, 'b' => 1, 'c' => 1]]]],
        'the cohort as text' => [[['cohort' => '0', 'size' => 2, 'retained' => [1, 1, 1]]]],
        'no size' => [[['cohort' => 0, 'retained' => [1, 1, 1]]]],
        'no retained' => [[['cohort' => 0, 'size' => 2]]],
    ]);
});

describe('storage form', function () {
    it('round trips through the array form and through JSON', function () {
        $result = retentionResult(R::rows([0 => [10, [5, 3, 2]], 1 => [8, [4, 2, 1]], 2 => [6, [3, 1, 0]], 3 => [4, [2, 0, 0]]]), o: ['period' => RetentionPeriod::Day, 'type' => RetentionType::Recurring]);

        expect(RetentionResult::fromArray($result->toArray()))->toEqual($result)
            ->and(RetentionResult::fromArray(json_decode(json_encode($result->toArray()), true)))->toEqual($result)
            ->and($result->toArray()['retention_type'])->toBe('recurring');
    });

    it('brings a rate that JSON turned into an int back as a float', function () {
        $result = retentionResult(R::rows([0 => [2, [2, 2, 2]]]), '2026-03-20 00:00:00 UTC');
        $array = json_decode('{"period":"day","periods":3,"retention_type":"first_time","timezone":"UTC","cohorts":[{"start":1772409600,"label":"x","size":2,"values":[2,2,2,2],"rates":[1,1,1,1]}],"averages":{"rates":[1,1,1,1],"cohorts":[1,1,1,1]}}', true);

        $again = RetentionResult::fromArray($array);

        expect($again->cohorts[0]['rates'])->toBe([1.0, 1.0, 1.0, 1.0])->and($again->averages['rates'])->toBe([1.0, 1.0, 1.0, 1.0])
            ->and($result->cohorts[0]['rates'])->toBe([1.0, 1.0, 1.0, 1.0]);
    });

    it('treats anything that is not the expected shape as a cache miss, never an error', function (callable $mutate) {
        $data = retentionResult(R::rows([0 => [2, [1, 1, 1]]]), '2026-03-20 00:00:00 UTC')->toArray();

        expect(RetentionResult::fromArray($mutate($data)))->toBeNull();
    })->with([
        'not an array' => [fn () => 'x'],
        'null' => [fn () => null],
        'period unknown' => [fn (array $d) => [...$d, 'period' => 'hour']],
        'periods text' => [fn (array $d) => [...$d, 'periods' => '3']],
        'periods zero' => [fn (array $d) => [...$d, 'periods' => 0]],
        'type unknown' => [fn (array $d) => [...$d, 'retention_type' => 'x']],
        'zone unknown' => [fn (array $d) => [...$d, 'timezone' => 'Mars/Base']],
        'no cohorts' => [fn (array $d) => [...$d, 'cohorts' => []]],
        'cohorts a map' => [fn (array $d) => [...$d, 'cohorts' => ['a' => $d['cohorts'][0]]]],
        'cohort a string' => [fn (array $d) => [...$d, 'cohorts' => ['x']]],
        'values too short' => [function (array $d) {
            $d['cohorts'][0]['values'] = [1, 2];

            return $d;
        }],
        'value as text' => [function (array $d) {
            $d['cohorts'][0]['values'][1] = '1';

            return $d;
        }],
        'rate as text' => [function (array $d) {
            $d['cohorts'][0]['rates'][1] = '0.5';

            return $d;
        }],
        'size as float' => [function (array $d) {
            $d['cohorts'][0]['size'] = 2.0;

            return $d;
        }],
        'no averages' => [function (array $d) {
            unset($d['averages']);

            return $d;
        }],
        'averages cohorts text' => [function (array $d) {
            $d['averages']['cohorts'][0] = 'a';

            return $d;
        }],
        'averages too short' => [function (array $d) {
            $d['averages']['rates'] = [1.0];

            return $d;
        }],
    ]);
});
