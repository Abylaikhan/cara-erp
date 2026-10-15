<?php

namespace App\Console\Commands;

use App\Legacy\Importers\BankStatementImporter;
use App\Legacy\Importers\CrmTaskImporter;
use App\Legacy\Importers\CustomerImporter;
use App\Legacy\Importers\ExpenseCategoryImporter;
use App\Legacy\Importers\IncomingInvoiceImporter;
use App\Legacy\Importers\LeadImporter;
use App\Legacy\Importers\MaterialAliasImporter;
use App\Legacy\Importers\MaterialImporter;
use App\Legacy\Importers\MaterialRequestImporter;
use App\Legacy\Importers\MoneyImporter;
use App\Legacy\Importers\OrderImporter;
use App\Legacy\Importers\ProductImporter;
use App\Legacy\Importers\ProductionProgressImporter;
use App\Legacy\Importers\ProjectImporter;
use App\Legacy\Importers\RealizationImporter;
use App\Legacy\Importers\SalonWorksheetImporter;
use App\Legacy\Importers\SupplierImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Moves one legacy table from the loaded snapshot (schema legacy) into the application tables of a migrated module. */
class LegacyImport extends Command
{
    protected $signature = 'legacy:import {entity : expense_categories | products | customers | suppliers | warehouse | material_aliases | incoming_invoices | leads | tasks | material_requests | orders | realizations | production_progress | money | projects | salon_worksheet | bank_statements} {--snapshot= : Snapshot id (default: the latest loaded)}';

    protected $description = 'Import a legacy table into the application (only modules that are already migrated)';

    /** @var array<string, class-string> */
    private const IMPORTERS = [
        'expense_categories' => ExpenseCategoryImporter::class,
        'products' => ProductImporter::class,
        'customers' => CustomerImporter::class,
        'suppliers' => SupplierImporter::class,
        'warehouse' => MaterialImporter::class,
        'material_aliases' => MaterialAliasImporter::class,
        'incoming_invoices' => IncomingInvoiceImporter::class, // with their lines and payment links
        'leads' => LeadImporter::class,
        'tasks' => CrmTaskImporter::class, // after leads: a task refers to its lead
        'material_requests' => MaterialRequestImporter::class,
        'orders' => OrderImporter::class, // with their lines, after legacy:natural-order order_items
        'realizations' => RealizationImporter::class, // after orders
        'production_progress' => ProductionProgressImporter::class, // after orders: a record names its order line
        'money' => MoneyImporter::class, // accounts, operations, order payments, advance reports — after orders and expense items
        'projects' => ProjectImporter::class, // with their calculations and messages
        'salon_worksheet' => SalonWorksheetImporter::class,
        'bank_statements' => BankStatementImporter::class, // after money: a statement belongs to an account
    ];

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
