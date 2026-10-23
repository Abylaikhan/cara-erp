<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Erp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Login screen of legacy (L1871) with server-side checks. Messages repeat legacy doLogin (L1695).
 * Unlike legacy, credentials are never sent to the browser (AUTH-01).
 */
class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $login = Str::lower(trim((string) $request->input('login')));
        $password = (string) $request->input('password');
        if ($login === '' || $password === '') {
            throw ValidationException::withMessages(['login' => 'Введите логин и пароль']);
        }
        $key = 'login|'.$login.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'login' => 'Слишком много попыток входа. Повторите через '.RateLimiter::availableIn($key).' с.',
            ]);
        }
        if (! Auth::attempt(['login' => $login, 'password' => $password, 'is_active' => true])) {
            RateLimiter::hit($key, 60);
            // Legacy doLogin (c917d37): a disabled user who gives the right password is told so.
            $user = Auth::getProvider()->retrieveByCredentials(['login' => $login]);
            $blocked = $user !== null && ! $user->is_active && Auth::getProvider()->validateCredentials($user, ['password' => $password]);
            throw ValidationException::withMessages(['login' => $blocked ? 'Пользователь заблокирован' : 'Неверный логин или пароль']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $first = Erp::firstPage($request->user());

        return redirect($first !== null ? '/'.$first : '/');
    }

    /** PIN sign-in opens the employee cabinet (legacy checkLoginPin). Employees (M17) are not migrated yet. */
    public function pin(Request $request): RedirectResponse
    {
        $key = 'login-pin|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'pin' => 'Слишком много попыток. Повторите через '.RateLimiter::availableIn($key).' с.',
            ]);
        }
        RateLimiter::hit($key, 60);
        throw ValidationException::withMessages(['pin' => 'Вход по PIN появится вместе с кабинетом сотрудника: PIN-коды выдаются заново (ERP-058, решение D-13)']);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
