<?php

namespace App\Infrastructure\ClickHouse;

use InvalidArgumentException;

/**
 * Olay okumanın TEK yolu: sorgu kurucular (builder) `events`'i yalnızca buradan okur.
 *
 * İKİ KATMAN
 *
 * 1) SUNUCU TARAFI (asıl izolasyon, fail-closed): salt-okunur kullanıcının `posthog.events` üzerinde
 * satır politikası vardır, `project_id = toUInt32(getSetting('SQL_project_id'))`
 * (docker/clickhouse/users.d/20-readonly-user.xml, özel ayar öneki: config.d/10-custom-settings.xml).
 * Okuyucu `SQL_project_id=<id>` ayarını HER sorguda URL argümanı olarak yollar. Ayar yoksa sunucu sorguyu
 * REDDEDER (Code 115), tüm satırları döndürmez. Politika alt sorgularda, UNION'da, JOIN'de ve CTE'de de
 * uygulanır; birincil indeks budaması sürer (`getSetting` sabite katlanır).
 *
 * KESİN SINIR: `readonly=2` özel ayarın değiştirilmesine izin verir ve `SQL_project_id` için `<const/>`
 * kısıtı yazılamaz (okuyucunun kendi URL argümanını da engellerdi). Yani SQL içine yazılmış
 * `SETTINGS SQL_project_id = <başka id>` politikayı o sorgu için EZER. Politika builder HATALARINA karşı
 * korur (unutulan filtre, `OR`, UNION kolu), SQL ENJEKSİYONUNA karşı DEĞİL. Enjeksiyonu şunlar önler:
 * kullanıcı değerleri yalnızca tipli `{ad:Tip}` parametreleriyle bağlanır, builder tanımlayıcıları
 * (özellik adları, birimler, fonksiyonlar) enum/izin listesinden gelir, SQL metnine kullanıcı girdisi
 * HİÇ girmez, ve aşağıdaki lint. Bu sınır tests/Feature/ClickHouseTenantIsolationTest.php içinde bir
 * testle sabitlenmiştir.
 *
 * 2) ERGONOMİK SÖZLEŞME (ikinci katman): builder SQL'i YALNIZCA `project_events` adlı CTE'den seçer.
 * Okuyucu
 *   WITH project_events AS (SELECT * FROM posthog.events WHERE project_id = {project_id:UInt32})
 * önekini KENDİSİ ekler, `project_id` parametresini ve `SQL_project_id` ayarını kendisi koyar; çağıranlar
 * `project_id` parametresi veremez ve `SQL_*` ayarı (büyük/küçük harf fark etmez) geçemez. CTE adı
 * `events` DEĞİL `project_events`'tir (özyinelemeli olmasın diye). Builder'lar CTE gerekiyorsa ALT SORGU
 * kullanır (`SELECT ... FROM (SELECT ... FROM project_events ...)`): `WITH` ile BAŞLAYAMAZLAR, çünkü
 * SQL `SELECT` ile başlamalıdır.
 *
 * LINT (ihlalde `InvalidArgumentException`, hiçbir şey gönderilmez). Builder SQL'i:
 *  - `SELECT` ile başlamalı (büyük/küçük harf serbest, baştaki boşluk serbest);
 *  - `;`, `--`, `#` ve blok yorum açıcı/kapatıcı (slash-yıldız, yıldız-slash) içermemeli (ClickHouse
 *    yorumları), ters tırnak ve çift tırnak içermemeli;
 *  - `SETTINGS`, `FORMAT`, `INTO`, `posthog`, `system`, `events` sözcüklerini (sözcük sınırıyla,
 *    büyük/küçük harf fark etmez) ve `{project_id` yer tutucusunu içermemeli (`project_events` serbest).
 * Lint bir SQL ayrıştırıcısı DEĞİLDİR: dize sabitlerinin içini de tarar (kasıtlı, muhafazakâr: değerler
 * `$params` ile gider). Yasak listesi güvenlik ağıdır; esas koruma sunucudaki satır politikasıdır.
 *
 * `log_comment` otomatik `project:<id>` olur (`system.query_log`), çağıran isterse değiştirebilir.
 */
