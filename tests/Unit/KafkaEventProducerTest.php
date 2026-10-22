<?php

use App\Domain\Capture\EventProducer;
use App\Domain\Capture\ProducerUnavailable;
use App\Infrastructure\Kafka\KafkaEventProducer;

function kafkaMessage(string $properties = '{}'): array
{
    return [
        'uuid' => '0197c3a2-1111-7222-8333-444455556666',
        'project_id' => 1,
        'event' => 'e',
        'distinct_id' => 'u',
        'properties' => $properties,
        'timestamp' => '2026-10-22 12:00:00.000',
    ];
}

/** Runs $call and returns [elapsed milliseconds, exception or null]. */
function timedProduce(KafkaEventProducer $producer, array $messages): array
{
    $start = hrtime(true);
    $error = null;

    try {
        $producer->produce($messages);
    } catch (Throwable $e) {
        $error = $e;
    }

    return [(hrtime(true) - $start) / 1e6, $error];
}

it('fails fast on a dead broker, then opens the circuit', function () {
    $producer = new KafkaEventProducer('127.0.0.1:1', 'review_dead_topic', messageTimeoutMs: 200, flushTimeoutMs: 300, breakerCooldownSeconds: 5.0);

    [$first, $firstError] = timedProduce($producer, [kafkaMessage()]);
    [$second, $secondError] = timedProduce($producer, [kafkaMessage()]);

    expect($firstError)->toBeInstanceOf(ProducerUnavailable::class)
        ->and($firstError->getMessage())->toBe('Kafka delivery failed')
        ->and($first)->toBeGreaterThan(190)->toBeLessThan(1500)
        ->and($secondError)->toBeInstanceOf(ProducerUnavailable::class)
        ->and($secondError->getMessage())->toBe('Kafka circuit open')
        ->and($second)->toBeLessThan(50);
});

it('probes again once the cooldown has passed and re-opens on failure', function () {
    $producer = new KafkaEventProducer('127.0.0.1:1', 'review_dead_topic', messageTimeoutMs: 200, flushTimeoutMs: 300, breakerCooldownSeconds: 0.3);

    [, $first] = timedProduce($producer, [kafkaMessage()]);
    usleep(400_000);
    [$probe, $probeError] = timedProduce($producer, [kafkaMessage()]);
    [$after, $afterError] = timedProduce($producer, [kafkaMessage()]);

    expect($first)->toBeInstanceOf(ProducerUnavailable::class)
        ->and($probeError)->toBeInstanceOf(ProducerUnavailable::class)
        ->and($probeError->getMessage())->toBe('Kafka delivery failed')
        ->and($probe)->toBeGreaterThan(100)
        ->and($afterError->getMessage())->toBe('Kafka circuit open')
        ->and($after)->toBeLessThan(50);
});

it('skips an oversized message without tripping the circuit breaker', function () {
    $producer = new KafkaEventProducer('127.0.0.1:1', 'review_dead_topic', messageTimeoutMs: 200, flushTimeoutMs: 300, breakerCooldownSeconds: 5.0);
    $oversized = kafkaMessage(json_encode(['a' => str_repeat('x', 1_500_000)]));

    [$oversizedTime, $oversizedError] = timedProduce($producer, [$oversized]);
    [, $normalError] = timedProduce($producer, [kafkaMessage()]);
    [, $thirdError] = timedProduce($producer, [kafkaMessage()]);

    expect($oversizedError)->toBeNull()
        ->and($oversizedTime)->toBeLessThan(200)
        ->and($normalError)->toBeInstanceOf(ProducerUnavailable::class)
        ->and($normalError->getMessage())->toBe('Kafka delivery failed')
        ->and($thirdError->getMessage())->toBe('Kafka circuit open');
});

it('keeps producing the rest of a batch around an oversized message', function () {
    $producer = new KafkaEventProducer('127.0.0.1:1', 'review_dead_topic', messageTimeoutMs: 200, flushTimeoutMs: 300, breakerCooldownSeconds: 5.0);
    $oversized = kafkaMessage(json_encode(['a' => str_repeat('x', 1_500_000)]));

    // The normal message after the oversized one is still sent (and fails on the dead broker).
    [, $error] = timedProduce($producer, [$oversized, kafkaMessage()]);

    expect($error)->toBeInstanceOf(ProducerUnavailable::class)
        ->and($error->getMessage())->toBe('Kafka delivery failed');
});

it('returns for an empty batch without a handle, an error or a circuit check', function () {
    $producer = new KafkaEventProducer('127.0.0.1:1', 'review_dead_topic', messageTimeoutMs: 200, flushTimeoutMs: 300, breakerCooldownSeconds: 5.0);

    $handle = new ReflectionProperty($producer, 'producer');

    [$empty, $emptyError] = timedProduce($producer, []);
    $handleAfterEmpty = $handle->getValue($producer);
    [, $failure] = timedProduce($producer, [kafkaMessage()]);
    [, $stillEmptyError] = timedProduce($producer, []);

    expect($emptyError)->toBeNull()
        ->and($handleAfterEmpty)->toBeNull()
        ->and($empty)->toBeLessThan(50)
        ->and($failure)->toBeInstanceOf(ProducerUnavailable::class)
        ->and($stillEmptyError)->toBeNull();
});

it('does not trip the breaker for a message that cannot be json encoded', function () {
    $producer = new KafkaEventProducer('127.0.0.1:1', 'review_dead_topic', messageTimeoutMs: 200, flushTimeoutMs: 300, breakerCooldownSeconds: 5.0);

    [, $error] = timedProduce($producer, [kafkaMessage(), ['bad' => "\xB1\x31"] + kafkaMessage()]);
    [, $next] = timedProduce($producer, [kafkaMessage()]);

    expect($error)->toBeInstanceOf(JsonException::class)
        ->and($next->getMessage())->toBe('Kafka delivery failed');
});

it('keeps the producer in the octane warm list so it is one instance per worker', function () {
    expect(config('octane.warm'))->toContain(EventProducer::class);
});

it('wires the producer timeouts and breaker cooldown from config', function () {
    expect(config('capture.kafka_message_timeout_ms'))->toBe(2500)
        ->and(config('capture.kafka_flush_timeout_ms'))->toBe(3000)
        ->and(config('capture.kafka_breaker_cooldown_seconds'))->toBe(10.0)
        ->and(app(EventProducer::class))->toBeInstanceOf(KafkaEventProducer::class);
});
