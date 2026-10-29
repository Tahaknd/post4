<?php

namespace App\Http\Controllers;

use App\Domain\Insights\Events\EventNamesSqlBuilder;
use App\Domain\Insights\Funnel\ConversionWindow;
use App\Domain\Insights\Funnel\FunnelOrder;
use App\Domain\Insights\Funnel\FunnelQuery;
use App\Domain\Insights\Funnel\FunnelStep;
use App\Domain\Insights\Funnel\WindowUnit;
use App\Domain\Insights\Query\DatePreset;
use App\Domain\Insights\Query\DateRange;
use App\Domain\Insights\Query\EventCondition;
use App\Domain\Insights\Query\EventName;
use App\Domain\Insights\Query\FilterOperator;
use App\Domain\Insights\Query\PropertyFilter;
use App\Domain\Insights\Retention\RetentionPeriod;
use App\Domain\Insights\Retention\RetentionQuery;
use App\Domain\Insights\Retention\RetentionType;
use App\Domain\Insights\Trends\Breakdown;
use App\Domain\Insights\Trends\BreakdownColumn;
use App\Domain\Insights\Trends\Interval;
use App\Domain\Insights\Trends\Metric;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Models\Project;
use DateTimeZone;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The insight pages: Trends (`GET /projects/{project}/insights/trends`), Funnel (`GET /projects/{project}/insights/funnel`) and
 * Retention (`GET /projects/{project}/insights/retention`).
 * `{project}` is bound through Project::visibleTo(user)
 * (a project the user cannot open is a 404) and the policy `view` is the independent second check.
 *
 * The page does not carry data, it carries what the client needs to build a definition and talk to the JSON API:
 * the allowed values (straight from the domain enums, so a new case shows up here without a UI change), the limits
 * (domain constants, mirrored by the client only for instant feedback; the API stays the source of truth), the
 * defaults, and the API URLs built here with route(). There is no API key: the page talks to the API with the
 * session cookie. The run URL (poll and cancel) comes from the `poll_url` of the 202 answer; `urls.runs` is the base the client
 * checks it against. The Funnel page carries the same kinds of props for its own definition (its steps, window and order), the
 * Retention page for its own (the two events, the periods, the retention type and the time zone list the API validates against).
 */
