<?php

use App\Domain\Capture\EventNormalizer;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->normalizer = new EventNormalizer();
    $this->now = CarbonImmutable::parse('2026-10-22 12:00:00.000', 'UTC');
});

it('builds a kafka message', function () {
    $m = $this->normalizer->normalize(
        ['event' => 'pageview', 'distinct_id' => 'u1', 'properties' => ['token' => 'phc_x', '$current_url' => 'http://a/']],
        project: 7, now: $this->now,
    );

    expect($m['project_id'])->toBe(7)
        ->and($m['event'])->toBe('pageview')
        ->and($m['distinct_id'])->toBe('u1')
        ->and($m['timestamp'])->toBe('2026-10-22 12:00:00.000')
        ->and(json_decode($m['properties'], true))->toBe(['$current_url' => 'http://a/'])
        ->and($m['uuid'])->toMatch('/^[0-9a-f-]{36}$/');
});

it('takes distinct_id from properties and keeps a valid client uuid', function () {
    $uuid = '0197c3a2-1111-7222-8333-444455556666';
    $m = $this->normalizer->normalize(
        ['event' => 'e', 'uuid' => $uuid, 'properties' => ['distinct_id' => 'u2']],
        project: 1, now: $this->now,
    );

    expect($m['distinct_id'])->toBe('u2')->and($m['uuid'])->toBe($uuid);
});

it('encodes empty properties as an object', function () {
    $m = $this->normalizer->normalize(['event' => 'e', 'distinct_id' => 'u'], project: 1, now: $this->now);

    expect($m['properties'])->toBe('{}');
});

it('adds $ip only when absent', function () {
    $a = $this->normalizer->normalize(['event' => 'e', 'distinct_id' => 'u'], project: 1, now: $this->now, ip: '1.2.3.4');
    $b = $this->normalizer->normalize(['event' => 'e', 'distinct_id' => 'u', 'properties' => ['$ip' => '9.9.9.9']], project: 1, now: $this->now, ip: '1.2.3.4');

    expect(json_decode($a['properties'], true)['$ip'])->toBe('1.2.3.4')
        ->and(json_decode($b['properties'], true)['$ip'])->toBe('9.9.9.9');
});

it('drops events without name or distinct id', function (array $raw) {
    expect($this->normalizer->normalize($raw, project: 1, now: $this->now))->toBeNull();
})->with([
    'no event' => [['distinct_id' => 'u']],
    'empty event' => [['event' => '', 'distinct_id' => 'u']],
    'no distinct id' => [['event' => 'e']],
    'empty distinct id' => [['event' => 'e', 'distinct_id' => '']],
]);

it('uses the client timestamp as is when sent_at is unknown', function () {
    $m = $this->normalizer->normalize(
        ['event' => 'e', 'distinct_id' => 'u', 'timestamp' => '2026-10-22T11:30:00.250Z'],
        project: 1, now: $this->now,
    );

    expect($m['timestamp'])->toBe('2026-10-22 11:30:00.250');
});

it('corrects client clock skew using sent_at', function () {
    // İstemci saati 60 sn geride: sent_at 11:59:00 iken sunucu saati 12:00:00.
    $m = $this->normalizer->normalize(
        ['event' => 'e', 'distinct_id' => 'u', 'timestamp' => '2026-10-22T11:58:58.500Z'],
        project: 1, now: $this->now, sentAt: CarbonImmutable::parse('2026-10-22T11:59:00Z'),
    );

    // Olay, gönderimden 1.5 sn önce olmuş: sunucu zamanı 12:00:00 - 1.5 sn.
    expect($m['timestamp'])->toBe('2026-10-22 11:59:58.500');
});

it('applies the offset field in milliseconds', function () {
    $m = $this->normalizer->normalize(['event' => 'e', 'distinct_id' => 'u', 'offset' => 1500], project: 1, now: $this->now);

    expect($m['timestamp'])->toBe('2026-10-22 11:59:58.500');
});

it('clamps client timestamps outside the accepted range to now', function (string $timestamp) {
    $m = $this->normalizer->normalize(
        ['event' => 'e', 'distinct_id' => 'u', 'timestamp' => $timestamp],
        project: 1, now: $this->now,
    );

    expect($m['timestamp'])->toBe('2026-10-22 12:00:00.000');
})->with([
    'epoch seconds read as milliseconds (1970)' => '1970-01-21T17:57:50Z',
    'before the lower bound' => '1999-12-31T23:59:59.999Z',
    'year 2300' => '2300-01-01T00:00:00Z',
    'now plus 400 days' => '2027-11-26T12:00:00Z',
    'one millisecond past the upper bound' => '2027-10-23T12:00:00.001Z',
]);

