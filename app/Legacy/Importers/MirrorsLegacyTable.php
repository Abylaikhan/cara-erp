<?php

namespace App\Legacy\Importers;

use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A legacy table copied as it is into its application table, for records the application shows but does not change yet
 * (staff and payroll until D-06/D-13): idempotent through the legacy key — its uuid, or its number (kept on the row and in legacy_id_map); rows
 * deleted in legacy are deleted here too (their values stay in the audit trail), every row read back and compared.
 */
trait MirrorsLegacyTable
{
    /**
     * Rows of a legacy table in snapshot order. The fields in $raw come as PostgreSQL prints their JSON value — numbers and
     * objects exactly as stored, nothing through float.
     *
     * @param  list<string>  $raw
     * @return list<array<string, mixed>>
     */
    private function legacyTable(string $snapshotId, string $table, array $raw, bool $natural = false): array
    {
        $columns = implode('', array_map(fn (string $f, int $i) => ", data->'{$f}' AS r{$i}", $raw, array_keys($raw)));
        // The order a legacy page lists the rows in (`legacy:natural-order`), as `position`; null when it was not read.
        $position = $natural && DB::selectOne("SELECT to_regclass('legacy.natural_order') IS NOT NULL AS ok")->ok
            ? ", (SELECT n.position FROM legacy.natural_order n WHERE n.snapshot_id = legacy.rows.snapshot_id AND n.table_name = legacy.rows.table_name AND n.row_id = legacy.rows.data->>'id') AS position"
            : ', NULL AS position';

        $rows = array_map(function ($r) use ($raw) {
            $data = json_decode($r->data, true, flags: JSON_THROW_ON_ERROR);
            foreach ($raw as $i => $f) {
                $v = $r->{"r{$i}"};
                $data[$f] = $v === null || $v === 'null' ? null : $v;
            }
            $data['position'] = $r->position === null ? null : (int) $r->position;

            return $data;
        }, DB::select("SELECT data{$columns}{$position} FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no", [$snapshotId, $table]));
        // legacy tables have no field named position; the rows keep the order of the snapshot where it was not read
        usort($rows, fn (array $a, array $b) => ($a['position'] ?? PHP_INT_MAX) <=> ($b['position'] ?? PHP_INT_MAX));

        return $rows;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<array<string, mixed>>  $attributes  one set per legacy row, with its legacy key ($key: the uuid, or the
     *                                                  number of a table keyed by numbers)
     * @return array{created: int, updated: int, unchanged: int}
     */
    private function mirror(string $sourceTable, string $model, array $attributes, string $snapshotId, string $key = 'uuid'): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        $existing = $model::query()->whereIn($key, array_column($attributes, $key))->lockForUpdate()->get()->keyBy($key);
        $map = [];
        foreach ($attributes as $a) {
            $row = $existing->get($a[$key]) ?? new $model;
            $row->forceFill($a);
            $stats[! $row->exists ? 'created' : ($row->isDirty() ? 'updated' : 'unchanged')]++;
            $row->timestamps = false;
            $row->save();
            $map[] = ['source_table' => $sourceTable, 'source_key' => (string) $a[$key], 'target_table' => $row->getTable(), 'target_id' => $row->getKey(), 'snapshot_id' => $snapshotId];
        }
        foreach (array_chunk($map, 1000) as $chunk) {
            DB::table('legacy_id_map')->upsert($chunk, ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at']);
        }

        return $stats;
    }

    /**
     * Rows no longer in legacy are deleted (their values go to the audit trail).
     *
     * @param  class-string<Model>  $model
     * @param  list<string|int>  $keep  legacy keys of the snapshot
     */
    private function removeGone(string $sourceTable, string $model, array $keep, ?User $by, string $key = 'uuid'): int
    {
        $keep = array_flip($keep);
        $gone = $model::query()->lockForUpdate()->get()->filter(fn (Model $m) => ! isset($keep[$m->getAttribute($key)]));
        if ($gone->isEmpty()) {
            return 0;
        }
        $table = $gone->first()->getTable();
        Audit::record('legacy.removed', $table, null, ['legacy_keys' => $gone->pluck($key)->values()->all(), 'rows' => $gone->map->getAttributes()->values()->all()], $by);
        $model::query()->whereIn('id', $gone->modelKeys())->delete();
        DB::table('legacy_id_map')->where('source_table', $sourceTable)->whereIn('source_key', $gone->pluck($key)->map(fn ($k) => (string) $k))->delete();

        return $gone->count();
    }

    /**
     * Every legacy row read back and compared field by field: numbers to their scale, days by day, moments to the
     * microsecond, everything else exactly.
     *
     * @param  class-string<Model>  $model
     * @param  list<array<string, mixed>>  $attributes
     * @param  array<string, int>  $numbers  field → scale
     * @param  list<string>  $days
     */
    private function verifyMirror(string $model, array $attributes, array $numbers, array $days, string $key = 'uuid'): void
    {
        $stored = $model::query()->whereIn($key, array_column($attributes, $key))->get()->keyBy($key);
        $label = (new $model)->getTable();
        if ($stored->count() !== count($attributes)) {
            throw new RuntimeException("Verification failed: {$stored->count()} of ".count($attributes)." {$label} rows stored");
        }
        foreach ($attributes as $a) {
            $row = $stored[$a[$key]];
            foreach ($a as $field => $expected) {
                $actual = isset($numbers[$field]) ? $row->getRawOriginal($field) : $row->getAttribute($field);
                $same = match (true) {
                    $expected instanceof CarbonInterface => $actual instanceof CarbonInterface && $actual->equalTo($expected),
                    in_array($field, $days, true) => $actual?->format('Y-m-d') === $expected,
                    isset($numbers[$field]) => $expected === null ? $actual === null : ($actual !== null && bccomp($actual, $expected, $numbers[$field]) === 0),
                    default => $actual === $expected,
                };
                if (! $same) {
                    throw new RuntimeException("Verification failed for {$label} {$a[$key]} ({$field})");
                }
            }
        }
    }

    private static function isUuid(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
    }

    private static function isDay(mixed $v): bool
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));
    }

    /** A JSON number (as PostgreSQL prints it) that fits numeric(30, $scale). */
    private static function fits(?string $v, int $scale): bool
    {
        $int = 30 - $scale;

        return $v !== null && preg_match("/^-?\\d{1,{$int}}(\\.\\d{1,{$scale}})?$/", $v) === 1;
    }

    /** Text as legacy keeps it; only an empty one is null. */
    private static function text(array $r, string $f): ?string
    {
        return ($v = (string) ($r[$f] ?? '')) === '' ? null : $v;
    }
}
