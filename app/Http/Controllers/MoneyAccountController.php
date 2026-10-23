<?php

namespace App\Http\Controllers;

use App\Http\Requests\Money\MoneyAccountRequest;
use App\Services\Money\MoneyAccountService;
use App\Support\Erp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** «Деньги» → «+ Касса», «+ Счёт» and the card of an account (legacy bankAccountModal; № 37, ADR-0047). */
class MoneyAccountController extends Controller
{
    public function __construct(private readonly MoneyAccountService $service) {}

    public function store(MoneyAccountRequest $request): RedirectResponse
    {
        $this->service->create($request->safe()->only(['type', 'name', 'bank', 'iik', 'bik', 'opening', 'currency']), $request->user());

        return back()->with('toast', '✅ Счёт сохранён');
    }

    public function update(MoneyAccountRequest $request, int $account): RedirectResponse
    {
        $this->service->update($account, (int) $request->validated('lock_version'), $request->safe()->only(['type', 'name', 'bank', 'iik', 'bik', 'opening', 'currency']), $request->user());

        return back()->with('toast', '✅ Счёт сохранён');
    }

    public function destroy(Request $request, int $account): RedirectResponse
    {
        abort_unless(Erp::canAccess($request->user(), 'money'), 403);
        $data = $request->validate(['lock_version' => ['required', 'integer']]);
        $this->service->delete($account, (int) $data['lock_version'], $request->user());

        return back()->with('toast', '🗑 Счёт удалён');
    }
}
