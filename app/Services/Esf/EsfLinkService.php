<?php

namespace App\Services\Esf;

use App\Models\EsfPaymentLink;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Входящие ЭСФ» → «Сверка с оплатами»: «Сохранить связи» and «🗑 Удалить связь» (legacy esfReconSaveLinks/
 * esfReconSavePoolLinks/esfReconDeleteAuditLink, L12145–12337; decision D-10, ADR-0048).
 *
 * The invoices ticked for a payment (or a group of payments) are linked to it; a link of that payment whose invoice is no
 * longer ticked is removed; a link that stays keeps its amount (also an excess carried over from legacy — № 54). A new link
 * covers a part of the invoice when the payment is short: the links never exceed what is left of the invoice nor what is left
 * of the payment (№ 52; legacy linked the whole invoice and let the last payment of a group take any rest). The payments of a
 * group are filled in their order, the larger invoices first, as legacy fills them.
 *
 * A link names its payment by the legacy id of the operation or report, as the links carried over do; every change is in
 * the audit trail — a later import of the invoices from legacy refuses to overwrite them (IncomingInvoiceImporter).
 */
class EsfLinkService
{
    public const NOTHING = 'Нет выбранного платежа — нечего сохранять.';

    private const TABLES = ['money_tx' => ['money_transactions', 'money_tx'], 'advance_report' => ['advance_reports', 'advance_reports']];

    /**
     * @param  list<array{type: string, id: int}>  $sources
     * @param  list<int>  $invoiceIds
     * @return array{added: list<array{invoice: int, amount: string}>, removed: list<int>, kept: int, skipped: list<int>}
     */
    public function save(array $sources, array $invoiceIds, User $by): array
    {
        if ($sources === []) {
            throw ValidationException::withMessages(['sources' => self::NOTHING]);
        }

        return DB::transaction(function () use ($sources, $invoiceIds, $by) {
            $payments = array_map(fn (array $s) => $this->source($s['type'], (int) $s['id']), $sources);
            $invoices = DB::table('incoming_invoices')->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get(['id', 'num', 'total_with_nds'])->keyBy('id');
            if ($invoices->count() !== count(array_unique($invoiceIds))) {
                throw ValidationException::withMessages(['invoices' => 'ЭСФ не найден — обновите страницу.']);
            }
            $ofSources = fn () => EsfPaymentLink::query()->where(function ($q) use ($payments) {
                foreach ($payments as $p) {
                    $q->orWhere(fn ($w) => $w->where('source_type', $p['type'])->where('source_legacy_id', $p['key']));
                }
            });
            $existing = $ofSources()->lockForUpdate()->orderBy('id')->get();
            $removed = $existing->reject(fn (EsfPaymentLink $l) => $invoices->has($l->invoice_id));
            $kept = $existing->filter(fn (EsfPaymentLink $l) => $invoices->has($l->invoice_id));
            EsfPaymentLink::query()->whereIn('id', $removed->pluck('id'))->delete();

            // What is left of each payment after the links it keeps, and of each invoice after all its links.
            $caps = array_map(fn (array $p) => $p + ['left' => bcsub($p['amount'], (string) $kept->where('source_type', $p['type'])->where('source_legacy_id', $p['key'])->sum('amount'), 2)], $payments);
            $linkedOf = DB::table('esf_payment_links')->whereIn('invoice_id', $invoices->keys())->groupBy('invoice_id')->selectRaw('invoice_id, sum(amount) AS s')->pluck('s', 'invoice_id');
            $fresh = $invoices->reject(fn ($i) => $kept->contains('invoice_id', $i->id))
                ->sort(fn ($a, $b) => bccomp((string) $b->total_with_nds, (string) $a->total_with_nds, 2) ?: $a->id <=> $b->id);

            $added = [];
            $skipped = [];
            foreach ($fresh as $inv) {
                $left = bcsub((string) $inv->total_with_nds, (string) ($linkedOf[$inv->id] ?? '0'), 2);
                $any = false;
                foreach ($caps as &$cap) {
                    if (bccomp($left, '0.005', 3) <= 0) {
                        break;
                    }
                    $amount = bccomp($left, $cap['left'], 2) <= 0 ? $left : $cap['left'];
                    if (bccomp($amount, '0.005', 3) <= 0) {
                        continue;
                    }
                    EsfPaymentLink::query()->create(['invoice_id' => $inv->id, 'source_type' => $cap['type'], 'source_legacy_id' => $cap['key'], 'amount' => $amount]);
                    $added[] = ['invoice' => $inv->id, 'type' => $cap['type'], 'source' => $cap['id'], 'amount' => $amount];
                    $cap['left'] = bcsub($cap['left'], $amount, 2);
                    $left = bcsub($left, $amount, 2);
                    $any = true;
                }
                unset($cap);
                if (! $any) {
                    $skipped[] = $inv->id;
                }
            }
            if ($added || $removed->isNotEmpty()) {
                Audit::record('esf_link.saved', 'esf_payment_link', null, [
                    'sources' => array_map(fn ($p) => ['type' => $p['type'], 'id' => $p['id']], $payments),
                    'added' => $added,
                    'removed' => $removed->map(fn (EsfPaymentLink $l) => ['invoice' => $l->invoice_id, 'type' => $l->source_type, 'amount' => (string) $l->amount])->values()->all(),
                ], $by);
            }

            return ['added' => array_map(fn ($a) => ['invoice' => $a['invoice'], 'amount' => $a['amount']], $added), 'removed' => $removed->pluck('invoice_id')->values()->all(),
                'kept' => $kept->count(), 'skipped' => $skipped];
        });
    }

    /** Legacy esfReconDeleteAuditLink: one link, whatever its payment. */
    public function delete(int $linkId, User $by): void
    {
        DB::transaction(function () use ($linkId, $by) {
            $link = EsfPaymentLink::query()->lockForUpdate()->find($linkId);
            if (! $link) {
                throw ValidationException::withMessages(['link' => 'Связь не найдена — обновите страницу.']);
            }
            Audit::record('esf_link.deleted', 'esf_payment_link', $link->id, ['invoice' => $link->invoice_id, 'type' => $link->source_type, 'amount' => (string) $link->amount], $by);
            $link->delete();
        });
    }

    /**
     * An expense operation or an advance report with its amount (legacy esfReconSourceAmount: the operation's amount, the
     * report's «issued») and the legacy id its links carry.
     *
     * @return array{type: string, id: int, key: string, amount: string}
     */
    private function source(string $type, int $id): array
    {
        [$table, $mapTable] = self::TABLES[$type] ?? throw ValidationException::withMessages(['sources' => 'Неизвестный вид платежа']);
        $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
        if (! $row || ($type === 'money_tx' && $row->flow !== 'expense')) {
            throw ValidationException::withMessages(['sources' => 'Платёж не найден — обновите страницу.']);
        }
        $key = DB::table('legacy_id_map')->where('source_table', $mapTable)->where('target_id', $id)->value('source_key');
        if ($key === null) {
            throw ValidationException::withMessages(['sources' => 'Платёж без записи в старой системе — связать его можно будет вместе с проведением денег (ERP-031).']);
        }

        return ['type' => $type, 'id' => $id, 'key' => (string) $key, 'amount' => bcadd($type === 'money_tx' ? (string) $row->amount : (string) $row->issued, '0', 2)];
    }
}
