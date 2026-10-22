<?php

namespace App\Infrastructure\ClickHouse;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class ClickHouseClient
{
    private const ERROR_BODY_LIMIT = 1000;

    public function __construct(
        private readonly string $url,
        private readonly string $database,
        private readonly string $user,
        private readonly string $password,
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
    ) {}

    /** Veri döndürmeyen ifade (DDL, INSERT). */
    public function execute(string $sql, array $params = [], ?int $timeout = null): void
    {
        $this->send($sql, $params, [], $timeout);
    }

    /**
     * `wait_end_of_query=1`: sunucu cevabı tamponlar, sorgu akış ortasında patlarsa
     * 200 + yarım gövde yerine gerçek bir HTTP 5xx döner.
     *
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $body = $this->send($sql, $params, ['default_format' => 'JSON', 'wait_end_of_query' => 1]);

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
     * @param  array<string, mixed>  $settings  URL argümanları (default_format vb.)
     */
    private function send(string $sql, array $params, array $settings, ?int $timeout = null): string
    {
        $query = ['database' => $this->database] + $settings;
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
