<?php

use App\Domain\Capture\EventProducer;
use App\Infrastructure\Kafka\KafkaEventProducer;

it('shares one event producer instance between resolutions', function () {
    expect($this->app->isShared(EventProducer::class))->toBeTrue()
        ->and(app(EventProducer::class))->toBeInstanceOf(KafkaEventProducer::class)
        ->and(app(EventProducer::class))->toBe(app(EventProducer::class));
});
