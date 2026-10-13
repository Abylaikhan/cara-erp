<?php

namespace App\Legacy\Importers;

use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy suppliers → M06. Whole set validated first; one transaction; idempotent through legacy_id_map. Legacy defaults are
 * applied as loadSuppliersFromDB does (status «active», expense source «payment»); the two expense codes become one link to
 * the article (or to the category when there is no article) of the already migrated «Статьи расходов».
 */
class SupplierImporter
{
    private const TEXT = ['category', 'contact', 'phone', 'email', 'bin', 'inn', 'comment'];

    private const LIMITS = ['name' => 255, 'category' => 100, 'contact' => 255, 'phone' => 64, 'email' => 255, 'bin' => 32, 'inn' => 32];

    /** @var array<string, ExpenseCategory> */
    private array $expenseByCode = [];

    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $rows = collect(DB::select('SELECT data FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no', [$snapshotId, 'suppliers']))
            ->map(fn ($r) => json_decode($r->data, true, flags: JSON_THROW_ON_ERROR));
        if ($rows->isEmpty()) {
            throw new RuntimeException("Snapshot {$snapshotId} has no suppliers rows");
        }
        $this->expenseByCode = ExpenseCategory::query()->get()->keyBy('code')->all();
        $this->validate($rows->all());

        return DB::transaction(function () use ($rows, $snapshotId, $by) {
            $stats = ['legacy_rows' => $rows->count(), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            $map = DB::table('legacy_id_map')->where('source_table', 'suppliers')->pluck('target_id', 'source_key');
            $existing = Supplier::query()->whereIn('id', $map->values())->lockForUpdate()->get()->keyBy('id');
            foreach ($rows as $r) {
                $supplier = isset($map[$r['id']]) ? $existing->get($map[$r['id']]) : null;
                if ($supplier === null) {
                    $supplier = new Supplier;
                    $stats['created']++;
                }
                $supplier->forceFill($this->attributes($r));
                if ($supplier->exists && $supplier->isDirty()) {
                    $supplier->lock_version++;
                    $stats['updated']++;
                } elseif ($supplier->exists) {
                    $stats['unchanged']++;
                }
                $supplier->timestamps = false; // keep the legacy creation time
                $supplier->save();
                DB::table('legacy_id_map')->upsert(
                    [['source_table' => 'suppliers', 'source_key' => (string) $r['id'], 'target_table' => 'suppliers', 'target_id' => $supplier->id, 'snapshot_id' => $snapshotId]],
                    ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at'],
                );
            }
            $this->verify($rows->all());
            $stats['suppliers'] = Supplier::query()->active()->count();
            $stats['with_bin'] = Supplier::query()->active()->whereNotNull('bin')->count();
            $stats['with_expense_item'] = Supplier::query()->active()->whereNotNull('expense_category_id')->count();
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'suppliers', null, $stats, $by);

            return $stats;
        });
    }

    /** Row-by-row comparison of the stored suppliers with the legacy rows. */
    private function verify(array $rows): void
    {
        $ids = DB::table('legacy_id_map')->where('source_table', 'suppliers')
            ->whereIn('source_key', array_map(fn ($r) => (string) $r['id'], $rows))->pluck('target_id', 'source_key');
        if ($ids->count() !== count($rows)) {
            throw new RuntimeException("Verification failed: {$ids->count()} of ".count($rows).' suppliers mapped');
        }
        $stored = Supplier::query()->whereIn('id', $ids->values())->get()->keyBy('id');
        foreach ($rows as $r) {
            $s = $stored->get($ids[(string) $r['id']]) ?? throw new RuntimeException("Verification failed: supplier {$r['id']} is missing");
            $expected = $this->attributes($r);
            foreach (['name', 'status', 'expense_category_id', 'expense_source', ...self::TEXT] as $field) {
                if ($s->{$field} !== $expected[$field]) {
                    throw new RuntimeException("Verification failed for supplier {$r['id']} ({$field})");
                }
            }
            if (! $s->created_at->equalTo($expected['created_at'])) {
                throw new RuntimeException("Verification failed for supplier {$r['id']} (created_at)");
            }
        }
    }

    /** @return array<string, mixed> */
    private function attributes(array $r): array
    {
        $text = fn (string $f) => ($v = trim((string) ($r[$f] ?? ''))) === '' ? null : $v;
        $createdAt = Carbon::parse($r['created_at']);
        $item = ($r['expense_subcategory'] ?? '') !== '' ? $r['expense_subcategory'] : (($r['expense_category'] ?? '') !== '' ? $r['expense_category'] : null);

        return [
            'name' => trim((string) $r['name']),
            ...array_combine(self::TEXT, array_map($text, self::TEXT)),
            'status' => ($r['status'] ?? '') !== '' ? $r['status'] : 'active',
            'expense_category_id' => $item !== null ? $this->expenseByCode[$item]->id : null,
            'expense_source' => ($r['expense_source'] ?? '') !== '' ? $r['expense_source'] : 'payment',
            'active' => true,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function validate(array $rows): void
    {
        $problems = [];
        $ids = [];
        foreach ($rows as $i => $r) {
            $ref = 'row '.($i + 1);
            if (! isset($r['id']) || isset($ids[$r['id']])) {
                $problems[] = "{$ref}: missing or duplicate id";
            }
            $ids[$r['id'] ?? ''] = true;
            if (trim((string) ($r['name'] ?? '')) === '') {
                $problems[] = "{$ref}: empty name";
            }
            if (($r['status'] ?? '') !== '' && ! array_key_exists($r['status'], Supplier::STATUSES)) {
                $problems[] = "{$ref}: unknown status";
            }
            if (($r['expense_source'] ?? '') !== '' && ! in_array($r['expense_source'], Supplier::EXPENSE_SOURCES, true)) {
                $problems[] = "{$ref}: unknown expense source";
            }
            $category = ($r['expense_category'] ?? '') !== '' ? ($this->expenseByCode[$r['expense_category']] ?? null) : null;
            $article = ($r['expense_subcategory'] ?? '') !== '' ? ($this->expenseByCode[$r['expense_subcategory']] ?? null) : null;
            if ((($r['expense_category'] ?? '') !== '' && ($category === null || $category->parent_id !== null))
                || (($r['expense_subcategory'] ?? '') !== '' && ($article === null || $category === null || $article->parent_id !== $category->id))) {
                $problems[] = "{$ref}: expense category/article not found in «Статьи расходов» or not related";
            }
            foreach (self::LIMITS as $field => $max) {
                if (mb_strlen(trim((string) ($r[$field] ?? ''))) > $max) {
                    $problems[] = "{$ref}: {$field} longer than {$max}";
                }
            }
            if (empty($r['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        if ($problems) {
            throw new RuntimeException('Legacy suppliers rejected, nothing imported: '.implode('; ', array_slice($problems, 0, 10)));
        }
    }
}
