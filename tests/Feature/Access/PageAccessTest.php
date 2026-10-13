<?php

namespace Tests\Feature\Access;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Server-side page access for every legacy role template × every page (provisional matrix, ADR-0001). */
class PageAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function roles(): array
    {
        $roles = require dirname(__DIR__, 3).'/config/erp.php';

        return array_combine(array_keys($roles['roles']), array_map(fn ($r) => [$r], array_keys($roles['roles'])));
    }

    #[DataProvider('roles')]
    public function test_role_reaches_exactly_its_pages(string $role): void
    {
        $user = User::factory()->role($role)->create();
        $allowed = array_intersect(config("erp.roles.$role.pages"), array_keys(config('erp.pages')));

        foreach (array_keys(config('erp.pages')) as $page) {
            $response = $this->actingAs($user)->get('/'.$page);
            $expected = in_array($page, $allowed, true) ? 200 : 403;
            $this->assertSame($expected, $response->status(), "$role → /$page");
        }
    }

    #[DataProvider('roles')]
    public function test_menu_shows_exactly_the_allowed_pages(string $role): void
    {
        $user = User::factory()->role($role)->create();
        $allowed = array_values(array_intersect(config("erp.roles.$role.pages"), array_keys(config('erp.pages'))));
        $first = collect($allowed)->first(fn ($p) => $p !== 'mycabinet') ?? $allowed[0];

        $this->actingAs($user)->get('/'.$first)->assertInertia(function (Assert $page) use ($allowed) {
            $items = collect($page->toArray()['props']['nav'])->flatMap(fn ($s) => array_column($s['items'], 'id'))->sort()->values()->all();
            $this->assertSame(collect($allowed)->sort()->values()->all(), $items);
            $page->has('nav', 5); // section labels stay, as in legacy
        });
    }

    public function test_director_menu_has_21_pages_and_no_techcards(): void
    {
        $this->actingAs(User::factory()->role('director')->create())->get('/dashboard')->assertInertia(function (Assert $page) {
            $items = collect($page->toArray()['props']['nav'])->flatMap(fn ($s) => array_column($s['items'], 'id'));
            $this->assertCount(21, $items);
            $this->assertNotContains('techcards', $items);
        });
        $this->actingAs(User::factory()->role('director')->create())->get('/techcards')->assertNotFound();
    }

    public function test_shared_props_carry_no_credentials(): void
    {
        $user = User::factory()->role('director')->create(['password' => 'secret-password']);

        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.name', $user->name)
            ->where('auth.user.roleLabel', 'Директор')
            ->missing('auth.user.password')
            ->missing('auth.user.login')
            ->missing('auth.user.email'));
        $this->assertStringNotContainsString($user->password, $response->getContent());
    }

    public function test_inactive_user_loses_access_immediately(): void
    {
        $user = User::factory()->role('director')->create();
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->forceFill(['is_active' => false])->save();
        $this->actingAs($user->fresh())->get('/dashboard')->assertForbidden();
    }

    public function test_guest_cannot_open_any_page(): void
    {
        foreach (array_keys(config('erp.pages')) as $page) {
            $this->get('/'.$page)->assertRedirect('/login');
        }
    }
}
