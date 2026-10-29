<?php

use App\Domain\Insights\InsightQuery;
use App\Domain\Insights\InsightResult;
use App\Domain\Insights\InsightRunner;
use App\Domain\Insights\InsightSqlBuilder;
use App\Jobs\RunInsight;

/*
 * The shape of the insight code: the shared vocabulary (Query, Sql) knows no type, the types know each other not at all,
 * and the types know nothing of the run machinery, the queue or HTTP. A new type is a new namespace beside these.
 */

arch('the shared query vocabulary knows no insight type')
    ->expect('App\Domain\Insights\Query')
    ->not->toUse(['App\Domain\Insights\Trends', 'App\Domain\Insights\Funnel']);

arch('the shared SQL helpers know no insight type')
    ->expect('App\Domain\Insights\Sql')
    ->not->toUse(['App\Domain\Insights\Trends', 'App\Domain\Insights\Funnel']);

arch('trends and funnels do not know each other')
    ->expect('App\Domain\Insights\Trends')
    ->not->toUse('App\Domain\Insights\Funnel');

arch('funnels do not know trends')
    ->expect('App\Domain\Insights\Funnel')
    ->not->toUse('App\Domain\Insights\Trends');

// One expectation per type: Pest's `not->toUse` over a LIST of subjects passes when any one of them is clean.
foreach (['Trends', 'Funnel'] as $type) {
    arch("the {$type} insight knows nothing of runs, jobs, HTTP or the ClickHouse clients")
        ->expect("App\\Domain\\Insights\\{$type}")
        ->not->toUse(['App\\Domain\\Insights\\Runs', 'App\\Jobs', 'App\\Http', 'App\\Infrastructure\\ClickHouse\\ProjectScopedReader', 'App\\Infrastructure\\ClickHouse\\ClickHouseClient', 'App\\Infrastructure\\ClickHouse\\ReadOnlyClickHouseClient']);
}

arch('the run machinery is generic: it names no insight type')
    ->expect('App\Domain\Insights\Runs')
    ->not->toUse(['App\Domain\Insights\Trends', 'App\Domain\Insights\Funnel']);

foreach ([InsightRunner::class, RunInsight::class] as $generic) {
    arch("{$generic} names no SQL builder of an insight type: it goes through the registry")
        ->expect($generic)
        ->not->toUse(['App\\Domain\\Insights\\Funnel\\FunnelSqlBuilder', 'App\\Domain\\Insights\\Trends\\TrendsSqlBuilder']);
}

arch('the insight contracts are interfaces')
    ->expect([InsightQuery::class, InsightResult::class, InsightSqlBuilder::class])
    ->toBeInterfaces();

it('keeps every insight query immutable and every builder stateless', function () {
    $queries = array_filter(get_declared_classes(), fn (string $c) => is_subclass_of($c, InsightQuery::class) && ! (new ReflectionClass($c))->isAnonymous());
    $builders = array_filter(get_declared_classes(), fn (string $c) => is_subclass_of($c, InsightSqlBuilder::class));

    foreach ($queries as $class) {
        expect((new ReflectionClass($class))->isReadOnly())->toBeTrue("{$class} must be a readonly class")
            ->and((new ReflectionClass($class))->isFinal())->toBeTrue("{$class} must be final");
    }
    foreach ($builders as $class) {
        expect((new ReflectionClass($class))->getProperties())->toBe([], "{$class} must hold no state");
    }
    expect($queries)->not->toBe([])->and($builders)->not->toBe([]);
});
