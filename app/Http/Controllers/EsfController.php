<?php

namespace App\Http\Controllers;

use App\Models\EsfPaymentLink;
use App\Models\IncomingInvoice;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\Warehouse;
use App\Services\Esf\EsfImportService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M05 «Входящие ЭСФ» — legacy page-esf (L3056–3238): initEsfPage, renderEsfList, setEsfType, openEsfDetail,
 * handleEsfXmlFiles/renderEsfXmlImportPreview/confirmEsfXmlImport. «Сверка с оплатами» waits for «Деньги» (ERP-048, D-10).
 */
class EsfController extends Controller
{
    private const MAX_FILES = 50;

    private const MAX_FILE_KB = 5120;

    public function index(): Response
    {
        Gate::authorize('viewAny', IncomingInvoice::class);
        $linked = EsfPaymentLink::query()->groupBy('invoice_id')->selectRaw('invoice_id, sum(amount) AS linked')->pluck('linked', 'invoice_id');

        return Inertia::render('Esf', [
            // Legacy loads them by date, newest first; its order within one date is not defined — here the latest import first.
            'invoices' => IncomingInvoice::query()->orderByDesc('date')->orderByDesc('created_at')->orderByDesc('id')
                ->get(['id', 'num', 'reg_num', 'date', 'status', 'invoice_type', 'supplier_name', 'supplier_bin', 'total_amount', 'total_nds', 'total_with_nds'])
                ->map(fn (IncomingInvoice $i) => [
                    'id' => $i->id,
                    'num' => $i->num,
                    'reg_num' => $i->reg_num,
                    'date' => $i->date->format('Y-m-d'),
                    'status' => $i->status,
                    'invoice_type' => $i->invoice_type,
                    'supplier_name' => $i->supplier_name,
                    'supplier_bin' => $i->supplier_bin,
                    'total_amount' => (float) $i->total_amount,
                    'total_nds' => (float) $i->total_nds,
                    'total_with_nds' => (float) $i->total_with_nds,
                    'linked' => (float) ($linked[$i->id] ?? 0),
                ])->values(),
            'materialWarehouses' => Warehouse::query()->ordered()->where('type', 'material')->get(['code', 'name', 'icon'])->values(),
        ]);
    }

    /** Legacy openEsfDetail: header, lines and, for each line, the warehouse material it matches (by name or alias). */
    public function detail(int $invoice): JsonResponse
    {
        Gate::authorize('viewAny', IncomingInvoice::class);
        $inv = IncomingInvoice::query()->with('items')->findOrFail($invoice);
        $materials = Material::query()->active()->orderBy('name')->orderBy('id')->pluck('name');
        $byLower = [];
        foreach ($materials as $name) {
            $byLower[mb_strtolower($name)] ??= $name;
        }
        $names = $materials->flip();
        $aliases = MaterialAlias::query()->orderBy('id')->get(['material_name', 'alias_name']);
        $match = function (string $description) use ($byLower, $names, $aliases): ?array {
            $d = mb_strtolower($description);
            if (isset($byLower[$d])) {
                return ['material' => $byLower[$d], 'via' => 'direct'];
            }
            $alias = $aliases->first(function (MaterialAlias $a) use ($d) {
                $an = mb_strtolower($a->alias_name);

                return $an === $d || str_contains($d, $an) || str_contains($an, $d);
            });

            return $alias !== null && isset($names[$alias->material_name]) ? ['material' => $alias->material_name, 'via' => 'alias'] : null;
        };

        return response()->json([
            'id' => $inv->id,
            'num' => $inv->num,
            'date' => $inv->date->format('Y-m-d'),
            'reg_num' => $inv->reg_num,
            'status' => $inv->status,
            'supplier_name' => $inv->supplier_name,
            'supplier_bin' => $inv->supplier_bin,
            'buyer_name' => $inv->buyer_name,
            'total_amount' => (float) $inv->total_amount,
            'total_nds' => (float) $inv->total_nds,
            'total_with_nds' => (float) $inv->total_with_nds,
            'items' => $inv->items->map(fn ($it) => [
                'id' => $it->id,
                'description' => $it->description,
                'qty' => (float) $it->qty,
                'unit_price' => (float) $it->unit_price,
                'amount' => (float) $it->amount,
                'nds_rate' => (float) $it->nds_rate,
                'nds_amount' => (float) $it->nds_amount,
                'total' => (float) $it->total,
                'match' => $match($it->description),
            ])->values(),
        ]);
    }

    /** Legacy setEsfType. */
    public function setType(Request $request, int $invoice): RedirectResponse
    {
        $data = $request->validate(['invoice_type' => ['required', Rule::in(IncomingInvoice::TYPES)]]);
        DB::transaction(function () use ($invoice, $data, $request) {
            $inv = IncomingInvoice::query()->whereKey($invoice)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $inv);
            if ($inv->invoice_type !== $data['invoice_type']) {
                $from = $inv->invoice_type;
                $inv->invoice_type = $data['invoice_type'];
                $inv->lock_version++;
                $inv->save();
                Audit::record('esf.type_changed', 'incoming_invoice', $inv->id, ['invoice_type' => ['from' => $from, 'to' => $inv->invoice_type]], $request->user());
            }
        });

        return back();
    }

    /** Legacy handleEsfXmlFiles: parse the chosen files and show the preview (flash `esfPreview`). */
    public function preview(Request $request, EsfImportService $service): RedirectResponse
    {
        Gate::authorize('create', IncomingInvoice::class);
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'max:'.self::MAX_FILE_KB],
        ], [
            'files.required' => 'Выберите файлы XML.',
            'files.max' => 'Не больше '.self::MAX_FILES.' файлов за раз.',
            'files.*.max' => 'Файл больше 5 МБ.',
        ]);
        $files = [];
        foreach ($request->file('files') as $file) {
            $files[$file->getClientOriginalName()] = (string) file_get_contents($file->getRealPath());
        }

        return back()->with('esfPreview', $service->preview($files, $request->user()));
    }

    /** Legacy confirmEsfXmlImport: the checked invoices of the preview. */
    public function import(Request $request, EsfImportService $service): RedirectResponse
    {
        Gate::authorize('create', IncomingInvoice::class);
        $data = $request->validate(['token' => ['required', 'string', 'size:32'], 'selected' => ['array'], 'selected.*' => ['integer', 'min:0']]);
        if (empty($data['selected'])) {
            return back()->with('dialog', ['type' => 'alert', 'text' => 'Ничего не отмечено для импорта.']);
        }
        $result = $service->import($data['token'], $data['selected'], $request->user());
        $text = 'Импортировано: '.$result['created'].($result['failed'] ? ', ошибок: '.count($result['failed'])."\n\n".implode("\n", $result['failed']) : '');

        return back()->with('dialog', ['type' => 'alert', 'text' => $text]);
    }
}
