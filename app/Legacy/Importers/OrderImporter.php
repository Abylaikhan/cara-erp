<?php

namespace App\Legacy\Importers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SalesChannel;
use App\Models\User;
use App\Support\Audit;
use App\Support\LegacyDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy orders and their lines → M09. Whole set validated first; one transaction; idempotent through legacy_id_map.
 * Defaults as loadOrdersFromDB applies them (channel «office», status «queue», line status «queue», progress 0). A line's
 * place in its order is the legacy natural order (`legacy:natural-order order_items` first); amounts are exact decimals.
 */
class OrderImporter
{
    private const STATUSES = ['queue', 'progress', 'ready', 'delivered', 'done', 'cancelled'];

    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $orders = collect(DB::select('SELECT data FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no', [$snapshotId, 'orders']))
            ->map(fn ($r) => json_decode($r->data, true, flags: JSON_THROW_ON_ERROR));
        // Lines in the legacy natural order, with the exact text of their numbers.
        $items = collect(DB::select(<<<'SQL'
            SELECT r.data, r.data->>'qty' AS qty_text, r.data->>'price' AS price_text, r.data->'attributes' AS attrs_json, n.position
            FROM legacy.rows r
            LEFT JOIN legacy.natural_order n ON n.snapshot_id = r.snapshot_id AND n.table_name = r.table_name AND n.row_id = r.data->>'id'
            WHERE r.snapshot_id = ? AND r.table_name = 'order_items'
            ORDER BY n.position NULLS LAST, r.row_no
            SQL, [$snapshotId]))
            // The characteristics keep their JSON shape: an empty object stays an object.
            ->map(fn ($r) => json_decode($r->data, true, flags: JSON_THROW_ON_ERROR) + ['qty_text' => $r->qty_text, 'price_text' => $r->price_text,
                'attrs' => $r->attrs_json === null ? new \stdClass : json_decode($r->attrs_json, flags: JSON_THROW_ON_ERROR), 'position' => $r->position]);
        if ($orders->isEmpty()) {
            throw new RuntimeException("Snapshot {$snapshotId} has no orders rows");
        }
        $this->validate($orders->all(), $items->all());

        return DB::transaction(function () use ($orders, $items, $snapshotId, $by) {
            $stats = ['legacy_orders' => $orders->count(), 'legacy_items' => $items->count(), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed_lines' => 0];
            $orderMap = DB::table('legacy_id_map')->where('source_table', 'orders')->pluck('target_id', 'source_key');
            $gone = $orderMap->keys()->diff($orders->map(fn ($o) => (string) $o['id']));
            if ($gone->isNotEmpty()) {
                throw new RuntimeException("{$gone->count()} imported orders are no longer in legacy — review them before importing, nothing imported");
            }
            $existing = Order::query()->whereIn('id', $orderMap->values())->lockForUpdate()->get()->keyBy('id');
            $idByNum = [];
            $mapRows = [];
            foreach ($orders as $o) {
                $order = isset($orderMap[(string) $o['id']]) ? $existing->get($orderMap[(string) $o['id']]) : null;
                if ($order === null) {
                    $order = new Order;
                    $stats['created']++;
                }
                $order->forceFill($this->orderAttributes($o));
                if ($order->exists && $order->isDirty()) {
                    $order->lock_version++;
                    $stats['updated']++;
                } elseif ($order->exists) {
                    $stats['unchanged']++;
                }
                $order->timestamps = false; // keep the legacy creation time
                $order->save();
                $idByNum[$order->num] = $order->id;
                $mapRows[] = ['source_table' => 'orders', 'source_key' => (string) $o['id'], 'target_table' => 'orders', 'target_id' => $order->id, 'snapshot_id' => $snapshotId];
            }

            // Lines follow legacy: placed in its order, and a line removed in legacy is removed here too (orders are view-only).
            $itemMap = DB::table('legacy_id_map')->where('source_table', 'order_items')->pluck('target_id', 'source_key');
            $removed = $itemMap->except($items->map(fn ($i) => (string) $i['id'])->all());
            if ($removed->isNotEmpty()) {
                OrderItem::query()->whereIn('id', $removed->values())->delete();
                DB::table('legacy_id_map')->where('source_table', 'order_items')->whereIn('source_key', $removed->keys())->delete();
                $stats['removed_lines'] = $removed->count();
            }
            $existingItems = OrderItem::query()->whereIn('id', $itemMap->values())->lockForUpdate()->get()->keyBy('id');
            $lineNo = [];
            foreach ($items as $it) {
                $orderId = $idByNum[trim((string) $it['order_num'])];
                $no = $lineNo[$orderId] = isset($lineNo[$orderId]) ? $lineNo[$orderId] + 1 : 0;
                $item = isset($itemMap[(string) $it['id']]) ? $existingItems->get($itemMap[(string) $it['id']]) : null;
                $item ??= new OrderItem;
                $item->forceFill(['order_id' => $orderId, 'line_no' => $no] + $this->itemAttributes($it));
                $item->timestamps = false;
                $item->save();
                $mapRows[] = ['source_table' => 'order_items', 'source_key' => (string) $it['id'], 'target_table' => 'order_items', 'target_id' => $item->id, 'snapshot_id' => $snapshotId];
            }
            foreach (array_chunk($mapRows, 1000) as $chunk) {
                DB::table('legacy_id_map')->upsert($chunk, ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at']);
            }
            $this->verify($orders->all(), $items->all());
            $stats['items'] = OrderItem::query()->count();
            $stats['channels'] = Order::query()->distinct()->count('channel');
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'orders', null, $stats, $by);

            return $stats;
        });
    }

