<?php

namespace App\Infrastructure\ClickHouse;

/**
 * Salt-okunur istemcinin kabul ettiği TEK sorgu ayarları. Başka her anahtar reddedilir: genel regex +
 * kısa bir yasak listesi `session_id`, `quota_key`, `role`, `user`, `password`, `query_id`, `compress`
 * gibi HTTP arayüzü argümanlarını kaçırırdı. Sunucu tarafı sınırlar ayrıca `ro_insights` profilinin
 * constraint'leriyle korunur (docker/clickhouse/users.d/20-readonly-user.xml); buradakiler yalnızca
 * profilin izin verdiği aralıkta DÜŞÜRMEK içindir.
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
