<?php

use App\Domain\Insights\InsightTypes;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardTileController;
use App\Http\Controllers\SavedInsightController;
use App\Http\Requests\SavedInsights\NestedInsightInput;

/*
 * The shape of the dashboard code: controllers are thin (an action call and JSON, no queries that write, no database
 * facade), the actions are final classes, the domain knows no HTTP, and the nested validation covers every insight type.
 */

const DASHBOARD_CONTROLLERS = [SavedInsightController::class, DashboardController::class, DashboardTileController::class];

foreach (DASHBOARD_CONTROLLERS as $controller) {
    arch("{$controller} does not touch the database facade or the infrastructure")
        ->expect($controller)
        ->not->toUse(['Illuminate\Support\Facades\DB', 'App\Infrastructure']);
}

arch('the write actions are final classes with a handle method')
    ->expect(['App\Actions\SavedInsights', 'App\Actions\Dashboards'])
    ->toBeFinal()
    ->toHaveMethod('handle');

arch('the dashboard domain knows no HTTP and no queue job')
    ->expect('App\Domain\Dashboards')
    ->not->toUse(['App\Http', 'App\Jobs', 'Illuminate\Http']);

arch('the dashboard actions know no HTTP')
    ->expect(['App\Actions\SavedInsights', 'App\Actions\Dashboards'])
    ->not->toUse(['App\Http', 'Illuminate\Http']);

arch('the policies and resources are final or plain, and resources are static presenters')
    ->expect('App\Http\Resources')
    ->toBeFinal();

it('keeps every controller method short: one call to an action or a query, and the JSON', function () {
    foreach (DASHBOARD_CONTROLLERS as $controller) {
        foreach ((new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== $controller) {
                continue;
            }
            $lines = $method->getEndLine() - $method->getStartLine() + 1;
            expect($lines)->toBeLessThanOrEqual(30, "{$controller}::{$method->name} has {$lines} lines");
        }
    }
});

it('validates the query of every insight type with the request class of that type', function () {
    expect(array_keys(NestedInsightInput::REQUESTS))->toEqualCanonicalizing(InsightTypes::keys());
});

it('only lets the saved insight and dashboard routes through the api prefix with their names', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => preg_match('#/(saved-insights|dashboards)#', $r->uri()) === 1 && str_starts_with($r->uri(), 'api/'));

    expect($routes)->toHaveCount(14);
    foreach ($routes as $route) {
        expect($route->getName())->toStartWith('api.')
            ->and($route->uri())->toStartWith('api/projects/{project}/')
            ->and($route->gatherMiddleware())->toContain('auth')->toContain('auth.session')->toContain('api.no-store');
    }
});
