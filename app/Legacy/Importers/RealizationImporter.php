<?php

namespace App\Legacy\Importers;

use App\Legacy\LegacyRejected;
use App\Models\Order;
use App\Models\Realization;
use App\Models\User;
use App\Support\Audit;
use App\Support\LegacyDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy realizations → M09 (after orders). Whole set validated first; one transaction; idempotent through legacy_id_map.
 * Legacy links a realization to its order by number only; some numbers name no order — they are kept, without a link.
 */
class RealizationImporter
{
    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $rows = collect(DB::select("SELECT data, data->>'qty' AS qty_text, data->>'revenue' AS revenue_text FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no", [$snapshotId, 'realizations']))
            ->map(fn ($r) => json_decode($r->data, true, flags: JSON_THROW_ON_ERROR) + ['qty_text' => $r->qty_text, 'revenue_text' => $r->revenue_text]);
        if ($rows->isEmpty()) {
            throw new RuntimeException("Snapshot {$snapshotId} has no realizations rows");
        }
        $this->validate($rows->all());
        $orders = Order::query()->pluck('id', 'num');

        return DB::transaction(function () use ($rows, $orders, $snapshotId, $by) {
            $stats = ['legacy_rows' => $rows->count(), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            $map = DB::table('legacy_id_map')->where('source_table', 'realizations')->pluck('target_id', 'source_key');
            $existing = Realization::query()->whereIn('id', $map->values())->lockForUpdate()->get()->keyBy('id');
            $mapRows = [];
            foreach ($rows as $r) {
                $realization = isset($map[$r['id']]) ? $existing->get($map[$r['id']]) : null;
                if ($realization === null) {
                    $realization = new Realization;
                    $stats['created']++;
                }
                $realization->forceFill($this->attributes($r, $orders->all()));
                if ($realization->exists && $realization->isDirty()) {
                    $stats['updated']++;
                } elseif ($realization->exists) {
                    $stats['unchanged']++;
                }
                $realization->timestamps = false;
                $realization->save();
                $mapRows[] = ['source_table' => 'realizations', 'source_key' => (string) $r['id'], 'target_table' => 'realizations', 'target_id' => $realization->id, 'snapshot_id' => $snapshotId];
            }
            foreach (array_chunk($mapRows, 1000) as $chunk) {
                DB::table('legacy_id_map')->upsert($chunk, ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at']);
            }
            $this->verify($rows->all(), $orders->all());
            $stats['linked'] = Realization::query()->whereNotNull('order_id')->count();
            $stats['without_order'] = Realization::query()->whereNull('order_id')->count();
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'realizations', null, $stats, $by);

            return $stats;
        });
    }

    /** @param array<string, int> $orders */
    private function verify(array $rows, array $orders): void
    {
        $ids = DB::table('legacy_id_map')->where('source_table', 'realizations')->whereIn('source_key', array_map(fn ($r) => (string) $r['id'], $rows))->pluck('target_id', 'source_key');
        if ($ids->count() !== count($rows)) {
            throw new RuntimeException("Verification failed: {$ids->count()} of ".count($rows).' realizations mapped');
        }
        $stored = Realization::query()->whereIn('id', $ids->values())->get()->keyBy('id');
        foreach ($rows as $r) {
            $realization = $stored->get($ids[(string) $r['id']]) ?? throw new RuntimeException("Verification failed: realization {$r['id']} is missing");
            $expected = $this->attributes($r, $orders);
            foreach (['order_num', 'order_id', 'doc_num', 'customer', 'name', 'qty', 'unit', 'revenue'] as $field) {
                if ((string) $realization->{$field} !== (string) $expected[$field]) {
                    throw new RuntimeException("Verification failed for realization {$r['id']} ({$field})");
                }
            }
            if ($realization->doc_date->format('Y-m-d') !== $expected['doc_date'] || ! $realization->created_at->equalTo($expected['created_at'])) {
                throw new RuntimeException("Verification failed for realization {$r['id']} (dates)");
            }
        }
    }

    /**
     * @param  array<string, int>  $orders
     * @return array<string, mixed>
     */
    private function attributes(array $r, array $orders): array
    {
        $text = fn (string $f) => ($v = trim((string) ($r[$f] ?? ''))) === '' ? null : $v;
        $createdAt = Carbon::parse($r['created_at']);
        $orderNum = $text('order_num');

        return [
            'order_num' => $orderNum,
            'order_id' => $orderNum === null ? null : ($orders[$orderNum] ?? null),
            'doc_num' => $text('doc_num'),
            'doc_date' => LegacyDate::parse($r['doc_date']),
            'customer' => $text('customer'),
            'name' => trim((string) ($r['name'] ?? '')),
            'qty' => bcadd($r['qty_text'] ?? '0', '0', 4),
            'unit' => $text('unit'),
            'revenue' => bcadd($r['revenue_text'] ?? '0', '0', 2),
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
            if (! isset($r['id']) || isset($ids[(string) $r['id']])) {
                $problems[] = "{$ref}: missing or duplicate id";
            }
            $ids[(string) ($r['id'] ?? '')] = true;
            if (! LegacyDate::parse($r['doc_date'] ?? null)) {
                $problems[] = "{$ref}: doc_date is not a date";
            }
            foreach (['qty_text' => 4, 'revenue_text' => 2] as $f => $scale) {
                $v = $r[$f];
                if ($v !== null && (! is_numeric($v) || preg_match('/\.\d{'.($scale + 1).',}/', $v))) {
                    $problems[] = "{$ref}: {$f} is not a number with up to {$scale} decimals";
                }
            }
            foreach (['order_num' => 32, 'doc_num' => 64, 'customer' => 255, 'name' => 500, 'unit' => 32] as $f => $max) {
                if (mb_strlen(trim((string) ($r[$f] ?? ''))) > $max) {
                    $problems[] = "{$ref}: {$f} longer than {$max}";
                }
            }
            if (empty($r['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        if ($problems) {
            throw new LegacyRejected('Legacy realizations', $problems);
        }
    }
}
