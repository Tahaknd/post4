<?php

namespace App\Infrastructure\ClickHouse;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Migration dosyalarını (`NNN_ad.sql`) sıralı uygular.
 *
 * Kurallar:
 * - ClickHouse ifadeleri transactional değildir. Her ifade idempotent olmalı
 *   (`IF NOT EXISTS` / `IF EXISTS`) ya da dosya tam olarak tek ifade içermeli.
 * - Yarım uygulanmış bir dosya (ortada hata) bir sonraki başlatmada ilk ifadesinden itibaren yeniden çalışır.
 * - Dosyalar sıralı dosya adı sırasıyla uygulanır (`NNN_ad.sql`).
 * - Uygulanmış dosyalar asla düzenlenmemeli: dosyanın sha256 özeti `schema_migrations.checksum`
 *   içinde saklanır ve değişirse migrate() hata verir.
 * - Eşzamanlı çalıştırıcılar `clickhouse:migrate` kilidinde BEKLER (atlamaz).
 * - `$settings` HER migration ifadesine URL argümanı olarak eklenir. Sunucuda tanımlı özel ayarlar
 *   (`SQL_project_id`, config.d/10-custom-settings.xml) bir görünümün gövdesinde `getSetting` ile
 *   kullanılacaksa CREATE sırasında da bilinmelidir (002_scoped_events_view.sql). DİKKAT: bu yüzden HER migration
 *   ifadesi `SQL_project_id=0` ile çalışır; ileride yazılacak bir `INSERT ... SELECT` ile kapsamlı olay
 *   görünümünden (002_scoped_events_view.sql) okuyan her ifade project 0'ı görür, yani SIFIR satır okur. Veri taşıyan migration'lar
 *   `events` tablosunu doğrudan okumalıdır (migrator yönetici kullanıcıyla çalışır).
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

    /** @return list<string> Uygulanan migration dosya adları */
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

        // Checksum kolonu öncesinde oluşturulmuş tablolar için.
        $this->clickhouse->execute("ALTER TABLE schema_migrations ADD COLUMN IF NOT EXISTS checksum String DEFAULT ''");

        $applied = array_column(
            $this->clickhouse->select('SELECT version, checksum FROM schema_migrations'),
            'checksum',
            'version',
        );

        $files = glob($this->path.'/*.sql') ?: [];
        sort($files);

        // Önce hepsini doğrula: kaymış bir dosya varken hiçbir yeni migration uygulanmaz.
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
     * Dosyayı üst düzey `;` karakterlerinden ifadelere böler. Tek tırnaklı string'ler (`\` ve `''`
     * kaçışlı), çift tırnaklı ve backtick'li tanımlayıcılar, `--` satır ve `/* *​/` blok yorumları
     * `;` ayırıcısını gizler. Yorumlar atılır; ifadeler kırpılır, boş olanlar düşer.
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
                // Satır sonu ifadeye dahil kalır ki bitişik token'lar yapışmasın.
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

    /** `$start` konumundaki açılış tırnağına karşılık gelen kapanıştan HEMEN SONRAKI konum. */
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
