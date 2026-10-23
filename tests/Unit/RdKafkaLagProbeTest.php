<?php

use App\Domain\Capture\KafkaLagProbe;
use App\Infrastructure\Kafka\RdKafkaLagProbe;

it('fails with an exception, quickly, when the brokers cannot be reached', function () {
    $probe = new RdKafkaLagProbe('127.0.0.1:1', 'events', 'clickhouse_events', timeoutMs: 500);
    $start = hrtime(true);

    expect($probe)->toBeInstanceOf(KafkaLagProbe::class)
        ->and(fn () => $probe->lag())->toThrow(RdKafka\Exception::class);

    // Generous bound: it only proves the call is bounded by the timeout, not hanging for librdkafka's defaults.
    expect((hrtime(true) - $start) / 1e9)->toBeLessThan(10.0);
});

it('is the binding behind the KafkaLagProbe interface', function () {
    expect(app(KafkaLagProbe::class))->toBeInstanceOf(RdKafkaLagProbe::class);
});
