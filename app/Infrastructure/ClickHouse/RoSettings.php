<?php

namespace App\Infrastructure\ClickHouse;

/**
 * The ONLY query settings the read-only client accepts. Every other key is rejected: a generic regex +
 * a short deny list would miss HTTP interface arguments such as `session_id`, `quota_key`, `role`, `user`,
 * `password`, `query_id`, `compress`. Server side limits are additionally protected by the constraints of the
 * `ro_insights` profile (docker/clickhouse/users.d/20-readonly-user.xml); the ones here exist only to LOWER a
 * limit within the range the profile allows.
 */
final class RoSettings
{
    public const MAX_EXECUTION_TIME = 'max_execution_time';

    public const MAX_RESULT_ROWS = 'max_result_rows';

    public const MAX_RESULT_BYTES = 'max_result_bytes';

    public const MAX_ROWS_TO_READ = 'max_rows_to_read';

    public const MAX_MEMORY_USAGE = 'max_memory_usage';

    public const MAX_THREADS = 'max_threads';

    public const LOG_COMMENT = 'log_comment';

    /**
     * Custom setting read by the DEFINER view posthog.project_events_v and its row policy (tenant isolation, server side). Only
     * ProjectScopedReader sets it; the value must be an integer 1..4294967295 (checked in
     * ReadOnlyClickHouseClient). A query without it FAILS on the server (fail-closed).
     */
    public const SQL_PROJECT_ID = 'SQL_project_id';

    /** @var list<string> */
    public const ALLOWED = [
        self::MAX_EXECUTION_TIME,
        self::MAX_RESULT_ROWS,
        self::MAX_RESULT_BYTES,
        self::MAX_ROWS_TO_READ,
        self::MAX_MEMORY_USAGE,
        self::MAX_THREADS,
        self::LOG_COMMENT,
        self::SQL_PROJECT_ID,
    ];

    private function __construct() {}
}
