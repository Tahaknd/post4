<?php

use Illuminate\Support\Facades\DB;

/*
 * Proves that the Integration suite really runs on the throwaway Postgres, with the application's migrations, and
 * that DatabaseTruncation gives every test an empty database. If these fail, nothing else in the suite means
 * anything.
 */

it('runs on the throwaway postgres service, not on the live database', function () {
    expect(config('database.default'))->toBe('pgsql')
        ->and(config('database.connections.pgsql.host'))->toBe('postgres-test')
        ->and(config('database.connections.pgsql.database'))->toBe('posthog_test')
        ->and(DB::scalar('select current_database()'))->toBe('posthog_test')
        ->and(DB::scalar('select current_user'))->toBe('posthog_test')
        ->and(DB::scalar('show server_version_num'))->toBeGreaterThanOrEqual('170000');
});

it('keeps its data on tmpfs, so nothing survives the service', function () {
    // The service's bootstrap user is a superuser and may read the server's own mount table.
    $mount = collect(explode("\n", DB::scalar("select pg_read_file('/proc/mounts')")))
        ->first(fn (string $line) => str_contains($line, ' /var/lib/postgresql/data '));

    expect($mount)->toStartWith('tmpfs ');
});

it('ran the real migrations: the identity tables, the composite key and the jsonb GIN index exist', function () {
    $indexes = DB::table('pg_indexes')->where('tablename', 'persons')->pluck('indexdef', 'indexname');

    expect(DB::table('information_schema.columns')->where('table_name', 'persons')->where('column_name', 'properties')->value('data_type'))->toBe('jsonb')
        ->and($indexes['persons_properties_gin'])->toContain('USING gin (properties)')
        ->and(DB::table('pg_constraint')->where('conrelid', DB::raw("'person_distinct_ids'::regclass"))->where('contype', 'f')->count())->toBe(2)
        ->and(DB::scalar("select count(*) from pg_indexes where tablename = 'person_distinct_ids' and indexdef like '%UNIQUE%(project_id, distinct_id)%'"))->toBe(1);
});

it('gives every test an empty database (first of a pair)', function () {
    expect(DB::table('projects')->count())->toBe(0);

    DB::table('projects')->insert(['name' => 'leftover', 'api_key' => 'phc_leftover', 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);

    expect(DB::table('projects')->count())->toBe(1);
});

it('gives every test an empty database (second of a pair: the project of the first is gone, and ids restart)', function () {
    expect(DB::table('projects')->count())->toBe(0);

    $id = DB::table('projects')->insertGetId(['name' => 'again', 'api_key' => 'phc_again', 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);

    expect($id)->toBe(1);
});
