<?php

namespace App\Legacy\Importers;

use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy products → M07 catalog. Whole set validated first; one transaction; idempotent through legacy_id_map;
 * legacy authors and timestamps are kept as they were (names of legacy users stay text until users are migrated).
 */
class ProductImporter
{
    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $rows = collect(DB::select('SELECT data FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no', [$snapshotId, 'products']))
            ->map(fn ($r) => json_decode($r->data, true, flags: JSON_THROW_ON_ERROR));
        if ($rows->isEmpty()) {
            throw new RuntimeException("Snapshot {$snapshotId} has no products rows");
        }
        $this->validate($rows->all());

        return DB::transaction(function () use ($rows, $snapshotId, $by) {
            $stats = ['legacy_rows' => $rows->count(), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            $map = DB::table('legacy_id_map')->where('source_table', 'products')->pluck('target_id', 'source_key');
            foreach ($rows as $r) {
                $product = isset($map[$r['id']]) ? Product::query()->lockForUpdate()->find($map[$r['id']]) : null;
                if ($product === null) {
                    $product = new Product;
                    $stats['created']++;
                }
                $product->forceFill($this->attributes($r));
                if ($product->exists && $product->isDirty()) {
                    $product->lock_version++;
                    $stats['updated']++;
                } elseif ($product->exists) {
                    $stats['unchanged']++;
                }
                $product->timestamps = false; // keep legacy created_at/updated_at
                $product->save();
                DB::table('legacy_id_map')->upsert(
                    [['source_table' => 'products', 'source_key' => (string) $r['id'], 'target_table' => 'products', 'target_id' => $product->id, 'snapshot_id' => $snapshotId]],
                    ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at'],
                );
            }
            $mapped = DB::table('legacy_id_map')->where('source_table', 'products')->whereIn('source_key', $rows->pluck('id')->map(fn ($id) => (string) $id))->count();
            if ($mapped !== $rows->count()) {
                throw new RuntimeException("Verification failed: {$mapped} of {$rows->count()} products mapped");
            }
            foreach ($rows as $r) {
                $p = Product::query()->findOrFail(DB::table('legacy_id_map')->where(['source_table' => 'products', 'source_key' => (string) $r['id']])->value('target_id'));
                $expected = $this->attributes($r);
                foreach (['name', 'category', 'unit', 'active', 'delete_requested'] as $field) {
                    if ($p->{$field} !== $expected[$field]) {
                        throw new RuntimeException("Verification failed for product {$r['id']} ({$field})");
                    }
                }
                if (bccomp((string) $p->price_retail, $expected['price_retail'], 2) !== 0 || bccomp((string) $p->price_wholesale, $expected['price_wholesale'], 2) !== 0) {
                    throw new RuntimeException("Verification failed for product {$r['id']} (prices)");
                }
            }
            $stats['active'] = Product::query()->active()->count();
            $stats['categories'] = Product::query()->active()->distinct()->count('category');
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'products', null, $stats, $by);

            return $stats;
        });
    }

    /** @return array<string, mixed> */
    private function attributes(array $r): array
    {
        $active = ($r['active'] ?? true) !== false;
        $deleteRequested = (bool) ($r['delete_requested'] ?? false);

        return [
            'name' => trim((string) $r['name']),
            'category' => trim((string) $r['category']),
            'price_retail' => number_format((float) ($r['price_retail'] ?? 0), 2, '.', ''),
            'price_wholesale' => number_format((float) ($r['price_wholesale'] ?? 0), 2, '.', ''),
            'unit' => $r['unit'] ?? 'шт',
            'sku' => $r['sku'] ?? null,
            'description' => $r['description'] ?? null,
            'active' => $active,
            'deactivated_at' => $active ? null : Carbon::parse($r['updated_at'] ?? $r['created_at'] ?? 'now'),
            'delete_requested' => $deleteRequested,
            'delete_requested_by_name' => $deleteRequested ? ($r['delete_requested_by'] ?? null) : null,
            'created_by_name' => $r['created_by'] ?? null,
            'updated_by_name' => $r['updated_by'] ?? null,
            'created_at' => isset($r['created_at']) ? Carbon::parse($r['created_at']) : now(),
            'updated_at' => isset($r['updated_at']) ? Carbon::parse($r['updated_at']) : (isset($r['created_at']) ? Carbon::parse($r['created_at']) : now()),
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
            if (trim((string) ($r['name'] ?? '')) === '' || trim((string) ($r['category'] ?? '')) === '') {
                $problems[] = "{$ref}: empty name or category";
            }
            if (! in_array($r['unit'] ?? 'шт', Product::UNITS, true)) {
                $problems[] = "{$ref}: unknown unit";
            }
            foreach (['price_retail', 'price_wholesale'] as $f) {
                if (isset($r[$f]) && (! is_numeric($r[$f]) || $r[$f] < 0)) {
                    $problems[] = "{$ref}: invalid {$f}";
                }
            }
        }
        if ($problems) {
            throw new RuntimeException('Legacy products rejected, nothing imported: '.implode('; ', array_slice($problems, 0, 10)));
        }
    }
}
