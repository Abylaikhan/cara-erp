<?php

namespace App\Http\Controllers;

use App\Models\BankStatementRow;
use App\Services\Money\BankStatementUpload;
use App\Support\Audit;
use App\Support\Erp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * «Деньги» → «Сверка с банком»: legacy clearBankReconSheet — the rows of the account not transferred to the journal are deleted;
 * handleBankReconImport — a Kaspi or Halyk statement (Excel) adds its new rows (ADR-0050).
 */
class BankReconController extends Controller
{
    public function import(Request $request, BankStatementUpload $upload): RedirectResponse
    {
        abort_unless(Erp::canAccess($request->user(), 'money'), 403);
        $data = $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls']], ['file.mimes' => 'Нужен файл Excel (.xlsx или .xls)']);
        $r = $upload->import($data['file']->getRealPath(), $request->user());

        return back()->with('alert', "Готово! Добавлено новых операций: {$r['inserted']} (пропущено как уже загруженные: ".($r['parsed'] - $r['inserted']).')')
            ->with('result', ['bankReconAccount' => $r['account']]);
    }

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
