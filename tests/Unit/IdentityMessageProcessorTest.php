<?php

use App\Domain\Identity\DistinctIdChange;
use App\Domain\Identity\IdentityResolver;
use App\Infrastructure\Kafka\Identity\ConsumedMessage;
use App\Infrastructure\Kafka\Identity\DeadLetter;
use App\Infrastructure\Kafka\Identity\Disposition;
use App\Infrastructure\Kafka\Identity\IdentityMessageProcessor;
use App\Infrastructure\Kafka\Identity\PersonDistinctIdRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;

/*
 * The parts of the processor that never reach the resolver (so SQLite is enough): the cheap filter, the poison
 * classification, and the sorting of database errors. What happens WITH a resolver is in tests/Integration.
 */

function processorMessage(string $payload, int $offset = 0): ConsumedMessage
{
    return new ConsumedMessage('events', 0, $offset, '1:alice', $payload);
}

function bareProcessor(): IdentityMessageProcessor
{
    return new IdentityMessageProcessor(new IdentityResolver, fn () => null, fn () => CarbonImmutable::parse('2026-10-29 12:00:00.123', 'UTC'));
}

function eventJson(array $overrides = []): string
{
    return json_encode([
        'uuid' => '0194c1e2-0000-7000-8000-000000000001', 'project_id' => 1, 'event' => '$identify', 'distinct_id' => 'alice',
        'properties' => json_encode(['$anon_distinct_id' => 'anon-1']), 'timestamp' => '2026-10-29 11:59:59.000',
        ...$overrides,
    ]);
}

function queryException(string $sqlState, string $message = 'boom'): QueryException
{
    $pdo = new PDOException($message);
    $pdo->errorInfo = [$sqlState, 0, $message];

    return new QueryException('pgsql', 'select 1', [], $pdo);
}

describe('the cheap filter', function () {
    it('lets through only messages that contain the name of an identity event', function (string $payload, bool $candidate) {
        expect(IdentityMessageProcessor::isCandidate($payload))->toBe($candidate);
    })->with([
        'pageview' => [eventJson(['event' => '$pageview', 'properties' => '{}']), false],
        'custom event' => [eventJson(['event' => 'signed_up', 'properties' => '{}']), false],
        'identify' => [eventJson(), true],
        'create alias' => [eventJson(['event' => '$create_alias']), true],
        'merge dangerously' => [eventJson(['event' => '$merge_dangerously']), true],
        'not even JSON, no name' => ['garbage', false],
        'the name only inside properties (a false positive: decoding sorts it out)' => [eventJson(['event' => 'x', 'properties' => '{"note":"$identify"}']), true],
    ]);

    it('never misses a real identity event: every event the producer can write is a candidate', function () {
        // The normalizer writes the event name as a plain JSON string; json_encode keeps `$` and ASCII letters as they are.
        foreach (['$identify', '$create_alias', '$merge_dangerously'] as $name) {
            foreach ([JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 0, JSON_PRETTY_PRINT] as $flags) {
                expect(IdentityMessageProcessor::isCandidate(json_encode(['event' => $name, 'distinct_id' => 'é/ü"x'], $flags)))->toBeTrue();
            }
        }
    });

    it('skips a non-candidate without decoding it', function () {
        expect(bareProcessor()->process(processorMessage('{"event":"$pageview"'))->disposition)->toBe(Disposition::NotIdentity);
    });
});

describe('what is not processable', function () {
    it('dead-letters a candidate that is not JSON', function () {
        $result = bareProcessor()->process(processorMessage('{"event":"$identify"'));

        expect($result->disposition)->toBe(Disposition::Poison)->and($result->errorClass)->toBe('JsonException')->and($result->rows)->toBe([]);
    });

    it('dead-letters JSON that is not an object', function (string $payload) {
        expect(bareProcessor()->process(processorMessage($payload))->disposition)->toBe(Disposition::Poison);
    })->with(['a string' => ['"x$identify"'], 'truncated' => ['"$identify" 1']]);

    it('skips a JSON list that merely mentions the name', function () {
        expect(bareProcessor()->process(processorMessage('["$identify"]'))->disposition)->toBe(Disposition::NotIdentity);
    });

    it('skips a false positive (the name only inside the properties or another field)', function () {
        $result = bareProcessor()->process(processorMessage(eventJson(['event' => 'x', 'properties' => '{"note":"$identify"}'])));

        expect($result->disposition)->toBe(Disposition::NotIdentity);
    });

    it('dead-letters an identity event without a usable distinct id or project', function (array $override) {
        $result = bareProcessor()->process(processorMessage(eventJson($override)));

        expect($result->disposition)->toBe(Disposition::Poison)->and($result->errorClass)->toBe('InvalidEvent');
    })->with([
        'no distinct id' => [['distinct_id' => null]],
        'a numeric distinct id' => [['distinct_id' => 5]],
        'an empty distinct id' => [['distinct_id' => '']],
        'a string project' => [['project_id' => '1']],
        'project zero' => [['project_id' => 0]],
        'a negative project' => [['project_id' => -3]],
    ]);

    it('skips an identity event that names no second id: there is nothing to link', function (array $override) {
        expect(bareProcessor()->process(processorMessage(eventJson($override)))->disposition)->toBe(Disposition::NoCounterpart);
    })->with([
        'no $anon_distinct_id' => [['properties' => '{"$set":{"plan":"pro"}}']],
        'empty properties' => [['properties' => '{}']],
        'unreadable properties' => [['properties' => 'not json']],
        'an alias event without alias' => [['event' => '$create_alias', 'properties' => '{}']],
    ]);
});

