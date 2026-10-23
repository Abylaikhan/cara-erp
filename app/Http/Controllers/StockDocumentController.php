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

    /** «Входящие ЭСФ» → «📦 Принять» / «📦 На склад» (ADR-0049, part 2). */
    public function fromEsf(Request $request, int $item): RedirectResponse
    {
        abort_unless(Erp::canAccess($request->user(), 'esf'), 403);
        $data = $request->validate([
            'material_id' => ['nullable', 'integer'],
            'warehouse' => ['required', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:16'],
        ]);
        $doc = $this->service->receiveEsfItem($item, isset($data['material_id']) ? (int) $data['material_id'] : null, $data['warehouse'], $data['name'] ?? null, $data['unit'] ?? null, $request->user());

        return back()->with('result', ['received' => ['num' => $doc->num, 'qty' => (float) $doc->lines()->value('qty')]]);
    }

    /** @return array{date: string, num: ?string, supplier: ?string, esf_ref: ?string, note: ?string, lines: list<array{material_id: int, qty: string, price: string}>} */
    private function data(StockReceiptRequest $request): array
    {
        return $request->safe()->only(['date', 'num', 'supplier', 'esf_ref', 'note', 'lines']) + ['num' => null, 'supplier' => null, 'esf_ref' => null, 'note' => null, 'lines' => []];
    }
}
