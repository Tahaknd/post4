<?php

namespace App\Infrastructure\ClickHouse;

/**
 * ClickHouse'tan satır okuma sözleşmesi. Yönetici `ClickHouseClient` ve salt-okunur
 * `ReadOnlyClickHouseClient` bunu uygular; sorgu katmanı yalnızca bu arayüze bağımlıdır, böylece
 * yazma yeteneği tip sistemiyle dışarıda kalır.
 */
interface ClickHouseReads
{
    /**
     * @param  array<string, mixed>  $params  Bağlı parametreler
     * @param  array<string, bool|int|float|string>  $settings  Sabit sorgu ayarları (kullanıcı girdisi DEĞİL)
     * @param  int|null  $timeout  Çağrı başına HTTP zaman aşımı (saniye); null ise istemci varsayılanı
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = [], array $settings = [], ?int $timeout = null): array;
}
