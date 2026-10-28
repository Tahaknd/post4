<?php

use App\Domain\Insights\Trends\BreakdownColumn;
use App\Infrastructure\ClickHouse\ProjectScopedReader;
use Tests\Support\MigrationColumns;

it('gives every breakdown column a non-empty label', function () {
    foreach (BreakdownColumn::cases() as $column) {
        expect($column->label())->toBeString()->not->toBe('');
    }
});

it('offers only columns of the scoped relation', function () {
    foreach (BreakdownColumn::cases() as $column) {
        expect(ProjectScopedReader::COLUMNS)->toContain($column->value);
    }
});

it('prints, from COLUMNS, exactly the view statement the last migration contains', function () {
    // one source for the column list: the command output equals the CREATE OR REPLACE VIEW the migrations leave behind
    expect(ProjectScopedReader::viewStatement())->toBe(MigrationColumns::viewStatement());

    $this->artisan('clickhouse:view-sql')->expectsOutput(ProjectScopedReader::viewStatement().';')->assertExitCode(0);
});
