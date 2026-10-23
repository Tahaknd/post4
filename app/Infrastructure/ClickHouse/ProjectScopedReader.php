<?php

namespace App\Infrastructure\ClickHouse;

use InvalidArgumentException;

/**
 * Olay okumanın TEK yolu: sorgu kurucular `events`'i yalnızca buradan okur.
 *
 * Sözleşme: SQL, `project_id` filtresini ham `{project_id:UInt32}` yer tutucusuyla yazar; okuyucu
 * değeri bağlı parametre olarak kendisi ekler (çağıran `project_id` adlı parametre veremez, proje
 * kimliği SQL'e yapıştırılmaz).
 *
 * UYARI: yer tutucu denetimi bir güvenlik ağıdır, SQL ayrıştırıcısı DEĞİLDİR. Yer tutucuyu anan ama
 * başka kiracıların satırlarını da okuyan düşmanca bir sorguyu (alt sorgu, UNION, `OR` ...) durduramaz.
 * Sorgu kurucular bu sözleşmeye göre yazılmalı ve gözden geçirilmelidir; kullanıcı girdisi asla SQL
 * metnine girmez, yalnızca `$params` ile bağlanır. Salt-okunur profil (`ro_insights`) etki alanını
 * `posthog.events` üzerinde SELECT ve sorgu başına sınırlarla kısıtlar, ama kiracı izolasyonunu SAĞLAMAZ.
 */
class ProjectScopedReader
{
    public const PLACEHOLDER = '{project_id:UInt32}';

    /** `events.project_id` UInt32'dir. */
    private const MAX_PROJECT_ID = 4294967295;

    public function __construct(private readonly ClickHouseReads $client) {}

    /**
     * @param  array<string, mixed>  $params  Bağlı parametreler (`project_id` adı ayrılmıştır)
     * @param  array<string, bool|int|float|string>  $settings  Sabit sorgu ayarları (kullanıcı girdisi DEĞİL).
     *                                                          `log_comment` verilmezse `project:<id>` olur (`system.query_log`).
     * @return array<int, array<string, mixed>>
     */
    public function select(int $projectId, string $sql, array $params = [], array $settings = []): array
    {
        if ($projectId <= 0 || $projectId > self::MAX_PROJECT_ID) {
            throw new InvalidArgumentException('project id must be a positive UInt32, got '.$projectId);
        }
        if (! str_contains($sql, self::PLACEHOLDER)) {
            throw new InvalidArgumentException('Project scoped SQL must contain the placeholder '.self::PLACEHOLDER);
        }
        if (array_key_exists('project_id', $params)) {
            throw new InvalidArgumentException("The 'project_id' param is set by the reader and cannot be passed by the caller");
        }

        // `+`: çağıranın verdiği log_comment kazanır.
        $settings += [RoSettings::LOG_COMMENT => 'project:'.$projectId];

        return $this->client->select($sql, ['project_id' => $projectId] + $params, $settings);
    }
}
