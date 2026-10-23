<?php

namespace App\Http\Controllers;

use App\Http\Requests\Hr\UserAccountRequest;
use App\Services\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** «Сотрудники» → «Пользователи» (D-13, ADR-0046): new account, edit, block and unblock — the director and HR only. */
class UserAccountController extends Controller
{
    public function __construct(private readonly UserAccountService $service) {}

    public function store(UserAccountRequest $request): RedirectResponse
    {
        $this->service->create($request->safe()->only(['login', 'password', 'name', 'label', 'channel', 'pages']), $request->user());

        return back()->with('toast', '✅ Пользователь сохранён');
    }

    public function update(UserAccountRequest $request, int $user): RedirectResponse
    {
        $this->service->update($user, (int) $request->validated('lock_version'), $request->safe()->only(['password', 'name', 'label', 'channel', 'pages']), $request->user());

        return back()->with('toast', '✅ Пользователь сохранён');
    }

    public function active(Request $request, int $user): RedirectResponse
    {
        abort_unless(UserAccountService::canManage($request->user()), 403);
        $data = $request->validate(['active' => ['required', 'boolean'], 'lock_version' => ['required', 'integer']]);
        $this->service->setActive($user, (int) $data['lock_version'], (bool) $data['active'], $request->user());

        return back();
    }
}
