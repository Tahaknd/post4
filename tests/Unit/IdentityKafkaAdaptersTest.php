<?php

use App\Infrastructure\Kafka\Identity\CommitFailed;
use App\Infrastructure\Kafka\Identity\RdKafkaMessageSource;
use App\Infrastructure\Kafka\Identity\RdKafkaRowWriter;
use App\Infrastructure\Kafka\Identity\WriteFailed;
use RdKafka\Exception;
use RdKafka\Message;
use Tests\Support\FakeRdKafkaConsumer;
use Tests\Support\FakeRdKafkaProducer;

function fakeWriter(?FakeRdKafkaProducer &$producer = null): RdKafkaRowWriter
{
    return new RdKafkaRowWriter('127.0.0.1:1', producerFactory: function ($conf, $onDelivery) use (&$producer) {
        return $producer = new FakeRdKafkaProducer($onDelivery);
    });
}

function rdMessage(int $err, int $partition = 0, int $offset = 0, ?string $payload = null, ?string $key = null): Message
{
    $m = new Message;
    $m->err = $err;
    $m->topic_name = 'events';
    $m->partition = $partition;
    $m->offset = $offset;
    $m->payload = $payload;
    $m->key = $key;

    return $m;
}

describe('RdKafkaRowWriter', function () {
    it('produces every message with its key and returns only after the flush', function () {
        $writer = fakeWriter($producer);

        $writer->publish('person_distinct_ids', [['key' => '1:a', 'payload' => '{"a":1}'], ['key' => '1:b', 'payload' => '{"b":2}']]);

        expect($producer->messages)->toBe([
            ['topic' => 'person_distinct_ids', 'payload' => '{"a":1}', 'key' => '1:a'],
            ['topic' => 'person_distinct_ids', 'payload' => '{"b":2}', 'key' => '1:b'],
        ])->and(end($producer->calls))->toBe('poll')
            ->and(in_array('flush', $producer->calls, true))->toBeTrue();
    });

    it('produces nothing and does not even build a client for an empty batch', function () {
        $built = false;
        $writer = new RdKafkaRowWriter('127.0.0.1:1', producerFactory: function () use (&$built) {
            $built = true;
        });

        $writer->publish('t', []);

        expect($built)->toBeFalse();
    });

    it('fails when a delivery report carries an error, and purges what is still queued', function () {
        $writer = fakeWriter($producer);
        $writer->publish('t', [['key' => 'k', 'payload' => 'ok']]);
        $producer->deliverWith = RD_KAFKA_RESP_ERR__MSG_TIMED_OUT;

        expect(fn () => $writer->publish('t', [['key' => 'k', 'payload' => 'lost']]))->toThrow(WriteFailed::class, 'delivery failed')
            ->and($producer->calls)->toContain('purge');
    });

    it('fails when the flush times out', function () {
        $writer = fakeWriter($producer);
        $writer->publish('t', [['key' => 'k', 'payload' => 'ok']]);
        $producer->flushResult = RD_KAFKA_RESP_ERR__TIMED_OUT;

        expect(fn () => $writer->publish('t', [['key' => 'k', 'payload' => 'x']]))->toThrow(WriteFailed::class);
    });

    it('does not count a failure of an earlier batch against the next one', function () {
        $writer = fakeWriter($producer);
        $writer->publish('t', [['key' => 'k', 'payload' => 'ok']]);
        $producer->deliverWith = RD_KAFKA_RESP_ERR__MSG_TIMED_OUT;
        try {
            $writer->publish('t', [['key' => 'k', 'payload' => 'lost']]);
        } catch (WriteFailed) {
        }
        $producer->deliverWith = RD_KAFKA_RESP_ERR_NO_ERROR;

        $writer->publish('t', [['key' => 'k', 'payload' => 'again']]);

        expect(array_column($producer->messages, 'payload'))->toBe(['ok', 'lost', 'again']);
    });

    it('turns a produce exception (queue full, too large) into WriteFailed instead of dropping the row', function () {
        $writer = fakeWriter($producer);
        $writer->publish('t', [['key' => 'k', 'payload' => 'ok']]);
        $producer->produceFails = new Exception('too large', RD_KAFKA_RESP_ERR_MSG_SIZE_TOO_LARGE);

        expect(fn () => $writer->publish('t', [['key' => 'k', 'payload' => 'x']]))->toThrow(WriteFailed::class, 'produce failed');
    });
});

