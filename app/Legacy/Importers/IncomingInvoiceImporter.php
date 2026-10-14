<?php

namespace App\Legacy\Importers;

use App\Models\EsfPaymentLink;
use App\Models\IncomingInvoice;
use App\Models\IncomingInvoiceItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy incoming_invoices + incoming_invoice_items + esf_payment_links → M05, in one transaction, idempotent through
 * legacy_id_map. Amounts are read as the exact decimal text of the JSON numbers: legacy invoice totals are float sums of
 * the lines (e.g. 15069.999999999998) and are kept to 0.01. Lines keep the natural order of the legacy table
 * (legacy:natural-order); the invoice is linked to a supplier only when exactly one supplier has its BIN.
 */
class IncomingInvoiceImporter
{
    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $invoices = $this->rows($snapshotId, 'incoming_invoices', ['total_amount', 'total_nds', 'total_with_nds']);
        $items = $this->rows($snapshotId, 'incoming_invoice_items', ['qty', 'unit_price', 'amount', 'nds_rate', 'nds_amount', 'total']);
        $links = $this->rows($snapshotId, 'esf_payment_links', ['amount']);
        if ($invoices->isEmpty()) {
            throw new RuntimeException("Snapshot {$snapshotId} has no incoming_invoices rows");
        }
        $natural = DB::getSchemaBuilder()->hasTable('legacy.natural_order')
            ? DB::table('legacy.natural_order')->where(['snapshot_id' => $snapshotId, 'table_name' => 'incoming_invoice_items'])->pluck('position', 'row_id')
            : collect();
        $itemOrder = $natural->count() === $items->count() ? 'natural' : 'snapshot';
        $this->validate($invoices, $items, $links);

