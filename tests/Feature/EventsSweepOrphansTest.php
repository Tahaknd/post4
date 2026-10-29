<?php

use App\Infrastructure\ClickHouse\ClickHouseClient;
use App\Infrastructure\ClickHouse\EventProjectIds;
use App\Infrastructure\ClickHouse\PersonDistinctIdProjectIds;
use App\Jobs\PurgeProjectEvents;
use App\Jobs\PurgeProjectPersonDistinctIds;
use App\Models\Project;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** An EventProjectIds that answers with a fixed list instead of asking ClickHouse. */
function fakeEventProjects(array $ids): void
{
    app()->instance(EventProjectIds::class, new class($ids) extends EventProjectIds
    {
        public function __construct(private readonly array $ids) {}

        public function all(): array
        {
            return $this->ids;
        }
    });
}

/** A PersonDistinctIdProjectIds that answers with a fixed list instead of asking ClickHouse. */
function fakePersonProjects(array $ids): void
{
    app()->instance(PersonDistinctIdProjectIds::class, new class($ids) extends PersonDistinctIdProjectIds
    {
        public function __construct(private readonly array $ids) {}

        public function all(): array
        {
            return $this->ids;
        }
    });
}

beforeEach(function () {
    Queue::fake();
    fakePersonProjects([]);
});

it('queues a purge for each project id with events and no project row, and leaves live projects alone', function () {
    $live = Project::factory()->create();
    fakeEventProjects([$live->id, 990601, 990602]);

    expect(Artisan::call('events:sweep-orphans'))->toBe(0);

    expect(Artisan::output())->toContain('990601, 990602');
    Queue::assertPushed(PurgeProjectEvents::class, 2);
    Queue::assertPushed(PurgeProjectEvents::class, fn (PurgeProjectEvents $job) => $job->projectId === 990601 && $job->pass === 1 && $job->queue === 'maintenance');
    Queue::assertPushed(PurgeProjectEvents::class, fn (PurgeProjectEvents $job) => $job->projectId === 990602);
    Queue::assertNotPushed(PurgeProjectEvents::class, fn (PurgeProjectEvents $job) => $job->projectId === $live->id);
});

it('lists the orphans without queueing anything on --dry-run', function () {
    fakeEventProjects([990601]);

    expect(Artisan::call('events:sweep-orphans', ['--dry-run' => true]))->toBe(0);

    expect(Artisan::output())->toContain('dry run')->toContain('990601');
    Queue::assertNothingPushed();
});

it('does nothing when every project id in ClickHouse has a project', function () {
    $a = Project::factory()->create();
    $b = Project::factory()->create();
    fakeEventProjects([$a->id, $b->id]);

    expect(Artisan::call('events:sweep-orphans'))->toBe(0);

    expect(Artisan::output())->toContain('No orphaned project ids');
    Queue::assertNothingPushed();
});

it('ignores the id 0 and sweeps many ids in chunks', function () {
    $live = Project::factory()->count(3)->create()->pluck('id')->all();
    fakeEventProjects([0, ...$live, ...range(2_000_001, 2_001_500)]);

    Artisan::call('events:sweep-orphans');

    Queue::assertPushed(PurgeProjectEvents::class, 1500);
    Queue::assertNotPushed(PurgeProjectEvents::class, fn (PurgeProjectEvents $job) => in_array($job->projectId, [0, ...$live], true));
});

it('reads the distinct project ids with the admin client under a time limit', function () {
    Http::fake(['*' => Http::response(json_encode(['data' => [['project_id' => '5'], ['project_id' => 7]]]))]);

    $ids = (new EventProjectIds(new ClickHouseClient('http://ch:8123', 'posthog', 'u', 'p')))->all();

    expect($ids)->toBe([5, 7]);
    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->body(), 'SELECT DISTINCT project_id FROM posthog.events') && $query['max_execution_time'] === '20';
    });
});

it('is scheduled daily without overlapping and warns about restoring Postgres in its help', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'events:sweep-orphans'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 3 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and(Artisan::all()['events:sweep-orphans']->getHelp())->toContain('restoring or resetting Postgres')->toContain('UNSAFE');
});

it('queues a person_distinct_ids purge for each orphaned project id of that table, independently of events', function () {
    $live = Project::factory()->create();
    fakeEventProjects([990601]);
    fakePersonProjects([$live->id, 990601, 990603]);

    expect(Artisan::call('events:sweep-orphans'))->toBe(0);

    Queue::assertPushed(PurgeProjectEvents::class, 1);
    Queue::assertPushed(PurgeProjectPersonDistinctIds::class, 2);
    Queue::assertPushed(PurgeProjectPersonDistinctIds::class, fn (PurgeProjectPersonDistinctIds $job) => $job->projectId === 990601 && $job->pass === 1 && $job->queue === 'maintenance');
    Queue::assertPushed(PurgeProjectPersonDistinctIds::class, fn (PurgeProjectPersonDistinctIds $job) => $job->projectId === 990603);
    Queue::assertNotPushed(PurgeProjectPersonDistinctIds::class, fn (PurgeProjectPersonDistinctIds $job) => $job->projectId === $live->id);
    expect(Artisan::output())->toContain('990601, 990603')->toContain('person_distinct_ids: 990601, 990603')->toContain('events: 990601');
});

it('finds orphans that only the person table has, and lists them on --dry-run without queueing', function () {
    fakeEventProjects([]);
    fakePersonProjects([990701]);

    expect(Artisan::call('events:sweep-orphans', ['--dry-run' => true]))->toBe(0);

    expect(Artisan::output())->toContain('dry run')->toContain('990701')->toContain('events: none');
    Queue::assertNothingPushed();
});

it('ignores the id 0 and sweeps many person ids in chunks', function () {
    $live = Project::factory()->count(2)->create()->pluck('id')->all();
    fakeEventProjects([]);
    fakePersonProjects([0, ...$live, ...range(2_000_001, 2_001_200)]);

    Artisan::call('events:sweep-orphans');

    Queue::assertPushed(PurgeProjectPersonDistinctIds::class, 1200);
    Queue::assertNotPushed(PurgeProjectPersonDistinctIds::class, fn (PurgeProjectPersonDistinctIds $job) => in_array($job->projectId, [0, ...$live], true));
});

it('reads the distinct project ids of person_distinct_ids with the admin client under a time limit', function () {
    Http::fake(['*' => Http::response(json_encode(['data' => [['project_id' => '5'], ['project_id' => 9]]]))]);

    $ids = (new PersonDistinctIdProjectIds(new ClickHouseClient('http://ch:8123', 'posthog', 'u', 'p')))->all();

    expect($ids)->toBe([5, 9]);
    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->body(), 'SELECT DISTINCT project_id FROM posthog.person_distinct_ids') && $query['max_execution_time'] === '20';
    });
});
