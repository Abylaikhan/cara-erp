<?php

namespace App\Legacy\Importers;

use App\Models\Material;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Audit;
use App\Support\LegacyNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy warehouse → M03 materials. Whole set validated first; one transaction; idempotent through legacy_id_map.
 * Quantities are kept exactly (up to 3 decimals). Unit prices are kept to 0.01 exactly as legacy showed and valued them:
 * loadWarehouseFromDB rounds with Math.round(price * 100) / 100 in binary floating point, so 9915.335 is 9915.33 there
 * (docs/adr/0008-warehouse.md, decision 2). Empty category/unit get the legacy defaults.
 */
class MaterialImporter
{
    private const LIMITS = ['name' => 255, 'category' => 100, 'unit' => 16, 'supplier' => 255];

    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        // Numbers are taken as the exact decimal text of the jsonb value, never through float.
        $rows = collect(DB::select("SELECT data, data->>'qty' AS qty_text, data->>'price' AS price_text, data->>'min_qty' AS min_qty_text
            FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no", [$snapshotId, 'warehouse']))
            ->map(fn ($r) => json_decode($r->data, true, flags: JSON_THROW_ON_ERROR) + ['qty_text' => $r->qty_text, 'price_text' => $r->price_text, 'min_qty_text' => $r->min_qty_text]);
        if ($rows->isEmpty()) {
            throw new RuntimeException("Snapshot {$snapshotId} has no warehouse rows");
        }
        $this->validate($rows->all());

        return DB::transaction(function () use ($rows, $snapshotId, $by) {
            $stats = ['legacy_rows' => $rows->count(), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            $map = DB::table('legacy_id_map')->where('source_table', 'warehouse')->pluck('target_id', 'source_key');
            $existing = Material::query()->whereIn('id', $map->values())->lockForUpdate()->get()->keyBy('id');
            $mapRows = [];
            foreach ($rows as $r) {
                $material = isset($map[$r['id']]) ? $existing->get($map[$r['id']]) : null;
                if ($material === null) {
                    $material = new Material;
                    $stats['created']++;
                }
                $material->forceFill($this->attributes($r));
                if ($material->exists && $material->isDirty()) {
                    $material->lock_version++;
                    $stats['updated']++;
                } elseif ($material->exists) {
                    $stats['unchanged']++;
                }
                $material->timestamps = false; // keep the legacy creation time
                $material->save();
                $mapRows[] = ['source_table' => 'warehouse', 'source_key' => (string) $r['id'], 'target_table' => 'materials', 'target_id' => $material->id, 'snapshot_id' => $snapshotId];
            }
            foreach (array_chunk($mapRows, 1000) as $chunk) {
                DB::table('legacy_id_map')->upsert($chunk, ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at']);
            }
            $this->verify($rows->all());
            $stats['materials'] = Material::query()->active()->count();
            $stats['in_stock'] = Material::query()->active()->where('qty', '>', 0)->count();
            $stats['categories'] = Material::query()->active()->distinct()->count('category');
            $stats['stock_value'] = (string) DB::table('materials')->where('active', true)->selectRaw('round(sum(qty * price), 2) AS v')->value('v');
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'materials', null, $stats, $by);

            return $stats;
        });
    }

    /** Row-by-row comparison of the stored materials with the legacy rows. */
    private function verify(array $rows): void
    {
        $ids = DB::table('legacy_id_map')->where('source_table', 'warehouse')
            ->whereIn('source_key', array_map(fn ($r) => (string) $r['id'], $rows))->pluck('target_id', 'source_key');
        if ($ids->count() !== count($rows)) {
            throw new RuntimeException("Verification failed: {$ids->count()} of ".count($rows).' materials mapped');
        }
        $stored = Material::query()->whereIn('id', $ids->values())->get()->keyBy('id');
        foreach ($rows as $r) {
            $m = $stored->get($ids[(string) $r['id']]) ?? throw new RuntimeException("Verification failed: material {$r['id']} is missing");
            $expected = $this->attributes($r);
            foreach (['name', 'category', 'unit', 'supplier_name', 'warehouse_code'] as $field) {
                if ($m->{$field} !== $expected[$field]) {
                    throw new RuntimeException("Verification failed for material {$r['id']} ({$field})");
                }
            }
            foreach (['qty' => 3, 'price' => 2, 'min_qty' => 3] as $field => $scale) {
                if (bccomp((string) $m->{$field}, $expected[$field], $scale) !== 0) {
                    throw new RuntimeException("Verification failed for material {$r['id']} ({$field})");
                }
            }
            if (! $m->created_at->equalTo($expected['created_at'])) {
                throw new RuntimeException("Verification failed for material {$r['id']} (created_at)");
            }
        }
    }

    /** @return array<string, mixed> */
    private function attributes(array $r): array
    {
        $createdAt = Carbon::parse($r['created_at']);
        $text = fn (string $f) => ($v = trim((string) ($r[$f] ?? ''))) === '' ? null : $v;

        return [
            'name' => trim((string) $r['name']),
            'category' => $text('category') ?? 'Прочее', // legacy: item.category || 'Прочее'
            'unit' => $text('unit') ?? 'шт',             // legacy: item.unit || 'шт'
            'qty' => self::decimal($r['qty_text'], 3),
            'price' => LegacyNumber::round2($r['price_text']),
            'min_qty' => self::decimal($r['min_qty_text'], 3),
            'supplier_name' => $text('supplier'),
            'warehouse_code' => $text('warehouse_id'),
            'active' => true,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /** Exact decimal half-up rounding of the decimal text of a JSON number; an absent value is 0 (legacy `|| 0`). */
    private static function decimal(?string $value, int $scale): string
    {
        $value = $value === null || $value === '' ? '0' : $value;
        $half = '0.'.str_repeat('0', $scale).'5';

        return str_starts_with($value, '-') ? bcsub($value, $half, $scale) : bcadd($value, $half, $scale);
    }

    /** @param list<array<string, mixed>> $rows */
    private function validate(array $rows): void
    {
        $warehouses = Warehouse::query()->pluck('code')->all();
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
            foreach (['qty', 'price', 'min_qty'] as $f) {
                if (isset($r[$f]) && ! is_numeric($r[$f])) {
                    $problems[] = "{$ref}: {$f} is not a number";
                }
            }
            if ((isset($r['price']) && is_numeric($r['price']) && $r['price'] < 0) || (isset($r['min_qty']) && is_numeric($r['min_qty']) && $r['min_qty'] < 0)) {
                $problems[] = "{$ref}: negative price or minimum";
            }
            if (($r['warehouse_id'] ?? '') !== '' && $r['warehouse_id'] !== null && ! in_array($r['warehouse_id'], $warehouses, true)) {
                $problems[] = "{$ref}: unknown warehouse";
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
            throw new RuntimeException('Legacy warehouse rejected, nothing imported: '.implode('; ', array_slice($problems, 0, 10)));
        }
    }
}
