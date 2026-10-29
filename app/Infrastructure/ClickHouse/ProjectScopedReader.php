<?php

namespace App\Infrastructure\ClickHouse;

use InvalidArgumentException;

/**
 * The ONLY way to read events: query builders read `events` through this class and nowhere else.
 *
 * TWO LAYERS
 *
 * 1) SERVER SIDE (the real isolation, fail-closed): the read-only user has NO privilege at all on
 * `posthog.events`; it can only read the view `posthog.project_events_v`
 * (database/clickhouse/migrations/002_scoped_events_view.sql, `DEFINER = <admin> SQL SECURITY DEFINER`,
 * `WHERE project_id = toUInt32(getSetting('SQL_project_id'))`; the same condition is a second layer on the
 * view as a row policy, docker/clickhouse/users.d/20-readonly-user.xml; custom setting prefix:
 * config.d/10-custom-settings.xml). The reader sends `SQL_project_id=<id>` as a URL argument on EVERY query.
 * Without the setting the server REJECTS the query (Code 115), it never returns all rows. Primary index
 * pruning still works through the view (`getSetting` folds to a constant).
 *
 * HARD LIMITS (an honest account: what is closed on the server, what only by lint)
 *  - CLOSED ON THE SERVER: `posthog.events` cannot be read directly, in a subquery, in a UNION, a JOIN, a CTE,
 *    with `merge()`, `view()`, `mergeTreeIndex()` or `mergeTreeProjection()` (ACCESS_DENIED: no grant);
 *    mergeTreeIndex cannot read the view (it is not a MergeTree). `remote/cluster/url/file/s3...` are
 *    rejected because the user has no SOURCES/REMOTE grant, `input/executable/dictionary/loop` because it has
 *    no CREATE TEMPORARY TABLE grant; other tables by the absence of a GRANT. A query without the setting is
 *    rejected. None of this relies on the lint.
 *  - NOT CLOSED ON THE SERVER (lint only): `readonly=2` allows changing the custom setting and no `<const/>`
 *    constraint can be written for `SQL_project_id` (it would block the reader's own URL argument too):
 *    `SETTINGS SQL_project_id = <other id>` written inside the SQL overrides the filter for that query; the
 *    lint (`SETTINGS` is forbidden) and the refusal of `SQL_*` settings close it. The filter protects against
 *    builder BUGS (a forgotten filter, `OR`, a UNION arm), NOT against SQL INJECTION. Injection is prevented
 *    by: user values are only bound as typed `{name:Type}` parameters (string literals are FORBIDDEN),
 *    builder identifiers (property names, units, functions) come from an enum/allow list, no user input ever
 *    enters the SQL text, and the lint below. tests/Feature/ClickHouseTenantIsolationTest.php pins this with
 *    a test. (A solution seen in scratch but NOT applied: a filter on `getClientHTTPHeader('X-Project-Id')` in
 *    the view; SQL cannot change an HTTP header. It needs header support in the client and could be done
 *    later.)
 *  - The lint stays as a second defence: the FORBIDDEN_FUNCTIONS blacklist also forbids the table functions
 *    that are already closed on the server (even a raw `ReadOnlyClickHouseClient::select` is protected
 *    against them on the server), and it mechanically enforces the contract of the builder SQL (only
 *    `project_events`).
 *
 * 2) ERGONOMIC CONTRACT (second layer): builder SQL selects ONLY from the CTE named `project_events`. The
 * reader itself (see {@see self::cte()}) prepends
 *   WITH project_events AS (SELECT <COLUMNS> FROM posthog.project_events_v)
 * (an explicit column list, not `*`) and sets the `SQL_project_id` setting itself; callers cannot pass an
 * `SQL_*` setting (case does not matter). The filtering lives inside the view, so the CTE has NO
 * `project_id` parameter. The CTE is named `project_events`, NOT `events`.
 * Builder SQL cannot START with `WITH` (only a LEADING `WITH` is rejected: the SQL must start with `SELECT`);
 * a nested WITH such as `FROM (WITH ... SELECT ...)` is allowed, but a subquery (`SELECT ... FROM (SELECT ...
 * FROM project_events ...)`) is preferred over a custom CTE.
 *
 * LINT (a violation throws `InvalidArgumentException`, nothing is sent). Builder SQL:
 *  - must start with `SELECT` (any case, leading whitespace allowed);
 *  - must not contain `;`, `--`, `#` or a block comment opener/closer (slash-star, star-slash) (ClickHouse
 *    comments), a backtick, a double quote, a SINGLE QUOTE (string literal: a table name can be assembled
 *    with `concat('ev','ents')`; values go through `{name:Type}` parameters, e.g. the breakdown 'other'
 *    bucket `{other:String}`) or `$` (heredoc string literal);
 *  - must not contain the table function names of FORBIDDEN_FUNCTIONS nor the words `SETTINGS`, `FORMAT`,
 *    `INTO`, `posthog`, `system`, `events`, `project_events_v` (on word boundaries, any case)
 *    (`project_events` is allowed).
 * The lint is NOT an SQL parser and the forbidden list is a BLACKLIST: when ClickHouse adds a new table
 * function (version upgrade), review the list. The real protection is the view and the grants on the server.
 *
 * `log_comment` automatically becomes `project:<id>` (`system.query_log`); the caller may override it.
 */
