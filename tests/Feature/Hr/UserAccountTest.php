<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Support\Erp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** «Сотрудники» → «Пользователи» (D-13): the director and HR create, edit and block accounts; the server keeps every rule. */
class UserAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->director = User::factory()->role('director')->create(['login' => 'director']);
    }

    public function test_a_new_account_signs_in_with_its_pages_and_channel(): void
    {
        $this->actingAs($this->director)->from('/hr');
        $this->post('/hr/users', ['login' => ' Demo.Seller ', 'password' => 'short', 'pages' => ['crm']])->assertSessionHasErrors(['password' => 'Пароль минимум 8 символов']);
        $this->post('/hr/users', ['login' => 'director', 'password' => 'Длинный-Пароль-1', 'pages' => []])->assertSessionHasErrors(['login' => 'Логин уже занят']);
        $this->post('/hr/users', ['login' => 'demo', 'password' => 'Длинный-Пароль-1', 'pages' => ['sales']])->assertSessionHasErrors('pages.0');
        $this->post('/hr/users', ['login' => ' Demo.Seller ', 'password' => 'Длинный-Пароль-1', 'name' => 'Демо Продавец', 'label' => 'Продавец',
            'channel' => 'salon_armada', 'pages' => ['crm', 'mycabinet']])->assertRedirect('/hr')->assertSessionHasNoErrors();

        $u = User::query()->where('login', 'demo.seller')->sole();
        $this->assertSame([null, 'salon_armada', ['crm', 'mycabinet'], 'Продавец', 'ДП', true], [$u->role, $u->channel, $u->pages, $u->role_label, $u->initials, $u->is_active]);
        $this->assertTrue(Hash::check('Длинный-Пароль-1', $u->password));
        $this->assertSame(['crm', 'mycabinet'], Erp::pagesFor($u));
        $this->assertSame('salon_armada', Erp::channelOf($u));
        $this->assertDatabaseHas('audit_events', ['action' => 'user.created', 'subject_id' => (string) $u->id]);
        $this->assertStringNotContainsString('Длинный-Пароль-1', (string) DB::table('audit_events')->where('action', 'user.created')->value('changes'));
    }

    public function test_editing_keeps_the_pages_the_form_does_not_offer_and_changes_the_password_only_when_typed(): void
    {
        $acc = User::factory()->role('accountant')->create(['login' => 'accountant', 'password' => 'Старый-Пароль-1']);
        $this->actingAs($this->director)->from('/hr');
        // «Продажи» of the role is not in the legacy form: it stays; an offered section left unticked is taken away
        $this->put("/hr/users/{$acc->id}", ['name' => 'Демо Главбух', 'label' => 'Главбух', 'pages' => ['dashboard', 'money'], 'lock_version' => 1])->assertSessionHasNoErrors();
        $acc->refresh();
        $this->assertSame(['dashboard', 'money', 'sales'], $acc->pages);
        $this->assertTrue(Hash::check('Старый-Пароль-1', $acc->password), 'an empty password field keeps the password');
        $this->assertSame(2, $acc->lock_version);

        $this->put("/hr/users/{$acc->id}", ['name' => 'X', 'pages' => [], 'lock_version' => 1])->assertSessionHasErrors('lock_version');
        $this->put("/hr/users/{$acc->id}", ['name' => 'Демо Главбух', 'label' => 'Главбух', 'password' => 'Новый-Пароль-12', 'pages' => ['dashboard', 'money'], 'lock_version' => 2])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Новый-Пароль-12', $acc->fresh()->password));
        $this->assertDatabaseHas('audit_events', ['action' => 'user.updated', 'subject_id' => (string) $acc->id]);
    }

    public function test_blocking_follows_legacy_and_ends_access(): void
    {
        $m = User::factory()->role('manager')->create(['login' => 'manager']);
        $this->actingAs($this->director)->from('/hr');
        $this->patch("/hr/users/{$this->director->id}/active", ['active' => false, 'lock_version' => 1])->assertSessionHasErrors(['active' => 'Нельзя заблокировать самого себя']);
        $this->patch("/hr/users/{$m->id}/active", ['active' => false, 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertFalse($m->fresh()->is_active);
        $this->assertSame([], Erp::pagesFor($m->fresh()));

        $hr = User::factory()->role('hr')->create(['login' => 'hr']);
        $this->actingAs($hr);
        $this->patch("/hr/users/{$this->director->id}/active", ['active' => false, 'lock_version' => 1])->assertSessionHasErrors(['active' => 'Директора нельзя заблокировать']);
        $this->patch("/hr/users/{$m->id}/active", ['active' => true, 'lock_version' => 2])->assertSessionHasNoErrors();
        $this->assertTrue($m->fresh()->is_active);
    }

    public function test_only_the_director_and_hr_manage_accounts_and_see_them(): void
    {
        foreach (['accountant', 'manager', 'salon_armada_mgr'] as $role) {
            $u = User::factory()->role($role)->create();
            $this->actingAs($u)->post('/hr/users', ['login' => 'x'.$role, 'password' => 'Длинный-Пароль-1', 'pages' => []])->assertForbidden();
            $this->patch("/hr/users/{$this->director->id}/active", ['active' => false, 'lock_version' => 1])->assertForbidden();
            if (Erp::canAccess($u, 'hr')) {
                $this->get('/hr')->assertInertia(fn (Assert $page) => $page->where('canManageUsers', false)->where('accounts', []));
            }
        }
        $this->actingAs($this->director)->get('/hr')->assertInertia(fn (Assert $page) => $page
            ->where('canManageUsers', true)
            ->where('accounts.0.login', 'director')
            ->missing('accounts.0.password'));
    }
}
