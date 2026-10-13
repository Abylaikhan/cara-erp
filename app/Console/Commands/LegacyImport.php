<?php

namespace App\Console\Commands;

use App\Legacy\Importers\ExpenseCategoryImporter;
use App\Legacy\Importers\ProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Moves one legacy table from the loaded snapshot (schema legacy) into the application tables of a migrated module. */
class LegacyImport extends Command
{
    protected $signature = 'legacy:import {entity : expense_categories | products} {--snapshot= : Snapshot id (default: the latest loaded)}';

    protected $description = 'Import a legacy table into the application (only modules that are already migrated)';

    /** @var array<string, class-string> */
    private const IMPORTERS = ['expense_categories' => ExpenseCategoryImporter::class, 'products' => ProductImporter::class];

    public function handle(): int
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Legacy import into production is a separate planned stage (ТЗ MIG).');
        }
        $entity = $this->argument('entity');
        if (! isset(self::IMPORTERS[$entity])) {
            $this->error("No importer for {$entity}: its module is not migrated yet. Available: ".implode(', ', array_keys(self::IMPORTERS)));

            return self::INVALID;
        }
        $snapshot = $this->option('snapshot') ?: DB::table('legacy.snapshots')->max('id');
        if (! $snapshot) {
            $this->error('No loaded snapshot: run legacy:snapshot and legacy:load first.');

            return self::FAILURE;
        }
        $stats = app(self::IMPORTERS[$entity])->import($snapshot);
        foreach ($stats as $key => $value) {
            $this->line(sprintf('%-22s %s', $key, $value));
        }
        $this->info("{$entity}: imported and verified from snapshot {$snapshot}");

        return self::SUCCESS;
    }
}