describe('RdKafkaMessageSource', function () {
    function fakeSource(?FakeRdKafkaConsumer &$consumer = null): RdKafkaMessageSource
    {
        $consumer = new FakeRdKafkaConsumer;
        $source = new RdKafkaMessageSource('127.0.0.1:1', 'events', 'identity', fn () => $consumer);
        $source->listen(fn () => null, fn () => null);

        return $source;
    }

    it('subscribes to the events topic', function () {
        fakeSource($consumer);

        expect($consumer->subscribed)->toBe(['events']);
    });

    it('turns a message into a ConsumedMessage and a timeout or end of partition into null', function () {
        $source = fakeSource($consumer);
        $consumer->messages = [
            rdMessage(RD_KAFKA_RESP_ERR_NO_ERROR, 2, 41, '{"a":1}', '1:a'),
            rdMessage(RD_KAFKA_RESP_ERR__PARTITION_EOF),
            rdMessage(RD_KAFKA_RESP_ERR_NO_ERROR, 0, 0, null, null),
        ];

        $first = $source->poll(10);
        $eof = $source->poll(10);
        $empty = $source->poll(10);
        $timeout = $source->poll(10);

        expect([$first->topic, $first->partition, $first->offset, $first->key, $first->payload])->toBe(['events', 2, 41, '1:a', '{"a":1}'])
            ->and($eof)->toBeNull()
            ->and($empty->payload)->toBe('')
            ->and($empty->key)->toBeNull()
            ->and($timeout)->toBeNull();
    });

    it('treats a transient consumer event as nothing to do, and a fatal one as an error', function () {
        $source = fakeSource($consumer);
        $consumer->messages = [rdMessage(RD_KAFKA_RESP_ERR__TRANSPORT), rdMessage(RD_KAFKA_RESP_ERR__FATAL)];

        expect($source->poll(10))->toBeNull()
            ->and(fn () => $source->poll(10))->toThrow(RuntimeException::class, 'fatal');
    });

    it('commits the given offsets per partition on the events topic', function () {
        $source = fakeSource($consumer);

        $source->commit([0 => 8, 2 => 3]);

        expect($consumer->commits)->toBe([[0 => 8, 2 => 3]]);
    });

    it('tells a commit that lost its partition from one that merely failed', function (int $code, bool $lost) {
        $source = fakeSource($consumer);
        $consumer->commitFails = new Exception('nope', $code);

        try {
            $source->commit([0 => 1]);
            $caught = null;
        } catch (CommitFailed $e) {
            $caught = $e;
        }

        expect($caught)->toBeInstanceOf(CommitFailed::class)->and($caught->ownershipLost)->toBe($lost);
    })->with([
        'rebalance in progress' => [RD_KAFKA_RESP_ERR_REBALANCE_IN_PROGRESS, true],
        'unknown member' => [RD_KAFKA_RESP_ERR_UNKNOWN_MEMBER_ID, true],
        'illegal generation' => [RD_KAFKA_RESP_ERR_ILLEGAL_GENERATION, true],
        'assignment lost' => [RD_KAFKA_RESP_ERR__ASSIGNMENT_LOST, true],
        'timed out' => [RD_KAFKA_RESP_ERR__TIMED_OUT, false],
        'coordinator not available' => [RD_KAFKA_RESP_ERR_NOT_COORDINATOR, false],
    ]);

    it('leaves the group on close and refuses to be used before listen()', function () {
        $source = fakeSource($consumer);
        $source->close();

        expect($consumer->closed)->toBeTrue()
            ->and(fn () => (new RdKafkaMessageSource('x', 't', 'g'))->poll(1))->toThrow(LogicException::class);
    });
});
