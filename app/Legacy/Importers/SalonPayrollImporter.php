<?php

namespace App\Legacy\Importers;

use App\Legacy\LegacyRejected;
use App\Models\SalonPayrollRow;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Legacy salon_payroll → the salon payroll of M21. Whole set validated first; one transaction; copied as it is (MirrorsLegacyTable). */
class SalonPayrollImporter
{
    use MirrorsLegacyTable;

    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $lines = $this->legacyTable($snapshotId, 'salon_payroll', [...SalonPayrollRow::NUMBER_FIELDS, 'year', 'month']);
        $this->validate($lines);

        return DB::transaction(function () use ($lines, $snapshotId, $by) {
            $rows = array_map(function (array $r) {
                $created = Carbon::parse($r['created_at']);
                $a = ['uuid' => $r['id'], 'employee' => $r['employee'], 'year' => (int) $r['year'], 'month' => (int) $r['month'], 'created_at' => $created, 'updated_at' => $created];
                foreach (SalonPayrollRow::NUMBER_FIELDS as $f) {
                    $a[$f] = bcadd($r[$f], '0', 12);
                }

                return $a;
            }, $lines);
            $stats = ['legacy_rows' => count($lines)] + $this->mirror('salon_payroll', SalonPayrollRow::class, $rows, $snapshotId);
            $stats['removed'] = $this->removeGone('salon_payroll', SalonPayrollRow::class, array_column($lines, 'id'), $by);
            $this->verifyMirror(SalonPayrollRow::class, $rows, array_fill_keys(SalonPayrollRow::NUMBER_FIELDS, 12), []);
            $stats['months'] = SalonPayrollRow::query()->distinct()->count(DB::raw('year * 100 + month'));
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'salon_payroll', null, $stats, $by);

            return $stats;
        });
    }

    private function validate(array $lines): void
    {
        $problems = [];
        $ids = [];
        foreach ($lines as $i => $r) {
            $ref = 'row '.($i + 1);
            if (! self::isUuid($r['id'] ?? null) || isset($ids[$r['id']])) {
                $problems[] = "{$ref}: missing, malformed or duplicate id";
            }
            $ids[$r['id'] ?? ''] = true;
            if (! is_string($r['employee'] ?? null) || $r['employee'] === '' || mb_strlen($r['employee']) > 255) {
                $problems[] = "{$ref}: no employee or too long";
            }
            if (! preg_match('/^\d{4}$/', (string) $r['year']) || ! preg_match('/^\d{1,2}$/', (string) $r['month']) || (int) $r['month'] < 1 || (int) $r['month'] > 12) {
                $problems[] = "{$ref}: year or month is not a month";
            }
            foreach (SalonPayrollRow::NUMBER_FIELDS as $f) {
                if (! self::fits($r[$f], 12)) {
                    $problems[] = "{$ref}: {$f} is not a number that fits";
                }
            }
            if (empty($r['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        if ($problems) {
            throw new LegacyRejected('Legacy salon payroll', $problems);
        }
    }
}
