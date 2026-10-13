<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Refuses to run tests unless the default connection is the isolated test PostgreSQL.
 * Runs before RefreshDatabase and before any data-changing SQL (ТЗ ENV-02).
 */
trait GuardsTestDatabase
{
    public const TEST_DATABASE_MARKER = 'promtech:test-database';

    protected function assertIsolatedTestDatabase(): void
    {
        $config = config('database.connections');
        $default = config('database.default');
        $test = $config['pgsql_test'] ?? [];
        $dev = $config['pgsql'] ?? [];

        $problems = [];
        if (! app()->environment('testing')) {
            $problems[] = 'APP_ENV is not "testing"';
        }
        if ($default !== 'pgsql_test') {
            $problems[] = "default connection is [{$default}], expected [pgsql_test]";
        }
        if (($test['database'] ?? null) === ($dev['database'] ?? null) && ($test['host'] ?? null) === ($dev['host'] ?? null)) {
            $problems[] = 'test database equals the development database';
        }
        if (($test['username'] ?? null) === ($dev['username'] ?? null)) {
            $problems[] = 'test role equals the development role';
        }

        if ($problems === []) {
            $marker = DB::connection('pgsql_test')->selectOne(
                'select shobj_description(oid, \'pg_database\') as marker from pg_database where datname = current_database()'
            )->marker ?? null;

            if ($marker !== self::TEST_DATABASE_MARKER) {
                $problems[] = 'database comment marker "'.self::TEST_DATABASE_MARKER.'" is missing';
            }
        }

        if ($problems !== []) {
            throw new RuntimeException('Refusing to run tests: '.implode('; ', $problems).'.');
        }
    }
}
