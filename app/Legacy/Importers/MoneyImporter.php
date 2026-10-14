<?php

namespace App\Legacy\Importers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy money → M12 (after orders and expense categories): the cash desks and bank accounts written in the legacy page
 * (bankAccounts, read from the accepted reference kept locally in .local-audit/blobs — the account numbers are skipped),
 * money_tx, order_payments and advance_reports. Whole set validated first; one transaction; idempotent through
 * legacy_id_map. New operations are inserted in the order legacy loads them (date descending, id ascending), so that order
 * holds inside a day.
 */
class MoneyImporter
{
    public function __construct(private readonly ?string $html = null) {}

    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $accounts = $this->accounts($this->html ?? $this->acceptedReference());
        $tx = collect(DB::select(<<<'SQL'
            SELECT data, data->>'amount' AS amount_text, data->>'amount_fx' AS fx_text FROM legacy.rows
            WHERE snapshot_id = ? AND table_name = 'money_tx'
            ORDER BY data->>'date' DESC, data->>'id' COLLATE "C" ASC
            SQL, [$snapshotId]))->map(fn ($r) => json_decode($r->data, true, flags: JSON_THROW_ON_ERROR) + ['amount_text' => $r->amount_text, 'fx_text' => $r->fx_text]);
        $payments = $this->rows($snapshotId, 'order_payments', "data->>'amount' AS amount_text");
        // Advance reports in the order the legacy page lists them (order=date.desc, `legacy:natural-order advance_reports
        // --order=date.desc`): rows of one date keep that order through their ids.
        // Without that read the snapshot order is kept.
        $ordered = DB::selectOne("SELECT to_regclass('legacy.natural_order') IS NOT NULL AS ok")->ok;
        $advances = $this->rows($snapshotId, 'advance_reports', "data->>'issued' AS issued_text, data->>'spent' AS spent_text, data->>'returned' AS returned_text, data->'lines' AS lines_json, "
            .($ordered ? "(SELECT n.position FROM legacy.natural_order n WHERE n.snapshot_id = legacy.rows.snapshot_id AND n.table_name = 'advance_reports' AND n.row_id = legacy.rows.data->>'id')" : 'NULL').' AS position')
            ->sortBy(fn ($a) => $a['position'] ?? PHP_INT_MAX)->values();
        if ($tx->isEmpty()) {
            throw new RuntimeException("Snapshot {$snapshotId} has no money_tx rows");
        }
        $categories = DB::table('expense_categories')->pluck('id', 'code');
        $orders = DB::table('orders')->pluck('id', 'num');
        $this->validate($accounts, $tx->all(), $payments->all(), $advances->all(), $categories->all());

