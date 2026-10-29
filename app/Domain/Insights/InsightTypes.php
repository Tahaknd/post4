<?php

namespace App\Domain\Insights;

use App\Domain\Insights\Funnel\FunnelQuery;
use App\Domain\Insights\Funnel\FunnelResult;
use App\Domain\Insights\Funnel\FunnelSqlBuilder;
use App\Domain\Insights\Retention\RetentionQuery;
use App\Domain\Insights\Retention\RetentionResult;
use App\Domain\Insights\Retention\RetentionSqlBuilder;
use App\Domain\Insights\Trends\TrendsQuery;
use App\Domain\Insights\Trends\TrendsResult;
use App\Domain\Insights\Trends\TrendsSqlBuilder;
use InvalidArgumentException;

/**
 * The registry of insight types: type key -> query class, result class, SQL builder. It is the one place that knows
 * which types exist; the runner (SQL, cache keys), the run job (rebuilding a query from its canonical array) and the
 * HTTP layer (routes) go through it, so a new type is a new entry here plus its own classes, not a change in them.
 *
 * Deliberately a constant table and static lookups, not a container or a plugin system: a type is code.
 */
final class InsightTypes
{
    public const TRENDS = 'trends';

    public const FUNNEL = 'funnel';

    public const RETENTION = 'retention';

    /** @var array<string, array{query: class-string<InsightQuery>, result: class-string<InsightResult>, builder: class-string<InsightSqlBuilder>}> */
    private const TYPES = [
        self::TRENDS => ['query' => TrendsQuery::class, 'result' => TrendsResult::class, 'builder' => TrendsSqlBuilder::class],
        self::FUNNEL => ['query' => FunnelQuery::class, 'result' => FunnelResult::class, 'builder' => FunnelSqlBuilder::class],
        self::RETENTION => ['query' => RetentionQuery::class, 'result' => RetentionResult::class, 'builder' => RetentionSqlBuilder::class],
    ];

    private function __construct() {}

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TYPES);
    }

    public static function has(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /** @return class-string<InsightQuery> */
    public static function query(string $type): string
    {
        return self::entry($type)['query'];
    }

    /** @return class-string<InsightResult> */
    public static function result(string $type): string
    {
        return self::entry($type)['result'];
    }

    public static function builder(string $type): InsightSqlBuilder
    {
        $class = self::entry($type)['builder'];

        return new $class;
    }

    /** @return array{query: class-string<InsightQuery>, result: class-string<InsightResult>, builder: class-string<InsightSqlBuilder>} */
    private static function entry(string $type): array
    {
        return self::TYPES[$type] ?? throw new InvalidArgumentException("Unknown insight type '{$type}'");
    }
}
