<?php

namespace App\Legacy\Importers;

use App\Models\Customer;
use App\Models\SalesChannel;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy customers → M08. Whole set validated first; one transaction; idempotent through legacy_id_map. Every legacy row
 * becomes its own customer: duplicates by name are kept (DATA-MAP §3), empty strings become empty values, the legacy
 * creation time is kept. `contact` and `turnover` are not stored, so a row with a value there stops the import.
 */
class CustomerImporter
{
    private const TYPES = ['client', 'company', Customer::LEGAL, Customer::INDIVIDUAL];

    private const TEXT = ['inn', 'phone', 'email', 'address', 'comment'];

    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $rows = collect(DB::select('SELECT data FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no', [$snapshotId, 'customers']))
            ->map(fn ($r) => json_decode($r->data, true, flags: JSON_THROW_ON_ERROR));
        if ($rows->isEmpty()) {
            throw new RuntimeException("Snapshot {$snapshotId} has no customers rows");
        }
        $this->validate($rows->all());

        return DB::transaction(function () use ($rows, $snapshotId, $by) {
            $stats = ['legacy_rows' => $rows->count(), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            $map = DB::table('legacy_id_map')->where('source_table', 'customers')->pluck('target_id', 'source_key');
            $existing = Customer::query()->whereIn('id', $map->values())->lockForUpdate()->get()->keyBy('id');
            foreach ($rows as $r) {
                $customer = isset($map[$r['id']]) ? $existing->get($map[$r['id']]) : null;
                if ($customer === null) {
                    $customer = new Customer;
                    $stats['created']++;
                }
                $customer->forceFill($this->attributes($r));
                if ($customer->exists && $customer->isDirty()) {
                    $customer->lock_version++;
                    $stats['updated']++;
                } elseif ($customer->exists) {
                    $stats['unchanged']++;
                }
                $customer->timestamps = false; // keep the legacy creation time
                $customer->save();
                DB::table('legacy_id_map')->upsert(
                    [['source_table' => 'customers', 'source_key' => (string) $r['id'], 'target_table' => 'customers', 'target_id' => $customer->id, 'snapshot_id' => $snapshotId]],
                    ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at'],
                );
            }
            $this->verify($rows->all());
            $stats['customers'] = Customer::query()->count();
            $stats['office'] = Customer::query()->officeDirect()->count();
            $stats['channels'] = Customer::query()->distinct()->count('channel');
            $stats['duplicate_names'] = DB::table('customers')->select('name')->groupBy('name')->havingRaw('count(*) > 1')->get()->count();
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'customers', null, $stats, $by);

            return $stats;
        });
    }

    /** Row-by-row comparison of the stored customers with the legacy rows. */
    private function verify(array $rows): void
    {
        $ids = DB::table('legacy_id_map')->where('source_table', 'customers')
            ->whereIn('source_key', array_map(fn ($r) => (string) $r['id'], $rows))->pluck('target_id', 'source_key');
        if ($ids->count() !== count($rows)) {
            throw new RuntimeException("Verification failed: {$ids->count()} of ".count($rows).' customers mapped');
        }
        $stored = Customer::query()->whereIn('id', $ids->values())->get()->keyBy('id');
        foreach ($rows as $r) {
            $c = $stored->get($ids[(string) $r['id']]) ?? throw new RuntimeException("Verification failed: customer {$r['id']} is missing");
            $expected = $this->attributes($r);
            foreach (['name', 'type', 'channel', ...self::TEXT] as $field) {
                if ($c->{$field} !== $expected[$field]) {
                    throw new RuntimeException("Verification failed for customer {$r['id']} ({$field})");
                }
            }
            if (! $c->created_at->equalTo($expected['created_at'])) {
                throw new RuntimeException("Verification failed for customer {$r['id']} (created_at)");
            }
        }
    }

    /** @return array<string, mixed> */
    private function attributes(array $r): array
    {
        $text = fn (string $f) => ($v = trim((string) ($r[$f] ?? ''))) === '' ? null : $v;
        $createdAt = Carbon::parse($r['created_at']);

        return [
            'name' => trim((string) $r['name']),
            'type' => ($r['type'] ?? '') !== '' ? $r['type'] : 'client', // legacy loadCustomersFromDB: c.type || 'client'
            'channel' => ($r['channel'] ?? '') !== '' ? $r['channel'] : SalesChannel::OFFICE, // c.channel || 'office'
            ...array_combine(self::TEXT, array_map($text, self::TEXT)),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function validate(array $rows): void
    {
        $channels = SalesChannel::query()->pluck('code')->all();
        $limits = ['name' => 255, 'phone' => 64, 'email' => 255, 'address' => 500];
        $problems = [];
        $ids = [];
        foreach ($rows as $i => $r) {
            $ref = 'row '.($i + 1);
            if (! isset($r['id']) || isset($ids[$r['id']])) {
                $problems[] = "{$ref}: missing or duplicate id";
            }
            $ids[$r['id'] ?? ''] = true;
            if (trim((string) ($r['name'] ?? '')) === '') {
                $problems[] = "{$ref}: empty name";
            }
            if (($r['type'] ?? '') !== '' && ! in_array($r['type'], self::TYPES, true)) {
                $problems[] = "{$ref}: unknown type";
            }
            if (($r['channel'] ?? '') !== '' && ! in_array($r['channel'], $channels, true)) {
                $problems[] = "{$ref}: unknown channel";
            }
            if (($inn = trim((string) ($r['inn'] ?? ''))) !== '' && preg_match('/^[0-9]{12}$/', $inn) !== 1) {
                $problems[] = "{$ref}: IIN/BIN is not 12 digits";
            }
            foreach ($limits as $field => $max) {
                if (mb_strlen(trim((string) ($r[$field] ?? ''))) > $max) {
                    $problems[] = "{$ref}: {$field} longer than {$max}";
                }
            }
            if (! empty($r['contact']) || ! in_array($r['turnover'] ?? 0, [0, null], true)) {
                $problems[] = "{$ref}: contact/turnover has a value the new schema does not keep";
            }
            if (empty($r['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        if ($problems) {
            throw new RuntimeException('Legacy customers rejected, nothing imported: '.implode('; ', array_slice($problems, 0, 10)));
        }
    }
}