        return DB::transaction(function () use ($accounts, $tx, $payments, $advances, $categories, $orders, $snapshotId, $by) {
            $stats = ['accounts' => count($accounts), 'operations' => $tx->count(), 'order_payments' => $payments->count(), 'advance_reports' => $advances->count(), 'created' => 0, 'unchanged' => 0, 'updated' => 0];
            $accountIds = $this->upsert('money_accounts', 'money_accounts', $accounts, fn ($a, $i) => [
                'name' => $a['name'], 'bank' => $a['bank'] ?: null, 'bik' => $a['bik'] ?: null, 'currency' => $a['currency'] ?: 'KZT', 'type' => $a['type'],
                'icon' => $a['icon'] ?: null, 'opening_balance' => bcadd($a['openingBalance'] ?? '0', '0', 2), 'sort_order' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ], $snapshotId, $stats, ['created_at', 'updated_at']);
            $txIds = $this->upsert('money_transactions', 'money_tx', $tx->all(), fn ($t) => $this->transaction($t, $accountIds, $categories->all(), $orders->all()), $snapshotId, $stats);
            $this->upsert('order_payments', 'order_payments', $payments->all(), fn ($p) => [
                'transaction_id' => $txIds[(string) $p['tx_id']], 'order_num' => trim((string) $p['order_num']), 'order_id' => $orders[trim((string) $p['order_num'])] ?? null,
                'amount' => bcadd($p['amount_text'], '0', 2), 'created_at' => Carbon::parse($p['created_at']), 'updated_at' => Carbon::parse($p['created_at']),
            ], $snapshotId, $stats);
            $this->upsert('advance_reports', 'advance_reports', $advances->all(), fn ($a) => [
                'doc_num' => ($v = trim((string) ($a['doc_num'] ?? ''))) === '' ? null : $v,
                'employee_name' => trim((string) $a['emp_name']),
                'report_date' => $a['date'],
                'purpose' => ($v = trim((string) ($a['purpose'] ?? ''))) === '' ? null : $v,
                'issued' => $a['issued_text'] ?? '0', 'spent' => $a['spent_text'] ?? '0', 'returned' => $a['returned_text'] ?? '0',
                'status' => $a['status'] ?: 'open',
                'account_id' => $accountIds[(string) $a['account_id']] ?? null,
                'transaction_id' => isset($a['tx_id']) ? ($txIds[(string) $a['tx_id']] ?? null) : null,
                // Legacy reads a missing list as an empty one.
                'lines' => in_array($a['lines_json'] ?? null, [null, 'null'], true) ? '[]' : $a['lines_json'],
                'created_at' => Carbon::parse($a['created_at']), 'updated_at' => Carbon::parse($a['created_at']),
            ], $snapshotId, $stats);
            $this->verify($tx->all(), $accountIds, $categories->all());
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'money', null, $stats, $by);

            return $stats;
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(string $snapshotId, string $table, string $extra)
    {
        return collect(DB::select("SELECT data, {$extra} FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no", [$snapshotId, $table]))
            ->map(function ($r) {
                $row = json_decode($r->data, true, flags: JSON_THROW_ON_ERROR);
                foreach ((array) $r as $k => $v) {
                    if ($k !== 'data') {
                        $row[$k] = $v;
                    }
                }

                return $row;
            });
    }

    /**
     * Insert or update rows through legacy_id_map; returns legacy id → new id.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $stats
     * @param  list<string>  $ignore  columns not compared for «unchanged»
     * @return array<string, int>
     */
    private function upsert(string $target, string $source, array $rows, callable $attributes, string $snapshotId, array &$stats, array $ignore = []): array
    {
        $map = DB::table('legacy_id_map')->where('source_table', $source)->pluck('target_id', 'source_key');
        $existing = DB::table($target)->whereIn('id', $map->values())->lockForUpdate()->get()->keyBy('id');
        $ids = [];
        $mapRows = [];
        foreach ($rows as $i => $row) {
            $key = (string) $row['id'];
            // Times keep their microseconds (the query builder would write seconds only).
            $values = array_map(fn ($v) => $v instanceof Carbon ? $v->format('Y-m-d H:i:s.uP') : $v, $attributes($row, $i));
            $current = isset($map[$key]) ? $existing->get($map[$key]) : null;
            if ($current === null) {
                $id = DB::table($target)->insertGetId($values);
                $stats['created']++;
            } else {
                $id = $current->id;
                $changed = collect($values)->except($ignore)->contains(fn ($v, $k) => ! $this->same($current->{$k}, $v));
                if ($changed) {
                    DB::table($target)->where('id', $id)->update($values);
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }
            }
            $ids[$key] = $id;
            $mapRows[] = ['source_table' => $source, 'source_key' => $key, 'target_table' => $target, 'target_id' => $id, 'snapshot_id' => $snapshotId];
        }
        foreach (array_chunk($mapRows, 1000) as $chunk) {
            DB::table('legacy_id_map')->upsert($chunk, ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at']);
        }

        return $ids;
    }

    private function same(mixed $stored, mixed $value): bool
    {
        if ($value instanceof Carbon || is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}/', $value)) {
            return $stored !== null && Carbon::parse($stored)->equalTo(Carbon::parse($value));
        }
        if (is_string($value) && str_starts_with(ltrim($value), '[') || is_string($value) && str_starts_with(ltrim($value), '{')) {
            return json_decode((string) $stored, true) == json_decode($value, true);
        }
        if (is_numeric($stored) && is_numeric($value)) {
            return bccomp((string) $stored, (string) $value, 12) === 0;
        }

        return (string) $stored === (string) $value;
    }

    /**
     * @param  array<string, int>  $accountIds
     * @param  array<string, int>  $categories
     * @param  array<string, int>  $orders
     * @return array<string, mixed>
     */
    private function transaction(array $t, array $accountIds, array $categories, array $orders): array
    {
        $text = fn (string $f) => ($v = trim((string) ($t[$f] ?? ''))) === '' ? null : $v;
        $orderNum = $text('order_num');

        return [
            'account_id' => $accountIds[(string) $t['account_id']],
            'tx_date' => $t['date'],
            'flow' => $t['flow'],
            'category' => $text('category'),
            'expense_category_id' => ($sub = $text('subcategory')) === null ? null : $categories[$sub],
            'amount' => bcadd($t['amount_text'], '0', 2),
            'amount_fx' => $t['fx_text'] === null ? null : bcadd($t['fx_text'], '0', 2),
            'fx_currency' => $text('fx_currency'),
            'description' => $text('description'),
            'counterparty' => $text('counterparty'),
            'bin' => $text('bin'),
            'order_num' => $orderNum,
            'order_id' => $orderNum === null ? null : ($orders[$orderNum] ?? null),
            'created_at' => Carbon::parse($t['created_at']),
            'updated_at' => Carbon::parse($t['created_at']),
        ];
    }

    /**
     * @param  array<string, int>  $accountIds
     * @param  array<string, int>  $categories
     */
    private function verify(array $tx, array $accountIds, array $categories): void
    {
        $ids = DB::table('legacy_id_map')->where('source_table', 'money_tx')->pluck('target_id', 'source_key');
        $stored = DB::table('money_transactions')->get()->keyBy('id');
        $orders = DB::table('orders')->pluck('id', 'num')->all();
        if ($stored->count() !== count($tx)) {
            throw new RuntimeException('Verification failed: the number of operations differs from legacy');
        }
        foreach ($tx as $t) {
            $s = $stored->get($ids[(string) $t['id']] ?? 0) ?? throw new RuntimeException("Verification failed: operation {$t['id']} is missing");
            $expected = $this->transaction($t, $accountIds, $categories, $orders);
            foreach (['account_id', 'tx_date', 'flow', 'category', 'expense_category_id', 'amount', 'amount_fx', 'counterparty', 'description', 'order_num'] as $f) {
                if (! $this->same($s->{$f}, $expected[$f])) {
                    throw new RuntimeException("Verification failed for operation {$t['id']} ({$f})");
                }
            }
        }
    }

    /** The accepted reference of legacy (config: source.accepted_ref), kept locally — never in Git. */
    private function acceptedReference(): string
    {
        $config = json_decode((string) file_get_contents(base_path('tools/harness/config.json')), true, flags: JSON_THROW_ON_ERROR);
        $sha = $config['source']['accepted_ref']['sha256'];
        foreach (glob(base_path('.local-audit/blobs/*.html')) ?: [] as $file) {
            if (hash_file('sha256', $file) === $sha) {
                return (string) file_get_contents($file);
            }
        }
        throw new RuntimeException('The accepted legacy reference is not in .local-audit/blobs — run make audit-daily first');
    }

    /**
     * Legacy `let bankAccounts = [ {id:'…', name:'…', …}, … ];`: one object per line. The account number (iin) is not read.
     *
     * @return list<array<string, string>>
     */
    private function accounts(string $html): array
    {
        if (! preg_match('/let bankAccounts\s*=\s*\[(.*?)\n\];/s', $html, $block)) {
            throw new RuntimeException('bankAccounts not found in the legacy reference');
        }
        $accounts = [];
        foreach (explode("\n", $block[1]) as $line) {
            if (! preg_match('/^\s*\{(.*)\},?\s*$/', $line, $m)) {
                continue;
            }
            preg_match_all("/(\\w+):(?:'([^']*)'|([0-9.]+))/u", $m[1], $pairs, PREG_SET_ORDER);
            $a = [];
            foreach ($pairs as $p) {
                if ($p[1] !== 'iin') {
                    $a[$p[1]] = ($p[2] ?? '') !== '' || ! isset($p[3]) ? $p[2] : $p[3];
                }
            }
            $accounts[] = $a;
        }

        return $accounts;
    }

    /**
     * @param  list<array<string, string>>  $accounts
     * @param  array<string, int>  $categories
     */
    private function validate(array $accounts, array $tx, array $payments, array $advances, array $categories): void
    {
        $problems = [];
        $accountIds = [];
        foreach ($accounts as $i => $a) {
            if (empty($a['id']) || empty($a['name']) || ! in_array($a['type'] ?? '', ['cash', 'bank'], true)) {
                $problems[] = 'account '.($i + 1).': id, name or type missing';
            }
            $accountIds[$a['id'] ?? ''] = true;
        }
        $txIds = [];
        foreach ($tx as $i => $t) {
            $ref = 'operation '.($t['id'] ?? $i + 1);
            if (! isset($t['id']) || isset($txIds[(string) $t['id']])) {
                $problems[] = "{$ref}: missing or duplicate id";
            }
            $txIds[(string) ($t['id'] ?? '')] = true;
            if (! isset($accountIds[(string) ($t['account_id'] ?? '')])) {
                $problems[] = "{$ref}: unknown account";
            }
            if (! in_array($t['flow'] ?? null, ['income', 'expense'], true)) {
                $problems[] = "{$ref}: unknown flow";
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($t['date'] ?? ''))) {
                $problems[] = "{$ref}: date is not YYYY-MM-DD";
            }
            foreach (['amount_text', 'fx_text'] as $f) {
                if (($f === 'amount_text' || $t[$f] !== null) && (! is_numeric($t[$f]) || $t[$f] < 0 || preg_match('/\.\d{3,}/', (string) $t[$f]))) {
                    $problems[] = "{$ref}: {$f} is not a non-negative amount";
                }
            }
            if (($sub = trim((string) ($t['subcategory'] ?? ''))) !== '' && ! isset($categories[$sub])) {
                $problems[] = "{$ref}: unknown expense item";
            }
            foreach (['counterparty' => 255, 'bin' => 12, 'order_num' => 32, 'category' => 32, 'fx_currency' => 3] as $f => $max) {
                if (mb_strlen(trim((string) ($t[$f] ?? ''))) > $max) {
                    $problems[] = "{$ref}: {$f} longer than {$max}";
                }
            }
        }
        foreach ($payments as $p) {
            if (! isset($txIds[(string) ($p['tx_id'] ?? '')])) {
                $problems[] = "order payment {$p['id']}: operation not found";
            }
        }
        foreach ($advances as $a) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($a['date'] ?? '')) || trim((string) ($a['emp_name'] ?? '')) === ''
                || ! in_array($a['status'] ?? 'open', ['open', 'reported', 'closed'], true) || ($a['account_id'] && ! isset($accountIds[$a['account_id']]))) {
                $problems[] = "advance report {$a['id']}: date, employee, status or account is wrong";
            }
        }
        if ($problems) {
            throw new RuntimeException('Legacy money rejected, nothing imported: '.implode('; ', array_slice($problems, 0, 10)));
        }
    }
}
