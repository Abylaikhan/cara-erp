<?php

namespace App\Legacy\Importers;

use App\Models\BankStatementRow;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy bank_statement_raw → «Сверка с банком». Whole set validated first; one transaction; idempotent through the legacy uuid;
 * rows deleted in legacy («🗑 Очистить») are deleted here too (their values stay in the audit trail); every row compared after
 * the import. The account is found through the imported money accounts (`legacy:import money` first).
 */
class BankStatementImporter
{
    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $rows = collect(DB::select("SELECT data, data->>'amount' AS amount_text FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no", [$snapshotId, 'bank_statement_raw']))
            ->map(fn ($r) => ['amount_text' => $r->amount_text] + json_decode($r->data, true, flags: JSON_THROW_ON_ERROR));
        $accounts = DB::table('legacy_id_map')->where('source_table', 'money_accounts')->pluck('target_id', 'source_key');
        $txIds = DB::table('legacy_id_map')->where('source_table', 'money_tx')->pluck('target_id', 'source_key');
        $this->validate($rows->all(), $accounts->all());

        return DB::transaction(function () use ($rows, $accounts, $txIds, $snapshotId, $by) {
            $stats = ['legacy_rows' => $rows->count(), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            $existing = BankStatementRow::query()->lockForUpdate()->get()->keyBy('uuid');
            $map = [];
            foreach ($rows as $r) {
                $row = $existing->get($r['id']) ?? new BankStatementRow;
                $row->forceFill($this->attributes($r, $accounts->all(), $txIds->all()));
                $stats[! $row->exists ? 'created' : ($row->isDirty() ? 'updated' : 'unchanged')]++;
                $row->timestamps = false;
                $row->save();
                $map[] = ['source_table' => 'bank_statement_raw', 'source_key' => $r['id'], 'target_table' => 'bank_statement_rows', 'target_id' => $row->id, 'snapshot_id' => $snapshotId];
            }
            foreach (array_chunk($map, 1000) as $chunk) {
                DB::table('legacy_id_map')->upsert($chunk, ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at']);
            }
            $gone = $existing->keys()->diff($rows->pluck('id'));
            if ($gone->isNotEmpty()) {
                Audit::record('legacy.removed', 'bank_statement_rows', null, ['legacy_keys' => $gone->values()->all(), 'rows' => $existing->only($gone->all())->map->getAttributes()->values()->all()], $by);
                BankStatementRow::query()->whereIn('uuid', $gone)->delete();
                DB::table('legacy_id_map')->where('source_table', 'bank_statement_raw')->whereIn('source_key', $gone)->delete();
            }
            $stats['removed'] = $gone->count();
            $this->verify($rows->all(), $accounts->all(), $txIds->all());
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'bank_statement_raw', null, $stats, $by);

            return $stats;
        });
    }

    /**
     * @param  array<string, int>  $accounts
     * @param  array<string, int>  $txIds
     * @return array<string, mixed>
     */
    private function attributes(array $r, array $accounts, array $txIds): array
    {
        $text = fn (string $f) => ($v = trim((string) ($r[$f] ?? ''))) === '' ? null : $v;
        $created = Carbon::parse($r['created_at']);

        return [
            'uuid' => $r['id'],
            'account_id' => $accounts[$r['account_id']],
            'bank_format' => $r['bank_format'],
            'row_date' => $text('date'),
            'flow' => $r['flow'],
            'amount' => bcadd($r['amount_text'], '0', 2),
            'counterparty' => $text('counterparty'),
            'bin' => $text('bin'),
            'description' => $text('description'),
            'knp' => $text('knp'),
            'doc_num' => $text('doc_num'),
            'transferred_to_db' => (bool) ($r['transferred_to_db'] ?? false),
            'transaction_id' => ($t = $text('transferred_tx_id')) === null ? null : ($txIds[$t] ?? null),
            'transferred_at' => empty($r['transferred_at']) ? null : Carbon::parse($r['transferred_at']),
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }

    /**
     * @param  array<string, int>  $accounts
     * @param  array<string, int>  $txIds
     */
    private function verify(array $rows, array $accounts, array $txIds): void
    {
        $stored = BankStatementRow::query()->get()->keyBy('uuid');
        if ($stored->count() !== count($rows)) {
            throw new RuntimeException("Verification failed: {$stored->count()} of ".count($rows).' bank statement rows stored');
        }
        foreach ($rows as $r) {
            $row = $stored->get($r['id']) ?? throw new RuntimeException("Verification failed: bank statement row {$r['id']} is missing");
            foreach ($this->attributes($r, $accounts, $txIds) as $field => $expected) {
                $actual = $row->getAttribute($field);
                $same = match (true) {
                    $expected instanceof Carbon => $actual?->equalTo($expected) ?? false,
                    $field === 'row_date' => $actual?->format('Y-m-d') === $expected,
                    $field === 'amount' => bccomp((string) $actual, $expected, 2) === 0,
                    default => $actual == $expected,
                };
                if (! $same) {
                    throw new RuntimeException("Verification failed for bank statement row {$r['id']} ({$field})");
                }
            }
        }
    }

    /** @param array<string, int> $accounts */
    private function validate(array $rows, array $accounts): void
    {
        $problems = [];
        $ids = [];
        foreach ($rows as $i => $r) {
            $ref = 'row '.($i + 1);
            if (! preg_match('/^[0-9a-f-]{36}$/i', (string) ($r['id'] ?? '')) || isset($ids[$r['id']])) {
                $problems[] = "{$ref}: missing, malformed or duplicate id";
            }
            $ids[$r['id'] ?? ''] = true;
            if (! isset($accounts[$r['account_id'] ?? ''])) {
                $problems[] = "{$ref}: account is not imported (legacy:import money first)";
            }
            if (! in_array($r['flow'] ?? null, ['income', 'expense'], true)) {
                $problems[] = "{$ref}: unknown flow";
            }
            if (! preg_match('/^\d{1,15}(\.\d{1,2})?$/', (string) $r['amount_text'])) {
                $problems[] = "{$ref}: amount is not a non-negative sum";
            }
            $d = trim((string) ($r['date'] ?? ''));
            if ($d !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || ! checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)))) {
                $problems[] = "{$ref}: date is not YYYY-MM-DD";
            }
            foreach (['counterparty' => 255, 'bin' => 32, 'knp' => 16, 'doc_num' => 64, 'bank_format' => 16] as $f => $max) {
                if (mb_strlen(trim((string) ($r[$f] ?? ''))) > $max) {
                    $problems[] = "{$ref}: {$f} longer than {$max}";
                }
            }
            if (empty($r['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        if ($problems) {
            throw new RuntimeException('Legacy bank statements rejected, nothing imported: '.implode('; ', array_slice($problems, 0, 10)));
        }
    }
}