it('keeps timestamps exactly on the range boundaries', function (string $timestamp, string $expected) {
    $m = $this->normalizer->normalize(
        ['event' => 'e', 'distinct_id' => 'u', 'timestamp' => $timestamp],
        project: 1, now: $this->now,
    );

    expect($m['timestamp'])->toBe($expected);
})->with([
    'lower bound' => ['2000-01-01T00:00:00Z', '2000-01-01 00:00:00.000'],
    'upper bound (now + 366 days)' => ['2027-10-23T12:00:00Z', '2027-10-23 12:00:00.000'],
    'inside the range' => ['2025-06-01T08:09:10.123Z', '2025-06-01 08:09:10.123'],
]);

it('converts client timestamps with an offset to UTC', function () {
    $m = $this->normalizer->normalize(
        ['event' => 'e', 'distinct_id' => 'u', 'timestamp' => '2026-10-22T14:30:00.250+03:00'],
        project: 1, now: $this->now,
    );

    expect($m['timestamp'])->toBe('2026-10-22 11:30:00.250');
});

it('clamps timestamps that fall out of range after the sent_at correction', function (string $timestamp, string $sentAt) {
    $m = $this->normalizer->normalize(
        ['event' => 'e', 'distinct_id' => 'u', 'timestamp' => $timestamp],
        project: 1, now: $this->now, sentAt: CarbonImmutable::parse($sentAt),
    );

    expect($m['timestamp'])->toBe('2026-10-22 12:00:00.000');
})->with([
    'corrected into the past' => ['1999-12-31T00:00:00Z', '2026-10-22T11:59:00Z'],
    'corrected into the future' => ['2026-10-22T11:58:58Z', '1970-01-01T00:00:00Z'],
]);

it('clamps an offset that lands out of range', function (int $offset) {
    $m = $this->normalizer->normalize(['event' => 'e', 'distinct_id' => 'u', 'offset' => $offset], project: 1, now: $this->now);

    expect($m['timestamp'])->toBe('2026-10-22 12:00:00.000');
})->with([
    'far in the past' => [1_000_000_000_000_000],
    'far in the future' => [-1_000_000_000_000_000],
]);

it('falls back to now for unparseable timestamps', function () {
    $m = $this->normalizer->normalize(['event' => 'e', 'distinct_id' => 'u', 'timestamp' => 'garbage'], project: 1, now: $this->now);

    expect($m['timestamp'])->toBe('2026-10-22 12:00:00.000');
});

it('drops events whose properties cannot be json encoded', function () {
    $properties = json_decode('{"a":1e999}', true);
    expect(is_infinite($properties['a']))->toBeTrue();

    $m = $this->normalizer->normalize(['event' => 'e', 'distinct_id' => 'u', 'properties' => $properties], project: 1, now: $this->now);

    expect($m)->toBeNull();
});

it('drops events whose properties nest deeper than the encoder allows', function () {
    $nested = 'x';
    for ($i = 0; $i < 600; $i++) {
        $nested = [$nested];
    }

    $m = $this->normalizer->normalize(['event' => 'e', 'distinct_id' => 'u', 'properties' => ['a' => $nested]], project: 1, now: $this->now);

    expect($m)->toBeNull();
});

it('drops events with an over-long event name or distinct id instead of truncating', function (array $raw) {
    expect($this->normalizer->normalize($raw, project: 1, now: $this->now))->toBeNull();
})->with([
    '201 character event' => [['event' => str_repeat('a', 201), 'distinct_id' => 'u']],
    '201 character distinct_id' => [['event' => 'e', 'distinct_id' => str_repeat('a', 201)]],
    '201 multibyte characters in event' => [['event' => str_repeat('ü', 201), 'distinct_id' => 'u']],
    '201 multibyte characters in distinct_id' => [['event' => 'e', 'distinct_id' => str_repeat('日', 201)]],
    'far too long distinct_id' => [['event' => 'e', 'distinct_id' => str_repeat('a', 100_000)]],
]);

