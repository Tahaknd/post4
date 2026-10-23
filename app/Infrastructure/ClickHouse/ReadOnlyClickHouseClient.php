<?php

namespace App\Infrastructure\ClickHouse;

use LogicException;

/**
 * ClickHouse istemcisi, salt-okunur kullanıcıyla (`posthog_ro`, profil `ro_insights`) bağlanır.
 * Yalnızca `select()` vardır; DDL ve INSERT yönetici `ClickHouseClient`'a aittir (migrator, altyapı).
 * Kapsayıcıda ayrı bir singleton olarak kayıtlıdır: `ReadOnlyClickHouseClient` tip bildirimi
 * yönetici istemciyle karışmaz. Sunucu da yazmayı reddeder (GRANT yalnızca SELECT ON posthog.*);
 * buradaki `LogicException` programlama hatasını sunucuya gitmeden yakalar.
 */
class ReadOnlyClickHouseClient extends ClickHouseClient
{
    public function execute(string $sql, array $params = [], ?int $timeout = null, array $settings = []): void
    {
        throw new LogicException('ReadOnlyClickHouseClient reads only; DDL and inserts belong to the admin ClickHouseClient.');
    }
}
