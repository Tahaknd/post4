<?php

namespace App\Infrastructure\ClickHouse;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;

class ClickHouseClient implements ClickHouseReads
{
    /** URL arguments the client sets itself; a per-query setting must never override them. */
    private const RESERVED_ARGUMENTS = ['database', 'default_format', 'wait_end_of_query', 'query'];

    public function __construct(
        private readonly string $url,
        private readonly string $database,
        private readonly string $user,
        private readonly string $password,
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
        /** Extra attempts of select() after a connection failure; execute() never retries. 0 = no retry. */
        private readonly int $selectRetries = 0,
        /** Pause before the first retry in milliseconds, doubled for each further one. */
        private readonly int $selectRetryBackoffMs = 100,
    ) {}

    /**
     * A statement that returns no data (DDL, INSERT).
     *
     * @param  array<string, bool|int|float|string>  $settings  Per-query ClickHouse settings, see {@see self::select()}.
     */
    public function execute(string $sql, array $params = [], ?int $timeout = null, array $settings = []): void
    {
        $this->send($sql, $params, [], $timeout, $settings);
    }

    /**
     * `wait_end_of_query=1`: the server buffers the answer, so a query that blows up in mid-stream
     * returns a real HTTP 5xx instead of 200 + a half body.
     *
     * `$settings` are per-query ClickHouse settings (`max_execution_time` etc.) and travel as URL
     * arguments like `params`. Keys must match `/^[a-z][a-z0-9_]*$/`, values must be scalars (a bool becomes 1/0).
     * The only exception is the custom setting prefix `SQL_`: exactly `/^SQL_[a-z][a-z0-9_]*$/` is accepted
     * (`SQL_project_id`; server side: docker/clickhouse/config.d/10-custom-settings.xml).
     * Settings NEVER come from user input: callers pass fixed values (user input is only bound through
     * `$params`). Names that collide with the client's own arguments (`database`, `default_format`,
     * `wait_end_of_query`, `query`, `param_*`) are rejected with `InvalidArgumentException`.
     * Server side limits are additionally protected by the constraints in the user's profile.
     * `$timeout` is the per-call HTTP timeout (seconds), null means the default from the constructor.
     * Retry: only `select()`, only `ConnectionException` (could not connect, DNS, connection
     * reset), at most `$selectRetries` times, with a small exponential backoff. cURL 28 (timeout) is NOT
     * RETRIED: the server may still be running the query and running a heavy query three times multiplies the load.
     * Errors returned by the server (HTTP 4xx/5xx, `exception`) are never retried. The error is a {@see ClickHouseException}.
     *
     * @param  array<string, bool|int|float|string>  $settings
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = [], array $settings = [], ?int $timeout = null): array
    {
        $body = $this->sendWithRetry($sql, $params, ['default_format' => 'JSON', 'wait_end_of_query' => 1], $timeout, $settings);

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ClickHouseException::unknown('unparseable response');
        }

        // A mid-stream error still arrives as 200 + an `exception` key in some configurations.
        if (! is_array($decoded) || array_key_exists('exception', $decoded) || ! array_key_exists('data', $decoded)) {
            throw ClickHouseException::fromResponse($body);
        }

        return $decoded['data'];
    }

    /** @see self::select() for what is retried */
    private function sendWithRetry(string $sql, array $params, array $arguments, ?int $timeout, array $settings): string
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->send($sql, $params, $arguments, $timeout, $settings);
            } catch (ConnectionException $e) {
                if ($attempt >= $this->selectRetries || self::isClientTimeout($e)) {
                    throw $e;
                }
                usleep($this->selectRetryBackoffMs * 1000 * (2 ** $attempt));
            }
        }
    }

    /**
     * Is this a client side timeout (cURL error 28)? The answer comes from the typed Guzzle exception Laravel
     * wraps (Guzzle 8: ConnectTimeoutException, NetworkTimeoutException, ResponseTimeoutException), or from the
     * `errno` of a handler context on older Guzzle versions. Only when neither is available (a fake, another
     * handler) is the message text the fallback.
     */
    private static function isClientTimeout(ConnectionException $e): bool
    {
        $previous = $e->getPrevious();

        if ($previous instanceof ConnectTimeoutException || $previous instanceof NetworkTimeoutException || $previous instanceof ResponseTimeoutException) {
            return true;
        }

        if ($previous !== null && method_exists($previous, 'getHandlerContext')) {
            $errno = $previous->getHandlerContext()['errno'] ?? null;
            if (is_int($errno)) {
                return $errno === 28;
            }
        }

        return str_contains($e->getMessage(), 'cURL error 28');
    }

    /**
     * @param  array<string, mixed>  $arguments  The client's own URL arguments (default_format etc.)
     * @param  array<string, mixed>  $settings  The caller's per-query settings (validated)
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
            throw ClickHouseException::fromResponse($response->body(), $response->status(), $response->header('X-ClickHouse-Exception-Code') ?: null);
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

    /** URL parameters are parsed as TSV-escaped text. */
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

    /** ClickHouse array literal: `['a','b\'c']`. Text elements are single-quoted with `\` and `'` escaped. */
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
            // Array(Bool) only accepts true/false (1/0 is rejected); a scalar Bool param takes 1/0.
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
}
