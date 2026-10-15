<?php

namespace App\Legacy\Importers;

use App\Models\SalonWorksheetRow;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy salon_worksheet → «Рабочий лист салонов». Whole set validated first; one transaction; idempotent through the legacy
 * uuid (kept on the row and in legacy_id_map); every row compared after the import. Amounts are kept exactly as stored.
 */
class SalonWorksheetImporter
{
    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $rows = collect(DB::select("SELECT data, data->'balance_before' AS bb, data->'cash_amount' AS ca, data->'card_amount' AS cr, data->'balance_after' AS ba,
                data->'cod_amount' AS co, data->'expense_amount' AS ex, data->'transferred_to_office' AS tr
            FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no", [$snapshotId, 'salon_worksheet']))
            ->map(function ($r) {
                $data = json_decode($r->data, true, flags: JSON_THROW_ON_ERROR);
                // Numbers as PostgreSQL prints them (no float rounding on the way).
                foreach (['balance_before' => 'bb', 'cash_amount' => 'ca', 'card_amount' => 'cr', 'balance_after' => 'ba', 'cod_amount' => 'co', 'expense_amount' => 'ex', 'transferred_to_office' => 'tr'] as $f => $c) {
                    $data[$f] = $r->{$c} === null || $r->{$c} === 'null' ? null : trim($r->{$c}, '"');
                }

                return $data;
            });
        $this->validate($rows->all());

        return DB::transaction(function () use ($rows, $snapshotId, $by) {
            $stats = ['legacy_rows' => $rows->count(), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            $existing = SalonWorksheetRow::query()->whereIn('uuid', $rows->pluck('id'))->lockForUpdate()->get()->keyBy('uuid');
            $map = [];
            foreach ($rows as $r) {
                $row = $existing->get($r['id']) ?? new SalonWorksheetRow;
                $row->forceFill($this->attributes($r));
                if (! $row->exists) {
                    $stats['created']++;
                } elseif ($row->isDirty()) {
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }
                $row->timestamps = false;
                $row->save();
                $map[] = ['source_table' => 'salon_worksheet', 'source_key' => $r['id'], 'target_table' => 'salon_worksheet_rows', 'target_id' => $row->id, 'snapshot_id' => $snapshotId];
            }
            foreach (array_chunk($map, 1000) as $chunk) {
                DB::table('legacy_id_map')->upsert($chunk, ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at']);
            }
            $this->verify($rows->all());
            $stats['armada'] = SalonWorksheetRow::query()->where('salon', 'armada')->count();
            $stats['nazarbayeva'] = SalonWorksheetRow::query()->where('salon', 'nazarbayeva')->count();
            $stats['transferred'] = SalonWorksheetRow::query()->where('transferred_to_db', true)->count();
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'salon_worksheet', null, $stats, $by);

            return $stats;
        });
    }

    /** @return array<string, mixed> */
    private function attributes(array $r): array
    {
        // Texts as the salon typed them (the worksheet shows them so); only an empty one is null.
        $text = fn (string $f) => ($v = (string) ($r[$f] ?? '')) === '' ? null : $v;
        $num = fn (string $f) => $r[$f] === null ? null : bcadd($r[$f], '0', 12);
        $created = Carbon::parse($r['created_at']);

        return [
            'uuid' => $r['id'],
            'salon' => $r['salon'],
            'order_date' => $text('order_date'),
            'order_num' => $text('order_num'),
            'manager' => $text('manager'),
            'balance_before' => $num('balance_before'),
            'payment_date' => $text('payment_date'),
            'cash_amount' => $num('cash_amount'),
            'card_amount' => $num('card_amount'),
            'balance_after' => $num('balance_after'),
            'note' => $text('note'),
            'delivery_date' => $text('delivery_date'),
            'cod_amount' => $num('cod_amount'),
            'expense_amount' => $num('expense_amount'),
            'transferred_to_office' => $num('transferred_to_office'),
            'recipient_desc' => $text('recipient_desc'),
            'transferred_to_db' => (bool) ($r['transferred_to_db'] ?? false),
            'transferred_at' => empty($r['transferred_at']) ? null : Carbon::parse($r['transferred_at']),
            'transferred_tx_ids' => $r['transferred_tx_ids'] ?? null,
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }

    private function verify(array $rows): void
    {
        $stored = SalonWorksheetRow::query()->whereIn('uuid', array_column($rows, 'id'))->get()->keyBy('uuid');
        if ($stored->count() !== count($rows)) {
            throw new RuntimeException("Verification failed: {$stored->count()} of ".count($rows).' salon worksheet rows stored');
        }
        foreach ($rows as $r) {
            $row = $stored[$r['id']];
            foreach ($this->attributes($r) as $field => $expected) {
                $actual = in_array($field, SalonWorksheetRow::NUMBER_FIELDS, true) ? $row->getRawOriginal($field) : $row->getAttribute($field);
                $same = match (true) {
                    $expected instanceof Carbon => $actual?->equalTo($expected) ?? false,
                    in_array($field, SalonWorksheetRow::DATE_FIELDS, true) => $actual?->format('Y-m-d') === $expected,
                    in_array($field, SalonWorksheetRow::NUMBER_FIELDS, true) => $expected === null ? $actual === null : ($actual !== null && bccomp($actual, $expected, 12) === 0),
                    default => $actual == $expected,
                };
                if (! $same) {
                    throw new RuntimeException("Verification failed for salon worksheet row {$r['id']} ({$field})");
                }
            }
        }
    }

    private function validate(array $rows): void
    {
        $problems = [];
        $ids = [];
        foreach ($rows as $i => $r) {
            $ref = 'row '.($i + 1);
            if (! isset($r['id']) || ! preg_match('/^[0-9a-f-]{36}$/i', (string) $r['id']) || isset($ids[$r['id']])) {
                $problems[] = "{$ref}: missing, malformed or duplicate id";
            }
            $ids[$r['id'] ?? ''] = true;
            if (! in_array($r['salon'] ?? null, SalonWorksheetRow::SALONS, true)) {
                $problems[] = "{$ref}: unknown salon";
            }
            foreach (SalonWorksheetRow::DATE_FIELDS as $f) {
                $v = trim((string) ($r[$f] ?? ''));
                if ($v !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || ! checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)))) {
                    $problems[] = "{$ref}: {$f} is not YYYY-MM-DD";
                }
            }
            foreach (SalonWorksheetRow::NUMBER_FIELDS as $f) {
                if ($r[$f] !== null && ! preg_match('/^-?\d{1,18}(\.\d{1,12})?$/', $r[$f])) {
                    $problems[] = "{$ref}: {$f} is not a number that fits";
                }
            }
            if (mb_strlen(trim((string) ($r['order_num'] ?? ''))) > 64 || mb_strlen(trim((string) ($r['manager'] ?? ''))) > 255) {
                $problems[] = "{$ref}: order number or manager too long";
            }
            if (empty($r['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        if ($problems) {
            throw new RuntimeException('Legacy salon worksheet rejected, nothing imported: '.implode('; ', array_slice($problems, 0, 10)));
        }
    }
}
