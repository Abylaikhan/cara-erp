<?php

namespace App\Legacy\Importers;

use App\Legacy\LegacyRejected;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * D-13 (23.10.2026): the accounts are those legacy has. Each built-in role of legacy (`var _USERS`, read from the accepted
 * reference kept locally — never its passwords) is an account whose login is the role key, with the name and initials legacy
 * gives it; the records of app_users are laid over them as legacy dbUsersApply does: name, role label, initials, colour, pages,
 * blocked (the director can be neither blocked nor deprived of pages). An account created here gets a random password nobody
 * knows: passwords are issued anew at the launch (`users:issue-passwords`, D-13 answer 2). Passwords and PINs of legacy are
 * never read. A record whose login is not a built-in role is refused: a new account needs its role decided.
 */
class AppUserImporter
{
    use MirrorsLegacyTable;

    /** @param string|null $html the legacy page (tests); by default the accepted reference */
    public function __construct(private readonly ?string $html = null) {}

    /** @return array<string, int|string> */
    public function import(string $snapshotId, ?User $by = null): array
    {
        $records = array_map(function (array $r) {
            unset($r['password'], $r['pin']);

            return $r;
        }, $this->legacyTable($snapshotId, 'app_users', []));
        $builtIn = $this->builtInUsers();
        $roles = config('erp.roles');
        $this->validate($records, $roles);

        return DB::transaction(function () use ($records, $builtIn, $roles, $snapshotId, $by) {
            $stats = ['legacy_rows' => count($records), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            $overlay = collect($records)->keyBy(fn (array $r) => mb_strtolower(trim((string) $r['login'])));
            foreach ($roles as $login => $role) {
                $base = $builtIn[$login] ?? [];
                $d = $overlay->get($login);
                $name = self::text($d ?? [], 'name') ?? ($base['name'] ?? $login);
                $pages = $d !== null ? self::pages($d['pages'] ?? null) : [];
                $attributes = [
                    'name' => $name,
                    'role' => $login,
                    'channel' => self::text($d ?? [], 'channel') ?? ($role['channel'] ?? null),
                    // legacy: label = d.label || d.role || the role's; avatar = d.initials || the role's || from the name
                    'role_label' => $d !== null ? (self::text($d, 'label') ?? self::text($d, 'role')) : null,
                    'initials' => ($d !== null ? self::text($d, 'initials') : null) ?? ($base['initials'] ?? null),
                    'color' => $d !== null ? self::text($d, 'color') : null,
                    'pages' => $pages === [] ? null : $pages,
                    'is_active' => $login === 'director' || $d === null || ($d['active'] ?? true) !== false,
                ];
                $user = User::query()->where('login', $login)->lockForUpdate()->first();
                $new = $user === null;
                // a password nobody knows: the real ones are issued at the launch
                $user ??= new User(['login' => $login, 'password' => Str::random(64)]);
                $user->forceFill($attributes);
                $stats[$new ? 'created' : ($user->isDirty() ? 'updated' : 'unchanged')]++;
                $user->save();
                $stored = $user->fresh();
                foreach ($attributes as $field => $value) {
                    if ($stored->getAttribute($field) !== $value) {
                        throw new RuntimeException("Verification failed for the account {$login} ({$field})");
                    }
                }
                if ($d !== null) {
                    DB::table('legacy_id_map')->upsert([['source_table' => 'app_users', 'source_key' => (string) $d['id'], 'target_table' => 'users',
                        'target_id' => $user->id, 'snapshot_id' => $snapshotId]], ['source_table', 'source_key'], ['target_id', 'snapshot_id', 'imported_at']);
                }
            }
            $stats['accounts'] = User::query()->whereIn('login', array_keys($roles))->count();
            $stats['overlaid'] = $overlay->count();
            $stats['snapshot'] = $snapshotId;
            Audit::record('legacy.imported', 'app_users', null, $stats, $by);

            return $stats;
        });
    }

    /** Legacy pages: an array, or its JSON text; anything else is none. @return list<string> */
    private static function pages(mixed $v): array
    {
        if (is_string($v)) {
            $v = json_decode($v, true);
        }

        return is_array($v) ? array_values(array_filter($v, fn ($p) => is_string($p) && $p !== '')) : [];
    }

    /**
     * Name and initials of the built-in users of legacy (`var _USERS={ role: {name:'…', initials:'…', …}, … }`). Passwords live
     * in another object (`_PWD`), which is not read.
     *
     * @return array<string, array{name?: string, initials?: string}>
     */
    private function builtInUsers(): array
    {
        if (! preg_match('/var _USERS\s*=\s*\{(.*?)\n\};/s', $this->html ?? $this->acceptedReference(), $block)) {
            throw new RuntimeException('_USERS not found in the legacy reference');
        }
        preg_match_all('/^\s*([a-z_]+)\s*:\s*\{(.*?)\}\s*,?\s*$/msu', $block[1], $entries, PREG_SET_ORDER);
        $users = [];
        foreach ($entries as [, $login, $body]) {
            foreach (['name', 'initials'] as $f) {
                if (preg_match("/\\b{$f}\\s*:\\s*'([^']*)'/u", $body, $m)) {
                    $users[$login][$f] = $m[1];
                }
            }
        }

        return $users;
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

    /** @param array<string, array<string, mixed>> $roles */
    private function validate(array $records, array $roles): void
    {
        $problems = [];
        $logins = [];
        foreach ($records as $i => $r) {
            $ref = 'row '.($i + 1);
            $login = mb_strtolower(trim((string) ($r['login'] ?? '')));
            if ($login === '') {
                $problems[] = "{$ref}: no login";

                continue;
            }
            if (isset($logins[$login])) {
                $problems[] = "{$ref}: the same login twice";
            }
            $logins[$login] = true;
            if (! isset($roles[$login])) {
                $problems[] = "{$ref}: the login is not a built-in role — a new account needs its role decided (D-13)";
            }
            $pages = $r['pages'] ?? null;
            if ($pages !== null && self::pages($pages) === [] && $pages !== [] && $pages !== '[]') {
                $problems[] = "{$ref}: pages are not a list";
            }
            if (mb_strlen((string) ($r['name'] ?? '')) > 255 || mb_strlen((string) ($r['label'] ?? '')) > 128 || mb_strlen((string) ($r['initials'] ?? '')) > 8
                || mb_strlen((string) ($r['color'] ?? '')) > 16) {
                $problems[] = "{$ref}: a field is too long";
            }
        }
        if ($problems) {
            throw new LegacyRejected('Legacy app_users', $problems);
        }
    }
}
