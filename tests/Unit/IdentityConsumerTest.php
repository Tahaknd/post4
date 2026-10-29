<?php

use App\Infrastructure\Kafka\Identity\CommitFailed;
use App\Infrastructure\Kafka\Identity\ConsumerGaveUp;
use App\Infrastructure\Kafka\Identity\Disposition;
use App\Infrastructure\Kafka\Identity\IdentityConsumer;
use App\Infrastructure\Kafka\Identity\Processed;
use App\Infrastructure\Kafka\Identity\RetryableFailure;
use Tests\Support\IdentityHarness as H;

/*
 * The loop of identity:consume against scripted fakes: no Kafka, no Postgres. The ORDER of the fakes' calls (the trace)
 * is what proves "rows first, commit after".
 */

describe('filtering', function () {
    it('skips what is not an identity event without a commit per message: one batched commit when the topic goes idle', function () {
        $h = new H([['assign' => [0]], H::message(4), H::message(5), H::message(6)]);

        $reason = $h->consumer->run();

        expect($reason)->toBe(IdentityConsumer::STOP_IDLE)
            ->and($h->trace->events)->toBe(['poll:0/4', 'process:0/4', 'poll:0/5', 'process:0/5', 'poll:0/6', 'process:0/6', 'commit:0=7', 'close'])
            ->and($h->writer->published)->toBe([])
            ->and($h->consumer->counts())->toMatchArray(['consumed' => 3, 'filtered' => 3, 'commits' => 1]);
    });

    it('does not commit an event without a second id at once either (it never reached the resolver)', function () {
        $h = new H([['assign' => [0]], H::message(0), H::message(1)], [0 => [new Processed(Disposition::NoCounterpart)]]);

        $h->consumer->run();

        expect($h->source->commits)->toBe([[0 => 2]])
            ->and($h->consumer->counts())->toMatchArray(['no_counterpart' => 1, 'filtered' => 1]);
    });

    it('commits lazily once enough skipped messages are waiting, without waiting for idle', function () {
        $script = [['assign' => [0]]];
        foreach (range(0, 599) as $offset) {
            $script[] = H::message($offset);
        }
        $h = new H($script, options: ['maxMessages' => 600]);

        $h->consumer->run();

        // The 500th message triggered one commit; the rest went with the shutdown commit.
        expect($h->source->commits)->toBe([[0 => 500], [0 => 600]]);
    });
});

describe('the order of the effects', function () {
    it('produces the rows, THEN commits, and does both before it reads the next message', function () {
        $h = new H(
            [['assign' => [0]], H::message(5), H::message(6)],
            [5 => [H::applied(Disposition::Created, 2)], 6 => [H::applied(Disposition::Merged, 3)]],
        );

        $h->consumer->run();

        expect($h->trace->events)->toBe([
            'poll:0/5', 'process:0/5', 'publish:person_distinct_ids:2', 'commit:0=6',
            'poll:0/6', 'process:0/6', 'publish:person_distinct_ids:3', 'commit:0=7',
            'close',
        ])->and($h->consumer->counts())->toMatchArray(['created' => 1, 'merged' => 1, 'rows_produced' => 5]);
    });

    it('writes each row as JSON keyed by project and distinct id', function () {
        $h = new H([['assign' => [0]], H::message(5)], [5 => [H::applied(Disposition::Created, 1)]]);

        $h->consumer->run();

        expect($h->writer->published['person_distinct_ids'][0]['key'])->toBe('1:id-1')
            ->and($h->writer->decoded('person_distinct_ids'))->toBe([[
                'project_id' => 1, 'distinct_id' => 'id-1', 'person_id' => '0194c1e2-0000-7000-8000-000000000001',
                'version' => 0, 'is_deleted' => 0, 'created_at' => '2026-10-29 11:59:59.000',
            ]]);
    });

    it('commits an event that reached the resolver but wrote nothing (a noop) at once, with nothing produced', function () {
        $h = new H([['assign' => [0]], H::message(5), H::message(6)], [5 => [new Processed(Disposition::Noop)], 6 => [new Processed(Disposition::Rejected)]]);

        $h->consumer->run();

        expect($h->trace->events)->toBe(['poll:0/5', 'process:0/5', 'commit:0=6', 'poll:0/6', 'process:0/6', 'commit:0=7', 'close'])
            ->and($h->consumer->counts())->toMatchArray(['noop' => 1, 'rejected' => 1]);
    });

    it('commits the skipped messages in front of an identity event together with it', function () {
        $h = new H([['assign' => [0]], H::message(3), H::message(4), H::message(5)], [5 => [H::applied()]]);

        $h->consumer->run();

        expect($h->source->commits[0])->toBe([0 => 6]);
    });

    it('commits one offset per partition', function () {
        $h = new H(
            [['assign' => [0, 1]], H::message(7, 0), H::message(2, 1)],
            [7 => [H::applied()], 2 => [H::applied()]],
        );

        $h->consumer->run();

        expect($h->source->commits)->toBe([[0 => 8], [1 => 3]]);
    });
});