it('keeps an event name and distinct id of exactly 200 characters', function (string $char) {
    $m = $this->normalizer->normalize(
        ['event' => str_repeat($char, 200), 'distinct_id' => str_repeat($char, 200)],
        project: 1, now: $this->now,
    );

    expect($m['event'])->toBe(str_repeat($char, 200))->and($m['distinct_id'])->toBe(str_repeat($char, 200));
})->with(['ascii' => 'a', 'two-byte' => 'ü', 'four-byte' => '😀']);

it('drops whitespace-only event names and distinct ids', function (array $raw) {
    expect($this->normalizer->normalize($raw, project: 1, now: $this->now))->toBeNull();
})->with([
    'spaces event' => [['event' => '   ', 'distinct_id' => 'u']],
    'spaces distinct_id' => [['event' => 'e', 'distinct_id' => '   ']],
    'int-like whitespace distinct_id' => [['event' => 'e', 'distinct_id' => " \t\n"]],
]);

it('drops NUL and control characters in event and distinct id', function (array $raw) {
    expect($this->normalizer->normalize($raw, project: 1, now: $this->now))->toBeNull();
})->with([
    'NUL in event' => [['event' => "ev\0il", 'distinct_id' => 'u']],
    'NUL in distinct_id' => [['event' => 'e', 'distinct_id' => "u\0"]],
    'newline in event' => [['event' => "a\nb", 'distinct_id' => 'u']],
    'tab in distinct_id' => [['event' => 'e', 'distinct_id' => "a\tb"]],
    'escape in event' => [['event' => "a\x1Bb", 'distinct_id' => 'u']],
    'DEL in distinct_id' => [['event' => 'e', 'distinct_id' => "a\x7Fb"]],
]);

it('drops events whose properties exceed the message size cap', function () {
    $normalizer = new EventNormalizer(maxPropertiesBytes: 100);

    $small = $normalizer->normalize(['event' => 'e', 'distinct_id' => 'u', 'properties' => ['a' => 'b']], project: 1, now: $this->now);
    $big = $normalizer->normalize(['event' => 'e', 'distinct_id' => 'u', 'properties' => ['a' => str_repeat('x', 200)]], project: 1, now: $this->now);

    expect($small)->not->toBeNull()->and($big)->toBeNull();
});

it('accepts properties of exactly the size cap', function () {
    $json = json_encode(['a' => str_repeat('x', 50)]);
    $normalizer = new EventNormalizer(maxPropertiesBytes: strlen($json));

    $m = $normalizer->normalize(['event' => 'e', 'distinct_id' => 'u', 'properties' => ['a' => str_repeat('x', 50)]], project: 1, now: $this->now);

    expect($m['properties'])->toBe($json);
});

it('defaults the properties cap to 900000 bytes', function () {
    $normalizer = new EventNormalizer();

    $ok = $normalizer->normalize(['event' => 'e', 'distinct_id' => 'u', 'properties' => ['a' => str_repeat('x', 899_000)]], project: 1, now: $this->now);
    $big = $normalizer->normalize(['event' => 'e', 'distinct_id' => 'u', 'properties' => ['a' => str_repeat('x', 900_000)]], project: 1, now: $this->now);

    expect($ok)->not->toBeNull()->and($big)->toBeNull();
});

it('replaces the nil uuid with a fresh one', function () {
    $m = $this->normalizer->normalize(
        ['event' => 'e', 'distinct_id' => 'u', 'uuid' => '00000000-0000-0000-0000-000000000000'],
        project: 1, now: $this->now,
    );

    expect($m['uuid'])->not->toBe('00000000-0000-0000-0000-000000000000')->and($m['uuid'])->toMatch('/^[0-9a-f-]{36}$/');
});

it('lowercases a valid client uuid', function () {
    $m = $this->normalizer->normalize(
        ['event' => 'e', 'distinct_id' => 'u', 'uuid' => '0197C3A2-1111-7222-8333-44445555ABCD'],
        project: 1, now: $this->now,
    );

    expect($m['uuid'])->toBe('0197c3a2-1111-7222-8333-44445555abcd');
});

