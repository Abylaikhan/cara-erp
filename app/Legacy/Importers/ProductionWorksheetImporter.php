<?php

namespace App\Legacy\Importers;

use App\Legacy\LegacyRejected;
use App\Models\ProductionWorksheetRow;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Legacy production_worksheet → «Рабочий лист производства» (after orders). A row names its order line as the progress does,
 * «номер|индекс»; the line is linked where it exists (legacy skips the others when it draws the worksheet). Whole set
 * validated first; one transaction; copied as it is (MirrorsLegacyTable, keyed by the legacy number), in the natural order
 * of the legacy table (`legacy:natural-order production_worksheet`).
 */
class ProductionWorksheetImporter
{
    use MirrorsLegacyTable;

    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $lines = $this->legacyTable($snapshotId, 'production_worksheet', [], natural: true);
        $this->validate($lines);
        $items = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->selectRaw("orders.num || '|' || order_items.line_no AS item_key, order_items.id")->pluck('id', 'item_key');

        return DB::transaction(function () use ($lines, $items, $snapshotId, $by) {
            $rows = array_map(function (array $r) use ($items) {
                $added = empty($r['added_at']) ? null : Carbon::parse($r['added_at']);

                return [
                    'legacy_id' => (int) $r['id'], 'order_num' => (string) $r['order_num'], 'item_idx' => (int) $r['item_idx'],
                    'order_item_id' => $items[$r['order_num'].'|'.(int) $r['item_idx']] ?? null,
                    'added_at' => $added, 'legacy_position' => $r['position'], 'created_at' => $added, 'updated_at' => $added,
                ];
            }, $lines);
            $stats = ['legacy_rows' => count($lines)] + $this->mirror('production_worksheet', ProductionWorksheetRow::class, $rows, $snapshotId, 'legacy_id');
            $stats['removed'] = $this->removeGone('production_worksheet', ProductionWorksheetRow::class, array_column($rows, 'legacy_id'), $by, 'legacy_id');
            $this->verifyMirror(ProductionWorksheetRow::class, $rows, [], [], 'legacy_id');
            $stats['linked'] = ProductionWorksheetRow::query()->whereNotNull('order_item_id')->count();
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'production_worksheet', null, $stats, $by);

            return $stats;
        });
    }

    private function validate(array $lines): void
    {
        $problems = [];
        $ids = [];
        $keys = [];
        foreach ($lines as $i => $r) {
            $ref = 'row '.($i + 1);
            if (! is_int($r['id'] ?? null) || $r['id'] <= 0 || isset($ids[$r['id']])) {
                $problems[] = "{$ref}: missing, malformed or duplicate id";
            }
            $ids[$r['id'] ?? ''] = true;
            if (! is_string($r['order_num'] ?? null) || $r['order_num'] === '' || mb_strlen($r['order_num']) > 32) {
                $problems[] = "{$ref}: order number missing or too long";
            }
            if (! is_int($r['item_idx'] ?? null) || $r['item_idx'] < 0 || $r['item_idx'] > 32767) {
                $problems[] = "{$ref}: item_idx is not a line index";
            }
            $key = ($r['order_num'] ?? '').'|'.($r['item_idx'] ?? '');
            if (isset($keys[$key])) {
                $problems[] = "{$ref}: the same order line twice";
            }
            $keys[$key] = true;
        }
        if ($problems) {
            throw new LegacyRejected('Legacy production worksheet', $problems);
        }
    }
}