describe('the dead letter queue', function () {
    it('writes a poison message to events_dlq with its origin, then commits, and the next message is still read', function () {
        $poison = new Processed(Disposition::Poison, errorClass: 'JsonException', errorMessage: "Syntax error\nnear alice@example.com");
        $h = new H(
            [['assign' => [1]], H::message(9, 1, '{"event":"$identify" nope', key: '1:alice'), H::message(10, 1)],
            [9 => [$poison], 10 => [H::applied()]],
        );

        $h->consumer->run();

        [$dead] = $h->writer->decoded('events_dlq');

        expect($h->trace->events)->toBe([
            'poll:1/9', 'process:1/9', 'publish:events_dlq:1', 'commit:1=10',
            'poll:1/10', 'process:1/10', 'publish:person_distinct_ids:2', 'commit:1=11', 'close',
        ])
            ->and($dead)->toMatchArray([
                'failed_at' => '2026-10-29 12:00:00.000', 'consumer' => 'identity', 'error_class' => 'JsonException',
                'error' => 'Syntax error near alice@example.com', 'source_topic' => 'events', 'source_partition' => 1,
                'source_offset' => 9, 'source_key' => '1:alice', 'payload' => '{"event":"$identify" nope',
            ])
            ->and($h->writer->published['events_dlq'][0]['key'])->toBe('1:alice')
            ->and($h->consumer->counts())->toMatchArray(['poison' => 1, 'created' => 1]);
    });

    it('logs only coordinates and the error class of a dead-lettered event: no payload, no message, no ids', function () {
        $poison = new Processed(Disposition::Poison, errorClass: 'JsonException', errorMessage: 'secret-error-text');
        $h = new H([['assign' => [0]], H::message(1, 0, '{"event":"$identify","distinct_id":"secret-distinct-id"')], [1 => [$poison]]);

        $h->consumer->run();

        $logged = json_encode($h->log->records);

        expect($h->log->at('warning'))->toHaveCount(1)
            ->and($h->log->at('warning')[0]['context'])->toBe(['topic' => 'events', 'partition' => 0, 'offset' => 1, 'error_class' => 'JsonException'])
            ->and($logged)->not->toContain('secret-distinct-id')->not->toContain('secret-error-text');
    });

    it('retries a failing dead-letter write and never commits before it went through', function () {
        $poison = new Processed(Disposition::Poison, errorClass: 'X', errorMessage: 'y');
        $h = new H([['assign' => [0]], H::message(1)], [1 => [$poison]]);
        $h->writer->failTimes = 2;

        $h->consumer->run();

        expect($h->trace->events)->toBe([
            'poll:0/1', 'process:0/1', 'publish:events_dlq:1', 'publish:events_dlq:1', 'publish:events_dlq:1', 'commit:0=2', 'close',
        ]);
    });
});

