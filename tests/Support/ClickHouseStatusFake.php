<?php

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * Canned ClickHouse answers for `capture:status`, routed by the leading "capture:status <tag>" SQL comment
 * each query carries. Every tag has a healthy default; a test overrides only what it needs.
 */
final class ClickHouseStatusFake
{
    /** @var list<string> */
    public static array $seenTags = [];

    /**
     * @param  array<string, list<array<string, mixed>>|\Closure>  $override  data rows per tag (or a closure returning a response)
     */
    public static function install(array $override = []): void
    {
        self::$seenTags = [];
        $defaults = self::defaults();

        self::reset();

        Http::fake(function ($request) use ($override, $defaults) {
            if (preg_match('#/\* capture:status (\w+) \*/#', $request->body(), $m) !== 1) {
                return Http::response('unexpected query: '.$request->body(), 500);
            }
            self::$seenTags[] = $m[1];

            $rows = $override[$m[1]] ?? $defaults[$m[1]] ?? null;
            if ($rows instanceof \Closure) {
                return $rows();
            }

            return $rows === null
                ? Http::response('unknown tag '.$m[1], 500)
                : Http::response(json_encode(['meta' => [], 'data' => $rows, 'rows' => count($rows)]));
        });
    }

    public static function down(): void
    {
        self::reset();
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to clickhouse port 8123'));
    }

    /** Drops earlier fakes: the first matching stub wins, so a re-install must start from a clean factory. */
    private static function reset(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    /** A consumer row relative to the fake clock (seconds ago). */
    public static function consumer(int $commitAgo = 600, int $pollAgo = 1, array $exceptions = [], array $offsets = [-1001, 1447, -1001]): array
    {
        $now = CarbonImmutable::now('UTC')->getTimestamp();

        return [
            'last_commit_ts' => $commitAgo === 0 ? 0 : $now - $commitAgo,
            'last_poll_ts' => $now - $pollAgo,
            'exception_times' => array_map(fn (int $ago) => $now - $ago, array_keys($exceptions)),
            'exception_texts' => array_values($exceptions),
            'offsets' => $offsets,
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function defaults(): array
    {
        $now = CarbonImmutable::now('UTC')->getTimestamp();

        return [
            'events' => [['now_ts' => $now, 'total' => '1500', 'last_hour' => '20', 'newest_ts' => $now - 30]],
            'errors_count' => [['c' => '0']],
            'errors_top' => [],
            'consumers' => [self::consumer()],
            'too_many_parts' => [],
            'disks' => [['name' => 'default', 'free_space' => '50000000000', 'total_space' => '100000000000']],
            'parts' => [['active' => '120', 'max_parts' => '100000']],
            'mutations' => [['c' => '0']],
        ];
    }
}
