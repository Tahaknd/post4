<?php

namespace App\Infrastructure\ClickHouse;

use InvalidArgumentException;

/**
 * Olay okumanın TEK yolu: sorgu kurucular (builder) `events`'i yalnızca buradan okur.
 *
 * İKİ KATMAN
 *
 * 1) SUNUCU TARAFI (asıl izolasyon, fail-closed): salt-okunur kullanıcının `posthog.events` üzerinde HİÇBİR
 * yetkisi yoktur; yalnızca `posthog.project_events_v` görünümünü okuyabilir
 * (database/clickhouse/migrations/002_scoped_events_view.sql, `DEFINER = <yönetici> SQL SECURITY DEFINER`,
 * `WHERE project_id = toUInt32(getSetting('SQL_project_id'))`; aynı koşul görünüm üzerinde ikinci katman
 * olarak satır politikasıdır, docker/clickhouse/users.d/20-readonly-user.xml; özel ayar öneki:
 * config.d/10-custom-settings.xml). Okuyucu `SQL_project_id=<id>` ayarını HER sorguda URL argümanı olarak
 * yollar. Ayar yoksa sunucu sorguyu REDDEDER (Code 115), tüm satırları döndürmez. Birincil indeks budaması
 * görünümün içinden de sürer (`getSetting` sabite katlanır).
 *
 * KESİN SINIRLAR (dürüst döküm: sunucuda ne kapalı, yalnızca lint ile ne kapalı)
 *  - SUNUCUDA KAPALI: `posthog.events` doğrudan, alt sorguda, UNION'da, JOIN'de, CTE'de, `merge()`,
 *    `view()`, `mergeTreeIndex()` ve `mergeTreeProjection()` ile okunamaz (ACCESS_DENIED: yetki yok);
 *    görünümü mergeTreeIndex okuyamaz (MergeTree değil). `remote/cluster/url/file/s3...` SOURCES/REMOTE
 *    yetkisi olmadığı için, `input/executable/dictionary/loop` CREATE TEMPORARY TABLE yetkisi olmadığı için
 *    reddedilir; diğer tablolar GRANT yokluğuyla. Ayarsız sorgu reddedilir. Bunların hiçbiri lint'e dayanmaz.
 *  - SUNUCUDA KAPALI DEĞİL (yalnızca lint): `readonly=2` özel ayarın değiştirilmesine izin verir ve
 *    `SQL_project_id` için `<const/>` kısıtı yazılamaz (okuyucunun kendi URL argümanını da engellerdi):
 *    SQL içine yazılmış `SETTINGS SQL_project_id = <başka id>` filtreyi o sorgu için EZER; bunu lint
 *    (`SETTINGS` yasak) ve `SQL_*` ayar reddi kapatır. Filtre builder HATALARINA karşı korur (unutulan
 *    filtre, `OR`, UNION kolu), SQL ENJEKSİYONUNA karşı DEĞİL. Enjeksiyonu şunlar önler: kullanıcı
 *    değerleri yalnızca tipli `{ad:Tip}` parametreleriyle bağlanır (dize sabiti YASAK), builder
 *    tanımlayıcıları (özellik adları, birimler, fonksiyonlar) enum/izin listesinden gelir, SQL metnine
 *    kullanıcı girdisi HİÇ girmez, ve aşağıdaki lint. tests/Feature/ClickHouseTenantIsolationTest.php
 *    bunu bir testle sabitler. (Scratch'te görülen ama UYGULANMAYAN çözüm: görünümde
 *    `getClientHTTPHeader('X-Project-Id')` filtresi; SQL HTTP başlığını değiştiremez. İstemciye başlık
 *    desteği gerektirir, ileride yapılabilir.)
 *  - Lint ikinci savunma olarak kalır: FORBIDDEN_FUNCTIONS kara listesi artık sunucuda zaten kapalı olan
 *    tablo fonksiyonlarını da yasaklar (ham `ReadOnlyClickHouseClient::select` bile bunlara karşı sunucuda
 *    korunur), ve builder SQL'inin sözleşmesini (yalnızca `project_events`) mekanik olarak zorlar.
 *
 * 2) ERGONOMİK SÖZLEŞME (ikinci katman): builder SQL'i YALNIZCA `project_events` adlı CTE'den seçer.
 * Okuyucu (bkz. {@see self::cte()})
 *   WITH project_events AS (SELECT <COLUMNS> FROM posthog.project_events_v)
 * önekini KENDİSİ ekler (açık sütun listesi, `*` değil) ve `SQL_project_id` ayarını kendisi koyar;
 * çağıranlar `SQL_*` ayarı (büyük/küçük harf fark etmez) geçemez. Süzme görünümün içindedir, bu yüzden
 * CTE'de `project_id` parametresi YOKTUR. CTE adı `events` DEĞİL `project_events`'tir.
 * Builder SQL'i `WITH` ile BAŞLAYAMAZ (yalnızca BAŞTAKİ `WITH` reddedilir: SQL `SELECT` ile başlamalıdır);
 * `FROM (WITH ... SELECT ...)` gibi iç içe WITH serbesttir, ama özel CTE yerine alt sorgu (`SELECT ...
 * FROM (SELECT ... FROM project_events ...)`) önerilir.
 *
 * LINT (ihlalde `InvalidArgumentException`, hiçbir şey gönderilmez). Builder SQL'i:
 *  - `SELECT` ile başlamalı (büyük/küçük harf serbest, baştaki boşluk serbest);
 *  - `;`, `--`, `#` ve blok yorum açıcı/kapatıcı (slash-yıldız, yıldız-slash) içermemeli (ClickHouse
 *    yorumları), ters tırnak, çift tırnak, TEK TIRNAK (dize sabiti: `concat('ev','ents')` ile tablo adı
 *    kurulabilir; değerler `{ad:Tip}` parametresiyle, ör. breakdown 'diğer' kovası `{other:String}`) ve `$`
 *    (heredoc dize sabiti) içermemeli;
 *  - FORBIDDEN_FUNCTIONS listesindeki tablo fonksiyonu adlarını ve `SETTINGS`, `FORMAT`, `INTO`,
 *    `posthog`, `system`, `events`, `project_events_v` sözcüklerini (sözcük sınırıyla, büyük/küçük harf
 *    fark etmez) içermemeli (`project_events` serbest).
 * Lint bir SQL ayrıştırıcısı DEĞİLDİR ve yasak liste bir KARA LİSTEDİR: ClickHouse yeni tablo fonksiyonu
 * eklediğinde (sürüm yükseltmesi) listeyi gözden geçirin. Esas koruma sunucudaki görünüm ve yetkidir.
 *
 * `log_comment` otomatik `project:<id>` olur (`system.query_log`), çağıran isterse değiştirebilir.
 */
