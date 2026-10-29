<?php

namespace App\Infrastructure\ClickHouse;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Applies the migration files (`NNN_name.sql`) in order.
 *
 * Rules:
 * - ClickHouse statements are not transactional. Every statement must be idempotent
 *   (`IF NOT EXISTS` / `IF EXISTS`) or the file must contain exactly one statement.
 * - A half-applied file (an error in the middle) runs again from its first statement on the next start.
 * - Files are applied in file name order (`NNN_name.sql`).
 * - Applied files must never be edited: the sha256 digest of the file is stored in
 *   `schema_migrations.checksum` and migrate() fails if it changes.
 *   (001_events.sql and 002_scoped_events_view.sql still carry Turkish comments for exactly this reason: they
 *   are checksummed, so they are translated only together with the first deliberate reset of the volumes.
 *   Every later migration is written in English from the start.)
 * - Concurrent runners WAIT on the `clickhouse:migrate` lock (they do not skip).
 * - `$settings` are added to EVERY migration statement as URL arguments. Custom settings defined on the server
 *   (`SQL_project_id`, config.d/10-custom-settings.xml) must also be known while the CREATE runs if a view body
 *   uses them with `getSetting` (002_scoped_events_view.sql). CAUTION: that is why EVERY migration
 *   statement runs with `SQL_project_id=0`; any future `INSERT ... SELECT` that reads from the scoped events
 *   view (002_scoped_events_view.sql) sees project 0, that is it reads ZERO rows. Migrations that move data
 *   must read the `events` table directly (the migrator runs as the admin user).
 */
final class ClickHouseMigrator
{
    private const LOCK_KEY = 'clickhouse:migrate';

    private const LOCK_TTL = 300;

    public function __construct(
        private readonly ClickHouseClient $clickhouse,
        private readonly string $path,
        private readonly int $lockWaitSeconds = 120,
        /** @var array<string, bool|int|float|string> */
        private readonly array $settings = [],
    ) {}

    /** @return list<string> Names of the applied migration files */
    public function migrate(): array
    {
        return Cache::lock(self::LOCK_KEY, self::LOCK_TTL)
            ->block($this->lockWaitSeconds, fn () => $this->run());
    }

    /** @return list<string> */
    private function run(): array
    {
        $this->clickhouse->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_migrations (
                version String,
                applied_at DateTime DEFAULT now(),
                checksum String DEFAULT ''
            ) ENGINE = MergeTree ORDER BY version
            SQL);

        // For tables created before the checksum column existed.
        $this->clickhouse->execute("ALTER TABLE schema_migrations ADD COLUMN IF NOT EXISTS checksum String DEFAULT ''");

        $applied = array_column(
            $this->clickhouse->select('SELECT version, checksum FROM schema_migrations'),
            'checksum',
            'version',
        );

        $files = glob($this->path.'/*.sql') ?: [];
        sort($files);

        // Verify all of them first: while a file has drifted, no new migration is applied.
        $pending = [];
        foreach ($files as $file) {
            $name = basename($file);
            $contents = (string) file_get_contents($file);
            $checksum = hash('sha256', $contents);

            if (! array_key_exists($name, $applied)) {
                $pending[$name] = [$contents, $checksum];

                continue;
            }

            $stored = (string) $applied[$name];
            if ($stored !== '' && $stored !== $checksum) {
                throw new RuntimeException("ClickHouse migration {$name} was modified after it was applied (checksum mismatch).");
            }
        }

        $ran = [];
        foreach ($pending as $name => [$contents, $checksum]) {
            foreach (self::statements($contents) as $statement) {
                $this->clickhouse->execute($statement, settings: $this->settings);
            }

            $this->clickhouse->execute(
                'INSERT INTO schema_migrations (version, checksum) SELECT {v:String}, {c:String}',
                ['v' => $name, 'c' => $checksum],
            );
            $ran[] = $name;
        }

        return $ran;
    }

    /**
     * Splits the file into statements at top-level `;` characters. Single-quoted strings (`\` and `''`
     * escapes), double-quoted and backtick-quoted identifiers, `--` line comments and `/* *​/` block comments
     * hide the `;` separator. Comments are dropped; statements are trimmed, empty ones are discarded.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($char === "'" || $char === '"' || $char === '`') {
                $end = self::quotedEnd($sql, $i, $length);
                $current .= substr($sql, $i, $end - $i);
                $i = $end - 1;
            } elseif ($char === '-' && $next === '-') {
                // The line break stays in the statement so that adjacent tokens do not stick together.
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline - 1;
            } elseif ($char === '/' && $next === '*') {
                $close = strpos($sql, '*/', $i + 2);
                $i = $close === false ? $length : $close + 1;
                $current .= ' ';
            } elseif ($char === ';') {
                $statements[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        $statements[] = $current;

        return array_values(array_filter(array_map('trim', $statements), fn (string $s) => $s !== ''));
    }

    /** The position IMMEDIATELY AFTER the closing quote that matches the opening quote at `$start`. */
    private static function quotedEnd(string $sql, int $start, int $length): int
    {
        $quote = $sql[$start];

        for ($i = $start + 1; $i < $length; $i++) {
            if ($sql[$i] === '\\') {
                $i++;
            } elseif ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i++;

                    continue;
                }

                return $i + 1;
            }
        }

        return $length;
    }
}
