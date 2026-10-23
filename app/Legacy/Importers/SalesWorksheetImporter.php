<?php

namespace App\Legacy\Importers;

use App\Legacy\LegacyRejected;
use App\Models\SalesWorksheetRow;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Legacy sales_worksheet → «Рабочий лист реализации». Whole set validated first; one transaction; copied as it is
 * (MirrorsLegacyTable). The order and the realization of a line are linked through the imported orders and realizations
 * (`legacy:import orders realizations` first); a number without an order stays as typed.
 */
class SalesWorksheetImporter
{
    use MirrorsLegacyTable;

    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $lines = $this->legacyTable($snapshotId, 'sales_worksheet', SalesWorksheetRow::NUMBER_FIELDS);
        $this->validate($lines);
        $orders = DB::table('orders')->pluck('id', 'num');
        $realizations = DB::table('legacy_id_map')->where('source_table', 'realizations')->pluck('target_id', 'source_key');

        return DB::transaction(function () use ($lines, $orders, $realizations, $snapshotId, $by) {
            $rows = array_map(function (array $r) use ($orders, $realizations) {
                $created = Carbon::parse($r['created_at']);
                $orderNum = self::text($r, 'order_num');
                $a = [
                    'uuid' => $r['id'], 'doc_id' => $r['doc_id'], 'doc_num' => (string) $r['doc_num'], 'doc_date' => $r['doc_date'],
                    'line_no' => (int) ($r['line_no'] ?? 0), 'line_kind' => self::text($r, 'line_kind') ?? 'sale',
                    'order_num' => $orderNum, 'order_id' => $orderNum !== null ? ($orders[$orderNum] ?? null) : null,
                    'customer' => self::text($r, 'customer'), 'item_name' => (string) $r['item_name'],
                    'item_no' => isset($r['item_no']) && is_numeric($r['item_no']) ? (int) $r['item_no'] : null,
                    'attrs' => self::text($r, 'attrs'), 'attrs_short' => (bool) ($r['attrs_short'] ?? false), 'unit' => self::text($r, 'unit'),
                    'driver' => self::text($r, 'driver'), 'note' => self::text($r, 'note'),
                    'posted' => (bool) ($r['posted'] ?? false), 'posted_at' => empty($r['posted_at']) ? null : Carbon::parse($r['posted_at']),
                    'realization_uuid' => self::text($r, 'realization_id'),
                    'realization_id' => ($id = self::text($r, 'realization_id')) !== null ? ($realizations[$id] ?? null) : null,
                    'restoration_uuid' => self::text($r, 'restoration_id'),
                    'delivered' => (bool) ($r['delivered'] ?? false), 'delivered_at' => empty($r['delivered_at']) ? null : Carbon::parse($r['delivered_at']),
                    'delivered_by' => self::text($r, 'delivered_by'), 'created_by' => self::text($r, 'created_by'), 'created_by_name' => self::text($r, 'created_by_name'),
                    'created_at' => $created, 'updated_at' => $created,
                ];
                foreach (SalesWorksheetRow::NUMBER_FIELDS as $f) {
                    $a[$f] = bcadd($r[$f], '0', 12);
                }

                return $a;
            }, $lines);
            $stats = ['legacy_rows' => count($lines)] + $this->mirror('sales_worksheet', SalesWorksheetRow::class, $rows, $snapshotId);
            $stats['removed'] = $this->removeGone('sales_worksheet', SalesWorksheetRow::class, array_column($lines, 'id'), $by);
            $this->verifyMirror(SalesWorksheetRow::class, $rows, array_fill_keys(SalesWorksheetRow::NUMBER_FIELDS, 12), ['doc_date']);
            $stats['notes'] = SalesWorksheetRow::query()->distinct()->count('doc_id');
            $stats['posted_lines'] = SalesWorksheetRow::query()->where('posted', true)->count();
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'sales_worksheet', null, $stats, $by);

            return $stats;
        });
    }

    private function validate(array $lines): void
    {
        $problems = [];
        $ids = [];
        foreach ($lines as $i => $r) {
            $ref = 'row '.($i + 1);
            if (! self::isUuid($r['id'] ?? null) || isset($ids[$r['id']])) {
                $problems[] = "{$ref}: missing, malformed or duplicate id";
            }
            $ids[$r['id'] ?? ''] = true;
            if (! self::isUuid($r['doc_id'] ?? null)) {
                $problems[] = "{$ref}: malformed doc_id";
            }
            if (! self::isDay($r['doc_date'] ?? null)) {
                $problems[] = "{$ref}: doc_date is not YYYY-MM-DD";
            }
            if (! is_string($r['item_name'] ?? null) || mb_strlen($r['item_name']) > 255 || mb_strlen((string) ($r['doc_num'] ?? '')) > 32 || mb_strlen((string) ($r['order_num'] ?? '')) > 32) {
                $problems[] = "{$ref}: product, note or order number missing or too long";
            }
            foreach (SalesWorksheetRow::NUMBER_FIELDS as $f) {
                if (! self::fits($r[$f], 12)) {
                    $problems[] = "{$ref}: {$f} is not a number that fits";
                }
            }
            foreach (['realization_id', 'restoration_id'] as $f) {
                if (self::text($r, $f) !== null && ! self::isUuid($r[$f])) {
                    $problems[] = "{$ref}: malformed {$f}";
                }
            }
            if (empty($r['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        if ($problems) {
            throw new LegacyRejected('Legacy sales worksheet', $problems);
        }
    }
}
