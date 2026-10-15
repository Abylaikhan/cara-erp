<?php

namespace App\Http\Controllers;

use App\Models\BankStatementRow;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** «Деньги» → «Сверка с банком»: legacy clearBankReconSheet — the rows of the account not transferred to the journal are deleted. */
class BankReconController extends Controller
{
    public function clear(Request $request): RedirectResponse
    {
        $data = $request->validate(['account' => ['required', 'integer', 'exists:money_accounts,id']]);
        DB::transaction(function () use ($data, $request) {
            $rows = BankStatementRow::query()->where('account_id', $data['account'])->where('transferred_to_db', false)->lockForUpdate()->get();
            Audit::record('bank_statement.cleared', 'money_account', $data['account'], ['rows' => $rows->map->getAttributes()->values()->all()], $request->user());
            BankStatementRow::query()->whereIn('id', $rows->pluck('id'))->delete();
        });

        return back();
    }
}
