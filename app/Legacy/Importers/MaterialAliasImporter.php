<?php

namespace App\Legacy\Importers;

use App\Legacy\LegacyRejected;
use App\Models\MaterialAlias;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy material_aliases → M03. Rows with the same material, alias and category are one rule entered twice (in the legacy
 * copy every pair is stored twice, minutes apart): they become one alias, and every source row maps to it in legacy_id_map.
 * The alias keeps the earliest creation time of its copies. Supplier, coefficient and comment do not exist in legacy.
 */
class MaterialAliasImporter
{
    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $rows = collect(DB::select('SELECT data FROM legacy.rows WHERE snapshot_id = ? AND table_name = ? ORDER BY row_no', [$snapshotId, 'material_aliases']))
            ->map(fn ($r) => json_decode($r->data, true, flags: JSON_THROW_ON_ERROR));
        if ($rows->isEmpty()) {
            throw new RuntimeException("Snapshot {$snapshotId} has no material_aliases rows");
        }
        $this->validate($rows->all());
        $groups = $rows->groupBy(fn (array $r) => self::key($r));

        return DB::transaction(function () use ($rows, $groups, $snapshotId, $by) {
            $stats = ['legacy_rows' => $rows->count(), 'aliases' => $groups->count(), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            $map = DB::table('legacy_id_map')->where('source_table', 'material_aliases')->pluck('target_id', 'source_key');
            $existing = MaterialAlias::query()->whereIn('id', $map->values())->lockForUpdate()->get()->keyBy('id');
            foreach ($groups as $copies) {
                $alias = $copies->map(fn (array $r) => isset($map[$r['id']]) ? $existing->get($map[$r['id']]) : null)->filter()->first();
                if ($alias === null) {
                    $alias = new MaterialAlias;
                    $stats['created']++;
                }
                $alias->forceFill($this->attributes($copies->all()));
                if ($alias->exists && $alias->isDirty()) {
                    $stats['updated']++;
                } elseif ($alias->exists) {
                    $stats['unchanged']++;
                }
                $alias->timestamps = false; // keep the legacy creation time
                $alias->save();
                DB::table('legacy_id_map')->upsert(
                    $copies->map(fn (array $r) => ['source_table' => 'material_aliases', 'source_key' => (string) $r['id'], 'target_table' => 'material_aliases',
                        'target_id' => $alias->id, 'snapshot_id' => $snapshotId])->values()->all(),
                    ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at'],
                );
            }
            $this->verify($rows->all());
            $stats['duplicate_rows'] = $rows->count() - $groups->count();
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'material_aliases', null, $stats, $by);

            return $stats;
        });
    }

    /** Every source row maps to an alias with its material, alias name and category. */
    private function verify(array $rows): void
    {
        $ids = DB::table('legacy_id_map')->where('source_table', 'material_aliases')
            ->whereIn('source_key', array_map(fn ($r) => (string) $r['id'], $rows))->pluck('target_id', 'source_key');
        if ($ids->count() !== count($rows)) {
            throw new RuntimeException("Verification failed: {$ids->count()} of ".count($rows).' aliases mapped');
        }
        $stored = MaterialAlias::query()->whereIn('id', $ids->values())->get()->keyBy('id');
        foreach ($rows as $r) {
            $a = $stored->get($ids[(string) $r['id']]) ?? throw new RuntimeException("Verification failed: alias {$r['id']} is missing");
            $expected = $this->attributes([$r]);
            foreach (['material_name', 'alias_name', 'category'] as $field) {
                if ($a->{$field} !== $expected[$field]) {
                    throw new RuntimeException("Verification failed for alias {$r['id']} ({$field})");
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $copies */
    private function attributes(array $copies): array
    {
        $first = $copies[0];
        $createdAt = collect($copies)->map(fn (array $r) => Carbon::parse($r['created_at']))->min();

        return [
            'material_name' => trim((string) $first['material_name']),
            'alias_name' => trim((string) $first['alias_name']),
            'category' => ($c = trim((string) ($first['category'] ?? ''))) === '' ? null : $c,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    private static function key(array $r): string
    {
        return json_encode([trim((string) $r['material_name']), trim((string) $r['alias_name']), trim((string) ($r['category'] ?? ''))], JSON_UNESCAPED_UNICODE);
    }

    /** @param list<array<string, mixed>> $rows */
    private function validate(array $rows): void
    {
        $problems = [];
        $ids = [];
        foreach ($rows as $i => $r) {
            $ref = 'row '.($i + 1);
            if (! isset($r['id']) || isset($ids[$r['id']])) {
                $problems[] = "{$ref}: missing or duplicate id";
            }
            $ids[$r['id'] ?? ''] = true;
            if (trim((string) ($r['material_name'] ?? '')) === '' || trim((string) ($r['alias_name'] ?? '')) === '') {
                $problems[] = "{$ref}: empty material or alias name";
            }
            foreach (['material_name' => 255, 'alias_name' => 255, 'category' => 100] as $field => $max) {
                if (mb_strlen(trim((string) ($r[$field] ?? ''))) > $max) {
                    $problems[] = "{$ref}: {$field} longer than {$max}";
                }
            }
            if (empty($r['created_at'])) {
                $problems[] = "{$ref}: no created_at";
            }
        }
        if ($problems) {
            throw new LegacyRejected('Legacy material_aliases', $problems);
        }
    }
}
