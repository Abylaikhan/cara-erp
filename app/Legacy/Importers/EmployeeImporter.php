<?php

namespace App\Legacy\Importers;

use App\Legacy\LegacyRejected;
use App\Models\Employee;
use App\Models\EmployeeChange;
use App\Models\EmployeeSalaryHistory;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Legacy staff with staff_salary_history and staff_changes → M17 «Сотрудники». Whole set validated first; one transaction;
 * copied as it is (MirrorsLegacyTable). The PIN is not copied (ADR-0004).
 */
class EmployeeImporter
{
    use MirrorsLegacyTable;

    private const HISTORY_NUMBERS = ['salary', 'commission_pct'];

    private const CHANGE_NUMBERS = ['base_salary', 'deduction_meal', 'deduction_opv_pct', 'deduction_osms_pct', 'deduction_so_pct'];

    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $staff = $this->legacyTable($snapshotId, 'staff', Employee::NUMBER_FIELDS);
        $history = $this->legacyTable($snapshotId, 'staff_salary_history', self::HISTORY_NUMBERS);
        $changes = $this->legacyTable($snapshotId, 'staff_changes', self::CHANGE_NUMBERS);
        $this->validate($staff, $history, $changes);

        return DB::transaction(function () use ($staff, $history, $changes, $snapshotId, $by) {
            $employees = array_map(fn (array $r) => $this->employee($r), $staff);
            $stats = ['legacy_staff' => count($staff)] + $this->mirror('staff', Employee::class, $employees, $snapshotId);
            $ids = Employee::query()->pluck('id', 'uuid')->all();
            $historyRows = array_map(fn (array $r) => $this->history($r, $ids), $history);
            $changeRows = array_map(fn (array $r) => $this->change($r, $ids), $changes);
            $h = $this->mirror('staff_salary_history', EmployeeSalaryHistory::class, $historyRows, $snapshotId);
            $c = $this->mirror('staff_changes', EmployeeChange::class, $changeRows, $snapshotId);
            $stats['salary_history'] = count($history);
            $stats['salary_history_changed'] = $h['created'] + $h['updated'];
            $stats['changes'] = count($changes);
            $stats['changes_changed'] = $c['created'] + $c['updated'];
            // children first: a history row refers to its employee
            $stats['removed'] = $this->removeGone('staff_salary_history', EmployeeSalaryHistory::class, array_column($history, 'id'), $by)
                + $this->removeGone('staff_changes', EmployeeChange::class, array_column($changes, 'id'), $by)
                + $this->removeGone('staff', Employee::class, array_column($staff, 'id'), $by);
            $this->verifyMirror(Employee::class, $employees, array_fill_keys(Employee::NUMBER_FIELDS, 12), Employee::DATE_FIELDS);
            $this->verifyMirror(EmployeeSalaryHistory::class, $historyRows, array_fill_keys(self::HISTORY_NUMBERS, 12), ['valid_from']);
            $this->verifyMirror(EmployeeChange::class, $changeRows, array_fill_keys(self::CHANGE_NUMBERS, 12), ['change_date']);
            foreach (Employee::TYPES as $type) {
                $stats[$type] = Employee::query()->where('type', $type)->count();
            }
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'staff', null, $stats, $by);

            return $stats;
        });
    }

    /** @return array<string, mixed> */
    private function employee(array $r): array
    {
        $created = Carbon::parse($r['created_at']);
        $a = [
            'uuid' => $r['id'],
            'name' => $r['name'],
            'position' => self::text($r, 'pos'),
            'type' => self::text($r, 'type'),
            'workshop' => self::text($r, 'ws'),
            'status' => self::text($r, 'status'),
            'active' => (bool) ($r['active'] ?? true),
            'hired' => self::text($r, 'hired'),
            'fired' => self::text($r, 'fired'),
            'fired_date' => self::text($r, 'fired_date'),
            'phone' => self::text($r, 'phone'),
            'iin' => self::text($r, 'iin'),
            'commission_method' => self::text($r, 'commission_method'),
            'comment' => self::text($r, 'comment'),
            'created_at' => $created,
            'updated_at' => $created,
        ];
        foreach (Employee::NUMBER_FIELDS as $f) {
            $a[$f] = $r[$f] === null ? null : bcadd($r[$f], '0', 12);
        }

        return $a;
    }

    /**
     * @param  array<string, int>  $ids
     * @return array<string, mixed>
     */
    private function history(array $r, array $ids): array
    {
        $created = Carbon::parse($r['created_at']);

        return [
            'uuid' => $r['id'],
            'employee_id' => $ids[$r['staff_id']],
            'salary' => $r['salary'] === null ? null : bcadd($r['salary'], '0', 12),
            'commission_pct' => $r['commission_pct'] === null ? null : bcadd($r['commission_pct'], '0', 12),
            'valid_from' => $r['valid_from'],
            'note' => self::text($r, 'note'),
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }

    /**
     * @param  array<string, int>  $ids
     * @return array<string, mixed>
     */
    private function change(array $r, array $ids): array
    {
        $created = Carbon::parse($r['created_at']);
        $a = [
            'uuid' => $r['id'],
            'employee_id' => $ids[$r['staff_id']],
            'change_date' => $r['change_date'],
            'change_type' => $r['change_type'],
            'comment' => self::text($r, 'comment'),
            'created_at' => $created,
            'updated_at' => $created,
        ];
        foreach (self::CHANGE_NUMBERS as $f) {
            $a[$f] = $r[$f] === null ? null : bcadd($r[$f], '0', 12);
        }

        return $a;
    }

    private function validate(array $staff, array $history, array $changes): void
    {
        $problems = [];
        $ids = [];
        foreach ($staff as $i => $r) {
            $ref = 'staff row '.($i + 1);
            if (! self::isUuid($r['id'] ?? null) || isset($ids[$r['id']])) {
                $problems[] = "{$ref}: missing, malformed or duplicate id";
            }
            $ids[$r['id'] ?? ''] = true;
            if (! is_string($r['name'] ?? null) || trim($r['name']) === '' || mb_strlen($r['name']) > 255) {
                $problems[] = "{$ref}: no name or too long";
            }
            if (self::text($r, 'type') !== null && ! in_array($r['type'], Employee::TYPES, true)) {
                $problems[] = "{$ref}: unknown type";
            }
            if (isset($r['active']) && ! is_bool($r['active'])) {
                $problems[] = "{$ref}: active is not true/false";
            }
            foreach (Employee::DATE_FIELDS as $f) {
                if (self::text($r, $f) !== null && ! self::isDay($r[$f])) {
                    $problems[] = "{$ref}: {$f} is not YYYY-MM-DD";
                }
            }
            foreach (Employee::NUMBER_FIELDS as $f) {
                if ($r[$f] !== null && ! self::fits($r[$f], 12)) {
                    $problems[] = "{$ref}: {$f} is not a number that fits";
                }
            }
            foreach (['pos' => 255, 'ws' => 64, 'status' => 32, 'phone' => 64, 'iin' => 32, 'commission_method' => 32] as $f => $max) {
                if (mb_strlen((string) ($r[$f] ?? '')) > $max) {
                    $problems[] = "{$ref}: {$f} too long";
                }
            }
            if (empty($r['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        foreach (['staff_salary_history' => [$history, 'valid_from', self::HISTORY_NUMBERS], 'staff_changes' => [$changes, 'change_date', self::CHANGE_NUMBERS]] as $table => [$rows, $day, $numbers]) {
            $own = [];
            foreach ($rows as $i => $r) {
                $ref = "{$table} row ".($i + 1);
                if (! self::isUuid($r['id'] ?? null) || isset($own[$r['id']])) {
                    $problems[] = "{$ref}: missing, malformed or duplicate id";
                }
                $own[$r['id'] ?? ''] = true;
                if (! isset($ids[$r['staff_id'] ?? ''])) {
                    $problems[] = "{$ref}: refers to an employee that is not in staff";
                }
                if (! self::isDay($r[$day] ?? null)) {
                    $problems[] = "{$ref}: {$day} is not YYYY-MM-DD";
                }
                foreach ($numbers as $f) {
                    if ($r[$f] !== null && ! self::fits($r[$f], 12)) {
                        $problems[] = "{$ref}: {$f} is not a number that fits";
                    }
                }
                if ($table === 'staff_changes' && (! is_string($r['change_type'] ?? null) || $r['change_type'] === '' || mb_strlen($r['change_type']) > 32)) {
                    $problems[] = "{$ref}: no change type or too long";
                }
                if (empty($r['created_at'])) {
                    $problems[] = "{$ref}: no created_at";
                }
            }
        }
        if ($problems) {
            throw new LegacyRejected('Legacy staff', $problems);
        }
    }
}
