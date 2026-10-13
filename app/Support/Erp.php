<?php

namespace App\Support;

use App\Models\User;

/**
 * Pages, menu and role templates of the ERP (config/erp.php). Single source of truth for navigation and page access:
 * the sidebar shows exactly the pages the server allows, and every page route checks the same list.
 */
class Erp
{
    /** @return array<string, array{label: string, title: string, module: string, icon: string}> */
    public static function pages(): array
    {
        return config('erp.pages');
    }

    public static function pageExists(string $page): bool
    {
        return array_key_exists($page, self::pages());
    }

    /** @return array<string, mixed>|null */
    public static function role(?string $key): ?array
    {
        return $key !== null ? (config('erp.roles')[$key] ?? null) : null;
    }

    /**
     * Pages of the user's role in role-template order, restricted to pages that exist (legacy `techcards` has none).
     *
     * @return list<string>
     */
    public static function pagesFor(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }
        $role = self::role($user->role);

        return array_values(array_filter($role['pages'] ?? [], fn (string $p) => self::pageExists($p)));
    }

    public static function canAccess(User $user, string $page): bool
    {
        return in_array($page, self::pagesFor($user), true);
    }

    /** Legacy doLogin: the first page of the role that is not «Мой кабинет», otherwise the first page. */
    public static function firstPage(User $user): ?string
    {
        $pages = self::pagesFor($user);

        return collect($pages)->first(fn (string $p) => $p !== 'mycabinet') ?? ($pages[0] ?? null);
    }

    /**
     * Menu in legacy order. Section labels stay visible even when all their items are hidden, as in legacy.
     *
     * @return list<array{label: string, items: list<array{id: string, label: string, href: string, icon: string}>}>
     */
    public static function navigation(User $user): array
    {
        $allowed = self::pagesFor($user);
        $pages = self::pages();

        return array_map(fn (array $section) => [
            'label' => $section['label'],
            'items' => array_values(array_map(fn (string $id) => [
                'id' => $id,
                'label' => $pages[$id]['label'],
                'href' => '/'.$id,
                'icon' => $pages[$id]['icon'],
            ], array_filter($section['pages'], fn (string $id) => in_array($id, $allowed, true)))),
        ], config('erp.sections'));
    }
}
