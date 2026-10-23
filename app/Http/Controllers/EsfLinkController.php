<?php

namespace App\Http\Controllers;

use App\Services\Esf\EsfLinkService;
use App\Support\Erp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** «Входящие ЭСФ» → «Сверка с оплатами»: «Сохранить связи» and «🗑 Удалить связь» (D-10, ADR-0048). */
class EsfLinkController extends Controller
{
    public function __construct(private readonly EsfLinkService $service) {}

    public function store(Request $request): RedirectResponse
    {
        abort_unless(Erp::canAccess($request->user(), 'esf'), 403);
        $data = $request->validate([
            'sources' => ['present', 'array', 'max:200'],
            'sources.*.type' => ['required', 'in:money_tx,advance_report'],
            'sources.*.id' => ['required', 'integer'],
            'invoices' => ['present', 'array', 'max:2000'],
            'invoices.*' => ['integer'],
        ]);
        $r = $this->service->save(array_values($data['sources']), array_values(array_map('intval', $data['invoices'])), $request->user());
        // The lines legacy writes to the log of the tab (esfReconLog); here also what could not be linked for want of money.
        $num = DB::table('incoming_invoices')->whereIn('id', [...array_column($r['added'], 'invoice'), ...$r['removed'], ...$r['skipped']])->pluck('num', 'id');
        $money = fn (string $a) => '₸'.number_format((float) $a, 0, ',', ' ');
        $log = [
            ...array_map(fn ($a) => "✅ Привязан ЭСФ {$num[$a['invoice']]} ({$money($a['amount'])})", $r['added']),
            ...array_map(fn ($id) => "↩️ Отвязан ЭСФ {$num[$id]}", $r['removed']),
            ...array_map(fn ($id) => "⚠️ ЭСФ {$num[$id]} не привязан: сумма платежа исчерпана или ЭСФ уже оплачен", $r['skipped']),
            '▶ Готово. Изменений: '.(count($r['added']) + count($r['removed'])).'. Ошибок: 0.',
        ];

        return back()->with('result', ['esfLog' => $log]);
    }

    public function destroy(Request $request, int $link): RedirectResponse
    {
        abort_unless(Erp::canAccess($request->user(), 'esf'), 403);
        $this->service->delete($link, $request->user());

        return back();
    }
}