class ProjectScopedReader
{
    /** The only relation builder SQL can read. */
    public const CTE_NAME = 'project_events';

    /**
     * The DEFINER view the read-only user can read (002_scoped_events_view.sql); it filters to one project
     * through the `SQL_project_id` setting. The user has no privilege on `posthog.events`.
     */
    public const VIEW = 'project_events_v';

    /**
     * The EXPLICIT column list of the CTE (NOT `SELECT *`: `*` does not include the MATERIALIZED columns
     * (`current_url`, `browser`, `country_code`)). It must equal database/clickhouse/migrations/001_events.sql
     * AND the `project_events_v` view (002_scoped_events_view.sql) exactly; if a new migration adds a column
     * to `events` it must recreate the view too, and the drift test in
     * tests/Feature/ClickHouseTenantIsolationTest.php (admin `DESCRIBE` of events and of the view) fails
     * loudly.
     *
     * @var list<string>
     */
    public const COLUMNS = ['uuid', 'project_id', 'event', 'distinct_id', 'properties', 'timestamp', 'created_at', 'current_url', 'browser', 'country_code', 'os', 'device_type', 'utm_source', 'utm_medium', 'utm_campaign'];

    /** `events.project_id` is a UInt32. */
    private const MAX_PROJECT_ID = 4294967295;

    /**
     * Table functions that can reach other tables or skip the row policy (forbidden on word boundaries, any
     * case). SECOND DEFENCE: `mergeTreeIndex(currentDatabase(), concat('ev', 'ents'))` used to skip the row
     * policy and leak the primary key values of EVERY project; now it is ACCESS_DENIED on the server because
     * the user has no privilege on `posthog.events` (so are `merge`, `view`, `mergeTreeProjection`). `remote`,
     * `cluster*`, `url`, `file`, `s3` ... are rejected by the absence of the SOURCES/REMOTE grant, `input`,
     * `executable`, `dictionary`, `loop` by the absence of the CREATE TEMPORARY TABLE grant. The list
     * mechanically guarantees that the builder never touches them.
     *
     * @var list<string>
     */
    public const FORBIDDEN_FUNCTIONS = [
        'mergeTreeIndex', 'mergeTreeProjection', 'mergeTreeAnalyzeIndexes', 'mergeTreeTextIndex', 'merge',
        'remote', 'remoteSecure', 'cluster', 'clusterAllReplicas', 'url', 'urlCluster', 'file', 'fileCluster',
        's3', 's3Cluster', 'gcs', 'azureBlobStorage', 'azureBlobStorageCluster', 'hdfs', 'hdfsCluster',
        'deltaLake', 'deltaLakeCluster', 'iceberg', 'icebergCluster', 'hudi', 'hudiCluster', 'mysql',
        'postgresql', 'mongodb', 'redis', 'sqlite', 'jdbc', 'odbc', 'arrowFlight', 'ytsaurus', 'input',
        'executable', 'dictionary', 'dictGet', 'dictGetOrDefault', 'dictGetOrNull', 'dictHas', 'view',
        'viewIfPermitted', 'loop', 'information_schema',
    ];

