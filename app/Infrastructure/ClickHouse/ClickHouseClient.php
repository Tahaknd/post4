<?php

namespace App\Infrastructure\ClickHouse;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class ClickHouseClient implements ClickHouseReads
{
    private const ERROR_BODY_LIMIT = 1000;

    /** URL arguments the client sets itself; a per-query setting must never override them. */
    private const RESERVED_ARGUMENTS = ['database', 'default_format', 'wait_end_of_query', 'query'];

    public function __construct(
        private readonly string $url,
        private readonly string $database,
        private readonly string $user,
        private readonly string $password,
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
    ) {}

    /**
     * Veri döndürmeyen ifade (DDL, INSERT).
     *
     * @param  array<string, bool|int|float|string>  $settings  Sorgu başına ClickHouse ayarları, bkz. {@see self::select()}.
     */
    public function execute(string $sql, array $params = [], ?int $timeout = null, array $settings = []): void
    {
        $this->send($sql, $params, [], $timeout, $settings);
    }

    /**
     * `wait_end_of_query=1`: sunucu cevabı tamponlar, sorgu akış ortasında patlarsa
     * 200 + yarım gövde yerine gerçek bir HTTP 5xx döner.
     *
     * `$settings` sorgu başına ClickHouse ayarlarıdır (`max_execution_time` vb.) ve `params` gibi URL
     * argümanı olarak gider. Anahtarlar `/^[a-z][a-z0-9_]*$/` olmalı, değerler skaler (bool 1/0 olur).
     * Tek istisna özel ayar öneki `SQL_`: tam olarak `/^SQL_[a-z][a-z0-9_]*$/` kabul edilir
     * (`SQL_project_id`; sunucu tarafı: docker/clickhouse/config.d/10-custom-settings.xml).
     * Ayarlar ASLA kullanıcı girdisinden gelmez: çağıranlar sabit değer geçer (kullanıcı girdisi
     * yalnızca `$params` ile bağlanır). İstemcinin kendi argümanlarıyla (`database`, `default_format`,
     * `wait_end_of_query`, `query`, `param_*`) çakışan adlar `InvalidArgumentException` ile reddedilir.
     * Sunucu tarafı sınırlar kullanıcının profilindeki constraint'lerle ayrıca korunur.
     * `$timeout` çağrı başına HTTP zaman aşımıdır (saniye), null ise yapıcıdaki varsayılan.
     *
     * @param  array<string, bool|int|float|string>  $settings
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = [], array $settings = [], ?int $timeout = null): array
    {
        $body = $this->send($sql, $params, ['default_format' => 'JSON', 'wait_end_of_query' => 1], $timeout, $settings);

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('ClickHouse error: unparseable response');
        }

        // Akış ortası hata bazı yapılandırmalarda hâlâ 200 + `exception` anahtarı olarak gelir.
        if (! is_array($decoded) || array_key_exists('exception', $decoded) || ! array_key_exists('data', $decoded)) {
            throw new RuntimeException('ClickHouse error: '.$this->truncate($body));
        }

        return $decoded['data'];
    }

    /**
     * @param  array<string, mixed>  $arguments  İstemcinin kendi URL argümanları (default_format vb.)
     * @param  array<string, mixed>  $settings  Çağıranın sorgu başına ayarları (doğrulanır)
     */
    private function send(string $sql, array $params, array $arguments, ?int $timeout = null, array $settings = []): string
    {
        $query = ['database' => $this->database] + $arguments + $this->encodeSettings($settings);
        foreach ($params as $name => $value) {
            $query['param_'.$name] = $this->encodeParam($value);
        }

        $response = Http::withBasicAuth($this->user, $this->password)
            ->timeout($timeout ?? $this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withBody($sql, 'text/plain')
            ->post($this->url.'?'.http_build_query($query));

        if ($response->failed()) {
            throw new RuntimeException('ClickHouse error: '.$this->truncate($response->body()));
        }

        return $response->body();
    }

    /**
     * @param  array<array-key, mixed>  $settings
     * @return array<string, string>
     */
    private function encodeSettings(array $settings): array
    {
        $encoded = [];
        foreach ($settings as $name => $value) {
            if (! is_string($name) || preg_match('/^(?:SQL_)?[a-z][a-z0-9_]*$/D', $name) !== 1) {
                throw new InvalidArgumentException('Invalid ClickHouse setting name: '.var_export($name, true));
            }
            if (in_array($name, self::RESERVED_ARGUMENTS, true) || str_starts_with($name, 'param_')) {
                throw new InvalidArgumentException("ClickHouse setting '{$name}' collides with an argument the client sets itself");
            }

            $encoded[$name] = match (true) {
                is_bool($value) => $value ? '1' : '0',
                is_int($value) => (string) $value,
                is_float($value) && is_finite($value) => var_export($value, true),
                is_string($value) => $value,
                default => throw new InvalidArgumentException("ClickHouse setting '{$name}' must be a bool, int, finite float or string, got ".get_debug_type($value)),
            };
        }

        return $encoded;
    }

    /** URL parametreleri TSV-escape edilmiş metin olarak ayrıştırılır. */
    private function encodeParam(mixed $value): string
    {
        return match (true) {
            is_string($value) => strtr($value, ['\\' => '\\\\', "\t" => '\t', "\n" => '\n', "\r" => '\r']),
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => var_export($value, true),
            $value === null => '\N',
            $value instanceof DateTimeInterface => $this->formatDate($value),
            is_array($value) => $this->encodeArray($value),
            default => throw new InvalidArgumentException('Unsupported ClickHouse param type: '.get_debug_type($value)),
        };
    }

    /** ClickHouse dizi literali: `['a','b\'c']`. Metin elemanlar tek tırnaklı, `\` ve `'` kaçışlı. */
    private function encodeArray(array $values): string
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('ClickHouse array params must be lists');
        }

        return '['.implode(',', array_map($this->encodeElement(...), $values)).']';
    }

    private function encodeElement(mixed $value): string
    {
        return match (true) {
            is_string($value) => $this->quote($value),
            // Array(Bool) yalnızca true/false kabul eder (1/0 reddedilir); skaler Bool param'ı 1/0 alır.
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => var_export($value, true),
            $value === null => 'NULL',
            $value instanceof DateTimeInterface => $this->quote($this->formatDate($value)),
            is_array($value) => $this->encodeArray($value),
            default => throw new InvalidArgumentException('Unsupported ClickHouse array element type: '.get_debug_type($value)),
        };
    }

    private function quote(string $value): string
    {
        return "'".strtr($value, ['\\' => '\\\\', "'" => "\\'", "\t" => '\t', "\n" => '\n', "\r" => '\r'])."'";
    }

    private function formatDate(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.v');
    }

    private function truncate(string $body): string
    {
        return mb_substr($body, 0, self::ERROR_BODY_LIMIT);
    }
}
