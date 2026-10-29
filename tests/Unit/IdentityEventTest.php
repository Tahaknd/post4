<?php

use App\Domain\Capture\EventNormalizer;
use App\Domain\Identity\IdentityEvent;
use App\Domain\Identity\IdentityKind;
use Carbon\CarbonImmutable;

/** A normalized event as the events topic carries it: `properties` is a JSON string. */
function normalizedEvent(string $event, string $distinctId, array $properties, array $extra = []): array
{
    return $extra + [
        'uuid' => '0197a000-0000-7000-8000-000000000001',
        'project_id' => 7,
        'event' => $event,
        'distinct_id' => $distinctId,
        'properties' => json_encode((object) $properties),
        'timestamp' => '2026-10-29 10:00:00.000',
    ];
}

it('parses an $identify with $anon_distinct_id: the event\'s own id is the target', function () {
    $event = IdentityEvent::fromNormalized(normalizedEvent('$identify', 'alice', ['$anon_distinct_id' => 'anon-1']));

    expect($event)->not->toBeNull()
        ->and($event->kind)->toBe(IdentityKind::Identify)
        ->and($event->projectId)->toBe(7)
        ->and($event->distinctId)->toBe('alice')
        ->and($event->otherDistinctId)->toBe('anon-1')
        ->and($event->eventUuid)->toBe('0197a000-0000-7000-8000-000000000001')
        ->and($event->distinctIds())->toBe(['alice', 'anon-1']);
});

it('parses $create_alias and $merge_dangerously through the alias property', function (string $name, IdentityKind $kind) {
    $event = IdentityEvent::fromNormalized(normalizedEvent($name, 'alice', ['alias' => 'bob']));

    expect($event->kind)->toBe($kind)->and($event->distinctId)->toBe('alice')->and($event->otherDistinctId)->toBe('bob');
})->with([
    '$create_alias' => ['$create_alias', IdentityKind::CreateAlias],
    '$merge_dangerously' => ['$merge_dangerously', IdentityKind::MergeDangerously],
]);

it('reads merge_distinct_id for $merge_dangerously when alias is absent, and lets alias win when both are there', function () {
    $only = IdentityEvent::fromNormalized(normalizedEvent('$merge_dangerously', 'alice', ['merge_distinct_id' => 'bob']));
    $both = IdentityEvent::fromNormalized(normalizedEvent('$merge_dangerously', 'alice', ['alias' => 'carol', 'merge_distinct_id' => 'bob']));
    $wrongKind = IdentityEvent::fromNormalized(normalizedEvent('$create_alias', 'alice', ['merge_distinct_id' => 'bob']));

    expect($only->otherDistinctId)->toBe('bob')
        ->and($both->otherDistinctId)->toBe('carol')
        ->and($wrongKind)->toBeNull();
});

it('is not an identity event for any other event name', function (string $name) {
    expect(IdentityEvent::fromNormalized(normalizedEvent($name, 'alice', ['$anon_distinct_id' => 'a', 'alias' => 'b'])))->toBeNull();
})->with(['$pageview', 'identify', '$set', '$Identify', '$identify ', 'custom']);

it('is null for an identity event without an id to link', function (string $name, array $properties) {
    expect(IdentityEvent::fromNormalized(normalizedEvent($name, 'alice', $properties)))->toBeNull();
})->with([
    'identify without anon id' => ['$identify', ['$set' => ['plan' => 'pro']]],
    'identify with the wrong key' => ['$identify', ['anon_distinct_id' => 'a']],
    'alias without alias' => ['$create_alias', ['$anon_distinct_id' => 'a']],
    'merge without alias' => ['$merge_dangerously', []],
]);

it('treats missing, empty, malformed or non-object properties as "no id to link"', function (mixed $properties) {
    $raw = normalizedEvent('$identify', 'alice', []);
    $raw['properties'] = $properties;

    expect(IdentityEvent::fromNormalized($raw))->toBeNull();
})->with([null, '', '{', '[]', '"text"', '7', '[1,2]', 'null']);

it('rejects a normalized event whose own fields are unusable', function (array $override) {
    $raw = $override + normalizedEvent('$identify', 'alice', ['$anon_distinct_id' => 'anon']);

    expect(IdentityEvent::fromNormalized($raw))->toBeNull();
})->with([
    'distinct_id is a number' => [['distinct_id' => 5]],
    'distinct_id is missing' => [['distinct_id' => null]],
    'project_id is a string' => [['project_id' => '7']],
    'project_id is zero' => [['project_id' => 0]],
    'project_id is negative' => [['project_id' => -3]],
    'event is not a string' => [['event' => ['$identify']]],
]);

it('accepts properties that are already decoded', function () {
    $raw = normalizedEvent('$identify', 'alice', []);
    $raw['properties'] = ['$anon_distinct_id' => 'anon'];

    expect(IdentityEvent::fromNormalized($raw)->otherDistinctId)->toBe('anon');
});

it('converts the other id the way JavaScript String() does, so a broken client\'s value is refused instead of linked', function (mixed $value, string $text, bool $refused) {
    $event = IdentityEvent::fromNormalized(normalizedEvent('$create_alias', 'alice', ['alias' => $value]));

    expect($event->otherDistinctId)->toBe($text)
        ->and($event->invalidDistinctId() !== null)->toBe($refused);
})->with([
    'string' => ['bob', 'bob', false],
    'integer' => [42, '42', false],
    'zero' => [0, '0', true],
    'float' => [1.5, '1.5', false],
    'true' => [true, 'true', true],
    'false' => [false, 'false', true],
    'null' => [null, 'null', true],
    'object' => [['a' => 1], '[object Object]', true],
]);

it('names the first refused id of the pair, the event\'s own first', function () {
    $own = IdentityEvent::fromNormalized(normalizedEvent('$identify', 'null', ['$anon_distinct_id' => 'undefined']));
    $other = IdentityEvent::fromNormalized(normalizedEvent('$identify', 'alice', ['$anon_distinct_id' => 'undefined']));
    $fine = IdentityEvent::fromNormalized(normalizedEvent('$identify', 'alice', ['$anon_distinct_id' => 'anon-1']));

    expect($own->invalidDistinctId())->toBe('null')
        ->and($other->invalidDistinctId())->toBe('undefined')
        ->and($fine->invalidDistinctId())->toBeNull();
});

it('parses what the real EventNormalizer produces', function () {
    $normalized = (new EventNormalizer)->normalize(
        ['event' => '$identify', 'distinct_id' => 'alice', 'properties' => ['$anon_distinct_id' => 'anon-1', '$set' => ['plan' => 'pro']]],
        project: 3,
        now: CarbonImmutable::parse('2026-10-29 10:00:00', 'UTC'),
    );

    $event = IdentityEvent::fromNormalized($normalized);

    expect($event->projectId)->toBe(3)->and($event->distinctId)->toBe('alice')->and($event->otherDistinctId)->toBe('anon-1')
        ->and($event->eventUuid)->toBe($normalized['uuid']);
});
