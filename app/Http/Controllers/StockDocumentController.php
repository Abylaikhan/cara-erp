<?php

namespace App\Http\Controllers;

use App\Http\Requests\Warehouse\StockReceiptRequest;
use App\Services\Warehouse\StockDocumentService;
use App\Support\Erp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** «Склад» → «📥 Приходная накладная»: save a draft, post, post a saved draft (№ 45, ADR-0049). */
class StockDocumentController extends Controller
{
    public function __construct(private readonly StockDocumentService $service) {}

    public function store(StockReceiptRequest $request): RedirectResponse
    {
        $doc = $this->service->saveReceipt($this->data($request), (bool) $request->validated('post'), $request->user());

        return back()->with('alert', $doc->status === 'posted' ? "Документ {$doc->num} проведён ✅" : null);
    }

    public function update(StockReceiptRequest $request, int $document): RedirectResponse
    {
        $doc = $this->service->saveReceipt($this->data($request), (bool) $request->validated('post'), $request->user(), $document, (int) $request->validated('lock_version'));

        return back()->with('alert', $doc->status === 'posted' ? "Документ {$doc->num} проведён ✅" : null);
    }

    public function post(Request $request, int $document): RedirectResponse
    {
        abort_unless(Erp::canAccess($request->user(), 'warehouse'), 403);
        $data = $request->validate(['lock_version' => ['required', 'integer']]);
        $doc = $this->service->post($document, (int) $data['lock_version'], $request->user());

        return back()->with('alert', "Документ {$doc->num} проведён ✅");
    }

    /** @return array{date: string, num: ?string, supplier: ?string, esf_ref: ?string, note: ?string, lines: list<array{material_id: int, qty: string, price: string}>} */
    private function data(StockReceiptRequest $request): array
    {
        return $request->safe()->only(['date', 'num', 'supplier', 'esf_ref', 'note', 'lines']) + ['num' => null, 'supplier' => null, 'esf_ref' => null, 'note' => null, 'lines' => []];
    }
}
