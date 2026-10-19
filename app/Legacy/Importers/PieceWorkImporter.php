<?php

namespace App\Legacy\Importers;

use App\Legacy\LegacyRejected;
use App\Models\PieceWorkItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Legacy wage_items → the piece work of M18. Whole set validated first; one transaction; copied as it is
 * (MirrorsLegacyTable). The day stays as typed: a few lines carry free text instead of a date, legacy shows them so.
 */
class PieceWorkImporter
{
    use MirrorsLegacyTable;

    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $items = $this->legacyTable($snapshotId, 'wage_items', PieceWorkItem::NUMBER_FIELDS);
        $this->validate($items);

        return DB::transaction(function () use ($items, $snapshotId, $by) {
            $rows = array_map(function (array $r) {
                $created = Carbon::parse($r['created_at']);

                return [
                    'uuid' => $r['id'],
                    'work_date' => $r['date'],
                    'emp_name' => $r['emp_name'],
                    'workshop' => $r['workshop'],
                    'operation' => $r['op'],
                    'order_num' => $r['order_num'],
                    'qty' => bcadd($r['qty'], '0', 12),
                    'rate' => bcadd($r['rate'], '0', 12),
                    'created_at' => $created,
                    'updated_at' => $created,
                ];
            }, $items);
            $stats = ['legacy_rows' => count($items)] + $this->mirror('wage_items', PieceWorkItem::class, $rows, $snapshotId);
            $stats['removed'] = $this->removeGone('wage_items', PieceWorkItem::class, array_column($items, 'id'), $by);
            $this->verifyMirror(PieceWorkItem::class, $rows, array_fill_keys(PieceWorkItem::NUMBER_FIELDS, 12), []);
            $stats['not_a_date'] = PieceWorkItem::query()->where('work_date', '!~', '^\d{4}-\d{2}-\d{2}$')->count();
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'wage_items', null, $stats, $by);

            return $stats;
        });
    }

    private function validate(array $items): void
    {
        $problems = [];
        $ids = [];
        foreach ($items as $i => $r) {
            $ref = 'row '.($i + 1);
            if (! self::isUuid($r['id'] ?? null) || isset($ids[$r['id']])) {
                $problems[] = "{$ref}: missing, malformed or duplicate id";
            }
            $ids[$r['id'] ?? ''] = true;
            foreach (['date' => 32, 'emp_name' => 255, 'workshop' => 64, 'op' => 64, 'order_num' => 64] as $f => $max) {
                if (! is_string($r[$f] ?? null) || mb_strlen($r[$f]) > $max) {
                    $problems[] = "{$ref}: {$f} is missing or too long";
                }
            }
            foreach (PieceWorkItem::NUMBER_FIELDS as $f) {
                if (! self::fits($r[$f], 12)) {
                    $problems[] = "{$ref}: {$f} is not a number that fits";
                }
            }
            if (empty($r['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        if ($problems) {
            throw new LegacyRejected('Legacy wage items', $problems);
        }
    }
}
