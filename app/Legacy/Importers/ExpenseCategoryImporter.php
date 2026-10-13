<?php

namespace App\Legacy\Importers;

use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy expense_categories (code, name, parent_code, is_fixed, sort_order, active) → M14 directory.
 * Validates the whole set first; changes nothing if any row is invalid. One transaction, idempotent,
 * every record mapped in legacy_id_map. Synthetic demo rows that are not in the legacy data are removed
 * (unreferenced) or deactivated, so the directory shows exactly the real data.
 */
class ExpenseCategoryImporter
{
    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $rows = collect(DB::select('SELECT data FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no', [$snapshotId, 'expense_categories']))
            ->map(fn ($r) => json_decode($r->data, true, flags: JSON_THROW_ON_ERROR));
        if ($rows->isEmpty()) {
            throw new RuntimeException("Snapshot {$snapshotId} has no expense_categories rows");
        }
        $this->validate($rows->all());
        $codes = $rows->pluck('code')->all();

        return DB::transaction(function () use ($rows, $codes, $snapshotId, $by) {
            $stats = ['legacy_rows' => $rows->count(), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'synthetic_removed' => 0, 'synthetic_deactivated' => 0];

            // 1. Synthetic demo rows that the real directory does not contain.
            $mapped = DB::table('legacy_id_map')->where('target_table', 'expense_categories')->pluck('target_id')->all();
            $synthetic = ExpenseCategory::query()->whereNotIn('code', $codes)->whereNotIn('id', $mapped)->orderByRaw('parent_id IS NULL')->lockForUpdate()->get();
            foreach ($synthetic as $row) {
                $referenced = DB::table('expense_category_merges')->where('from_id', $row->id)->orWhere('to_id', $row->id)->exists()
                    || ExpenseCategory::query()->where('parent_id', $row->id)->whereNotIn('id', $synthetic->pluck('id'))->exists();
                if ($referenced) {
                    $row->forceFill(['active' => false, 'deactivated_at' => now()])->save();
                    $stats['synthetic_deactivated']++;
                } else {
                    ExpenseCategory::query()->whereKey($row->id)->delete();
                    $stats['synthetic_removed']++;
                }
            }

            // 2. Real rows: categories first, then articles (parent by code).
            $idByCode = [];
            foreach ($rows->sortBy(fn ($r) => $r['parent_code'] === null ? 0 : 1) as $r) {
                $attributes = [
                    'name' => trim((string) $r['name']),
                    'parent_id' => $r['parent_code'] === null ? null : $idByCode[$r['parent_code']],
                    'is_fixed' => (bool) ($r['is_fixed'] ?? false),
                    'sort_order' => (int) ($r['sort_order'] ?? 50),
                    'active' => ($r['active'] ?? true) !== false,
                ];
                $category = ExpenseCategory::query()->where('code', $r['code'])->lockForUpdate()->first();
                if ($category === null) {
                    $category = new ExpenseCategory(['code' => $r['code']]);
                    $stats['created']++;
                }
                $category->forceFill($attributes);
                $category->deactivated_at = $attributes['active'] ? null : ($category->deactivated_at ?? now());
                if ($category->exists && $category->isDirty()) {
                    $category->lock_version++;
                    $stats['updated']++;
                } elseif ($category->exists) {
                    $stats['unchanged']++;
                }
                $category->save();
                $idByCode[$r['code']] = $category->id;
                DB::table('legacy_id_map')->upsert(
                    [['source_table' => 'expense_categories', 'source_key' => (string) ($r['id'] ?? $r['code']), 'target_table' => 'expense_categories', 'target_id' => $category->id, 'snapshot_id' => $snapshotId]],
                    ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at'],
                );
            }

            // 3. Verify inside the transaction: every legacy row is represented exactly.
            foreach ($rows as $r) {
                $c = ExpenseCategory::query()->with('parent')->where('code', $r['code'])->firstOrFail();
                $expected = [trim((string) $r['name']), $r['parent_code'], (bool) ($r['is_fixed'] ?? false), (int) ($r['sort_order'] ?? 50), ($r['active'] ?? true) !== false];
                $actual = [$c->name, $c->parent?->code, $c->is_fixed, $c->sort_order, $c->active];
                if ($expected !== $actual) {
                    throw new RuntimeException("Verification failed for code {$r['code']}");
                }
            }
            $stats['active'] = ExpenseCategory::query()->active()->count();
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'expense_categories', null, $stats, $by);

            return $stats;
        });
    }

    /** @param list<array<string, mixed>> $rows */
    private function validate(array $rows): void
    {
        $byCode = [];
        $problems = [];
        foreach ($rows as $r) {
            $code = (string) ($r['code'] ?? '');
            if (! preg_match('/^[a-z0-9_]+$/', $code)) {
                $problems[] = 'bad code format';
            }
            if (isset($byCode[$code])) {
                $problems[] = "duplicate code {$code}";
            }
            if (trim((string) ($r['name'] ?? '')) === '') {
                $problems[] = "empty name for {$code}";
            }
            $byCode[$code] = $r;
        }
        foreach ($rows as $r) {
            $parent = $r['parent_code'] ?? null;
            if ($parent !== null && ! isset($byCode[$parent])) {
                $problems[] = "parent {$parent} of {$r['code']} is missing";
            } elseif ($parent !== null && ($byCode[$parent]['parent_code'] ?? null) !== null) {
                $problems[] = "{$r['code']} would be a third level";
            }
        }
        if ($problems) {
            throw new RuntimeException('Legacy expense_categories rejected, nothing imported: '.implode('; ', array_slice(array_unique($problems), 0, 10)));
        }
    }
}
