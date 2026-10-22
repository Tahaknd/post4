<?php

namespace App\Infrastructure\ClickHouse;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClickHouseClient
{
    public function __construct(
        private readonly string $url,
        private readonly string $database,
        private readonly string $user,
        private readonly string $password,
    ) {}

    /** Veri döndürmeyen ifade (DDL, INSERT). */
    public function execute(string $sql, array $params = []): void
    {
        $this->send($sql, $params);
    }

    /** @return array<int, array<string, mixed>> */
    public function select(string $sql, array $params = []): array
    {
        $body = $this->send($sql.' FORMAT JSON', $params);

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR)['data'];
    }

    private function send(string $sql, array $params): string
    {
        $query = ['database' => $this->database];
        foreach ($params as $name => $value) {
            $query['param_'.$name] = (string) $value;
        }

        $response = Http::withBasicAuth($this->user, $this->password)
            ->withBody($sql, 'text/plain')
            ->post($this->url.'?'.http_build_query($query));

        if ($response->failed()) {
            throw new RuntimeException('ClickHouse error: '.$response->body());
        }

        return $response->body();
    }
}
