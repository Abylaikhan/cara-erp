<?php

namespace App\Services\Esf;

use Illuminate\Support\Facades\DB;

/**
 * What the tab «Сверка с оплатами» of «Входящие ЭСФ» reads (legacy initEsfRecon, L11880): the expense operations of every
 * account (money_tx flow=expense, in the order legacy gets them), the advance reports (order=date.desc) with the invoices their
 * lines name, and the links of the invoices to both (esf_payment_links). The links keep the legacy id of their source; here
 * they name the operation or the report by its id in this system. A link whose source is not in the data is kept with no
 * source, as legacy keeps it (it counts for its invoice only).
 */
class EsfReconData
{
    /** @return array{payments: list<array<string, mixed>>, advances: list<array<string, mixed>>, links: list<array<string, mixed>>} */
    public function get(): array
    {
        $txIds = DB::table('legacy_id_map')->where('source_table', 'money_tx')->pluck('target_id', 'source_key');
        $advIds = DB::table('legacy_id_map')->where('source_table', 'advance_reports')->pluck('target_id', 'source_key');
        $invoiceIds = DB::table('legacy_id_map')->where('source_table', 'incoming_invoices')->pluck('target_id', 'source_key');

        $payments = DB::table('money_transactions')->where('flow', 'expense')->orderBy('id')
            ->get(['id', 'tx_date', 'counterparty', 'amount'])
            ->map(fn ($t) => ['id' => $t->id, 'date' => $t->tx_date, 'counterparty' => $t->counterparty, 'amount' => (float) $t->amount])->all();

        // legacy order=date.desc; within a date the order of the table
        $advances = DB::table('advance_reports')->orderByDesc('report_date')->orderBy('id')
            ->get(['id', 'report_date', 'employee_name', 'issued', 'status', 'lines'])
            ->map(fn ($a) => [
                'id' => $a->id, 'date' => $a->report_date, 'emp_name' => $a->employee_name, 'issued' => (float) $a->issued, 'status' => $a->status,
                // legacy esfReconAuditAdvanceLinks: the invoices the report's lines name (lines[].esfInvoiceId)
                'lineInvoices' => collect(json_decode((string) $a->lines, true) ?: [])
                    ->map(fn ($l) => is_array($l) ? ($invoiceIds[(string) ($l['esfInvoiceId'] ?? '')] ?? null) : null)->filter()->unique()->values()->all(),
            ])->all();

        $links = DB::table('esf_payment_links')->orderBy('id')->get(['invoice_id', 'source_type', 'source_legacy_id', 'amount'])
            ->map(fn ($l) => [
                'invoice' => $l->invoice_id,
                'type' => $l->source_type,
                'source' => match ($l->source_type) {
                    'money_tx' => $txIds[(string) $l->source_legacy_id] ?? null,
                    'advance_report' => $advIds[(string) $l->source_legacy_id] ?? null,
                    default => null,
                },
                'amount' => (float) $l->amount,
            ])->all();

        return ['payments' => $payments, 'advances' => $advances, 'links' => $links];
    }
}
