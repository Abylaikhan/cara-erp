<?php

namespace App\Legacy\Importers;

use App\Legacy\LegacyRejected;
use App\Models\MaterialRequest;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy material_requests → M04. Whole set validated first; one transaction; idempotent through legacy_id_map; creation and
 * change times kept. The quantity is read as the exact decimal of the JSON number.
 */
class MaterialRequestImporter
{
    private const TEXT = ['unit', 'order_num', 'item_name', 'workshop', 'requested_by', 'purchase_note'];

    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $rows = collect(DB::select("SELECT data, data->>'qty' AS qty_text FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no", [$snapshotId, 'material_requests']))
            ->map(fn ($r) => json_decode($r->data, true, flags: JSON_THROW_ON_ERROR) + ['qty_text' => $r->qty_text]);
        if ($rows->isEmpty()) {
            throw new RuntimeException("Snapshot {$snapshotId} has no material_requests rows");
        }
        $this->validate($rows->all());

        return DB::transaction(function () use ($rows, $snapshotId, $by) {
            $stats = ['legacy_rows' => $rows->count(), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            $map = DB::table('legacy_id_map')->where('source_table', 'material_requests')->pluck('target_id', 'source_key');
            $existing = MaterialRequest::query()->whereIn('id', $map->values())->lockForUpdate()->get()->keyBy('id');
            $mapRows = [];
            foreach ($rows as $r) {
                $request = isset($map[(string) $r['id']]) ? $existing->get($map[(string) $r['id']]) : null;
                if ($request === null) {
                    $request = new MaterialRequest;
                    $stats['created']++;
                }
                $request->forceFill($this->attributes($r));
                if ($request->exists && $request->isDirty()) {
                    $request->lock_version++;
                    $stats['updated']++;
                } elseif ($request->exists) {
                    $stats['unchanged']++;
                }
                $request->timestamps = false; // keep the legacy times
                $request->save();
                $mapRows[] = ['source_table' => 'material_requests', 'source_key' => (string) $r['id'], 'target_table' => 'material_requests', 'target_id' => $request->id, 'snapshot_id' => $snapshotId];
            }
            DB::table('legacy_id_map')->upsert($mapRows, ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at']);
            $this->verify($rows->all());
            $stats['open'] = MaterialRequest::query()->where('status', '!=', 'received')->count();
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'material_requests', null, $stats, $by);

            return $stats;
        });
    }

    private function verify(array $rows): void
    {
        $ids = DB::table('legacy_id_map')->where('source_table', 'material_requests')
            ->whereIn('source_key', array_map(fn ($r) => (string) $r['id'], $rows))->pluck('target_id', 'source_key');
        if ($ids->count() !== count($rows)) {
            throw new RuntimeException("Verification failed: {$ids->count()} of ".count($rows).' material requests mapped');
        }
        $stored = MaterialRequest::query()->whereIn('id', $ids->values())->get()->keyBy('id');
        foreach ($rows as $r) {
            $request = $stored->get($ids[(string) $r['id']]) ?? throw new RuntimeException("Verification failed: material request {$r['id']} is missing");
            $expected = $this->attributes($r);
            foreach (['material_name', 'qty', 'status', ...self::TEXT] as $field) {
                if ((string) $request->{$field} !== (string) $expected[$field]) {
                    throw new RuntimeException("Verification failed for material request {$r['id']} ({$field})");
                }
            }
            if (! $request->created_at->equalTo($expected['created_at']) || ! $request->updated_at->equalTo($expected['updated_at'])) {
                throw new RuntimeException("Verification failed for material request {$r['id']} (times)");
            }
        }
    }

    /** @return array<string, mixed> */
    private function attributes(array $r): array
    {
        $text = fn (string $f) => ($v = trim((string) ($r[$f] ?? ''))) === '' ? null : $v;
        $createdAt = Carbon::parse($r['created_at']);

        return [
            'material_name' => trim((string) $r['material_name']),
            'qty' => bcadd($r['qty_text'] === null || $r['qty_text'] === '' ? '0' : $r['qty_text'], '0', 4),
            ...array_combine(self::TEXT, array_map($text, self::TEXT)),
            'status' => $text('status') ?? 'requested',
            'created_at' => $createdAt,
            'updated_at' => empty($r['updated_at']) ? $createdAt : Carbon::parse($r['updated_at']),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function validate(array $rows): void
    {
        $workshops = config('erp.piece_workshops');
        $problems = [];
        $ids = [];
        foreach ($rows as $i => $r) {
            $ref = 'row '.($i + 1);
            if (! isset($r['id']) || isset($ids[(string) $r['id']])) {
                $problems[] = "{$ref}: missing or duplicate id";
            }
            $ids[(string) ($r['id'] ?? '')] = true;
            if (trim((string) ($r['material_name'] ?? '')) === '') {
                $problems[] = "{$ref}: empty material name";
            }
            if (! in_array($r['status'] ?? null, [null, '', 'requested', 'in_purchase', 'received'], true)) {
                $problems[] = "{$ref}: unknown status";
            }
            if ($r['qty_text'] !== null && (! is_numeric($r['qty_text']) || $r['qty_text'] < 0 || preg_match('/\.\d{5,}/', $r['qty_text']))) {
                $problems[] = "{$ref}: qty is not a non-negative number with up to 4 decimals";
            }
            if (($w = trim((string) ($r['workshop'] ?? ''))) !== '' && ! isset($workshops[$w])) {
                $problems[] = "{$ref}: unknown workshop";
            }
            foreach (['material_name' => 255, 'unit' => 32, 'order_num' => 64, 'item_name' => 500, 'requested_by' => 255] as $field => $max) {
                if (mb_strlen(trim((string) ($r[$field] ?? ''))) > $max) {
                    $problems[] = "{$ref}: {$field} longer than {$max}";
                }
            }
            if (empty($r['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        if ($problems) {
            throw new LegacyRejected('Legacy material requests', $problems);
        }
    }
}