    private function verify(array $orders, array $items): void
    {
        $ids = DB::table('legacy_id_map')->where('source_table', 'orders')->whereIn('source_key', array_map(fn ($o) => (string) $o['id'], $orders))->pluck('target_id', 'source_key');
        if ($ids->count() !== count($orders)) {
            throw new RuntimeException("Verification failed: {$ids->count()} of ".count($orders).' orders mapped');
        }
        $stored = Order::query()->whereIn('id', $ids->values())->get()->keyBy('id');
        foreach ($orders as $o) {
            $order = $stored->get($ids[(string) $o['id']]) ?? throw new RuntimeException("Verification failed: order {$o['id']} is missing");
            foreach ($this->orderAttributes($o) as $field => $value) {
                $actual = $order->{$field} instanceof \DateTimeInterface && ! in_array($field, ['created_at', 'updated_at'], true) ? $order->{$field}->format('Y-m-d') : $order->{$field};
                $same = $actual instanceof \DateTimeInterface ? $actual->equalTo($value) : (string) $actual === (string) $value;
                if (! $same) {
                    throw new RuntimeException("Verification failed for order {$o['id']} ({$field})");
                }
            }
        }
        $itemIds = DB::table('legacy_id_map')->where('source_table', 'order_items')->whereIn('source_key', array_map(fn ($i) => (string) $i['id'], $items))->pluck('target_id', 'source_key');
        $storedItems = OrderItem::query()->whereIn('id', $itemIds->values())->get()->keyBy('id');
        $orderIdByNum = [];
        foreach ($orders as $o) {
            $orderIdByNum[trim((string) $o['num'])] = $ids[(string) $o['id']];
        }
        if (OrderItem::query()->count() !== count($items)) {
            throw new RuntimeException('Verification failed: the number of order lines differs from legacy');
        }
        $expectedNo = [];
        foreach ($items as $it) {
            $item = $storedItems->get($itemIds[(string) $it['id']] ?? 0) ?? throw new RuntimeException("Verification failed: order line {$it['id']} is missing");
            $num = trim((string) $it['order_num']);
            $no = $expectedNo[$num] = isset($expectedNo[$num]) ? $expectedNo[$num] + 1 : 0;
            if ($item->line_no !== $no || $item->order_id !== $orderIdByNum[$num]) {
                throw new RuntimeException("Verification failed for order line {$it['id']} (place in the order)");
            }
            foreach (['qty', 'price', 'name', 'status', 'progress'] as $field) {
                if ((string) $item->{$field} !== (string) $this->itemAttributes($it)[$field]) {
                    throw new RuntimeException("Verification failed for order line {$it['id']} ({$field})");
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function orderAttributes(array $o): array
    {
        $text = fn (string $f) => ($v = trim((string) ($o[$f] ?? ''))) === '' ? null : $v;
        $createdAt = Carbon::parse($o['created_at']);

        return [
            'num' => trim((string) $o['num']),
            'channel' => $text('channel') ?? SalesChannel::OFFICE,
            'customer' => $text('customer'),
            'end_client' => $text('end_client'),
            'seller' => $text('seller'),
            'order_date' => LegacyDate::parse($o['created_date'] ?? null),
            'due_date' => LegacyDate::parse($o['date'] ?? null),
            'delivery_date' => LegacyDate::parse($o['delivery_date'] ?? null),
            'payment_due' => LegacyDate::parse($o['payment_due'] ?? null),
            'payment_pct' => (int) ($o['payment'] ?? 0),
            'status' => $text('status') ?? 'queue',
            'comment' => $text('comment'),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /** @return array<string, mixed> */
    private function itemAttributes(array $it): array
    {
        $text = fn (string $f) => ($v = trim((string) ($it[$f] ?? ''))) === '' ? null : $v;
        $createdAt = Carbon::parse($it['created_at']);

        return [
            'name' => trim((string) ($it['name'] ?? '')),
            'item_type' => $text('item_type'),
            'category' => $text('category'),
            'qty' => bcadd($it['qty_text'] ?? '1', '0', 4),
            'price' => bcadd($it['price_text'] ?? '0', '0', 2),
            'progress' => (int) ($it['progress'] ?? 0),
            'status' => $text('status') ?? 'queue',
            'resp' => $text('resp'),
            'item_date' => LegacyDate::parse($it['date'] ?? null),
            'attrs' => $it['attrs'],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @param  list<array<string, mixed>>  $items
     */
    private function validate(array $orders, array $items): void
    {
        $channels = SalesChannel::query()->pluck('code')->flip();
        $problems = [];
        $ids = [];
        $nums = [];
        foreach ($orders as $i => $o) {
            $ref = 'order row '.($i + 1);
            if (! isset($o['id']) || isset($ids[(string) $o['id']])) {
                $problems[] = "{$ref}: missing or duplicate id";
            }
            $ids[(string) ($o['id'] ?? '')] = true;
            $num = trim((string) ($o['num'] ?? ''));
            if ($num === '' || isset($nums[$num]) || mb_strlen($num) > 32) {
                $problems[] = "{$ref}: missing, duplicate or too long number";
            }
            $nums[$num] = true;
            if (($c = trim((string) ($o['channel'] ?? ''))) !== '' && ! isset($channels[$c])) {
                $problems[] = "{$ref}: unknown channel";
            }
            if (! in_array($o['status'] ?? null, [null, '', ...self::STATUSES], true)) {
                $problems[] = "{$ref}: unknown status";
            }
            $p = $o['payment'] ?? 0;
            if (! is_int($p) && ! (is_float($p) && floor($p) === $p) || $p < 0 || $p > 100) {
                $problems[] = "{$ref}: payment is not a whole percentage 0–100";
            }
            foreach (['created_date', 'date', 'delivery_date', 'payment_due'] as $f) {
                if (LegacyDate::parse($o[$f] ?? null) === false) {
                    $problems[] = "{$ref}: {$f} is not a date";
                }
            }
            foreach (['customer' => 255, 'end_client' => 255, 'seller' => 255] as $f => $max) {
                if (mb_strlen(trim((string) ($o[$f] ?? ''))) > $max) {
                    $problems[] = "{$ref}: {$f} longer than {$max}";
                }
            }
            if (empty($o['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        foreach ($items as $i => $it) {
            $ref = 'order line '.($it['id'] ?? $i + 1);
            if ($it['position'] === null) {
                $problems[] = "{$ref}: no natural order (run legacy:natural-order order_items)";
            }
            if (! isset($nums[trim((string) ($it['order_num'] ?? ''))])) {
                $problems[] = "{$ref}: order not found";
            }
            foreach (['qty_text' => 4, 'price_text' => 2] as $f => $scale) {
                $v = $it[$f];
                if ($v !== null && (! is_numeric($v) || $v < 0 || preg_match('/\.\d{'.($scale + 1).',}/', $v))) {
                    $problems[] = "{$ref}: {$f} is not a non-negative number with up to {$scale} decimals";
                }
            }
            if (! in_array($it['status'] ?? null, [null, '', ...self::STATUSES], true)) {
                $problems[] = "{$ref}: unknown status";
            }
            if (($pr = $it['progress'] ?? 0) !== null && ($pr < 0 || $pr > 100)) {
                $problems[] = "{$ref}: progress outside 0–100";
            }
            if (LegacyDate::parse($it['date'] ?? null) === false) {
                $problems[] = "{$ref}: date is not a date";
            }
            if (empty($it['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        if ($problems) {
            throw new RuntimeException('Legacy orders rejected, nothing imported: '.implode('; ', array_slice($problems, 0, 10)));
        }
    }
}
