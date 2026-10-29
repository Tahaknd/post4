<?php

use App\Domain\Capture\DlqDepthProbe;
use App\Domain\Capture\IdentityLagProbe;
use App\Domain\Capture\KafkaLagProbe;
use App\Domain\Capture\PersonLagProbe;
use App\Infrastructure\Kafka\RdKafkaIdentityLagProbe;
use App\Infrastructure\Kafka\RdKafkaLagProbe;
use App\Infrastructure\Kafka\RdKafkaPersonLagProbe;
use App\Infrastructure\Kafka\RdKafkaTopicDepthProbe;
use RdKafka\Exception;

it('fails with an exception, quickly, when the brokers cannot be reached', function () {
    $probe = new RdKafkaLagProbe('127.0.0.1:1', 'events', 'clickhouse_events', timeoutMs: 500);
    $start = hrtime(true);

    expect($probe)->toBeInstanceOf(KafkaLagProbe::class)
        ->and(fn () => $probe->lag())->toThrow(Exception::class);

    // Generous bound: it only proves the call is bounded by the timeout, not hanging for librdkafka's defaults.
    expect((hrtime(true) - $start) / 1e9)->toBeLessThan(10.0);
});

it('is the binding behind the KafkaLagProbe interface', function () {
    expect(app(KafkaLagProbe::class))->toBeInstanceOf(RdKafkaLagProbe::class);
});

it('has its own probe for the identity group, under its own type', function () {
    expect(app(IdentityLagProbe::class))->toBeInstanceOf(RdKafkaIdentityLagProbe::class)
        ->not->toBe(app(KafkaLagProbe::class));
});

it('has its own probe for ClickHouse\'s group on the person_distinct_ids topic, never the events one', function () {
    $probe = app(PersonLagProbe::class);
    $inner = (new ReflectionProperty(RdKafkaPersonLagProbe::class, 'probe'))->getValue($probe);
    $read = fn (string $name) => (new ReflectionProperty(RdKafkaLagProbe::class, $name))->getValue($inner);

    expect($probe)->toBeInstanceOf(RdKafkaPersonLagProbe::class)->not->toBe(app(KafkaLagProbe::class))->not->toBe(app(IdentityLagProbe::class))
        ->and($read('group'))->toBe('clickhouse_person_distinct_ids')->and($read('topic'))->toBe('person_distinct_ids');
});

it('fails with an exception, quickly, when the brokers cannot be reached for the dead letter depth', function () {
    $probe = new RdKafkaTopicDepthProbe('127.0.0.1:1', 'events_dlq', timeoutMs: 500);
    $start = hrtime(true);

    expect($probe)->toBeInstanceOf(DlqDepthProbe::class)
        ->and(fn () => $probe->depth())->toThrow(Exception::class)
        ->and(app(DlqDepthProbe::class))->toBeInstanceOf(RdKafkaTopicDepthProbe::class);
    expect((hrtime(true) - $start) / 1e9)->toBeLessThan(10.0);
});
