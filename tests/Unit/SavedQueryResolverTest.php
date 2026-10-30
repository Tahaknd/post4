<?php

use App\Domain\Dashboards\SavedQueryDefinition;
use App\Domain\Dashboards\SavedQueryResolver;
use App\Domain\Dashboards\SavedQueryState;
use App\Domain\Insights\InsightTypes;
use App\Domain\Insights\Query\InvalidInsightQuery;
use App\Models\SavedInsight;
use Illuminate\Support\Carbon;
use Tests\Support\SavedInputs;

/*
 * The state policy of stored definitions: ok, or needs_review with a reason, never an exception. Rows are built in
 * memory (no database): the resolver reads attributes only.
 */

beforeEach(fn () => Carbon::setTestNow('2026-03-15 10:00:00 UTC'));
afterEach(fn () => Carbon::setTestNow());

function savedRow(string $type, array $input, array $row = [], ?DateTimeZone $zone = null): SavedInsight
{
    $definition = SavedQueryDefinition::fromInput($type, $input, $zone ?? new DateTimeZone('UTC'), now());

    return new SavedInsight([
        'type' => $definition->type,
        'query' => $definition->canonical,
        'query_version' => $definition->version,
        'date_preset' => $definition->datePreset,
        'timezone' => $definition->timezone,
        ...$row,
    ]);
}

it('builds a definition of every registered type and the resolver accepts it again', function (string $type) {
    $state = (new SavedQueryResolver)->resolve(savedRow($type, SavedInputs::for($type)), now());

    expect($state->status)->toBe(SavedQueryState::OK)
        ->and($state->reason)->toBeNull()
        ->and($state->query?->type())->toBe($type)
        ->and($state->migrated)->toBeFalse()
        ->and($state->isRunnable())->toBeTrue();
})->with(InsightTypes::keys());

it('keeps its range under the key the resolver rewrites, in every type', function (string $type) {
    $definition = SavedQueryDefinition::fromInput($type, SavedInputs::for($type), new DateTimeZone('UTC'), now());

    expect($definition->canonical)->toHaveKey('range')
        ->and(array_keys($definition->canonical['range']))->toBe(['from', 'to'])
        ->and($definition->version)->toBe(SavedQueryResolver::currentVersion($type))
        ->and($definition->datePreset)->toBe('last_7_days')
        ->and($definition->timezone)->toBe('UTC');
})->with(InsightTypes::keys());

it('resolves a relative range to today every time it is read, not to the day it was saved', function (string $type) {
    $row = savedRow($type, SavedInputs::for($type));
    $savedRange = $row->query['range'];

    Carbon::setTestNow('2026-04-20 08:00:00 UTC');
    $state = (new SavedQueryResolver)->resolve($row, now());

    expect($state->status)->toBe(SavedQueryState::OK)
        ->and($state->query->canonical()['range'])->toBe(['from' => '2026-04-14T00:00:00.000Z', 'to' => '2026-04-21T00:00:00.000Z'])
        ->and($state->query->canonical()['range'])->not->toBe($savedRange)
        ->and($row->query['range'])->toBe($savedRange);
})->with(InsightTypes::keys());

it('resolves a relative range in the stored zone', function () {
    $zone = new DateTimeZone('Pacific/Auckland');
    $row = savedRow('trends', SavedInputs::for('trends', ['timezone' => 'Pacific/Auckland']), zone: $zone);

    // 2026-03-15 10:00 UTC is already 2026-03-15 23:00 in Auckland (+13): today is still the 15th there.
    expect($row->timezone)->toBe('Pacific/Auckland')
        ->and((new SavedQueryResolver)->resolve($row, now())->query->canonical()['range']['to'])->toBe('2026-03-15T11:00:00.000Z');

    Carbon::setTestNow('2026-03-15 12:00:00 UTC');
    expect((new SavedQueryResolver)->resolve($row, now())->query->canonical()['range']['to'])->toBe('2026-03-16T11:00:00.000Z');
});

