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
