<?php

namespace App\Http\Controllers;

use App\Http\Requests\Finance\FixedCostsRequest;
use App\Services\Finance\FixedCosts;
use Illuminate\Http\RedirectResponse;

/** «Финансы» → «Точка безубыточности»: the plan of fixed costs of a month is saved as a whole (legacy saveAllFixedCosts; ADR-0055). */
class FixedCostController extends Controller
{
    public function update(FixedCostsRequest $request, FixedCosts $costs, string $period): RedirectResponse
    {
        $rows = array_map(fn (array $r) => ['name' => (string) $r['name'], 'category' => (string) $r['category'], 'plan' => (string) $r['plan'],
            'approved' => (bool) $r['approved'], 'pay_day' => $r['pay_day'] === null ? null : (int) $r['pay_day']], array_values($request->validated('rows')));
        $costs->save($period, (string) $request->validated('version'), $rows, $request->user());

        return redirect()->route('finance.index', ['fc_period' => $period]);
    }
}
