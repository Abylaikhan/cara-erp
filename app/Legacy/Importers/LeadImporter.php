<?php

namespace App\Legacy\Importers;

use App\Models\Lead;
use App\Models\SalesChannel;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy leads → M10. Whole set validated first; one transaction; idempotent through legacy_id_map. Legacy defaults are
 * applied as loadLeadsFromDB does (status «new», channel «office»); the budget is read as the exact decimal of the JSON
 * number. Segment, tags and the next action do not exist in legacy and start empty.
 */
class LeadImporter
{
    private const TEXT = ['phone', 'interest', 'source', 'comment', 'manager'];

    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $rows = collect(DB::select("SELECT data, data->>'budget' AS budget_text FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no", [$snapshotId, 'leads']))
            ->map(fn ($r) => json_decode($r->data, true, flags: JSON_THROW_ON_ERROR) + ['budget_text' => $r->budget_text]);
        if ($rows->isEmpty()) {
            throw new RuntimeException("Snapshot {$snapshotId} has no leads rows");
        }
        $this->validate($rows->all());

        return DB::transaction(function () use ($rows, $snapshotId, $by) {
            $stats = ['legacy_rows' => $rows->count(), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            $map = DB::table('legacy_id_map')->where('source_table', 'leads')->pluck('target_id', 'source_key');
            $existing = Lead::query()->whereIn('id', $map->values())->lockForUpdate()->get()->keyBy('id');
            $mapRows = [];
            foreach ($rows as $r) {
                $lead = isset($map[$r['id']]) ? $existing->get($map[$r['id']]) : null;
                if ($lead === null) {
                    $lead = new Lead;
                    $stats['created']++;
                }
                $lead->forceFill($this->attributes($r));
                if ($lead->exists && $lead->isDirty()) {
                    $lead->lock_version++;
                    $stats['updated']++;
                } elseif ($lead->exists) {
                    $stats['unchanged']++;
                }
                $lead->timestamps = false; // keep the legacy creation time
                $lead->save();
                $mapRows[] = ['source_table' => 'leads', 'source_key' => (string) $r['id'], 'target_table' => 'leads', 'target_id' => $lead->id, 'snapshot_id' => $snapshotId];
            }
            foreach (array_chunk($mapRows, 1000) as $chunk) {
                DB::table('legacy_id_map')->upsert($chunk, ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at']);
            }
            $this->verify($rows->all());
            $stats['active_leads'] = Lead::query()->active()->whereNotIn('status', Lead::CLOSED)->count();
            $stats['channels'] = Lead::query()->distinct()->count('channel');
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'leads', null, $stats, $by);

            return $stats;
        });
    }

    private function verify(array $rows): void
    {
        $ids = DB::table('legacy_id_map')->where('source_table', 'leads')
            ->whereIn('source_key', array_map(fn ($r) => (string) $r['id'], $rows))->pluck('target_id', 'source_key');
        if ($ids->count() !== count($rows)) {
            throw new RuntimeException("Verification failed: {$ids->count()} of ".count($rows).' leads mapped');
        }
        $stored = Lead::query()->whereIn('id', $ids->values())->get()->keyBy('id');
        foreach ($rows as $r) {
            $lead = $stored->get($ids[(string) $r['id']]) ?? throw new RuntimeException("Verification failed: lead {$r['id']} is missing");
            $expected = $this->attributes($r);
            foreach (['name', 'status', 'channel', 'budget', ...self::TEXT] as $field) {
                if ((string) $lead->{$field} !== (string) $expected[$field]) {
                    throw new RuntimeException("Verification failed for lead {$r['id']} ({$field})");
                }
            }
            if (! $lead->created_at->equalTo($expected['created_at'])) {
                throw new RuntimeException("Verification failed for lead {$r['id']} (created_at)");
            }
        }
    }

    /** @return array<string, mixed> */
    private function attributes(array $r): array
    {
        $text = fn (string $f) => ($v = trim((string) ($r[$f] ?? ''))) === '' ? null : $v;
        $createdAt = Carbon::parse($r['created_at']);
        $budget = $r['budget_text'] === null || $r['budget_text'] === '' ? '0' : $r['budget_text'];

        return [
            'name' => trim((string) $r['name']),
            ...array_combine(self::TEXT, array_map($text, self::TEXT)),
            'status' => $text('status') ?? 'new',
            'channel' => $text('channel') ?? SalesChannel::OFFICE,
            'budget' => bcadd($budget, '0.005', 2),
            'active' => true,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function validate(array $rows): void
    {
        $channels = SalesChannel::query()->pluck('code')->flip();
        $statuses = config('erp.lead_statuses');
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
            if (($r['status'] ?? '') !== '' && $r['status'] !== null && ! isset($statuses[$r['status']])) {
                $problems[] = "{$ref}: unknown status";
            }
            if (($r['channel'] ?? '') !== '' && $r['channel'] !== null && ! isset($channels[$r['channel']])) {
                $problems[] = "{$ref}: unknown channel";
            }
            if ($r['budget_text'] !== null && (! is_numeric($r['budget_text']) || $r['budget_text'] < 0)) {
                $problems[] = "{$ref}: budget is not a non-negative number";
            }
            foreach (['name' => 255, 'phone' => 64, 'interest' => 500, 'source' => 32, 'manager' => 255] as $field => $max) {
                if (mb_strlen(trim((string) ($r[$field] ?? ''))) > $max) {
                    $problems[] = "{$ref}: {$field} longer than {$max}";
                }
            }
            if (empty($r['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        if ($problems) {
            throw new RuntimeException('Legacy leads rejected, nothing imported: '.implode('; ', array_slice($problems, 0, 10)));
        }
    }
}
