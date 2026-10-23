<?php

namespace App\Services;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Сотрудники» → «Пользователи» (legacy saveAppUser/toggleUserActive/deleteAppUser, L22045–22165; D-13, ADR-0046): the director
 * and HR create and edit the accounts — name, role label, channel, pages, a new password — and block them. Legacy kept these
 * as app_users records over its built-in roles, with plain passwords; here they are the accounts themselves, passwords only as
 * hashes, every change under the version of the record and in the audit trail.
 */
class UserAccountService
{
    public const STALE = 'Пользователь изменён другим пользователем — обновите страницу и повторите.';

    public const NOT_FOUND = 'Пользователь не найден — обновите страницу.';

    /** Legacy ALL_PAGES (L21855): the sections the form offers. */
    public const FORM_PAGES = ['dashboard', 'mrp', 'warehouse', 'purchases', 'suppliers', 'customers', 'crm', 'finance', 'money', 'channels', 'reconcile',
        'money_salon', 'hr', 'wages', 'techcards', 'mycabinet', 'ai'];

    /** Legacy: the director and HR (by login) manage the accounts. */
    public static function canManage(User $user): bool
    {
        return in_array($user->role, ['director', 'hr'], true);
    }

    /**
     * Legacy saveAppUser for a new login: a login taken by an account (built-in or not) is refused; the colour of a new account
     * is that of the template it was filled from, else the default.
     *
     * @param  array{login: string, password: string, name: ?string, label: ?string, channel: ?string, pages: list<string>}  $data
     */
    public function create(array $data, User $by): User
    {
        return DB::transaction(function () use ($data, $by) {
            if (User::query()->where('login', $data['login'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['login' => 'Логин уже занят']);
            }
            $user = new User(['login' => $data['login'], 'password' => $data['password']]);
            $user->forceFill([
                'name' => $data['name'] ?: $data['login'],
                'role' => null,
                'role_label' => $data['label'],
                'channel' => $data['channel'],
                'pages' => $data['pages'],
                'initials' => self::initials($data['name'] ?: ''),
                'color' => '#4f8ef7',
                'is_active' => true,
            ])->save();
            Audit::record('user.created', 'user', $user->id, ['login' => $user->login, 'pages' => $user->pages, 'channel' => $user->channel], $by);

            return $user;
        });
    }

    /**
     * Legacy saveAppUser for an existing account: name, label, channel, pages and, when typed, a new password. The pages the form
     * does not offer stay as they were (legacy lost them on saving — ADR-0002, item 113); the director keeps the pages of the
     * role besides, as legacy keeps them.
     *
     * @param  array{password: ?string, name: ?string, label: ?string, channel: ?string, pages: list<string>}  $data
     */
    public function update(int $id, int $lockVersion, array $data, User $by): User
    {
        return DB::transaction(function () use ($id, $lockVersion, $data, $by) {
            $user = $this->locked($id, $lockVersion);
            $kept = array_values(array_diff(self::currentPages($user), self::FORM_PAGES));
            $pages = array_values(array_unique([...$data['pages'], ...$kept]));
            $before = ['name' => $user->name, 'role_label' => $user->role_label, 'channel' => $user->channel, 'pages' => $user->pages];
            $user->forceFill([
                'name' => $data['name'] ?: $user->login,
                'role_label' => $data['label'],
                'channel' => $data['channel'],
                'pages' => $pages,
                'initials' => self::initials($data['name'] ?: ''),
            ]);
            if ($data['password'] !== null && $data['password'] !== '') {
                $user->password = $data['password'];
                $user->remember_token = null;
            }
            $passwordChanged = $user->isDirty('password');
            $diff = collect($before)->filter(fn ($v, $k) => $user->{$k} !== $v)->map(fn ($v, $k) => ['from' => $v, 'to' => $user->{$k}])->all();
            if ($diff || $passwordChanged) {
                $user->lock_version++;
                $user->save();
                if ($passwordChanged) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
                Audit::record('user.updated', 'user', $user->id, $diff + ($passwordChanged ? ['password' => 'changed'] : []), $by);
            }

            return $user;
        });
    }

    /** Legacy toggleUserActive: «Нельзя заблокировать самого себя»; the director stays active (legacy dbUsersApply). */
    public function setActive(int $id, int $lockVersion, bool $active, User $by): User
    {
        return DB::transaction(function () use ($id, $lockVersion, $active, $by) {
            $user = $this->locked($id, $lockVersion);
            if (! $active && $user->id === $by->id) {
                throw ValidationException::withMessages(['active' => 'Нельзя заблокировать самого себя']);
            }
            if (! $active && $user->role === 'director') {
                throw ValidationException::withMessages(['active' => 'Директора нельзя заблокировать']);
            }
            if ($user->is_active !== $active) {
                $user->forceFill(['is_active' => $active]);
                $user->lock_version++;
                $user->save();
                if (! $active) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
                Audit::record($active ? 'user.unblocked' : 'user.blocked', 'user', $user->id, ['login' => $user->login], $by);
            }

            return $user;
        });
    }

    /** The pages the account has now: its own, else those of its role. @return list<string> */
    public static function currentPages(User $user): array
    {
        return is_array($user->pages) && $user->pages !== [] ? $user->pages : (config('erp.roles')[$user->role]['pages'] ?? []);
    }

    /** Legacy saveAppUser: the first letters of the words of the name, two, upper case; none — «??». */
    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_map(fn (string $w) => mb_substr($w, 0, 1), array_slice($words, 0, 2)))) ?: '??';
    }

    private function locked(int $id, int $lockVersion): User
    {
        $user = User::query()->whereKey($id)->lockForUpdate()->first();
        if ($user === null) {
            throw ValidationException::withMessages(['user' => self::NOT_FOUND]);
        }
        if ($user->lock_version !== $lockVersion) {
            throw ValidationException::withMessages(['lock_version' => self::STALE]);
        }

        return $user;
    }
}