describe('retrying without committing', function () {
    it('repeats the SAME message with a growing delay and commits only after it finally worked', function () {
        $h = new H(
            [['assign' => [0]], H::message(5), H::message(6)],
            [5 => [new RetryableFailure('lock timeout'), new RetryableFailure('lock timeout'), H::applied()], 6 => [H::applied()]],
        );

        $h->consumer->run();

        expect($h->sleeps)->toBe([0.5, 1.0])
            ->and($h->trace->events)->toBe([
                'poll:0/5', 'process:0/5', 'process:0/5', 'process:0/5', 'publish:person_distinct_ids:2', 'commit:0=6',
                'poll:0/6', 'process:0/6', 'publish:person_distinct_ids:2', 'commit:0=7', 'close',
            ])
            ->and($h->consumer->counts()['retries'])->toBe(2);
    });

    it('caps the delay of the backoff', function () {
        $h = new H(
            [['assign' => [0]], H::message(5)],
            [5 => [new RetryableFailure('x'), new RetryableFailure('x'), new RetryableFailure('x'), H::applied()]],
            ['maxAttempts' => 5],
        );

        $h->consumer->run();

        expect($h->sleeps)->toBe([0.5, 1.0, 2.0]);
    });

    it('does not run the resolver again when only the produce failed (its result would be a no-op and the rows lost)', function () {
        $h = new H([['assign' => [0]], H::message(5)], [5 => [H::applied()]]);
        $h->writer->failTimes = 2;

        $h->consumer->run();

        expect(count($h->processor->calls))->toBe(1)
            ->and($h->trace->events)->toBe([
                'poll:0/5', 'process:0/5', 'publish:person_distinct_ids:2', 'publish:person_distinct_ids:2', 'publish:person_distinct_ids:2', 'commit:0=6', 'close',
            ]);
    });

    it('gives up after the attempt budget, commits nothing past the failing message and still leaves the group', function () {
        $h = new H(
            [['assign' => [0]], H::message(4), H::message(5)],
            [5 => array_fill(0, 10, new RetryableFailure('database is down'))],
        );

        expect(fn () => $h->consumer->run())->toThrow(ConsumerGaveUp::class);

        // Offset 4 (skipped) is committed, 5 (the failing one) and everything after it are not.
        expect($h->source->commits)->toBe([[0 => 5]])
            ->and($h->source->closed)->toBeTrue()
            ->and($h->log->at('error'))->toHaveCount(1)
            ->and($h->log->at('error')[0]['context'])->toBe(['step' => 'resolve', 'attempts' => 4, 'error_class' => 'RetryableFailure']);
    });

    it('retries a commit that failed for a reason other than a lost partition', function () {
        $h = new H([['assign' => [0]], H::message(5)], [5 => [H::applied()]]);
        $h->source->commitOutcomes = [new CommitFailed('coordinator not available'), null];

        $h->consumer->run();

        expect($h->trace->events)->toBe(['poll:0/5', 'process:0/5', 'publish:person_distinct_ids:2', 'commit-failed', 'commit:0=6', 'close']);
    });

    it('does not retry a commit for a partition that was lost, and goes on with the next message', function () {
        $h = new H([['assign' => [0]], H::message(5), H::message(6)], [5 => [H::applied()], 6 => [H::applied()]]);
        $h->source->commitOutcomes = [new CommitFailed('rebalance in progress', ownershipLost: true)];

        $h->consumer->run();

        expect($h->trace->events)->toBe([
            'poll:0/5', 'process:0/5', 'publish:person_distinct_ids:2', 'commit-failed',
            'poll:0/6', 'process:0/6', 'publish:person_distinct_ids:2', 'commit:0=7', 'close',
        ])->and($h->consumer->counts())->toMatchArray(['commits_lost' => 1]);
    });
});

describe('shutting down', function () {
    it('finishes the message in flight when the stop arrives, commits it, never reads the next one, and reports a signal', function () {
        $h = new H([['assign' => [0]], H::message(3), H::message(4), H::message(5)], [4 => [H::applied()], 5 => [H::applied()]]);
        $h->onProcess = fn ($message) => $message->offset === 4 ? $h->consumer->requestStop() : null;

        $reason = $h->consumer->run();

        expect($reason)->toBe(IdentityConsumer::STOP_SIGNAL)
            ->and($h->trace->events)->toBe([
                'poll:0/3', 'process:0/3', 'poll:0/4', 'process:0/4', 'publish:person_distinct_ids:2', 'commit:0=5', 'close',
            ]);
    });

    it('leaves a message uncommitted when the stop arrives while it waits for its retry', function () {
        $h = new H(
            [['assign' => [0]], H::message(4), H::message(5)],
            [5 => [new RetryableFailure('lock timeout'), H::applied()]],
            ['sleeper' => function (float $seconds) use (&$h): void {
                $h->consumer->requestStop();
            }],
        );

        $reason = $h->consumer->run();

        expect($reason)->toBe(IdentityConsumer::STOP_SIGNAL)
            // The skipped offset 4 is committed on the way out, the message that never finished is not.
            ->and($h->source->commits)->toBe([[0 => 5]])
            ->and($h->source->closed)->toBeTrue()
            ->and($h->writer->published)->toBe([]);
    });
});

