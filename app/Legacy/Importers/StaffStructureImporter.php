<?php

namespace App\Legacy\Importers;

use App\Models\StaffDepartment;
use App\Models\StaffPosition;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy «Штатное расписание» → staff_departments, staff_positions (№ 76 — ADR-0051). Legacy has no table for it: the
 * departments and positions are literals in the code of its page (`let staffDepts = [...]`, `let staffPositions = [...]`), read
 * here from the accepted reference. Idempotent through the legacy key; a row the company has changed here (version above 1)
 * keeps what it entered; a row deleted here does not come back (there is no deletion yet).
 */
class StaffStructureImporter
{
    /** @param string|null $html the legacy page (tests); by default the accepted reference */
    public function __construct(private readonly ?string $html = null) {}

    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $html = $this->html ?? $this->acceptedReference();
        $depts = self::literal($html, 'staffDepts');
        $positions = self::literal($html, 'staffPositions');
        if ($depts === [] || $positions === []) {
            throw new RuntimeException('staffDepts / staffPositions not found in the legacy reference');
        }
        $keys = array_column($depts, 'id');
        foreach ($positions as $p) {
            if (! in_array($p['deptId'] ?? null, $keys, true) || trim((string) ($p['title'] ?? '')) === '' || ! in_array($p['wageType'] ?? '', ['salary', 'piecework'], true)) {
                throw new RuntimeException('A legacy position is not valid: '.($p['id'] ?? '?'));
            }
        }

        return DB::transaction(function () use ($depts, $positions, $snapshotId, $by) {
            $stats = ['departments' => count($depts), 'positions' => count($positions), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'kept' => 0];
            $deptIds = [];
            foreach ($depts as $i => $d) {
                $row = StaffDepartment::query()->where('legacy_key', $d['id'])->lockForUpdate()->first() ?? new StaffDepartment(['legacy_key' => $d['id']]);
                $deptIds[$d['id']] = $this->save($row, ['name' => $d['name'], 'icon' => $d['icon'] ?: '🏢', 'sort_order' => $i], $stats);
            }
            foreach ($positions as $i => $p) {
                $row = StaffPosition::query()->where('legacy_key', (string) $p['id'])->lockForUpdate()->first() ?? new StaffPosition(['legacy_key' => (string) $p['id']]);
                $this->save($row, ['department_id' => $deptIds[$p['deptId']], 'title' => trim($p['title']), 'grade' => (string) ($p['grade'] ?? ''), 'wage_type' => $p['wageType'],
                    'salary_min' => (int) ($p['salaryMin'] ?? 0), 'salary_max' => (int) ($p['salaryMax'] ?? 0), 'slots' => max(1, (int) ($p['slots'] ?? 1)), 'sort_order' => $i], $stats);
            }
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'staff_structure', null, $stats, $by);

            return $stats;
        });
    }

    /** @param  array<string, int>  $stats */
    private function save(StaffDepartment|StaffPosition $row, array $values, array &$stats): int
    {
        if ($row->exists && $row->lock_version > 1) {
            $stats['kept']++;

            return $row->id;
        }
        $row->fill($values);
        $stats[! $row->exists ? 'created' : ($row->isDirty() ? 'updated' : 'unchanged')]++;
        $row->save();

        return $row->id;
    }

    /**
     * The objects of a legacy `let <name> = [ {key:'text', key:123}, … ];` literal, one per line; comments are skipped.
     *
     * @return list<array<string, string|int>>
     */
    public static function literal(string $html, string $name): array
    {
        if (! preg_match('/let '.preg_quote($name, '/').'\s*=\s*\[(.*?)\n\];/s', $html, $block)) {
            return [];
        }
        $out = [];
        foreach (explode("\n", $block[1]) as $line) {
            if (! preg_match('/^\s*\{(.*)\},?\s*$/u', $line, $m)) {
                continue;
            }
            preg_match_all("/(\\w+)\\s*:\\s*(?:'([^']*)'|(-?[0-9.]+))/u", $m[1], $pairs, PREG_SET_ORDER);
            $o = [];
            foreach ($pairs as $p) {
                $o[$p[1]] = isset($p[3]) && $p[3] !== '' ? (int) $p[3] : $p[2];
            }
            $out[] = $o;
        }

        return $out;
    }

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
}
