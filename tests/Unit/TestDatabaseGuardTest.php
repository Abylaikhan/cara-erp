<?php

namespace Tests\Unit;

use RuntimeException;
use Tests\TestCase;

class TestDatabaseGuardTest extends TestCase
{
    public function test_guard_refuses_development_connection(): void
    {
        config(['database.default' => 'pgsql']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('default connection is [pgsql]');

        $this->assertIsolatedTestDatabase();
    }

    public function test_guard_refuses_test_connection_pointing_to_dev_database(): void
    {
        config(['database.connections.pgsql_test.database' => config('database.connections.pgsql.database')]);
        config(['database.connections.pgsql_test.host' => config('database.connections.pgsql.host')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test database equals the development database');

        $this->assertIsolatedTestDatabase();
    }

    public function test_guard_accepts_isolated_test_database(): void
    {
        $this->assertIsolatedTestDatabase();
        $this->addToAssertionCount(1);
    }
}