        return DB::transaction(function () use ($invoices, $items, $links, $natural, $itemOrder, $snapshotId, $by) {
            $map = DB::table('legacy_id_map')->where('source_table', 'incoming_invoices')->pluck('target_id', 'source_key');
            $binOwners = Supplier::query()->active()->whereNotNull('bin')->get(['id', 'bin'])->groupBy(fn (Supplier $s) => trim((string) $s->bin));
            $stats = ['legacy_invoices' => $invoices->count(), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            $ids = [];
            foreach ($invoices as $r) {
                $invoice = isset($map[$r['id']]) ? IncomingInvoice::query()->lockForUpdate()->find($map[$r['id']]) : null;
                if ($invoice === null) {
                    $invoice = new IncomingInvoice;
                    $stats['created']++;
                }
                $owners = $binOwners->get(trim((string) ($r['supplier_bin'] ?? '')));
                $invoice->forceFill($this->invoiceAttributes($r) + ['supplier_id' => $owners !== null && $owners->count() === 1 ? $owners->first()->id : null]);
                if ($invoice->exists && $invoice->isDirty()) {
                    $invoice->lock_version++;
                    $stats['updated']++;
                } elseif ($invoice->exists) {
                    $stats['unchanged']++;
                }
                $invoice->timestamps = false;
                $invoice->save();
                $ids[$r['id']] = $invoice->id;
            }
            DB::table('legacy_id_map')->upsert(
                collect($ids)->map(fn (int $target, string $source) => ['source_table' => 'incoming_invoices', 'source_key' => $source, 'target_table' => 'incoming_invoices', 'target_id' => $target, 'snapshot_id' => $snapshotId])->values()->all(),
                ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at'],
            );

            // Lines and links are replaced as a whole for the imported invoices: they have no own identity in the UI.
            IncomingInvoiceItem::query()->whereIn('invoice_id', $ids)->delete();
            EsfPaymentLink::query()->whereIn('invoice_id', $ids)->delete();
            $byInvoice = $items->sortBy(fn (array $r) => $itemOrder === 'natural' ? $natural[$r['id']] : $r['_row_no'])->groupBy('invoice_id');
            foreach ($byInvoice as $legacyInvoiceId => $lines) {
                $rows = $lines->values()->map(fn (array $r, int $i) => $this->itemAttributes($r) + ['invoice_id' => $ids[$legacyInvoiceId], 'line_no' => $i + 1])->all();
                foreach (array_chunk($rows, 500) as $chunk) {
                    IncomingInvoiceItem::query()->insert($chunk);
                }
            }
            foreach ($links->chunk(500) as $chunk) {
                EsfPaymentLink::query()->insert($chunk->map(fn (array $r) => [
                    'invoice_id' => $ids[$r['esf_invoice_id']],
                    'source_type' => $r['source_type'],
                    'source_legacy_id' => $r['source_id'] ?? null,
                    'amount' => self::decimal($r['amount_text'], 2),
                    'created_at' => Carbon::parse($r['created_at']),
                ])->values()->all());
            }

            $stats += $this->verify($invoices, $items, $links, $ids);
            $stats['items'] = $items->count();
            $stats['item_order'] = $itemOrder;
            $stats['links'] = $links->count();
            $stats['with_supplier'] = IncomingInvoice::query()->whereIn('id', $ids)->whereNotNull('supplier_id')->count();
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'incoming_invoices', null, $stats, $by);

            return $stats;
        });
    }

    /**
     * Rows of a snapshot table with the exact decimal text of the numeric fields.
     *
     * @param  list<string>  $numbers
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(string $snapshotId, string $table, array $numbers): Collection
    {
        $select = implode(', ', array_map(fn (string $f) => "data->>'{$f}' AS \"{$f}_text\"", $numbers));

        return collect(DB::select("SELECT row_no, data, {$select} FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no", [$snapshotId, $table]))
            ->map(function ($r) use ($numbers) {
                $row = json_decode($r->data, true, flags: JSON_THROW_ON_ERROR) + ['_row_no' => $r->row_no];
                foreach ($numbers as $f) {
                    $row["{$f}_text"] = $r->{"{$f}_text"};
                }

                return $row;
            });
    }

    /** @return array<string, mixed> */
    private function invoiceAttributes(array $r): array
    {
        $text = fn (string $f) => ($v = trim((string) ($r[$f] ?? ''))) === '' ? null : $v;
        $createdAt = Carbon::parse($r['created_at']);

        return [
            'num' => $text('num'),
            'reg_num' => $text('reg_num'),
            'date' => $r['date'],
            'status' => $text('status') ?? 'CREATED',
            'invoice_type' => $text('invoice_type') ?? 'general',
            'supplier_name' => trim((string) $r['supplier_name']),
            'supplier_bin' => $text('supplier_bin'),
            'buyer_name' => $text('buyer_name'),
            'buyer_bin' => $text('buyer_bin'),
            'currency' => $text('currency') ?? 'KZT',
            'total_amount' => self::decimal($r['total_amount_text'], 2),
            'total_nds' => self::decimal($r['total_nds_text'], 2),
            'total_with_nds' => self::decimal($r['total_with_nds_text'], 2),
            'posted_at' => isset($r['posted_at']) ? Carbon::parse($r['posted_at']) : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /** @return array<string, mixed> */
    private function itemAttributes(array $r): array
    {
        return [
            'description' => (string) ($r['description'] ?? ''),
            'qty' => self::decimal($r['qty_text'], 3),
            'unit_price' => self::decimal($r['unit_price_text'], 2),
            'amount' => self::decimal($r['amount_text'], 2),
            'nds_rate' => self::decimal($r['nds_rate_text'], 2),
            'nds_amount' => self::decimal($r['nds_amount_text'], 2),
            'total' => self::decimal($r['total_text'], 2),
            'warehouse_code' => ($r['warehouse_id'] ?? '') === '' ? null : $r['warehouse_id'],
        ];
    }

    /**
     * Stored values against the legacy rows: every invoice field and total, every line and link; and the totals of each
     * invoice against the sum of its lines.
     *
     * @return array<string, int>
     */
    private function verify(Collection $invoices, Collection $items, Collection $links, array $ids): array
    {
        $stored = IncomingInvoice::query()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($invoices as $r) {
            $inv = $stored->get($ids[$r['id']]) ?? throw new RuntimeException("Verification failed: invoice {$r['id']} is missing");
            $expected = $this->invoiceAttributes($r);
            foreach (['num', 'reg_num', 'status', 'invoice_type', 'supplier_name', 'supplier_bin', 'buyer_name', 'buyer_bin', 'total_amount', 'total_nds', 'total_with_nds'] as $f) {
                if ((string) $inv->{$f} !== (string) $expected[$f]) {
                    throw new RuntimeException("Verification failed for invoice {$r['id']} ({$f})");
                }
            }
            if ($inv->date->format('Y-m-d') !== $expected['date'] || ! $inv->created_at->equalTo($expected['created_at'])) {
                throw new RuntimeException("Verification failed for invoice {$r['id']} (date)");
            }
        }
        $sums = DB::table('incoming_invoice_items')->whereIn('invoice_id', $ids)->groupBy('invoice_id')
            ->selectRaw('invoice_id, count(*) AS n, sum(amount) AS amount, sum(nds_amount) AS nds, sum(total) AS total')->get()->keyBy('invoice_id');
        $mismatch = 0;
        foreach ($stored as $inv) {
            $s = $sums->get($inv->id);
            if ($s === null || bccomp((string) $s->total, (string) $inv->total_with_nds, 2) !== 0 || bccomp((string) $s->amount, (string) $inv->total_amount, 2) !== 0
                || bccomp((string) $s->nds, (string) $inv->total_nds, 2) !== 0) {
                $mismatch++;
            }
        }
        if ((int) $sums->sum('n') !== $items->count()) {
            throw new RuntimeException('Verification failed: '.$sums->sum('n').' of '.$items->count().' lines stored');
        }
        $linkSum = (string) DB::table('esf_payment_links')->whereIn('invoice_id', $ids)->sum('amount');
        $expectedLinkSum = $links->reduce(fn (string $s, array $r) => bcadd($s, self::decimal($r['amount_text'], 2), 2), '0');
        if (DB::table('esf_payment_links')->whereIn('invoice_id', $ids)->count() !== $links->count() || bccomp($linkSum, $expectedLinkSum, 2) !== 0) {
            throw new RuntimeException('Verification failed: payment links');
        }

        return ['invoices_totals_differ_from_lines' => $mismatch];
    }

    /** Exact decimal half-up rounding of the decimal text of a JSON number; an absent value is 0. */
    private static function decimal(?string $value, int $scale): string
    {
        $value = $value === null || $value === '' ? '0' : $value;
        $half = '0.'.str_repeat('0', $scale).'5';

        return str_starts_with($value, '-') ? bcsub($value, $half, $scale) : bcadd($value, $half, $scale);
    }

    private function validate(Collection $invoices, Collection $items, Collection $links): void
    {
        $problems = [];
        $invoiceIds = $invoices->pluck('id')->flip();
        $warehouses = Warehouse::query()->pluck('code')->flip();
        if ($invoiceIds->count() !== $invoices->count()) {
            $problems[] = 'duplicate invoice ids';
        }
        foreach ($invoices as $r) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r['date'] ?? '')) || trim((string) ($r['supplier_name'] ?? '')) === '') {
                $problems[] = "invoice {$r['id']}: no date or supplier";
            }
            if (($t = $r['invoice_type'] ?? 'general') !== null && ! in_array($t, IncomingInvoice::TYPES, true)) {
                $problems[] = "invoice {$r['id']}: unknown type";
            }
            foreach (['total_amount', 'total_nds', 'total_with_nds'] as $f) {
                if (! is_numeric($r["{$f}_text"] ?? null)) {
                    $problems[] = "invoice {$r['id']}: {$f} is not a number";
                }
            }
            foreach (['num' => 64, 'reg_num' => 64, 'supplier_name' => 255, 'supplier_bin' => 32, 'buyer_name' => 255, 'buyer_bin' => 32, 'status' => 64] as $f => $max) {
                if (mb_strlen((string) ($r[$f] ?? '')) > $max) {
                    $problems[] = "invoice {$r['id']}: {$f} longer than {$max}";
                }
            }
        }
        foreach ($items as $r) {
            if (! isset($invoiceIds[$r['invoice_id'] ?? ''])) {
                $problems[] = "item {$r['id']}: unknown invoice";
            }
            if (($r['warehouse_id'] ?? '') !== '' && ! isset($warehouses[$r['warehouse_id']])) {
                $problems[] = "item {$r['id']}: unknown warehouse";
            }
            foreach (['qty', 'unit_price', 'amount', 'nds_rate', 'nds_amount', 'total'] as $f) {
                if (($r["{$f}_text"] ?? null) !== null && ! is_numeric($r["{$f}_text"])) {
                    $problems[] = "item {$r['id']}: {$f} is not a number";
                }
            }
        }
        foreach ($links as $r) {
            if (! isset($invoiceIds[$r['esf_invoice_id'] ?? '']) || ! in_array($r['source_type'] ?? '', ['money_tx', 'advance_report'], true) || ! is_numeric($r['amount_text'] ?? null) || $r['amount_text'] <= 0) {
                $problems[] = "link {$r['id']}: unknown invoice or source type, or amount not positive";
            }
        }
        if ($problems) {
            throw new RuntimeException('Legacy ESF rejected, nothing imported: '.implode('; ', array_slice($problems, 0, 10)));
        }
    }
}
