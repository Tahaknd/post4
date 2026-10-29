<?php

use App\Infrastructure\Kafka\Identity\MessageSource;
use App\Infrastructure\Kafka\Identity\RowWriter;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\IdentityHarness as H;
use Tests\Support\RecordingRowWriter;
use Tests\Support\ScriptedMessageSource;
use Tests\Support\Trace;

/*
 * The artisan command around the loop, with the Kafka source and sink replaced. Events that need the resolver (and so
 * Postgres) are covered by the Integration suite and the kafka-identity group; here only what never reaches it.
 */

beforeEach(function () {
    $this->trace = new Trace;
    $this->writer = new RecordingRowWriter($this->trace);
    $this->groups = [];
    $this->script = [['assign' => [0]]];

    $this->app->bind(MessageSource::class, function ($app, array $parameters) {
        $this->groups[] = $parameters['group'] ?? null;

        return new ScriptedMessageSource($this->script, $this->trace);
    });
    $this->app->instance(RowWriter::class, $this->writer);
});

it('runs until its limit, skips what is not an identity event, dead-letters what cannot be parsed, and exits 0', function () {
    $this->script[] = H::message(0, 0, '{"event":"$pageview","distinct_id":"a"}');
    $this->script[] = H::message(1, 0, '{"event":"$identify"');

    $code = Artisan::call('identity:consume', ['--max-messages' => 2, '--max-memory-mb' => 100000]);
    $output = Artisan::output();

    $dead = $this->writer->decoded('events_dlq');

    expect($code)->toBe(0)
        ->and($output)->toContain('identity:consume stopped (max_messages)')->toContain('"filtered":1')->toContain('"poison":1')
        ->and($dead)->toHaveCount(1)
        ->and($dead[0])->toMatchArray(['error_class' => 'JsonException', 'source_topic' => 'events', 'source_offset' => 1, 'payload' => '{"event":"$identify"'])
        ->and($this->trace->events)->toContain('commit:0=2');
});

it('uses the configured group, and --group replays under another one', function () {
    $this->artisan('identity:consume', ['--max-runtime' => 1, '--max-memory-mb' => 100000])->assertExitCode(0);
    config(['identity.group' => 'identity-v2']);
    $this->artisan('identity:consume', ['--max-runtime' => 1, '--max-memory-mb' => 100000])->assertExitCode(0);
    $this->artisan('identity:consume', ['--max-runtime' => 1, '--max-memory-mb' => 100000, '--group' => 'replay-1'])->assertExitCode(0);

    expect($this->groups)->toBe(['identity', 'identity-v2', 'replay-1']);
});

it('refuses limits that are not positive integers', function (string $option, string $value) {
    $this->artisan('identity:consume', [$option => $value])
        ->expectsOutputToContain("{$option} must be a positive integer")
        ->assertExitCode(2);

    expect($this->groups)->toBe([]);
})->with([
    ['--max-messages', '0'], ['--max-messages', 'ten'], ['--stop-after-idle', '-1'], ['--max-runtime', '1.5'],
    ['--max-memory-mb', '0'], ['--summary-interval', 'x'],
]);
