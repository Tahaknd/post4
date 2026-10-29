<?php

namespace App\Domain\Insights;

use InvalidArgumentException;

/**
 * Turns the query of ONE insight type into SQL for ProjectScopedReader, and the rows that SQL returns into the result.
 * The builder owns the output columns, so it owns both directions. The SQL is made only of class constants, enum
 * fragments and typed `{name:Type}` placeholders (see {@see Sql\Params}); a builder is stateless.
 *
 * Both methods take the generic {@see InsightQuery} and refuse a query of another type with an
 * InvalidArgumentException (a programming error: the runner picks the builder by the query's type).
 */
interface InsightSqlBuilder
{
    /** @throws InvalidArgumentException the query is not of this builder's type */
    public function build(InsightQuery $query): BuiltQuery;

    /**
     * @param  array<int, array<string, mixed>>  $rows  the rows of the SQL of {@see self::build()}
     *
     * @throws InsightFailed the answer cannot be trusted (it would have been cut off)
     * @throws InvalidArgumentException the query is not of this builder's type
     */
    public function result(InsightQuery $query, array $rows): InsightResult;
}