it('leaves an absolute range exactly as saved', function () {
    $row = savedRow('trends', ['events' => ['signup'], 'date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
    Carbon::setTestNow('2027-01-01 00:00:00 UTC');

    $state = (new SavedQueryResolver)->resolve($row, now());

    expect($row->date_preset)->toBeNull()
        ->and($state->query->canonical()['range'])->toBe(['from' => '2026-01-01T00:00:00.000Z', 'to' => '2026-02-01T00:00:00.000Z']);
});

it('marks an unknown type, naming the reason', function () {
    $state = (new SavedQueryResolver)->resolve(savedRow('trends', SavedInputs::for('trends'), ['type' => 'paths']), now());

    expect($state->status)->toBe(SavedQueryState::NEEDS_REVIEW)
        ->and($state->reason)->toBe('unknown_type')
        ->and($state->query)->toBeNull()
        ->and($state->isRunnable())->toBeFalse();
});

it('marks a definition stored under another version as outdated, never runs it', function () {
    $row = savedRow('trends', SavedInputs::for('trends'));
    $old = [...$row->query, 'v' => 1];
    $row->query = $old;
    $row->query_version = 1;

    $state = (new SavedQueryResolver)->resolve($row, now());

    expect($state->status)->toBe(SavedQueryState::NEEDS_REVIEW)->and($state->reason)->toBe('outdated_version')->and($state->query)->toBeNull();
});

it('marks a definition of the current version that no longer passes its rules as invalid', function (array $change) {
    $row = savedRow('trends', SavedInputs::for('trends'));
    $row->query = [...$row->query, ...$change];

    $state = (new SavedQueryResolver)->resolve($row, now());

    expect($state->status)->toBe(SavedQueryState::NEEDS_REVIEW)->and($state->reason)->toBe('invalid_definition');
})->with([
    'no events' => [['events' => []]],
    'events of the wrong type' => [['events' => 'signup']],
    'unknown metric' => [['metric' => 'median']],
    'extra key' => [['surprise' => 1]],
]);

it('marks an absolute range of the wrong shape as invalid', function () {
    $row = savedRow('trends', ['events' => ['signup'], 'date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
    $row->query = [...$row->query, 'range' => 'last week'];

    expect((new SavedQueryResolver)->resolve($row, now())->reason)->toBe('invalid_definition');
});

it('marks a stored query that is not an array of the expected shape', function () {
    $row = savedRow('funnel', SavedInputs::for('funnel'));
    $row->query = ['v' => 1];
    $row->date_preset = null;

    expect((new SavedQueryResolver)->resolve($row, now())->reason)->toBe('invalid_definition');
});

it('marks a relative range that cannot be resolved', function (array $change) {
    $row = savedRow('trends', SavedInputs::for('trends'), $change);

    expect((new SavedQueryResolver)->resolve($row, now())->reason)->toBe('invalid_date');
})->with([
    'unknown preset' => [['date_preset' => 'yesterday']],
    'unknown zone' => [['timezone' => 'Mars/Olympus']],
]);

it('never throws, whatever is stored', function (mixed $query) {
    $row = savedRow('retention', SavedInputs::for('retention'), ['date_preset' => null]);
    $row->query = $query;

    expect((new SavedQueryResolver)->resolve($row, now())->status)->toBe(SavedQueryState::NEEDS_REVIEW);
})->with([[[]], [['v' => 'x']], [['v' => 1, 'target' => 5]], [null], [['range' => ['from' => 1, 'to' => 2]]]]);

it('builds a definition only from a valid input of a registered type', function () {
    expect(fn () => SavedQueryDefinition::fromInput('paths', [], new DateTimeZone('UTC'), now()))->toThrow(InvalidInsightQuery::class)
        ->and(fn () => SavedQueryDefinition::fromInput('trends', ['events' => []], new DateTimeZone('UTC'), now()))->toThrow(InvalidInsightQuery::class)
        ->and(fn () => SavedQueryDefinition::fromInput('trends', ['events' => ['a'], 'date_preset' => 'nope'], new DateTimeZone('UTC'), now()))->toThrow(InvalidInsightQuery::class);
});

it('stores the project zone when the input names none', function () {
    $definition = SavedQueryDefinition::fromInput('trends', SavedInputs::for('trends'), new DateTimeZone('Europe/Istanbul'), now());

    expect($definition->timezone)->toBe('Europe/Istanbul')->and($definition->canonical['timezone'])->toBe('Europe/Istanbul');
});
