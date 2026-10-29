<?php

namespace App\Console\Commands;

use App\Infrastructure\ClickHouse\ScopedViewStatement;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ClickHouseViewSql extends Command
{
    protected $signature = 'clickhouse:view-sql {relation=events : events or person_distinct_ids}';

    protected $description = 'Prints the CREATE OR REPLACE VIEW statement of a scoped view for its registered column list (paste it into the migration that changes the base table)';

    public function handle(): int
    {
        try {
            $this->line(ScopedViewStatement::for((string) $this->argument('relation')).';');
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        return self::SUCCESS;
    }
}