class ProjectScopedReader
{
    /** Builder SQL'in okuyabildiği tek ilişki. */
    public const CTE_NAME = 'project_events';

    /**
     * Salt-okunur kullanıcının okuyabildiği DEFINER görünümü (002_scoped_events_view.sql); `SQL_project_id`
     * ayarıyla tek projeye süzer. `posthog.events` üzerinde kullanıcının yetkisi yoktur.
     */
    public const VIEW = 'project_events_v';

    /**
     * CTE'nin AÇIK sütun listesi (`SELECT *` DEĞİL: `*` MATERIALIZED sütunları (`current_url`, `browser`,
     * `country_code`) içermez). database/clickhouse/migrations/001_events.sql VE `project_events_v` görünümü
     * (002_scoped_events_view.sql) ile birebir aynı olmalı; yeni bir migration `events`'e sütun eklerse
     * görünümü de yenilemeli ve tests/Feature/ClickHouseTenantIsolationTest.php içindeki sapma testi (admin
     * `DESCRIBE` ile events ve görünüm) yüksek sesle patlar.
     *
     * @var list<string>
     */
    public const COLUMNS = ['uuid', 'project_id', 'event', 'distinct_id', 'properties', 'timestamp', 'created_at', 'current_url', 'browser', 'country_code', 'os', 'device_type', 'utm_source', 'utm_medium', 'utm_campaign'];

    /** `events.project_id` UInt32'dir. */
    private const MAX_PROJECT_ID = 4294967295;

    /**
     * Başka tablolara ulaşabilen veya satır politikasını atlayan tablo fonksiyonları (sözcük sınırıyla,
     * büyük/küçük harf fark etmeden yasak). İKİNCİ SAVUNMA: `mergeTreeIndex(currentDatabase(), concat('ev',
     * 'ents'))` eskiden satır politikasını atlayıp HER projenin birincil anahtar değerlerini sızdırırdı;
     * artık kullanıcının `posthog.events` üzerinde yetkisi olmadığı için sunucuda ACCESS_DENIED olur
     * (`merge`, `view`, `mergeTreeProjection` da). `remote`, `cluster*`, `url`, `file`, `s3` ... SOURCES/REMOTE
     * yetkisi yokluğuyla, `input`, `executable`, `dictionary`, `loop` CREATE TEMPORARY TABLE yetkisi
     * yokluğuyla reddedilir. Liste, builder'ın bunlara hiç dokunmadığını mekanik olarak garanti eder.
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

    /** Sözcük sınırıyla, büyük/küçük harf fark etmeden yasak sözcükler. */
    private const FORBIDDEN_WORDS = ['SETTINGS', 'FORMAT', 'INTO', 'posthog', 'system', 'events', self::VIEW];

    /** Düz metin olarak yasak parçalar => hata iletisinde görünen ad. */
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
     * @param  string  $sql  `SELECT ...` yalnızca `project_events`'ten; bkz. sınıf açıklaması
     * @param  array<string, mixed>  $params  Bağlı parametreler (`{ad:Tip}`)
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
        foreach (array_keys($settings) as $name) {
            if (is_string($name) && stripos($name, 'sql_') === 0) {
                throw new InvalidArgumentException("The SQL_* settings are set by the reader (tenant isolation) and cannot be passed by the caller, got '{$name}'");
            }
        }

        // `+`: çağıranın verdiği log_comment kazanır. SQL_project_id ise HER ZAMAN okuyucunundur.
        $settings += [RoSettings::LOG_COMMENT => 'project:'.$projectId];
        $settings[RoSettings::SQL_PROJECT_ID] = $projectId;

        return $this->client->select(self::cte().$sql, $params, $settings, $timeout);
    }

    /**
     * The statement a migration that changes the columns of `events` must contain (one line): the scoped view
     * over exactly {@see self::COLUMNS}, DEFINER and filter as in 002_scoped_events_view.sql. Printed by
     * `php artisan clickhouse:view-sql`, so nobody types the column list by hand twice.
     */
    public static function viewStatement(): string
    {
        return 'CREATE OR REPLACE VIEW '.self::VIEW.' DEFINER = CURRENT_USER SQL SECURITY DEFINER AS SELECT '
            .implode(', ', self::COLUMNS)." FROM events WHERE project_id = toUInt32(getSetting('SQL_project_id'))";
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
