<?php

namespace App\Console\Commands;

use App\Infrastructure\ClickHouse\ClickHouseMigrator;
use Illuminate\Console\Command;

class ClickHouseMigrate extends Command
{
    protected $signature = 'clickhouse:migrate';

    protected $description = 'ClickHouse migration dosyalarını sırayla uygular';

    public function handle(ClickHouseMigrator $migrator): int
    {
        $ran = $migrator->migrate();

        $ran === []
            ? $this->info('ClickHouse: uygulanacak migration yok.')
            : collect($ran)->each(fn (string $name) => $this->info("ClickHouse: {$name} uygulandı."));

        return self::SUCCESS;
    }
}
