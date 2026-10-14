<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** AUTH-01: legacy login scenario (doLogin L1695) with server-side checks. */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_login_or_password_shows_legacy_message(): void
    {
        $this->from('/login')->post('/login', ['login' => '', 'password' => ''])
            ->assertSessionHasErrors(['login' => 'Введите логин и пароль']);
        $this->from('/login')->post('/login', ['login' => 'director', 'password' => ''])
            ->assertSessionHasErrors(['login' => 'Введите логин и пароль']);
    }

    public function test_login_is_trimmed_and_case_insensitive_like_legacy(): void
    {
        User::factory()->create(['login' => 'director', 'password' => 'secret-password']);

        $this->post('/login', ['login' => '  Director ', 'password' => 'secret-password'])->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }

    public function test_first_page_follows_role_template(): void
    {
        $salon = User::factory()->role('salon_armada')->create(['password' => 'secret-password']);
        $this->post('/login', ['login' => $salon->login, 'password' => 'secret-password'])->assertRedirect('/catalog');
        $this->get('/')->assertRedirect('/catalog');
    }

    public function test_inactive_user_cannot_sign_in(): void
    {
        $user = User::factory()->inactive()->create(['password' => 'secret-password']);

        // Legacy doLogin: a disabled user with the right password is told the account is blocked, otherwise the usual message.
        $this->from('/login')->post('/login', ['login' => $user->login, 'password' => 'secret-password'])
            ->assertSessionHasErrors(['login' => 'Пользователь заблокирован']);
        $this->from('/login')->post('/login', ['login' => $user->login, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['login' => 'Неверный логин или пароль']);
        $this->assertGuest();
    }

    public function test_repeated_failures_are_throttled(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => $user->login, 'password' => 'wrong']);
        }

        $this->from('/login')->post('/login', ['login' => $user->login, 'password' => 'secret-password'])
            ->assertSessionHasErrors('login');
        $this->assertStringStartsWith('Слишком много попыток входа', session('errors')->first('login'));
        $this->assertGuest();
    }

    public function test_pin_sign_in_is_not_available_until_employees_are_migrated(): void
    {
        $this->from('/login')->post('/login/pin', ['pin' => '1234'])
            ->assertSessionHasErrors(['pin' => 'Вход по PIN появится после переноса раздела «Сотрудники»']);
        $this->assertGuest();
    }

    public function test_after_logout_the_server_refuses_changes(): void
    {
        $user = User::factory()->role('director')->create();
        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->post('/expense_cats', ['name' => 'X', 'code' => 'after_logout', 'is_top' => true])->assertRedirect('/login');
        $this->get('/expense_cats')->assertRedirect('/login');
        $this->assertDatabaseMissing('expense_categories', ['code' => 'after_logout']);
    }

    public function test_login_page_never_contains_credentials(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertStringNotContainsString('secret-password', $html);
        $this->assertStringNotContainsString($user->password, $html);
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('auth.user', null));
    }
}
