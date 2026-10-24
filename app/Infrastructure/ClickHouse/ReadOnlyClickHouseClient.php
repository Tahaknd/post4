<?php

namespace App\Infrastructure\ClickHouse;

use InvalidArgumentException;
use RuntimeException;

/**
 * ClickHouse istemcisi, salt-okunur kullanıcıyla (`posthog_ro`, profil `ro_insights`) bağlanır.
 * Yönetici `ClickHouseClient`'ı GENİŞLETMEZ, onu içinde (özel alan) salt-okunur kimlik bilgileriyle
 * kurar ve yalnızca `select()` sunar: `execute()` hiç yoktur, DDL ve INSERT yönetici istemciye aittir
 * (migrator, altyapı) ve tip sistemi bunu garanti eder. Sorgu ayarları `RoSettings::ALLOWED`
 * listesiyle sınırlıdır. Sunucu da yazmayı reddeder (GRANT yalnızca SELECT ON posthog.project_events_v).
 *
 * Her okuma `SQL_project_id` ayarını taşımalıdır: `posthog.project_events_v` görünümü (ve üzerindeki satır
 * politikası) ayar yoksa sorguyu reddeder, varsa yalnızca o projenin satırlarını gösterir; kullanıcının
 * `posthog.events` üzerinde yetkisi yoktur, bu yüzden ham `select()` bile mergeTreeIndex ile başka projeyi
 * okuyamaz (bkz. `ProjectScopedReader`, kesin sınır orada anlatılır). Bu ayarın değeri 1..4294967295 aralığında bir tamsayı olmalıdır.
 */
final class ReadOnlyClickHouseClient implements ClickHouseReads
{
    /** `events.project_id` UInt32'dir. */
    private const MAX_PROJECT_ID = 4294967295;

    private readonly ClickHouseClient $client;

    public function __construct(
        string $url,
        string $database,
        string $user,
        string $password,
        int $timeout = 25,
        int $connectTimeout = 10,
    ) {
        if ($password === '') {
            throw new RuntimeException('The read-only ClickHouse password is empty: set CLICKHOUSE_RO_PASSWORD (default posthog_ro, as in docker-compose.yml and docker/clickhouse/users.d/20-readonly-user.xml).');
        }

        $this->client = new ClickHouseClient($url, $database, $user, $password, $timeout, $connectTimeout);
    }

    public function select(string $sql, array $params = [], array $settings = [], ?int $timeout = null): array
    {
        foreach (array_keys($settings) as $name) {
            if (! in_array($name, RoSettings::ALLOWED, true)) {
                throw new InvalidArgumentException('ClickHouse setting '.var_export($name, true).' is not allowed for the read-only client; allowed: '.implode(', ', RoSettings::ALLOWED));
            }
        }

        if (array_key_exists(RoSettings::SQL_PROJECT_ID, $settings)) {
            $id = $settings[RoSettings::SQL_PROJECT_ID];
            if (! is_int($id) || $id < 1 || $id > self::MAX_PROJECT_ID) {
                throw new InvalidArgumentException('ClickHouse setting SQL_project_id must be an integer from 1 to '.self::MAX_PROJECT_ID.', got '.var_export($id, true));
            }
        }

        return $this->client->select($sql, $params, $settings, $timeout);
    }
}
