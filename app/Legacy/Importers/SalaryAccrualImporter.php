<?php

namespace App\Legacy\Importers;

use App\Legacy\LegacyRejected;
use App\Models\SalaryAccrual;
use App\Models\SalaryAccrualChange;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Legacy salary_accruals with salary_accruals_history → the payroll records of M19/M20. Whole set validated first; one
 * transaction; copied as it is (MirrorsLegacyTable). Amounts keep all their decimals (numeric(30,15)); details keep the
 * legacy JSON as stored. A record names the employee by text, as legacy does: names of people no longer in staff stay.
 */
class SalaryAccrualImporter
{
    use MirrorsLegacyTable;

    private const RAW = ['amount', 'deducted', 'net', 'details'];

    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $accruals = $this->legacyTable($snapshotId, 'salary_accruals', self::RAW);
        $history = $this->legacyTable($snapshotId, 'salary_accruals_history', self::RAW);
        $this->validate($accruals, $history);

        return DB::transaction(function () use ($accruals, $history, $snapshotId, $by) {
            $rows = array_map(fn (array $r) => $this->record($r) + ['type' => $r['type']], $accruals);
            $stats = ['legacy_rows' => count($accruals)] + $this->mirror('salary_accruals', SalaryAccrual::class, $rows, $snapshotId);
            // an earlier version of an accrual deleted in legacy keeps no link (the accrual goes below)
            $ids = SalaryAccrual::query()->whereIn('uuid', array_column($accruals, 'id'))->pluck('id', 'uuid')->all();
            $changes = array_map(fn (array $r) => $this->record($r) + [
                'accrual_uuid' => $r['accrual_id'],
                'salary_accrual_id' => $ids[$r['accrual_id']] ?? null,
                'changed_by' => self::text($r, 'changed_by'),
                'changed_at' => Carbon::parse($r['changed_at']),
                'created_at' => Carbon::parse($r['changed_at']),
                'updated_at' => Carbon::parse($r['changed_at']),
            ], $history);
            $h = $this->mirror('salary_accruals_history', SalaryAccrualChange::class, $changes, $snapshotId);
            $stats['history'] = count($history);
            $stats['history_changed'] = $h['created'] + $h['updated'];
            $stats['removed'] = $this->removeGone('salary_accruals_history', SalaryAccrualChange::class, array_column($history, 'id'), $by)
                + $this->removeGone('salary_accruals', SalaryAccrual::class, array_column($accruals, 'id'), $by);
            $numbers = array_fill_keys(SalaryAccrual::NUMBER_FIELDS, 15);
            $this->verifyMirror(SalaryAccrual::class, $rows, $numbers, ['accrual_date']);
            $this->verifyMirror(SalaryAccrualChange::class, $changes, $numbers, ['accrual_date']);
            foreach (SalaryAccrual::TYPES as $type) {
                $stats[$type] = SalaryAccrual::query()->where('type', $type)->count();
            }
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'salary_accruals', null, $stats, $by);

            return $stats;
        });
    }

    /** @return array<string, mixed> the fields an accrual and its earlier version share */
    private function record(array $r): array
    {
        $a = [
            'uuid' => $r['id'],
            'emp_name' => $r['emp_name'],
            'accrual_date' => $r['date'],
            'details' => $r['details'],
            'comment' => self::text($r, 'comment'),
        ];
        foreach (SalaryAccrual::NUMBER_FIELDS as $f) {
            $a[$f] = bcadd($r[$f], '0', 15);
        }
        if (isset($r['created_at'])) {
            $a['created_at'] = $a['updated_at'] = Carbon::parse($r['created_at']);
        }

        return $a;
    }

    private function validate(array $accruals, array $history): void
    {
        $problems = [];
        foreach (['salary_accruals' => $accruals, 'salary_accruals_history' => $history] as $table => $rows) {
            $ids = [];
            foreach ($rows as $i => $r) {
                $ref = "{$table} row ".($i + 1);
                if (! self::isUuid($r['id'] ?? null) || isset($ids[$r['id']])) {
                    $problems[] = "{$ref}: missing, malformed or duplicate id";
                }
                $ids[$r['id'] ?? ''] = true;
                if (! is_string($r['emp_name'] ?? null) || $r['emp_name'] === '' || mb_strlen($r['emp_name']) > 255) {
                    $problems[] = "{$ref}: no employee name or too long";
                }
                if (! self::isDay($r['date'] ?? null)) {
                    $problems[] = "{$ref}: date is not YYYY-MM-DD";
                }
                foreach (SalaryAccrual::NUMBER_FIELDS as $f) {
                    if (! self::fits($r[$f], 15)) {
                        $problems[] = "{$ref}: {$f} is not a number that fits";
                    }
                }
                if ($r['details'] !== null && ! str_starts_with($r['details'], '{')) {
                    $problems[] = "{$ref}: details is not an object";
                }
                if ($table === 'salary_accruals') {
                    if (! in_array($r['type'] ?? null, SalaryAccrual::TYPES, true)) {
                        $problems[] = "{$ref}: unknown type";
                    }
                    if (empty($r['created_at'])) {
                        $problems[] = "{$ref}: no created_at";
                    }
                } else {
                    if (! self::isUuid($r['accrual_id'] ?? null)) {
                        $problems[] = "{$ref}: malformed accrual_id";
                    }
                    if (empty($r['changed_at']) || mb_strlen((string) ($r['changed_by'] ?? '')) > 64) {
                        $problems[] = "{$ref}: no changed_at or changed_by too long";
                    }
                }
            }
        }
        if ($problems) {
            throw new LegacyRejected('Legacy salary accruals', $problems);
        }
    }
}
