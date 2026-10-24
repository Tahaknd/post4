<?php

/*
 * Offline guard for docker/clickhouse/users.d/20-readonly-user.xml: every table the read-only user may
 * SELECT from needs its own row policy that filters on SQL_project_id. A grant added without a policy would
 * make that table readable for EVERY project (the server only fails closed for tables that HAVE a policy).
 * scripts/check-readonly-user.sh checks the same thing against a running server.
 */

function readOnlyUserXml(): SimpleXMLElement
{
    $path = base_path('docker/clickhouse/users.d/20-readonly-user.xml');
    $xml = simplexml_load_file($path);
    expect($xml)->toBeInstanceOf(SimpleXMLElement::class, "cannot parse {$path}");

    return $xml;
}

/** @return list<string> table names of every `GRANT SELECT ON posthog.<table>` of posthog_ro */
function grantedTables(SimpleXMLElement $xml): array
{
    $tables = [];
    foreach ($xml->users->posthog_ro->grants->query as $query) {
        $sql = trim((string) $query);
        if (preg_match('/^GRANT\s+SELECT\s+ON\s+posthog\.([A-Za-z0-9_]+)$/', $sql, $m) === 1) {
            $tables[] = $m[1];
        } else {
            // any other shape (posthog.*, column list, *.*, other privileges) needs a conscious test update
            test()->fail("unexpected grant for posthog_ro, extend this guard first: {$sql}");
        }
    }

    return $tables;
}

it('has a SQL_project_id row policy for every table posthog_ro may select from', function () {
    $xml = readOnlyUserXml();
    $tables = grantedTables($xml);

    expect($tables)->not->toBeEmpty();
    foreach ($tables as $table) {
        $filter = (string) ($xml->users->posthog_ro->databases->posthog->{$table}->filter ?? '');
        test()->assertStringContainsString('SQL_project_id', $filter, "posthog.{$table} is granted but has no row policy filter on SQL_project_id");
        test()->assertStringContainsString('project_id =', $filter);
    }
});

it('has no row policy for a table that is not granted', function () {
    $xml = readOnlyUserXml();
    $tables = grantedTables($xml);

    foreach ($xml->users->posthog_ro->databases->posthog->children() as $policy) {
        expect($tables)->toContain($policy->getName());
    }
});

it('keeps the custom settings prefix that the row policy needs', function () {
    $xml = simplexml_load_file(base_path('docker/clickhouse/config.d/10-custom-settings.xml'));

    expect((string) $xml->custom_settings_prefixes)->toBe('SQL_');
});

it('mounts the config.d file next to the users.d file in docker-compose.yml', function () {
    $compose = file_get_contents(base_path('docker-compose.yml'));

    expect($compose)->toContain('source: ./docker/clickhouse/config.d/10-custom-settings.xml')
        ->and($compose)->toContain('target: /etc/clickhouse-server/config.d/10-custom-settings.xml');
});
