<?php

namespace App\Console\Commands;

use App\Infrastructure\ClickHouse\ClickHouseMigrator;
use App\Infrastructure\ClickHouse\ScopedViewDriftCheck;
use Illuminate\Console\Command;

class ClickHouseMigrate extends Command
{
    protected $signature = 'clickhouse:migrate';

    protected $description = 'ClickHouse migration dosyalarını sırayla uygular';

    public function handle(ClickHouseMigrator $migrator, ScopedViewDriftCheck $driftCheck): int
    {
        $ran = $migrator->migrate();

        $ran === []
            ? $this->info('ClickHouse: uygulanacak migration yok.')
            : collect($ran)->each(fn (string $name) => $this->info("ClickHouse: {$name} uygulandı."));

        // Fails the command (and so the container start, like a checksum mismatch) when a migration changed
        // `events` without recreating the scoped view: the view would silently miss the new column.
        $problems = $driftCheck->problems();
        foreach ($problems as $problem) {
            $this->error("ClickHouse: {$problem}");
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
