<?php

namespace App\Infrastructure\ClickHouse;

use InvalidArgumentException;
use RuntimeException;

/**
 * ClickHouse istemcisi, salt-okunur kullanıcıyla (`posthog_ro`, profil `ro_insights`) bağlanır.
 * Yönetici `ClickHouseClient`'ı GENİŞLETMEZ, onu içinde (özel alan) salt-okunur kimlik bilgileriyle
 * kurar ve yalnızca `select()` sunar: `execute()` hiç yoktur, DDL ve INSERT yönetici istemciye aittir
 * (migrator, altyapı) ve tip sistemi bunu garanti eder. Sorgu ayarları `RoSettings::ALLOWED`
 * listesiyle sınırlıdır. Sunucu da yazmayı reddeder (GRANT yalnızca SELECT ON posthog.events).
 */
final class ReadOnlyClickHouseClient implements ClickHouseReads
{
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

        return $this->client->select($sql, $params, $settings, $timeout);
    }
}
