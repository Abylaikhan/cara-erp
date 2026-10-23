<?php

namespace App\Http\Controllers;

use App\Models\EsfPaymentLink;
use App\Models\IncomingInvoice;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\Warehouse;
use App\Services\Esf\EsfImportService;
use App\Services\Esf\EsfReconData;
use App\Services\Excel\XlsxFromRows;
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
 * handleEsfXmlFiles/renderEsfXmlImportPreview/confirmEsfXmlImport. «Сверка с оплатами» is shown (EsfReconData, ADR-0043); saving
 * links waits for D-10 (ERP-048).
 */
class EsfController extends Controller
{
    /** Legacy exportEsfGoodsToExcel RULES wh_main: hardware, fasteners, tools. */
    private const MAIN_KEYS = ['шуруп', 'болт', 'гайка', 'винт', 'заглушка', 'шайба', 'корпус', 'minifix', 'tandem', 'blumotion', 'замок', 'направляющ', 'петля', 'ручка', 'фурнитур', 'муфта', 'втулка', 'кабел', 'электр', 'щетки', 'makita', 'инструмент', 'механизм', 'подпятник', 'рамка', 'пакет', 'стрейч', 'пленка'];

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
            // Tab «Сверка с оплатами» (legacy initEsfRecon): the payments, advance reports and links, loaded with the tab.
            'recon' => Inertia::optional(fn () => app(EsfReconData::class)->get()),
        ]);
    }

    /** Legacy openEsfDetail: header, lines and, for each line, the warehouse material it matches (by name or alias). */
    public function detail(int $invoice): JsonResponse
    {
        Gate::authorize('viewAny', IncomingInvoice::class);
        $inv = IncomingInvoice::query()->with('items')->findOrFail($invoice);
        $materials = Material::query()->active()->orderBy('name')->orderBy('id')->get(['id', 'name', 'qty']);
        $byLower = [];
        foreach ($materials as $m) {
            $byLower[mb_strtolower($m->name)] ??= $m;
        }
        $names = $materials->keyBy('name');
        $aliases = MaterialAlias::query()->orderBy('id')->get(['material_name', 'alias_name']);
        $received = DB::table('stock_document_lines')->join('stock_documents', 'stock_documents.id', '=', 'stock_document_lines.document_id')
            ->whereIn('stock_document_lines.esf_item_id', $inv->items->pluck('id'))->pluck('stock_documents.num', 'stock_document_lines.esf_item_id');
        $match = function (string $description) use ($byLower, $names, $aliases): ?array {
            $d = mb_strtolower($description);
            if (isset($byLower[$d])) {
                return ['material' => $byLower[$d]->name, 'via' => 'direct', 'id' => $byLower[$d]->id, 'qty' => (float) $byLower[$d]->qty];
            }
            $alias = $aliases->first(function (MaterialAlias $a) use ($d) {
                $an = mb_strtolower($a->alias_name);

                return $an === $d || str_contains($d, $an) || str_contains($an, $d);
            });

            $m = $alias !== null ? $names->get($alias->material_name) : null;

            return $m !== null ? ['material' => $m->name, 'via' => 'alias', 'id' => $m->id, 'qty' => (float) $m->qty] : null;
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
                // ADR-0049: the receipt that already took this line in
                'received' => $received[$it->id] ?? null,
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

    /**
     * Legacy exportEsfGoodsToExcel (L12832): every line of the goods invoices with the warehouse its description suggests, and the
     * list of the warehouses; the user fills in the warehouse. Legacy read the lines in the order of its table; here — by the
     * time the invoices were loaded, then the line (ADR-0002, item 119). The last column holds the line's id in this system.
     */
    public function exportGoods(XlsxFromRows $builder): \Symfony\Component\HttpFoundation\Response
    {
        Gate::authorize('viewAny', IncomingInvoice::class);
        $rules = [
            'wh_paint' => ['грунт', 'лак', 'краска', 'отвердитель', 'растворитель', 'ral', 'полиуретан', 'патина', 'ведро', 'скотч', 'тара', 'штуцер', 'акрилов'],
            'wh_fabric' => ['велюр', 'ткань', 'обивочный', 'букле', 'поролон', 'набивка', 'кожа', 'флок'],
            'wh_plates' => ['фанера', 'лдсп', 'мдф', 'шпон', 'двп', 'плита', 'панель', 'стекло', 'зеркало'],
            'wh_wood' => ['брус', 'доска', 'рейка', 'пиломатер', 'бревно', 'балка', 'сосна', 'дуб'],
            'wh_main' => self::MAIN_KEYS,
        ];
        $names = ['wh_paint' => '🎨 Склад краски', 'wh_fabric' => '🧵 Склад тканей', 'wh_plates' => '🪵 Склад плитных материалов', 'wh_wood' => '🌲 Склад леса',
            'wh_main' => '🏭 Основной склад', 'service' => '🔧 Услуга (не на склад)', '' => '❓ Не определён'];
        $guess = function (?string $desc) use ($rules): string {
            $d = mb_strtolower((string) $desc);
            foreach ($rules as $wh => $keys) {
                foreach ($keys as $k) {
                    if (str_contains($d, $k)) {
                        return $wh;
                    }
                }
            }

            return '';
        };
        $invoices = IncomingInvoice::query()->where('invoice_type', 'goods')->orderBy('created_at')->orderBy('id')->get(['id', 'date', 'num', 'supplier_name'])->keyBy('id');
        $items = DB::table('incoming_invoice_items as i')->join('incoming_invoices as v', 'v.id', '=', 'i.invoice_id')->where('v.invoice_type', 'goods')
            ->orderBy('v.created_at')->orderBy('v.id')->orderBy('i.line_no')->get(['i.id', 'i.invoice_id', 'i.description', 'i.qty', 'i.unit_price', 'i.total']);
        $num = fn ($v) => $v === null ? 0 : (float) $v;
        $rows = $items->map(function ($it) use ($invoices, $guess, $names, $num) {
            $inv = $invoices[$it->invoice_id];

            return [$inv->date?->format('Y-m-d') ?? '', $inv->num ?? '', $inv->supplier_name ?? '', $it->description ?? '', $num($it->qty), $num($it->unit_price), $num($it->total),
                $names[$guess($it->description)] ?? '❓ Не определён', $it->id];
        })->all();
        $bytes = $builder->build([
            ['name' => 'Товары из ЭСФ', 'rows' => [['Дата', '№ ЭСФ', 'Поставщик', 'Наименование товара', 'Кол-во', 'Цена', 'Сумма', 'СКЛАД (заполните)', 'item_id'], ...$rows],
                'cols' => [12, 16, 30, 50, 8, 10, 12, 28, 38]],
            ['name' => 'Склады', 'rows' => [
                ['ID склада', 'Название', 'Примечание'],
                ['wh_paint', '🎨 Склад краски', 'Лаки, краски, грунты, растворители'],
                ['wh_fabric', '🧵 Склад тканей', 'Ткань, велюр, букле, поролон'],
                ['wh_plates', '🪵 Склад плитных материалов', 'Фанера, МДФ, ЛДСП, ДВП, стекло'],
                ['wh_wood', '🌲 Склад леса', 'Брус, доска, рейка, пиломатериалы'],
                ['wh_main', '🏭 Основной склад', 'Фурнитура, крепёж, инструменты'],
                ['service', '🔧 Услуга (не на склад)', 'Услуги — не заносятся на склад'],
                ['', '❓ Не определён', 'Нужно заполнить вручную'],
            ]],
        ]);
        Audit::record('export.xlsx', 'page', 'esf', ['filename' => 'ЭСФ_товары_склады.xlsx', 'rows' => count($rows)], request()->user());

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"esf_goods.xlsx\"; filename*=UTF-8''".rawurlencode('ЭСФ_товары_склады.xlsx'),
            'Cache-Control' => 'no-store',
            'X-Export-Rows' => (string) count($rows),
            'X-Export-Invoices' => (string) $invoices->count(),
        ]);
    }
}
