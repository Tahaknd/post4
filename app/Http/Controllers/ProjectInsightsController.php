<?php

namespace App\Http\Controllers;

use App\Domain\Insights\Events\EventNamesSqlBuilder;
use App\Domain\Insights\Trends\Breakdown;
use App\Domain\Insights\Trends\BreakdownColumn;
use App\Domain\Insights\Trends\DatePreset;
use App\Domain\Insights\Trends\DateRange;
use App\Domain\Insights\Trends\FilterOperator;
use App\Domain\Insights\Trends\Interval;
use App\Domain\Insights\Trends\Metric;
use App\Domain\Insights\Trends\PropertyFilter;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Trends page: `GET /projects/{project}/insights/trends`. `{project}` is bound through Project::visibleTo(user)
 * (a project the user cannot open is a 404) and the policy `view` is the independent second check.
 *
 * The page does not carry data, it carries what the client needs to build a definition and talk to the JSON API:
 * the allowed values (straight from the domain enums, so a new case shows up here without a UI change), the limits
 * (domain constants, mirrored by the client only for instant feedback; the API stays the source of truth), the
 * defaults, and the API URLs built here with route(). There is no API key: the page talks to the API with the
 * session cookie. The run URL (poll and cancel) comes from the `poll_url` of the 202 answer, not from a template.
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
                'breakdown_columns' => array_map(fn (BreakdownColumn $column) => ['value' => $column->value, 'label' => self::columnLabel($column)], BreakdownColumn::cases()),
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
                'events' => route('api.insights.events', $project, absolute: false),
                'project' => route('projects.show', $project, absolute: false),
            ],
        ]);
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

    private static function columnLabel(BreakdownColumn $column): string
    {
        return match ($column) {
            BreakdownColumn::Event => 'Event name',
            BreakdownColumn::DistinctId => 'User (distinct id)',
            BreakdownColumn::Browser => 'Browser',
            BreakdownColumn::CountryCode => 'Country',
            BreakdownColumn::CurrentUrl => 'Current URL',
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
