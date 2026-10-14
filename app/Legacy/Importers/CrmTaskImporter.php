<?php

namespace App\Legacy\Importers;

use App\Models\CrmTask;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy tasks → M10. Whole set validated first; one transaction; idempotent through legacy_id_map. The lead of a task is found
 * through the leads already imported (`legacy:import leads` first). Defaults as loadTasksFromDB applies them (priority
 * «normal», status «open»); a task «deleted» in legacy is imported deactivated.
 */
class CrmTaskImporter
{
    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $rows = collect(DB::select('SELECT data FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no', [$snapshotId, 'tasks']))
            ->map(fn ($r) => json_decode($r->data, true, flags: JSON_THROW_ON_ERROR));
        if ($rows->isEmpty()) {
            throw new RuntimeException("Snapshot {$snapshotId} has no tasks rows");
        }
        $leads = DB::table('legacy_id_map')->where('source_table', 'leads')->pluck('target_id', 'source_key');
        $this->validate($rows->all(), $leads->all());

        return DB::transaction(function () use ($rows, $leads, $snapshotId, $by) {
            $stats = ['legacy_rows' => $rows->count(), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            $map = DB::table('legacy_id_map')->where('source_table', 'tasks')->pluck('target_id', 'source_key');
            $existing = CrmTask::query()->whereIn('id', $map->values())->lockForUpdate()->get()->keyBy('id');
            $mapRows = [];
            foreach ($rows as $r) {
                $task = isset($map[$r['id']]) ? $existing->get($map[$r['id']]) : null;
                if ($task === null) {
                    $task = new CrmTask;
                    $stats['created']++;
                }
                $task->forceFill($this->attributes($r, $leads->all()));
                if ($task->exists && $task->isDirty()) {
                    $task->lock_version++;
                    $stats['updated']++;
                } elseif ($task->exists) {
                    $stats['unchanged']++;
                }
                $task->timestamps = false; // keep the legacy creation time
                $task->save();
                $mapRows[] = ['source_table' => 'tasks', 'source_key' => (string) $r['id'], 'target_table' => 'crm_tasks', 'target_id' => $task->id, 'snapshot_id' => $snapshotId];
            }
            DB::table('legacy_id_map')->upsert($mapRows, ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at']);
            $this->verify($rows->all(), $leads->all());
            $stats['open_tasks'] = CrmTask::query()->active()->where('status', 'open')->count();
            $stats['with_lead'] = CrmTask::query()->active()->whereNotNull('lead_id')->count();
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'tasks', null, $stats, $by);

            return $stats;
        });
    }

    /** @param array<string, int> $leads */
    private function verify(array $rows, array $leads): void
    {
        $ids = DB::table('legacy_id_map')->where('source_table', 'tasks')
            ->whereIn('source_key', array_map(fn ($r) => (string) $r['id'], $rows))->pluck('target_id', 'source_key');
        if ($ids->count() !== count($rows)) {
            throw new RuntimeException("Verification failed: {$ids->count()} of ".count($rows).' tasks mapped');
        }
        $stored = CrmTask::query()->whereIn('id', $ids->values())->get()->keyBy('id');
        foreach ($rows as $r) {
            $task = $stored->get($ids[(string) $r['id']]) ?? throw new RuntimeException("Verification failed: task {$r['id']} is missing");
            $expected = $this->attributes($r, $leads);
            $actual = ['title' => $task->title, 'due_date' => $task->due_date?->format('Y-m-d'), 'due_time' => $task->timeLabel(), 'manager' => $task->manager,
                'priority' => $task->priority, 'status' => $task->status, 'lead_id' => $task->lead_id, 'active' => $task->active];
            foreach ($actual as $field => $value) {
                if ($value !== $expected[$field]) {
                    throw new RuntimeException("Verification failed for task {$r['id']} ({$field})");
                }
            }
            if (! $task->created_at->equalTo($expected['created_at'])) {
                throw new RuntimeException("Verification failed for task {$r['id']} (created_at)");
            }
        }
    }

    /**
     * @param  array<string, int>  $leads
     * @return array<string, mixed>
     */
    private function attributes(array $r, array $leads): array
    {
        $text = fn (string $f) => ($v = trim((string) ($r[$f] ?? ''))) === '' ? null : $v;
        $createdAt = Carbon::parse($r['created_at']);
        $status = $text('status') ?? 'open';
        $time = $text('time');

        return [
            'title' => trim((string) $r['title']),
            'due_date' => $text('date'),
            'due_time' => $time === null ? null : substr($time, 0, 5),
            'manager' => $text('manager'),
            'priority' => $text('priority') ?? 'normal',
            'status' => $status === 'deleted' ? 'open' : $status,
            'lead_id' => ($lead = $text('lead_id')) === null ? null : (int) $leads[$lead],
            'active' => $status !== 'deleted',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $leads
     */
    private function validate(array $rows, array $leads): void
    {
        $problems = [];
        $ids = [];
        foreach ($rows as $i => $r) {
            $ref = 'row '.($i + 1);
            if (! isset($r['id']) || isset($ids[$r['id']])) {
                $problems[] = "{$ref}: missing or duplicate id";
            }
            $ids[$r['id'] ?? ''] = true;
            if (trim((string) ($r['title'] ?? '')) === '') {
                $problems[] = "{$ref}: empty title";
            }
            if (! in_array($r['status'] ?? null, [null, '', 'open', 'done', 'deleted'], true)) {
                $problems[] = "{$ref}: unknown status";
            }
            if (! in_array($r['priority'] ?? null, [null, '', ...config('erp.task_priorities')], true)) {
                $problems[] = "{$ref}: unknown priority";
            }
            $date = trim((string) ($r['date'] ?? ''));
            if ($date !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)))) {
                $problems[] = "{$ref}: date is not YYYY-MM-DD";
            }
            $time = trim((string) ($r['time'] ?? ''));
            if ($time !== '' && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) {
                $problems[] = "{$ref}: time is not HH:MM";
            }
            $lead = trim((string) ($r['lead_id'] ?? ''));
            if ($lead !== '' && ! isset($leads[$lead])) {
                $problems[] = "{$ref}: lead is not imported (legacy:import leads first)";
            }
            if (mb_strlen(trim((string) ($r['manager'] ?? ''))) > 255) {
                $problems[] = "{$ref}: manager longer than 255";
            }
            if (empty($r['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        if ($problems) {
            throw new RuntimeException('Legacy tasks rejected, nothing imported: '.implode('; ', array_slice($problems, 0, 10)));
        }
    }
}
