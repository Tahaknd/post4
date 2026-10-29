<?php

use App\Domain\Insights\InsightQuery;
use App\Domain\Insights\InsightResult;
use App\Domain\Insights\InsightSqlBuilder;
use App\Domain\Insights\InsightTypes;
use App\Domain\Insights\Runs\InsightRunStore;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Domain\Insights\Trends\TrendsResult;
use App\Domain\Insights\Trends\TrendsSqlBuilder;
use Tests\Support\FunnelFixtures as F;
use Tests\Support\RetentionFixtures as R;
use Tests\Support\TrendsFixtures as T;

/*
 * The registry of insight types. Every type must be consistent in itself: the key maps to a query class that reports the
 * same key, a result class and a builder. A new type fails the coverage test until a sample query is added below.
 */

/** One sample query per registered type. */
function sampleQueries(): array
{
    return [
        InsightTypes::TRENDS => fn (): InsightQuery => T::query(),
        InsightTypes::FUNNEL => fn (): InsightQuery => F::query(['view', 'buy']),
        InsightTypes::RETENTION => fn (): InsightQuery => R::query('signup', 'visit'),
    ];
}

it('has a sample for every registered type, so no type escapes the consistency checks below', function () {
    expect(array_keys(sampleQueries()))->toEqualCanonicalizing(InsightTypes::keys());
});

it('knows the trends type and nothing else it was not told about', function () {
    expect(InsightTypes::has('trends'))->toBeTrue()
        ->and(InsightTypes::has('nope'))->toBeFalse()
        ->and(InsightTypes::query('trends'))->toBe(TrendsQuery::class)
        ->and(InsightTypes::result('trends'))->toBe(TrendsResult::class)
        ->and(InsightTypes::builder('trends'))->toBeInstanceOf(TrendsSqlBuilder::class)
        ->and(fn () => InsightTypes::query('nope'))->toThrow(InvalidArgumentException::class, "Unknown insight type 'nope'")
        ->and(fn () => InsightTypes::builder('nope'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => InsightTypes::result('nope'))->toThrow(InvalidArgumentException::class);
});

it('registers consistent classes for every type', function (string $type) {
    $query = sampleQueries()[$type]();

    expect($query->type())->toBe($type)
        ->and($query)->toBeInstanceOf(InsightTypes::query($type))
        ->and(is_subclass_of(InsightTypes::query($type), InsightQuery::class))->toBeTrue()
        ->and(is_subclass_of(InsightTypes::result($type), InsightResult::class))->toBeTrue()
        ->and(InsightTypes::builder($type))->toBeInstanceOf(InsightSqlBuilder::class)
        // the canonical form is what the job carries: it must rebuild the very same definition
        ->and(InsightTypes::query($type)::fromCanonical($query->canonical())->hash())->toBe($query->hash())
        ->and($query->hash())->toMatch('/\A[0-9a-f]{64}\z/')
        // the builder accepts its own query
        ->and(InsightTypes::builder($type)->build($query)->sql)->toStartWith('SELECT ');
})->with(fn () => InsightTypes::keys());

it('keeps the run record type constant and the registry in step', function () {
    expect(InsightRunStore::TYPE_TRENDS)->toBe(InsightTypes::TRENDS)->toBe('trends');
});

it('lets a builder refuse the query of another type', function () {
    $foreign = new class implements InsightQuery
    {
        public function type(): string
        {
            return 'other';
        }

        public function canonical(): array
        {
            return [];
        }

        public function hash(): string
        {
            return str_repeat('0', 64);
        }

        public static function fromCanonical(array $data): InsightQuery
        {
            return new self;
        }

        public static function fromInput(array $input, DateTimeZone $defaultZone, DateTimeInterface $now): InsightQuery
        {
            return new self;
        }
    };

    expect(fn () => (new TrendsSqlBuilder)->build($foreign))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new TrendsSqlBuilder)->result($foreign, []))->toThrow(InvalidArgumentException::class);
});