    /** Words that are forbidden on word boundaries, any case. */
    private const FORBIDDEN_WORDS = ['SETTINGS', 'FORMAT', 'INTO', 'posthog', 'system', 'events', self::VIEW];

    /** Forbidden plain-text fragments => the name shown in the error message. */
    private const FORBIDDEN_TOKENS = [
        ';' => ';',
        '--' => '--',
        '/*' => '/*',
        '*/' => '*/',
        '#' => '#',
        '`' => 'a backtick (quoted identifiers)',
        '"' => 'a double quote (quoted identifiers)',
        "'" => 'a string literal (single quote): pass values as {name:Type} params, names could be assembled with concat',
        '$' => '$ (heredoc string literals): pass values as {name:Type} params',
    ];

    public function __construct(private readonly ClickHouseReads $client) {}

    /**
     * @param  string  $sql  `SELECT ...` from `project_events` only; see the class description
     * @param  array<string, mixed>  $params  Bound parameters (`{name:Type}`)
     * @param  array<string, bool|int|float|string>  $settings  Fixed query settings (NOT user input).
     *                                                          `SQL_*` cannot be passed. Without `log_comment` it becomes `project:<id>`.
     * @param  int|null  $timeout  Per-call HTTP timeout (seconds); null means the client default
     * @return array<int, array<string, mixed>>
     */
    public function select(int $projectId, string $sql, array $params = [], array $settings = [], ?int $timeout = null): array
    {
        if ($projectId <= 0 || $projectId > self::MAX_PROJECT_ID) {
            throw new InvalidArgumentException('project id must be a positive UInt32, got '.$projectId);
        }
        $this->lint($sql);
        foreach (array_keys($settings) as $name) {
            if (is_string($name) && stripos($name, 'sql_') === 0) {
                throw new InvalidArgumentException("The SQL_* settings are set by the reader (tenant isolation) and cannot be passed by the caller, got '{$name}'");
            }
        }

        // `+`: a log_comment given by the caller wins. SQL_project_id ALWAYS belongs to the reader.
        $settings += [RoSettings::LOG_COMMENT => 'project:'.$projectId];
        $settings[RoSettings::SQL_PROJECT_ID] = $projectId;

        return $this->client->select(self::cte().$sql, $params, $settings, $timeout);
    }

    /** `WITH project_events AS (SELECT <COLUMNS> FROM posthog.project_events_v) `. */
    public static function cte(): string
    {
        return 'WITH '.self::CTE_NAME.' AS (SELECT '.implode(', ', self::COLUMNS).' FROM posthog.'.self::VIEW.') ';
    }

    private function lint(string $sql): void
    {
        if (preg_match('/^\s*SELECT\b/i', $sql) !== 1) {
            throw new InvalidArgumentException('Project scoped SQL must start with SELECT (use a subquery instead of WITH); it reads only from the '.self::CTE_NAME.' CTE');
        }

        foreach (self::FORBIDDEN_FUNCTIONS as $function) {
            if (preg_match('/\b'.$function.'\b/i', $sql) === 1) {
                throw new InvalidArgumentException("Project scoped SQL must not use {$function} (it can reach other tables or skip the row policy)");
            }
        }

        foreach (self::FORBIDDEN_WORDS as $word) {
            if (preg_match('/\b'.$word.'\b/i', $sql) === 1) {
                $shown = strtoupper($word) === $word ? $word : "'{$word}'";
                $hint = in_array($word, ['events', 'posthog', self::VIEW], true) ? ' (the table is only reachable through '.self::CTE_NAME.')' : '';
                throw new InvalidArgumentException("Project scoped SQL must not contain the word {$shown}{$hint}");
            }
        }

        foreach (self::FORBIDDEN_TOKENS as $token => $name) {
            if (str_contains($sql, $token)) {
                throw new InvalidArgumentException("Project scoped SQL must not contain {$name}");
            }
        }
    }
}
