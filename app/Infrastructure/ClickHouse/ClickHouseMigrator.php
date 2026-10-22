<?php

namespace App\Infrastructure\ClickHouse;

final class ClickHouseMigrator
{
    public function __construct(
        private readonly ClickHouseClient $clickhouse,
        private readonly string $path,
    ) {}

    /** @return list<string> Uygulanan migration dosya adları */
    public function migrate(): array
    {
        $this->clickhouse->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_migrations (
                version String,
                applied_at DateTime DEFAULT now()
            ) ENGINE = MergeTree ORDER BY version
            SQL);

        $applied = array_column(
            $this->clickhouse->select('SELECT version FROM schema_migrations'),
            'version',
        );

        $files = glob($this->path.'/*.sql') ?: [];
        sort($files);

        $ran = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }

            foreach (self::statements((string) file_get_contents($file)) as $statement) {
                $this->clickhouse->execute($statement);
            }

            $this->clickhouse->execute(
                'INSERT INTO schema_migrations (version) SELECT {v:String}',
                ['v' => $name],
            );
            $ran[] = $name;
        }

        return $ran;
    }

    /**
     * Dosyayı `;` + satır sonu ile ifadelere böler. Tek satırlık `--` yorumları atılır.
     * Migration dosyalarında string içinde `;` + satır sonu kullanma.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        $parts = preg_split('/;\s*(?:\R|$)/', $sql) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $s) => $s !== ''));
    }
}
