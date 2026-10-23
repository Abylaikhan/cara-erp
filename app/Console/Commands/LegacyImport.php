<?php

namespace App\Console\Commands;

use App\Legacy\Importers\BankStatementImporter;
use App\Legacy\Importers\CrmTaskImporter;
use App\Legacy\Importers\CustomerImporter;
use App\Legacy\Importers\EmployeeImporter;
use App\Legacy\Importers\ExpenseCategoryImporter;
use App\Legacy\Importers\IncomingInvoiceImporter;
use App\Legacy\Importers\LeadImporter;
use App\Legacy\Importers\MaterialAliasImporter;
use App\Legacy\Importers\MaterialImporter;
use App\Legacy\Importers\MaterialRequestImporter;
use App\Legacy\Importers\MoneyImporter;
use App\Legacy\Importers\OrderImporter;
use App\Legacy\Importers\PieceWorkImporter;
use App\Legacy\Importers\ProductImporter;
use App\Legacy\Importers\ProductionProgressImporter;
use App\Legacy\Importers\ProductionWorksheetImporter;
use App\Legacy\Importers\ProjectImporter;
use App\Legacy\Importers\RealizationImporter;
use App\Legacy\Importers\SalaryAccrualImporter;
use App\Legacy\Importers\SalesWorksheetImporter;
use App\Legacy\Importers\SalonPayrollImporter;
use App\Legacy\Importers\SalonWorksheetImporter;
use App\Legacy\Importers\SupplierImporter;
use App\Legacy\LegacyRejected;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves legacy tables from the loaded snapshot (schema legacy) into the application tables of the migrated modules — one
 * entity, or «all» in dependency order. --dry-run runs exactly the same import (validation, writing, row-by-row
 * verification) inside a transaction that is rolled back: it tells what would be created, changed and removed and writes
 * nothing (only sequence numbers may skip). --errors keeps every problem of a refused set (the console shows ten).
 */
class LegacyImport extends Command
{
    protected $signature = 'legacy:import {entity : all | expense_categories | products | customers | suppliers | warehouse | material_aliases | incoming_invoices | leads | tasks | material_requests | orders | realizations | production_progress | money | projects | salon_worksheet | bank_statements | staff | salary_accruals | wage_items | salon_payroll | sales_worksheet | production_worksheet}
        {--snapshot= : Snapshot id (default: the latest loaded)}
        {--dry-run : Import, verify and roll back: nothing is written}
        {--errors= : File for every problem of a refused set (keep it in .local-audit: it names legacy rows)}';

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
        'staff' => EmployeeImporter::class, // with the salary history and the changes of conditions
        'salary_accruals' => SalaryAccrualImporter::class, // with their earlier versions
        'wage_items' => PieceWorkImporter::class,
        'salon_payroll' => SalonPayrollImporter::class,
        'sales_worksheet' => SalesWorksheetImporter::class, // after orders and realizations: a line names its order and realization
        'production_worksheet' => ProductionWorksheetImporter::class, // after orders: a row names its order line
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Legacy import into production is a separate planned stage (ТЗ MIG).');
        }
        $entity = (string) $this->argument('entity');
        if ($entity !== 'all' && ! isset(self::IMPORTERS[$entity])) {
            $this->error("No importer for {$entity}: its module is not migrated yet. Available: all, ".implode(', ', array_keys(self::IMPORTERS)));

            return self::INVALID;
        }
        $snapshot = $this->option('snapshot') ?: DB::table('legacy.snapshots')->max('id');
        if (! $snapshot) {
            $this->error('No loaded snapshot: run legacy:snapshot and legacy:load first.');

            return self::FAILURE;
        }
        $entities = $entity === 'all' ? array_keys(self::IMPORTERS) : [$entity];
        $dry = (bool) $this->option('dry-run');
        if ($dry) {
            DB::beginTransaction(); // the importers' own transactions become savepoints inside it
        }
        try {
            foreach ($entities as $name) {
                $stats = app(self::IMPORTERS[$name])->import($snapshot);
                foreach ($stats as $key => $value) {
                    $this->line(sprintf('%-22s %s', $key, $value));
                }
                $this->info(($dry ? 'DRY RUN ' : '')."{$name}: imported and verified from snapshot {$snapshot}");
            }
        } catch (LegacyRejected $e) {
            $this->error($e->getMessage());
            if ($file = $this->option('errors')) {
                file_put_contents($file, implode(PHP_EOL, $e->problems()).PHP_EOL);
                chmod($file, 0600);
                $this->line(count($e->problems())." problem(s) written to {$file}");
            }

            return self::FAILURE;
        } finally {
            if ($dry) {
                DB::rollBack();
            }
        }
        if ($dry) {
            $this->warn('DRY RUN: everything above was rolled back — nothing is written (only sequence numbers may skip).');
        }

        return self::SUCCESS;
    }
}