describe('rebalances', function () {
    it('flags the first message that reaches the resolver after an assignment as a possible replay, per partition, once', function () {
        $h = new H(
            [['assign' => [0, 1]], H::message(1, 0), H::message(2, 0), H::message(1, 1), H::message(2, 1)],
            [1 => [H::applied(), H::applied()], 2 => [H::applied(), H::applied()]],
        );

        $h->consumer->run();

        // process calls: p0/1, p0/2, p1/1, p1/2 (offsets repeat across partitions, so look at the call list)
        expect(array_map(fn ($c) => [$c['partition'], $c['offset'], $c['reconcile']], $h->processor->calls))
            ->toBe([[0, 1, true], [0, 2, false], [1, 1, true], [1, 2, false]]);
    });

    it('does not use the flag up on messages that never reached the resolver', function () {
        $h = new H(
            [['assign' => [0]], H::message(1), H::message(2), H::message(3)],
            [1 => [new Processed(Disposition::NotIdentity)], 2 => [new Processed(Disposition::NoCounterpart)], 3 => [H::applied()]],
        );

        $h->consumer->run();

        expect(array_column($h->processor->calls, 'reconcile'))->toBe([true, true, true]);
    });

    it('flags again after a new assignment, and a retry of the same message keeps the flag', function () {
        $h = new H(
            [['assign' => [0]], H::message(1), ['revoke' => [0]], ['assign' => [0]], H::message(2)],
            [1 => [new RetryableFailure('x'), H::applied()], 2 => [H::applied()]],
        );

        $h->consumer->run();

        expect(array_column($h->processor->calls, 'reconcile'))->toBe([true, true, true]);
    });

    it('commits what is waiting when its partitions are revoked, before they are taken away', function () {
        $h = new H([['assign' => [0]], H::message(1), H::message(2), ['revoke' => [0]], 'idle']);

        $h->consumer->run();

        expect($h->trace->events)->toBe(['poll:0/1', 'process:0/1', 'poll:0/2', 'process:0/2', 'commit:0=3', 'close']);
    });

    it('forgets the offsets of a revoked partition that could not be committed', function () {
        $h = new H([['assign' => [0]], H::message(1), ['revoke' => [0]]]);
        $h->source->commitOutcomes = [new CommitFailed('lost', ownershipLost: true)];

        $h->consumer->run();

        // Never committed again later: the new owner replays them.
        expect($h->source->commits)->toBe([]);
    });
});

describe('stopping on its own', function () {
    it('stops after max messages', function () {
        $h = new H([['assign' => [0]], H::message(1), H::message(2), H::message(3)], options: ['maxMessages' => 2, 'stopAfterIdleSeconds' => null]);

        expect($h->consumer->run())->toBe(IdentityConsumer::STOP_MAX_MESSAGES)
            ->and($h->consumer->counts()['consumed'])->toBe(2)
            ->and($h->source->commits)->toBe([[0 => 3]]);
    });

    it('stops after the idle time, counted from the assignment, not from the start', function () {
        $h = new H(['idle', 'idle', 'idle', 'idle', 'idle', ['assign' => [0]]], options: ['stopAfterIdleSeconds' => 0.05]);

        // Five idle polls (50 ms) before the assignment do not count; after it 50 ms more of idle end the run.
        expect($h->consumer->run())->toBe(IdentityConsumer::STOP_IDLE)
            ->and($h->now)->toBeGreaterThan(1000.095);
    });

    it('never stops on idle while it has no assignment, but the runtime limit does', function () {
        $h = new H([], options: ['stopAfterIdleSeconds' => 0.02, 'maxRuntimeSeconds' => 0.1]);

        expect($h->consumer->run())->toBe(IdentityConsumer::STOP_MAX_RUNTIME);
    });

    it('exits when its memory grows over the limit, after committing what it has', function () {
        $h = new H([['assign' => [0]], H::message(1), H::message(2)], [1 => [H::applied()]], ['maxMemoryBytes' => 50_000_000]);
        $h->onProcess = function () use ($h) {
            $h->memory = 60_000_000;
        };

        expect($h->consumer->run())->toBe(IdentityConsumer::STOP_MEMORY)
            ->and($h->consumer->counts()['consumed'])->toBe(1)
            ->and($h->source->commits)->toBe([[0 => 2]])
            ->and($h->source->closed)->toBeTrue();
    });

    it('logs one summary line with counts only, at the interval and when it stops', function () {
        $h = new H([['assign' => [0]], H::message(1), H::message(2)], [1 => [H::applied()]], ['summaryIntervalSeconds' => 0.015]);

        $h->consumer->run();

        $summaries = array_values(array_filter($h->log->at('info'), fn ($r) => $r['message'] === 'identity:consume'));

        expect(count($summaries))->toBeGreaterThanOrEqual(2)
            ->and(end($summaries)['context'])->toMatchArray(['state' => 'stopped', 'consumed' => 2, 'created' => 1, 'filtered' => 1, 'rows_produced' => 2])
            ->and(array_keys(end($summaries)['context']))->toContain('memory_mb', 'uptime_seconds');
    });
});
