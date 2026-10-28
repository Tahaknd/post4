<?php

namespace App\Console\Commands;

use App\Domain\Capture\Enrichment\MmdbGeoLookup;
use App\Domain\Capture\Enrichment\PublicIp;
use Illuminate\Console\Command;

class GeoIpCheck extends Command
{
    protected $signature = 'geoip:check {ip=8.8.8.8 : Public address to look up} {--path= : Database file (default: capture.enrichment.geoip_path / GEOIP_DB_PATH)}';

    protected $description = 'Opens the configured GeoIP database with the real reader and shows what the capture path would store for an ip. Exit codes: 0 answered, 1 usable database but no answer for this ip, 2 no database or unreadable';

    public function handle(): int
    {
        $path = (string) ($this->option('path') ?: config('capture.enrichment.geoip_path'));
        $ip = (string) $this->argument('ip');
        $this->line("database: {$path}");

        $result = (new MmdbGeoLookup($path))->check($ip);

        if ($result['status'] !== 'ready') {
            $this->error($result['status'] === 'no database' ? 'no database: geoip enrichment is off (see GEOIP_DB_PATH)' : "unreadable: {$result['error']}");

            return 2;
        }

        if (PublicIp::normalise($ip) === null) {
            $this->warn("{$ip} is not a globally routable address: the capture path never looks it up");

            return 1;
        }

        if ($result['properties'] === []) {
            $this->warn("database ok, but no usable record for {$ip}".($result['error'] ? " ({$result['error']})" : ''));

            return 1;
        }

        foreach ($result['properties'] as $name => $value) {
            $this->line(str_pad($name, 28).$value);
        }

        return self::SUCCESS;
    }
}
