<?php

use App\Domain\Insights\Events\EventNames;
use App\Domain\Insights\Events\EventNamesQuery;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use Tests\Support\ScriptedReads;
use Tests\Support\TrendsFixtures as T;

$now = new DateTimeImmutable('2026-03-15 10:30:00 UTC');

describe('SQL', function () use ($now) {
    it('builds the name list without a search as constants and two placeholders', function () use ($now) {
        $built = (new EventNamesQuery)->build($now);

        expect($built->sql)->toBe(
            'SELECT event AS name, count() AS total FROM project_events '
            .'WHERE timestamp >= {from:DateTime64(3)} AND timestamp < {to:DateTime64(3)} AND lengthUTF8(event) <= 200 '
            .'GROUP BY name ORDER BY total DESC, name ASC LIMIT 101'
        )->and(T::plain($built->params))->toBe(['from' => '2026-02-14 00:00:00.000', 'to' => '2026-03-16 00:00:00.000']);
    });

    it('adds one typed, case-insensitive substring predicate for a search text', function () use ($now) {
        $built = (new EventNamesQuery)->build($now, 'sign');

        expect($built->sql)->toContain('AND positionCaseInsensitiveUTF8(event, {q:String}) > 0 GROUP BY name')
            ->and($built->params['q'])->toBe('sign');
    });

    it('treats null and the empty text as no search', function (?string $search) use ($now) {
        $built = (new EventNamesQuery)->build($now, $search);

        expect($built->sql)->not->toContain('positionCaseInsensitiveUTF8')->and($built->params)->not->toHaveKey('q');
    })->with([null, '']);

    it('keeps the limits as class constants that agree with the trends definition', function () {
        expect(EventNamesQuery::MAX_NAMES)->toBe(100)
            ->and(EventNamesQuery::MAX_SEARCH_LENGTH)->toBe(100)
            ->and(EventNamesQuery::WINDOW_DAYS)->toBe(30)
            ->and(TrendsQuery::MAX_EVENT_LENGTH)->toBe(200)
            ->and((new EventNamesQuery)->build(new DateTimeImmutable)->sql)->toContain('LIMIT '.(EventNamesQuery::MAX_NAMES + 1))
            ->toContain('lengthUTF8(event) <= '.TrendsQuery::MAX_EVENT_LENGTH);
    });

    it('always has the partition key predicate, an aggregate, a group by and a LIMIT, and no literal', function (?string $search) use ($now) {
        $sql = (new EventNamesQuery)->build($now, $search)->sql;

        expect($sql)->toContain('timestamp >= {from:DateTime64(3)} AND timestamp < {to:DateTime64(3)}')
            ->and($sql)->toContain('count()')->toContain('GROUP BY')->toEndWith('LIMIT 101')
            ->and($sql)->not->toContain("'")->not->toContain('"')->not->toContain('$')->not->toContain(';')->not->toContain('`');
    })->with([null, 'sign']);
});

describe('window', function () {
    it('is 30 whole UTC days ending with today, from inclusive to exclusive', function (string $now, string $from, string $to) {
        [$start, $end] = EventNamesQuery::window(new DateTimeImmutable($now));

        expect($start->format('Y-m-d H:i:s e'))->toBe($from.' 00:00:00 UTC')->and($end->format('Y-m-d H:i:s e'))->toBe($to.' 00:00:00 UTC');
    })->with([
        'midday' => ['2026-03-15 10:30:00 UTC', '2026-02-14', '2026-03-16'],
        'just before midnight' => ['2026-03-15 23:59:59 UTC', '2026-02-14', '2026-03-16'],
        'midnight' => ['2026-03-16 00:00:00 UTC', '2026-02-15', '2026-03-17'],
        'a zone ahead of UTC is read in UTC' => ['2026-03-16 01:30:00 +03:00', '2026-02-14', '2026-03-16'],
        'year boundary' => ['2026-01-05 12:00:00 UTC', '2025-12-07', '2026-01-06'],
    ]);

    it('spans exactly 30 days', function () {
        [$start, $end] = EventNamesQuery::window(new DateTimeImmutable('2026-03-15 10:30:00 UTC'));

        expect(($end->getTimestamp() - $start->getTimestamp()) / 86400)->toBe(30);
    });
});

describe('property test: hostile search text changes only the parameters', function () use ($now) {
    $hostile = [
        "x' OR '1'='1",
        "'; DROP TABLE posthog.events; --",
        '/* comment */ UNION ALL SELECT 1',
        'SETTINGS SQL_project_id = 999',
        '{evil:String} {project_id:UInt32}',
        '$$dollar$$ $1 ${x}',
        "back\\slash \\' \\\\",
        '100% _wild_ %',
        "tab\there\nnewline\r\n",
        'ünïcödé 日本語 😀 '."\u{202E}rtl",
        'FORMAT TSV INTO OUTFILE mergeTreeIndex(a, b)',
        "null\0byte",
        'x`; --`',
        str_repeat('A', 100),
    ];

    it('builds the SAME sql text whatever the search text is', function () use ($hostile, $now) {
        $builder = new EventNamesQuery;
        $reference = $builder->build($now, 'plain');
        $paramSets = [];

        foreach ($hostile as $h) {
            $built = $builder->build($now, $h);

            expect($built->sql)->toBe($reference->sql)
                ->and(array_keys($built->params))->toBe(array_keys($reference->params))
                ->and($built->params['q'])->toBe($h);
            $paramSets[] = json_encode(T::plain($built->params));
        }

        expect(array_unique($paramSets))->toHaveCount(count($hostile));
    });

    it('changes the SQL text only with the presence of a search, not with the clock', function () use ($now) {
        $builder = new EventNamesQuery;

        expect($builder->build($now)->sql)->not->toBe($builder->build($now, 'x')->sql)
            ->and($builder->build($now)->sql)->toBe($builder->build(new DateTimeImmutable('2031-12-31 UTC'))->sql);
    });

    it('lets every hostile text through the real reader lint, as a parameter', function () use ($hostile, $now) {
        $fake = new ScriptedReads;
        $reader = new ProjectScopedReader($fake);
        foreach ($hostile as $h) {
            $built = (new EventNamesQuery)->build($now, $h);
            $reader->select(1, $built->sql, $built->params);
        }

        expect($fake->calls)->toHaveCount(count($hostile));
    });
});

describe('normalise', function () {
    it('trims, lower-cases and treats nothing as no search', function (?string $input, ?string $expected) {
        expect(EventNames::normalise($input))->toBe($expected);
    })->with([
        [null, null],
        ['', null],
        ["  \t\n ", null],
        ["\u{00A0}", null],
        ['  Sign Up ', 'sign up'],
        ['ÇOK', 'çok'],
        ['100%_', '100%_'],
    ]);

    it('keys the cache by project and the normalised text, so one entry serves every spelling', function () {
        expect(EventNames::cacheKey(7, 'abc'))->toBe('insights:events:7:'.hash('sha256', 'abc'))
            ->and(EventNames::cacheKey(7, null))->toBe('insights:events:7:'.hash('sha256', ''))
            ->and(EventNames::cacheKey(7, 'abc'))->not->toBe(EventNames::cacheKey(8, 'abc'))
            ->and(EventNames::cacheKey(7, 'abc'))->not->toBe(EventNames::cacheKey(7, 'abd'));
    });
});