class ProjectScopedReader
{
    /** Builder SQL'in okuyabildiği tek ilişki. */
    public const CTE_NAME = 'project_events';

    /** Okuyucunun eklediği önek; `{project_id:UInt32}` okuyucuya aittir. */
    public const PREFIX = 'WITH '.self::CTE_NAME.' AS (SELECT * FROM posthog.events WHERE project_id = {project_id:UInt32}) ';

    /** `events.project_id` UInt32'dir. */
    private const MAX_PROJECT_ID = 4294967295;

    /** Sözcük sınırıyla, büyük/küçük harf fark etmeden yasak sözcükler. */
    private const FORBIDDEN_WORDS = ['SETTINGS', 'FORMAT', 'INTO', 'posthog', 'system', 'events'];

    /** Düz metin olarak yasak parçalar => hata iletisinde görünen ad. */
    private const FORBIDDEN_TOKENS = [
        ';' => ';',
        '--' => '--',
        '/*' => '/*',
        '*/' => '*/',
        '#' => '#',
        '`' => 'a backtick (quoted identifiers)',
        '"' => 'a double quote (quoted identifiers)',
        '{project_id' => '{project_id (the reader owns that placeholder)',
    ];

    public function __construct(private readonly ClickHouseReads $client) {}

    /**
     * @param  string  $sql  `SELECT ...` yalnızca `project_events`'ten; bkz. sınıf açıklaması
     * @param  array<string, mixed>  $params  Bağlı parametreler (`project_id` adı ayrılmıştır)
     * @param  array<string, bool|int|float|string>  $settings  Sabit sorgu ayarları (kullanıcı girdisi DEĞİL).
     *                                                          `SQL_*` verilemez. `log_comment` verilmezse `project:<id>` olur.
     * @param  int|null  $timeout  Çağrı başına HTTP zaman aşımı (saniye); null ise istemci varsayılanı
     * @return array<int, array<string, mixed>>
     */
    public function select(int $projectId, string $sql, array $params = [], array $settings = [], ?int $timeout = null): array
    {
        if ($projectId <= 0 || $projectId > self::MAX_PROJECT_ID) {
            throw new InvalidArgumentException('project id must be a positive UInt32, got '.$projectId);
        }
        $this->lint($sql);
        if (array_key_exists('project_id', $params)) {
            throw new InvalidArgumentException("The 'project_id' param is set by the reader and cannot be passed by the caller");
        }
        foreach (array_keys($settings) as $name) {
            if (is_string($name) && stripos($name, 'sql_') === 0) {
                throw new InvalidArgumentException("The SQL_* settings are set by the reader (tenant isolation) and cannot be passed by the caller, got '{$name}'");
            }
        }

        // `+`: çağıranın verdiği log_comment kazanır. SQL_project_id ise HER ZAMAN okuyucunundur.
        $settings += [RoSettings::LOG_COMMENT => 'project:'.$projectId];
        $settings[RoSettings::SQL_PROJECT_ID] = $projectId;

        return $this->client->select(self::PREFIX.$sql, ['project_id' => $projectId] + $params, $settings, $timeout);
    }

    private function lint(string $sql): void
    {
        if (preg_match('/^\s*SELECT\b/i', $sql) !== 1) {
            throw new InvalidArgumentException('Project scoped SQL must start with SELECT (use a subquery instead of WITH); it reads only from the '.self::CTE_NAME.' CTE');
        }

        foreach (self::FORBIDDEN_TOKENS as $token => $name) {
            if (str_contains($sql, $token)) {
                throw new InvalidArgumentException("Project scoped SQL must not contain {$name}");
            }
        }

        foreach (self::FORBIDDEN_WORDS as $word) {
            if (preg_match('/\b'.$word.'\b/i', $sql) === 1) {
                $shown = strtoupper($word) === $word ? $word : "'{$word}'";
                $hint = $word === 'events' || $word === 'posthog' ? ' (the table is only reachable through '.self::CTE_NAME.')' : '';
                throw new InvalidArgumentException("Project scoped SQL must not contain the word {$shown}{$hint}");
            }
        }
    }
}
