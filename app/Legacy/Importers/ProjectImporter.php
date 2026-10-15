<?php

namespace App\Legacy\Importers;

use App\Models\Project;
use App\Models\ProjectCalc;
use App\Models\ProjectComment;
use App\Models\User;
use App\Services\ProjectService;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy projects, project_calcs, project_comments → M10 «Проекты». Whole set validated first; one transaction; idempotent
 * through legacy_id_map; every row compared after the import. An empty set is a valid state (legacy had no projects yet).
 * project_files are refused while they exist: their content lies in the Supabase Storage bucket, which the read-only copy
 * does not read yet.
 */
class ProjectImporter
{
    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $read = fn (string $table) => collect(DB::select('SELECT data FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no', [$snapshotId, $table]))
            ->map(fn ($r) => json_decode($r->data, true, flags: JSON_THROW_ON_ERROR))->values();
        $projects = $read('projects');
        $calcs = $read('project_calcs');
        $comments = $read('project_comments');
        if ($read('project_files')->isNotEmpty()) {
            throw new RuntimeException('Legacy project_files rejected, nothing imported: file contents (Supabase Storage) are not copied yet');
        }
        $this->validate($projects, $calcs, $comments);

        return DB::transaction(function () use ($projects, $calcs, $comments, $snapshotId, $by) {
            $stats = ['projects' => $projects->count(), 'calcs' => $calcs->count(), 'comments' => $comments->count(), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            $projectIds = [];
            foreach ($projects as $r) {
                $projectIds[(string) $r['id']] = $this->save('projects', $r, Project::class, $this->projectAttributes($r), $snapshotId, $stats);
            }
            foreach ($calcs as $r) {
                $this->save('project_calcs', $r, ProjectCalc::class, $this->calcAttributes($r, $projectIds), $snapshotId, $stats);
            }
            foreach ($comments as $r) {
                $this->save('project_comments', $r, ProjectComment::class, $this->commentAttributes($r, $projectIds), $snapshotId, $stats);
            }
            $this->verify($projects, $calcs, $comments, $projectIds);
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'projects', null, $stats, $by);

            return $stats;
        });
    }

    /**
     * @param  class-string<Model>  $class
     * @param  array<string, mixed>  $attributes
     * @param  array<string, int>  $stats
     */
    private function save(string $table, array $r, string $class, array $attributes, string $snapshotId, array &$stats): int
    {
        $id = DB::table('legacy_id_map')->where('source_table', $table)->where('source_key', (string) $r['id'])->value('target_id');
        $model = $id === null ? null : $class::query()->lockForUpdate()->find($id);
        if ($model === null) {
            $model = new $class;
            $stats['created']++;
        }
        $model->forceFill($attributes);
        // jsonb keeps the keys of the lines in its own order: the same lines are not a change.
        if ($model->exists && $model->isDirty('items') && $model->getOriginal('items') == $attributes['items']) {
            $model->syncOriginalAttribute('items');
        }
        if ($model->exists && $model->isDirty()) {
            if ($model instanceof Project) {
                $model->lock_version++;
            }
            $stats['updated']++;
        } elseif ($model->exists) {
            $stats['unchanged']++;
        }
        $model->timestamps = false; // keep the legacy times
        $model->save();
        DB::table('legacy_id_map')->upsert([['source_table' => $table, 'source_key' => (string) $r['id'], 'target_table' => $model->getTable(), 'target_id' => $model->getKey(), 'snapshot_id' => $snapshotId]],
            ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at']);

        return (int) $model->getKey();
    }

    /** @return array<string, mixed> */
    private function projectAttributes(array $r): array
    {
        $created = Carbon::parse($r['created_at']);

        return [
            'num' => self::text($r['num'] ?? null),
            'title' => trim((string) $r['title']),
            'channel' => self::text($r['channel'] ?? null) ?? 'office',
            'status' => self::text($r['status'] ?? null) ?? 'request',
            'customer_name' => self::text($r['customer_name'] ?? null),
            'description' => $r['description'] ?? null,
            'due_date' => ($d = self::text($r['due_date'] ?? null)) === null ? null : substr($d, 0, 10),
            'executors' => array_values(array_filter(array_map('trim', explode(',', (string) ($r['responsible'] ?? ''))), fn ($l) => $l !== '')),
            'responsible_name' => self::text($r['responsible_name'] ?? null),
            'order_num' => self::text($r['order_num'] ?? null),
            'total_amount' => ($r['total_amount'] ?? null) === null ? null : self::decimal($r['total_amount'], 2),
            'created_by_login' => self::text($r['created_by'] ?? null),
            'created_by_name' => self::text($r['created_by_name'] ?? null),
            'created_at' => $created,
            'updated_at' => empty($r['updated_at']) ? $created : Carbon::parse($r['updated_at']),
        ];
    }

    /**
     * @param  array<string, int>  $projectIds
     * @return array<string, mixed>
     */
    private function calcAttributes(array $r, array $projectIds): array
    {
        $lines = array_map(fn (array $i) => ['name' => trim((string) ($i['name'] ?? '')), 'attrs' => trim((string) ($i['attrs'] ?? '')),
            'qty' => self::decimal($i['qty'] ?? 0, 3), 'cost' => self::decimal($i['cost'] ?? 0, 2), 'price' => self::decimal($i['price'] ?? 0, 2)], self::items($r));
        $created = Carbon::parse($r['created_at']);

        return [
            'project_id' => $projectIds[(string) $r['project_id']],
            'version' => (int) $r['version'],
            'items' => $lines,
            'total' => ProjectService::total($lines),
            'markup_pct' => ($r['markup_pct'] ?? null) === null ? null : self::decimal($r['markup_pct'], 2),
            'sent_at' => empty($r['sent_at']) ? null : Carbon::parse($r['sent_at']),
            'created_by_login' => self::text($r['created_by'] ?? null),
            'created_by_name' => self::text($r['created_by_name'] ?? null),
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }

    /**
     * @param  array<string, int>  $projectIds
     * @return array<string, mixed>
     */
    private function commentAttributes(array $r, array $projectIds): array
    {
        $created = Carbon::parse($r['created_at']);

        return [
            'project_id' => $projectIds[(string) $r['project_id']],
            'side' => ($r['side'] ?? 'staff') === 'client' ? 'client' : 'staff',
            'internal' => (bool) ($r['internal'] ?? false),
            'text' => trim((string) $r['text']),
            'author_login' => self::text($r['author'] ?? null),
            'author_name' => self::text($r['author_name'] ?? null),
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $projects
     * @param  Collection<int, array<string, mixed>>  $calcs
     * @param  Collection<int, array<string, mixed>>  $comments
     * @param  array<string, int>  $projectIds
     */
    private function verify(Collection $projects, Collection $calcs, Collection $comments, array $projectIds): void
    {
        foreach (['projects' => [$projects, Project::class, fn ($r) => $this->projectAttributes($r)],
            'project_calcs' => [$calcs, ProjectCalc::class, fn ($r) => $this->calcAttributes($r, $projectIds)],
            'project_comments' => [$comments, ProjectComment::class, fn ($r) => $this->commentAttributes($r, $projectIds)]] as $table => [$rows, $class, $expected]) {
            $ids = DB::table('legacy_id_map')->where('source_table', $table)->whereIn('source_key', $rows->map(fn ($r) => (string) $r['id']))->pluck('target_id', 'source_key');
            if ($ids->count() !== $rows->count()) {
                throw new RuntimeException("Verification failed: {$ids->count()} of {$rows->count()} {$table} mapped");
            }
            $stored = $class::query()->whereIn('id', $ids->values())->get()->keyBy('id');
            foreach ($rows as $r) {
                $model = $stored->get($ids[(string) $r['id']]) ?? throw new RuntimeException("Verification failed: {$table} {$r['id']} is missing");
                foreach ($expected($r) as $field => $value) {
                    $actual = $model->getAttribute($field);
                    $same = $value instanceof Carbon ? $actual?->equalTo($value)
                        : ($field === 'due_date' ? $actual?->format('Y-m-d') === $value : $actual == $value);
                    if (! $same) {
                        throw new RuntimeException("Verification failed for {$table} {$r['id']} ({$field})");
                    }
                }
            }
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $projects
     * @param  Collection<int, array<string, mixed>>  $calcs
     * @param  Collection<int, array<string, mixed>>  $comments
     */
    private function validate(Collection $projects, Collection $calcs, Collection $comments): void
    {
        $problems = [];
        $channels = DB::table('sales_channels')->pluck('code')->all();
        $ids = [];
        foreach ($projects as $i => $r) {
            $ref = 'projects row '.($i + 1);
            if (! isset($r['id']) || isset($ids[$r['id']])) {
                $problems[] = "{$ref}: missing or duplicate id";
            }
            $ids[(string) ($r['id'] ?? '')] = true;
            if (trim((string) ($r['title'] ?? '')) === '') {
                $problems[] = "{$ref}: empty title";
            }
            if (! in_array($r['status'] ?? null, [null, '', ...Project::STATUSES], true)) {
                $problems[] = "{$ref}: unknown status";
            }
            if (! in_array(self::text($r['channel'] ?? null) ?? 'office', $channels, true)) {
                $problems[] = "{$ref}: unknown channel";
            }
            $due = self::text($r['due_date'] ?? null);
            if ($due !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}/', $due)) {
                $problems[] = "{$ref}: due_date is not YYYY-MM-DD";
            }
            if (($r['total_amount'] ?? null) !== null && ! self::fits($r['total_amount'], 2)) {
                $problems[] = "{$ref}: total_amount is not a sum";
            }
            if (empty($r['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        $versions = [];
        foreach ($calcs as $i => $r) {
            $ref = 'project_calcs row '.($i + 1);
            if (! isset($ids[(string) ($r['project_id'] ?? '')])) {
                $problems[] = "{$ref}: project is missing";
            }
            $key = ($r['project_id'] ?? '').':'.($r['version'] ?? '');
            if (! is_int($r['version'] ?? null) || $r['version'] < 1 || isset($versions[$key])) {
                $problems[] = "{$ref}: missing or duplicate version";
            }
            $versions[$key] = true;
            foreach (self::items($r) as $n => $item) {
                if (! self::fits($item['qty'] ?? 0, 3) || ! self::fits($item['cost'] ?? 0, 2) || ! self::fits($item['price'] ?? 0, 2)) {
                    $problems[] = "{$ref}: line ".($n + 1).' has a number that is not a quantity or a sum';
                }
            }
            if (! is_array($r['items'] ?? null) && ! is_string($r['items'] ?? null)) {
                $problems[] = "{$ref}: items is not a list";
            }
            if (empty($r['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        foreach ($comments as $i => $r) {
            $ref = 'project_comments row '.($i + 1);
            if (! isset($ids[(string) ($r['project_id'] ?? '')])) {
                $problems[] = "{$ref}: project is missing";
            }
            if (trim((string) ($r['text'] ?? '')) === '') {
                $problems[] = "{$ref}: empty text";
            }
            if (empty($r['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        if ($problems) {
            throw new RuntimeException('Legacy projects rejected, nothing imported: '.implode('; ', array_slice($problems, 0, 10)));
        }
    }

    /** @return list<array<string, mixed>> */
    private static function items(array $r): array
    {
        $items = $r['items'] ?? [];
        if (is_string($items)) {
            $items = json_decode($items, true) ?? [];
        }

        return array_values(array_filter(is_array($items) ? $items : [], 'is_array'));
    }

    private static function text(mixed $v): ?string
    {
        return ($v = trim((string) $v)) === '' ? null : $v;
    }

    /** A non-negative number with at most $scale decimals (legacy keeps JS numbers; an empty field is zero). */
    private static function fits(mixed $v, int $scale): bool
    {
        if ($v === '' || $v === null) {
            return true;
        }
        $s = is_float($v) ? rtrim(rtrim(sprintf('%.10F', $v), '0'), '.') : (string) $v;

        return (bool) preg_match('/^\d{1,13}(\.\d{1,'.$scale.'})?$/', $s);
    }

    private static function decimal(mixed $v, int $scale): string
    {
        if ($v === '' || $v === null) {
            return bcadd('0', '0', $scale);
        }
        $s = is_float($v) ? rtrim(rtrim(sprintf('%.10F', $v), '0'), '.') : (string) $v;

        return bcadd($s, '0', $scale);
    }
}
