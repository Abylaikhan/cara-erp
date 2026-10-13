<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class DbCheck extends Command
{
    protected $signature = 'app:db-check {--connection= : Connection name (default connection if omitted)}';

    protected $description = 'Verify that the connection is a reachable PostgreSQL server (prints no secrets)';

    public function handle(): int
    {
        $name = $this->option('connection') ?: config('database.default');

        try {
            $connection = DB::connection($name);
            $driver = $connection->getDriverName();

            if ($driver !== 'pgsql') {
                $this->error("Connection [{$name}] uses driver [{$driver}], PostgreSQL is required.");

                return self::FAILURE;
            }

            $row = $connection->selectOne('select current_database() as db, current_user as role, current_setting(\'server_version\') as version');
        } catch (Throwable $e) {
            $this->error("Connection [{$name}] failed: ".$e->getMessage());

            return self::FAILURE;
        }

        $this->line("{$name}: PostgreSQL {$row->version}, database={$row->db}, role={$row->role}");

        return self::SUCCESS;
    }
}
