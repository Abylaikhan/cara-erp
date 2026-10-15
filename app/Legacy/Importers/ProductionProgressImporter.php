<?php

namespace App\Legacy\Importers;

use App\Legacy\LegacyRejected;
use App\Models\ProductionProgress;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy production_progress → M02 (after orders). A record names its order line as «num|index»; the index is the line's
 * place in the order, which the order import keeps (line_no). Whole set validated first; one transaction; idempotent
 * through legacy_id_map. Stages and materials are kept as legacy stores them.
 */
class ProductionProgressImporter
{
    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $rows = collect(DB::select('SELECT data, data->\'stages\' AS stages_json, data->\'materials\' AS materials_json FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no', [$snapshotId, 'production_progress']))
            ->map(fn ($r) => json_decode($r->data, true, flags: JSON_THROW_ON_ERROR) + ['stages_json' => $r->stages_json, 'materials_json' => $r->materials_json]);
        if ($rows->isEmpty()) {
            throw new RuntimeException("Snapshot {$snapshotId} has no production_progress rows");
        }
        $lines = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->selectRaw("orders.num || '|' || order_items.line_no AS item_key, order_items.id")->pluck('id', 'item_key');
        $this->validate($rows->all(), $lines->all());

        return DB::transaction(function () use ($rows, $lines, $snapshotId, $by) {
            $stats = ['legacy_rows' => $rows->count(), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            $map = DB::table('legacy_id_map')->where('source_table', 'production_progress')->pluck('target_id', 'source_key');
            $existing = ProductionProgress::query()->whereIn('id', $map->values())->lockForUpdate()->get()->keyBy('id');
            $mapRows = [];
            foreach ($rows as $r) {
                $progress = isset($map[(string) $r['id']]) ? $existing->get($map[(string) $r['id']]) : null;
                if ($progress === null) {
                    $progress = new ProductionProgress;
                    $stats['created']++;
                }
                $progress->forceFill($this->attributes($r, $lines->all()));
                if ($progress->exists && $progress->isDirty()) {
                    $stats['updated']++;
                } elseif ($progress->exists) {
                    $stats['unchanged']++;
                }
                $progress->timestamps = false;
                $progress->save();
                $mapRows[] = ['source_table' => 'production_progress', 'source_key' => (string) $r['id'], 'target_table' => 'production_progress', 'target_id' => $progress->id, 'snapshot_id' => $snapshotId];
            }
            foreach (array_chunk($mapRows, 1000) as $chunk) {
                DB::table('legacy_id_map')->upsert($chunk, ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at']);
            }
            $this->verify($rows->all(), $lines->all());
            $stats['with_activity'] = ProductionProgress::query()->get()->filter->hasActivity()->count();
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'production_progress', null, $stats, $by);

            return $stats;
        });
    }

    /** @param array<string, int> $lines */
    private function verify(array $rows, array $lines): void
    {
        $ids = DB::table('legacy_id_map')->where('source_table', 'production_progress')->whereIn('source_key', array_map(fn ($r) => (string) $r['id'], $rows))->pluck('target_id', 'source_key');
        if ($ids->count() !== count($rows)) {
            throw new RuntimeException("Verification failed: {$ids->count()} of ".count($rows).' progress records mapped');
        }
        $stored = DB::table('production_progress')->whereIn('id', $ids->values())->get()->keyBy('id');
        foreach ($rows as $r) {
            $p = $stored->get($ids[(string) $r['id']]) ?? throw new RuntimeException("Verification failed: progress {$r['id']} is missing");
            $same = $p->order_item_id === $lines[$r['item_key']] && (bool) $p->completed === (bool) ($r['completed'] ?? false)
                && json_decode($p->stages) == json_decode($r['stages_json'] ?? '[]') && json_decode($p->materials) == json_decode($r['materials_json'] ?? '[]');
            if (! $same) {
                throw new RuntimeException("Verification failed for progress {$r['id']}");
            }
        }
    }

    /**
     * @param  array<string, int>  $lines
     * @return array<string, mixed>
     */
    private function attributes(array $r, array $lines): array
    {
        $at = Carbon::parse($r['updated_at'] ?? 'now');

        return [
            'order_item_id' => $lines[$r['item_key']],
            'stages' => json_decode($r['stages_json'] ?? '[]', true),
            'materials' => json_decode($r['materials_json'] ?? '[]', true),
            'completed' => (bool) ($r['completed'] ?? false),
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $lines
     */
    private function validate(array $rows, array $lines): void
    {
        $problems = [];
        $ids = [];
        $keys = [];
        foreach ($rows as $i => $r) {
            $ref = 'row '.($i + 1);
            if (! isset($r['id']) || isset($ids[(string) $r['id']])) {
                $problems[] = "{$ref}: missing or duplicate id";
            }
            $ids[(string) ($r['id'] ?? '')] = true;
            $key = (string) ($r['item_key'] ?? '');
            if (! isset($lines[$key])) {
                $problems[] = "{$ref}: order line {$key} not found (legacy:import orders first)";
            } elseif (isset($keys[$key])) {
                $problems[] = "{$ref}: second record for order line {$key}";
            }
            $keys[$key] = true;
            foreach (['stages_json', 'materials_json'] as $f) {
                if ($r[$f] !== null && ! str_starts_with(ltrim($r[$f]), '[')) {
                    $problems[] = "{$ref}: {$f} is not a list";
                }
            }
        }
        if ($problems) {
            throw new LegacyRejected('Legacy production progress', $problems);
        }
    }
}