describe('sorting database errors', function () {
    it('treats lock timeouts, deadlocks, serialization failures and connection trouble as retryable', function (string $state) {
        expect(IdentityMessageProcessor::isRetryable(queryException($state)))->toBeTrue();
    })->with([
        'lock_timeout' => ['55P03'], 'deadlock' => ['40P01'], 'serialization failure' => ['40001'],
        'connection failure' => ['08006'], 'connection does not exist' => ['08003'], 'admin shutdown' => ['57P01'],
        'cannot connect now' => ['57P03'], 'too many connections' => ['53300'], 'system error' => ['58000'],
    ]);

    it('treats a lost connection recognised by its message as retryable even without a SQLSTATE', function (string $message) {
        $e = new PDOException($message);

        expect(IdentityMessageProcessor::isRetryable($e))->toBeTrue();
    })->with([
        'server closed the connection unexpectedly',
        'SQLSTATE[08006] [7] could not translate host name "postgres" to address',
        'could not connect to server: Connection refused',
        'no connection to the server',
    ]);

    it('finds a lost connection inside the chain of previous exceptions', function () {
        expect(IdentityMessageProcessor::isRetryable(new RuntimeException('wrapper', 0, new LostConnectionException('Lost connection and no reconnector available'))))->toBeTrue()
            ->and(IdentityMessageProcessor::isRetryable(new RuntimeException('wrapper', 0, new LogicException('unrelated'))))->toBeFalse()
            ->and(IdentityMessageProcessor::isRetryable(new LostConnectionException('server has gone away')))->toBeTrue();
    });

    it('does not retry what no retry cures', function (string $state) {
        expect(IdentityMessageProcessor::isRetryable(queryException($state)))->toBeFalse();
    })->with([
        'foreign key violation' => ['23503'], 'unique violation' => ['23505'], 'invalid text representation' => ['22P02'],
        'invalid byte sequence' => ['22021'], 'undefined table' => ['42P01'], 'syntax error' => ['42601'],
    ]);

    it('reads the SQLSTATE from the exception, from its previous chain, and gives an empty string without one', function () {
        expect(IdentityMessageProcessor::sqlState(queryException('23503')))->toBe('23503')
            ->and(IdentityMessageProcessor::sqlState(new RuntimeException('x', 0, queryException('40001'))))->toBe('40001')
            ->and(IdentityMessageProcessor::sqlState(new RuntimeException('plain')))->toBe('');
    });
});

describe('the rows', function () {
    it('has exactly the shape the ClickHouse side reads, and keys the message by project and distinct id', function () {
        $row = PersonDistinctIdRow::fromChange(new DistinctIdChange(12, 'é/"x', 7, '0194c1e2-0000-7000-8000-000000000001', 3), '2026-10-29 12:00:00.123');
        $message = PersonDistinctIdRow::message($row);

        expect($row)->toBe([
            'project_id' => 12, 'distinct_id' => 'é/"x', 'person_id' => '0194c1e2-0000-7000-8000-000000000001',
            'version' => 3, 'is_deleted' => 0, 'created_at' => '2026-10-29 12:00:00.123',
        ])->and($message['key'])->toBe('12:é/"x')
            ->and($message['payload'])->toBe('{"project_id":12,"distinct_id":"é/\"x","person_id":"0194c1e2-0000-7000-8000-000000000001","version":3,"is_deleted":0,"created_at":"2026-10-29 12:00:00.123"}');
    });
});

describe('the dead letter envelope', function () {
    it('carries the error, the origin and the original payload', function () {
        $message = DeadLetter::message(new ConsumedMessage('events', 2, 99, '1:alice', '{"event":"$identify"'), 'JsonException', "Syntax\n  error", '2026-10-29 12:00:00.000');
        $dead = json_decode($message['payload'], true);

        expect($message['key'])->toBe('1:alice')
            ->and($dead)->toBe([
                'failed_at' => '2026-10-29 12:00:00.000', 'consumer' => 'identity', 'error_class' => 'JsonException', 'error' => 'Syntax error',
                'source_topic' => 'events', 'source_partition' => 2, 'source_offset' => 99, 'source_key' => '1:alice',
                'payload_bytes' => 20, 'payload_encoding' => 'utf8', 'payload_truncated' => false, 'payload' => '{"event":"$identify"',
            ]);
    });

    it('keeps bytes that are not UTF-8 as base64, so the original can be recovered', function () {
        $binary = "\xff\xfe\$identify\x00";
        $dead = json_decode(DeadLetter::message(new ConsumedMessage('events', 0, 1, null, $binary), 'X', 'y', 't')['payload'], true);

        expect($dead['payload_encoding'])->toBe('base64')->and(base64_decode($dead['payload']))->toBe($binary)->and($dead['source_key'])->toBeNull();
    });

    it('cuts an oversized payload so the message fits a Kafka message, and says so', function (string $payload) {
        $message = DeadLetter::message(new ConsumedMessage('events', 0, 1, 'k', $payload), 'X', 'y', 't');
        $dead = json_decode($message['payload'], true);

        expect(strlen($message['payload']))->toBeLessThan(900_001)
            ->and($dead['payload_truncated'])->toBeTrue()
            ->and($dead['payload_bytes'])->toBe(strlen($payload))
            ->and(str_starts_with($payload, $dead['payload']))->toBeTrue();
    })->with([
        'plain text' => [str_repeat('a', 950_000)],
        'multibyte text' => [str_repeat('é', 475_000)],
        'control characters that JSON escapes to six bytes each' => [str_repeat("\x01", 950_000)],
    ]);

    it('trims and bounds a long error text', function () {
        $dead = json_decode(DeadLetter::message(new ConsumedMessage('events', 0, 1, 'k', '{}'), 'X', str_repeat('e ', 3000), 't')['payload'], true);

        expect(mb_strlen($dead['error']))->toBeLessThanOrEqual(1000);
    });
});