it('generates a fresh uuid for invalid client uuids', function (mixed $uuid) {
    $m = $this->normalizer->normalize(['event' => 'e', 'distinct_id' => 'u', 'uuid' => $uuid], project: 1, now: $this->now);

    expect($m['uuid'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
})->with(['garbage' => 'nope', 'integer' => 5, 'array' => [['x']], 'empty' => '']);

it('casts an integer distinct id to a string', function () {
    $m = $this->normalizer->normalize(['event' => 'e', 'distinct_id' => 123], project: 1, now: $this->now);

    expect($m['distinct_id'])->toBe('123');
});

it('drops events with a bool, float or array distinct id', function (mixed $distinctId) {
    $m = $this->normalizer->normalize(['event' => 'e', 'distinct_id' => $distinctId], project: 1, now: $this->now);

    expect($m)->toBeNull();
})->with(['bool' => true, 'float' => 1.5, 'array' => [['a']]]);

it('ignores relative timestamp strings and uses the injected now', function (string $timestamp) {
    $m = $this->normalizer->normalize(['event' => 'e', 'distinct_id' => 'u', 'timestamp' => $timestamp], project: 1, now: $this->now);

    expect($m['timestamp'])->toBe('2026-10-22 12:00:00.000');
})->with(['tomorrow', '+1 day', 'now', '@1700000000', 'yesterday noon', '2026']);

it('accepts a space separated date time timestamp', function () {
    $m = $this->normalizer->normalize(['event' => 'e', 'distinct_id' => 'u', 'timestamp' => '2026-10-22 11:30:00'], project: 1, now: $this->now);

    expect($m['timestamp'])->toBe('2026-10-22 11:30:00.000');
});

it('rejects project ids outside the UInt32 range', function (int $project) {
    $this->normalizer->normalize(['event' => 'e', 'distinct_id' => 'u'], project: $project, now: $this->now);
})->with(['zero' => 0, 'negative' => -1, 'above UInt32' => 4294967296])->throws(InvalidArgumentException::class);

it('accepts the largest UInt32 project id', function () {
    $m = $this->normalizer->normalize(['event' => 'e', 'distinct_id' => 'u'], project: 4294967295, now: $this->now);

    expect($m['project_id'])->toBe(4294967295);
});

/** Backslash-heavy properties: every backslash is escaped again when the message is encoded. */
function backslashEvent(int $backslashes): array
{
    return ['event' => 'e', 'distinct_id' => 'u', 'properties' => ['a' => str_repeat('\\', $backslashes)]];
}

it('drops events whose encoded kafka message exceeds the cap even when properties fit', function () {
    // ~880 KB of properties (each backslash is escaped once already), over 1.7 MB once the message is encoded again.
    $raw = backslashEvent(440_000);

    $properties = json_encode((object) $raw['properties'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    expect(strlen($properties))->toBeLessThan(900_000);

    expect($this->normalizer->normalize($raw, project: 1, now: $this->now))->toBeNull();
});

it('keeps an event whose encoded kafka message is just under the cap', function () {
    $normalizer = new EventNormalizer(maxMessageBytes: 1_000);
    $raw = ['event' => 'e', 'distinct_id' => 'u', 'properties' => ['a' => str_repeat('x', 500)]];

    $m = $normalizer->normalize($raw, project: 1, now: $this->now);
    $size = strlen(json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    // Pin the cap to the exact encoded size: it passes at the limit and is dropped one byte below it.
    $atLimit = new EventNormalizer(maxMessageBytes: $size);
    $belowLimit = new EventNormalizer(maxMessageBytes: $size - 1);

    expect($atLimit->normalize($raw, project: 1, now: $this->now))->not->toBeNull()
        ->and($belowLimit->normalize($raw, project: 1, now: $this->now))->toBeNull();
});

it('keeps a backslash-heavy event that fits the default message cap', function () {
    // 200_000 backslashes: 400 KB properties, ~800 KB encoded message.
    $m = $this->normalizer->normalize(backslashEvent(200_000), project: 1, now: $this->now);

    expect($m)->not->toBeNull();
});

it('makes the encoded message cap configurable through the constructor', function () {
    $raw = ['event' => 'e', 'distinct_id' => 'u', 'properties' => ['a' => str_repeat('x', 500)]];

    $tight = new EventNormalizer(maxMessageBytes: 300);
    $roomy = new EventNormalizer(maxMessageBytes: 5_000);

    expect($tight->normalize($raw, project: 1, now: $this->now))->toBeNull()
        ->and($roomy->normalize($raw, project: 1, now: $this->now))->not->toBeNull();
});
