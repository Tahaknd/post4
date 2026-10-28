<?php

namespace App\Console\Commands;

use App\Infrastructure\ClickHouse\ScopedViewStatement;
use Illuminate\Console\Command;

class ClickHouseViewSql extends Command
{
    protected $signature = 'clickhouse:view-sql';

    protected $description = 'Prints the CREATE OR REPLACE VIEW statement of the scoped events view for ProjectScopedReader::COLUMNS (paste it into the migration that changes events)';

    public function handle(): int
    {
        $this->line(ScopedViewStatement::sql().';');

        return self::SUCCESS;
    }
}