class ProjectInsightsController extends Controller
{
    public function trends(Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('Projects/Insights/Trends', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'timezone' => $project->timezone,
            ],
            'options' => [
                'presets' => array_map(fn (DatePreset $preset) => ['value' => $preset->value, 'label' => self::presetLabel($preset)], DatePreset::cases()),
                'intervals' => array_map(fn (Interval $interval) => ['value' => $interval->value, 'label' => ucfirst($interval->value)], Interval::cases()),
                'metrics' => array_map(fn (Metric $metric) => ['value' => $metric->value, 'label' => self::metricLabel($metric)], Metric::cases()),
                'breakdown_columns' => array_map(fn (BreakdownColumn $column) => ['value' => $column->value, 'label' => $column->label()], BreakdownColumn::cases()),
                'operators' => array_map(fn (FilterOperator $operator) => ['value' => $operator->value, 'label' => self::operatorLabel($operator), 'needs_value' => $operator->needsValue()], FilterOperator::cases()),
            ],
            'defaults' => [
                'date_preset' => DatePreset::Last30Days->value,
                'interval' => Interval::Day->value,
                'metric' => Metric::TotalEvents->value,
                'top_n' => Breakdown::DEFAULT_TOP_N,
            ],
            'limits' => [
                'max_events' => TrendsQuery::MAX_SERIES,
                'max_event_length' => TrendsQuery::MAX_EVENT_LENGTH,
                'max_filters' => TrendsQuery::MAX_FILTERS,
                'max_filter_key_length' => PropertyFilter::MAX_KEY_LENGTH,
                'max_filter_value_length' => PropertyFilter::MAX_VALUE_LENGTH,
                'max_breakdown_key_length' => Breakdown::MAX_KEY_LENGTH,
                'max_top_n' => Breakdown::MAX_TOP_N,
                'max_range_days' => DateRange::MAX_DAYS,
                'max_rows' => TrendsQuery::MAX_ROWS,
                'max_search_length' => EventNamesSqlBuilder::MAX_SEARCH_LENGTH,
                'event_window_days' => EventNamesSqlBuilder::WINDOW_DAYS,
            ],
            'urls' => [
                'trends' => route('api.insights.trends', $project, absolute: false),
                // The base of every run URL (`…/runs/`): the client accepts a poll URL only when it is this plus a run id.
                'runs' => self::runsBase($project),
                'events' => route('api.insights.events', $project, absolute: false),
                'project' => route('projects.show', $project, absolute: false),
            ],
        ]);
    }

    public function funnel(Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('Projects/Insights/Funnel', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'timezone' => $project->timezone,
            ],
            'options' => [
                'presets' => array_map(fn (DatePreset $preset) => ['value' => $preset->value, 'label' => self::presetLabel($preset)], DatePreset::cases()),
                'operators' => array_map(fn (FilterOperator $operator) => ['value' => $operator->value, 'label' => self::operatorLabel($operator), 'needs_value' => $operator->needsValue()], FilterOperator::cases()),
                'window_units' => array_map(fn (WindowUnit $unit) => ['value' => $unit->value, 'label' => ucfirst($unit->value).'s', 'seconds' => $unit->seconds()], WindowUnit::cases()),
                'orders' => array_map(fn (FunnelOrder $order) => ['value' => $order->value, 'label' => self::orderLabel($order)], FunnelOrder::cases()),
            ],
            'defaults' => [
                'date_preset' => DatePreset::Last30Days->value,
                'window' => ConversionWindow::default()->canonical(),
                'order' => FunnelOrder::Ordered->value,
            ],
            'limits' => [
                'min_steps' => FunnelQuery::MIN_STEPS,
                'max_steps' => FunnelQuery::MAX_STEPS,
                'max_event_length' => EventName::MAX_LENGTH,
                'max_filters_per_step' => FunnelStep::MAX_FILTERS,
                'max_filters' => FunnelQuery::MAX_FILTERS,
                'max_filter_key_length' => PropertyFilter::MAX_KEY_LENGTH,
                'max_filter_value_length' => PropertyFilter::MAX_VALUE_LENGTH,
                'max_range_days' => DateRange::MAX_DAYS,
                'min_window_seconds' => ConversionWindow::MIN_SECONDS,
                'max_window_seconds' => ConversionWindow::MAX_SECONDS,
                'max_window_value' => ConversionWindow::MAX_VALUE,
                'max_search_length' => EventNamesSqlBuilder::MAX_SEARCH_LENGTH,
                'event_window_days' => EventNamesSqlBuilder::WINDOW_DAYS,
            ],
            'urls' => [
                'funnel' => route('api.insights.funnel', $project, absolute: false),
                'runs' => self::runsBase($project),
                'events' => route('api.insights.events', $project, absolute: false),
                'project' => route('projects.show', $project, absolute: false),
            ],
        ]);
    }

    public function retention(Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('Projects/Insights/Retention', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'timezone' => $project->timezone,
            ],
            'options' => [
                'presets' => array_map(fn (DatePreset $preset) => ['value' => $preset->value, 'label' => self::presetLabel($preset)], DatePreset::cases()),
                'operators' => array_map(fn (FilterOperator $operator) => ['value' => $operator->value, 'label' => self::operatorLabel($operator), 'needs_value' => $operator->needsValue()], FilterOperator::cases()),
                'periods' => array_map(fn (RetentionPeriod $period) => ['value' => $period->value, 'label' => ucfirst($period->value)], RetentionPeriod::cases()),
                'retention_types' => array_map(fn (RetentionType $type) => ['value' => $type->value, 'label' => self::retentionTypeLabel($type)], RetentionType::cases()),
                // The identifiers the API accepts for `timezone` (the same list the definition validates against).
                'timezones' => DateTimeZone::listIdentifiers(),
            ],
            'defaults' => [
                'date_preset' => DatePreset::Last30Days->value,
                'period' => RetentionPeriod::Day->value,
                'periods' => RetentionQuery::DEFAULT_PERIODS,
                'retention_type' => RetentionType::FirstTime->value,
                'timezone' => $project->timezone,
            ],
            'limits' => [
                'min_periods' => RetentionQuery::MIN_PERIODS,
                'max_periods' => RetentionQuery::MAX_PERIODS,
                'max_event_length' => EventName::MAX_LENGTH,
                'max_filters_per_event' => EventCondition::MAX_FILTERS,
                'max_filter_key_length' => PropertyFilter::MAX_KEY_LENGTH,
                'max_filter_value_length' => PropertyFilter::MAX_VALUE_LENGTH,
                'max_range_days' => DateRange::MAX_DAYS,
                'max_search_length' => EventNamesSqlBuilder::MAX_SEARCH_LENGTH,
                'event_window_days' => EventNamesSqlBuilder::WINDOW_DAYS,
            ],
            'urls' => [
                'retention' => route('api.insights.retention', $project, absolute: false),
                'runs' => self::runsBase($project),
                'events' => route('api.insights.events', $project, absolute: false),
                'project' => route('projects.show', $project, absolute: false),
            ],
        ]);
    }

    /** `/api/projects/{id}/insights/runs/`, taken from the route itself (a run id placeholder is cut off again). */
    private static function runsBase(Project $project): string
    {
        $placeholder = str_repeat('0', 32);

        return str_replace($placeholder, '', route('api.insights.runs.show', ['project' => $project, 'run' => $placeholder], absolute: false));
    }

    private static function presetLabel(DatePreset $preset): string
    {
        return match ($preset) {
            DatePreset::Last7Days => 'Last 7 days',
            DatePreset::Last30Days => 'Last 30 days',
            DatePreset::Last90Days => 'Last 90 days',
            DatePreset::ThisMonth => 'This month',
            DatePreset::LastMonth => 'Last month',
        };
    }

    private static function metricLabel(Metric $metric): string
    {
        return match ($metric) {
            Metric::TotalEvents => 'Total events',
            Metric::UniqueUsers => 'Unique users',
        };
    }

    private static function orderLabel(FunnelOrder $order): string
    {
        return match ($order) {
            FunnelOrder::Ordered => 'Ordered',
            FunnelOrder::StrictOrder => 'Strict order',
        };
    }

    private static function retentionTypeLabel(RetentionType $type): string
    {
        return match ($type) {
            RetentionType::FirstTime => 'First time',
            RetentionType::Recurring => 'Recurring',
        };
    }

    private static function operatorLabel(FilterOperator $operator): string
    {
        return match ($operator) {
            FilterOperator::Equals => 'equals',
            FilterOperator::NotEquals => 'does not equal',
            FilterOperator::Contains => 'contains',
            FilterOperator::IsSet => 'is set',
        };
    }
}
