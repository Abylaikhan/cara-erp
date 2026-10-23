<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductionProgress;
use App\Models\ProductionWorksheetRow;
use App\Support\ChannelScope;
use App\Support\Erp;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M02 «Производство», part 1 — legacy page-mrp (L2233–2300), tab «Производственные заказы»: showPage('mrp') (L16332),
 * mrpCollectAllPositions/mrpEffectiveStatus/isItemRealized (L33448–33480), renderMrpOrdersJournal (L36074). Every order line
 * with its effective status: realized → done, a queued line with recorded work → in work. Tabs «Загрузка цехов» and «Журнал работ»
 * (renderWorkshopLoad, collectWorkLogEntries/renderWorkLog L19627–19760) read the stages recorded on the production worksheet;
 * they load with the tab. Tab «Рабочий лист производства» (initProdWorksheet/renderProdWorksheet L35323–35620) shows the lines
 * put on the worksheet with what is recorded for them. View only; adding lines, recording and posting stages, the shop floor
 * and technological cards come with ERP-041…044.
 */
class MrpController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(Erp::canAccess($request->user(), 'mrp'), 403);
        $orders = ChannelScope::apply(Order::query(), $request->user())->get(['id', 'num', 'channel', 'due_date', 'delivery_date'])->keyBy('id');
        $items = OrderItem::query()->whereIn('order_id', $orders->keys())->orderBy('order_id')->orderBy('line_no')->get()->keyBy('id');
        $progress = ProductionProgress::query()->whereIn('order_item_id', $items->pluck('id'))->get()->keyBy('order_item_id');
        // Legacy isItemRealized: a realization of the order with the same name, trimmed and lower-cased.
        $realized = DB::table('realizations')->whereIn('order_id', $orders->keys())->get(['order_num', 'name'])
            ->mapWithKeys(fn ($r) => [$r->order_num.'|'.mb_strtolower(trim($r->name)) => true]);

        return Inertia::render('Mrp', [
            'positions' => $items->map(function (OrderItem $i) use ($orders, $progress, $realized) {
                $num = $orders[$i->order_id]->num;
                $p = $progress->get($i->id);
                $status = $i->status;
                if ($status !== 'done' && isset($realized[$num.'|'.mb_strtolower(trim($i->name))])) {
                    $status = 'done';
                } elseif ($status === 'queue' && $p?->hasActivity()) {
                    $status = 'progress';
                }

                return [
                    'id' => $i->id,
                    'num' => $num,
                    'pos' => $i->line_no + 1, // legacy posBadge: the place of the product in its order
                    'name' => $i->name,
                    'category' => $i->category,
                    'qty' => (float) $i->qty,
                    'progress' => $i->progress,
                    'status' => $status,
                    'resp' => $i->resp,
                    'date' => $i->item_date?->format('d.m.Y'),
                    'stages' => $p ? collect($p->stages)->map(fn ($s) => (string) ($s['stage'] ?? '') ?: '(без названия)')->values() : null,
                    'completed' => (bool) $p?->completed,
                ];
            })->values(),
            'today' => now(config('erp.business_timezone'))->format('Y-m-d'),
            'operations' => config('erp.production_operations'),
            // The stages recorded for the lines (legacy prodProgress, in the order legacy loads them) and the staff for the
            // executor filter of the work log (legacy employeesData, by name).
            'work' => Inertia::optional(fn () => [
                'items' => $progress->filter(fn (ProductionProgress $p) => ! empty($p->stages))->sortBy('id')->map(function (ProductionProgress $p) use ($items, $orders) {
                    $i = $items[$p->order_item_id];

                    return [
                        'num' => $orders[$i->order_id]->num, 'pos' => $i->line_no + 1, 'name' => $i->name, 'category' => $i->category,
                        'date' => $i->item_date?->format('d.m.Y'), 'deadline' => $i->item_date?->format('Y-m-d'),
                        'stages' => collect($p->stages)->map(fn ($s) => [
                            'stage' => (string) ($s['stage'] ?? ''), 'workshop' => (string) ($s['workshop'] ?? ''), 'posted' => (bool) ($s['posted'] ?? false),
                            'postedDate' => (string) ($s['postedDate'] ?? ''),
                            'assignees' => collect($s['assignees'] ?? [])->map(fn ($a) => ['name' => (string) ($a['name'] ?? ''), 'ktu' => (float) ($a['ktu'] ?? 1)])->values(),
                        ])->values(),
                    ];
                })->values(),
                'execs' => Employee::query()->orderBy('name')->pluck('name'),
            ]),
            // «Рабочий лист производства»: the lines on the worksheet (legacy prodWbRows, in the order of its table) whose order
            // and line exist, with what is recorded for them, and the staff for the executor filter; loaded with the tab.
            'worksheet' => Inertia::optional(fn () => [
                'rows' => $this->worksheet($items, $orders, $progress),
                'execs' => Employee::query()->orderBy('name')->pluck('name'),
            ]),
        ]);
    }

    /**
     * Legacy renderProdWorksheet: the order (number, channel, date and delivery date), the product and its characteristics
     * (legacy itemCharacteristicsText: the values of the attributes), the stages with their workshops and executors, the
     * materials and whether something is posted.
     *
     * @param  Collection<int, OrderItem>  $items
     * @param  Collection<int, Order>  $orders
     * @param  Collection<int, ProductionProgress>  $progress
     * @return Collection<int, array<string, mixed>>
     */
    private function worksheet(Collection $items, Collection $orders, Collection $progress): Collection
    {
        return ProductionWorksheetRow::query()->whereIn('order_item_id', $items->keys())
            ->orderByRaw('legacy_position IS NULL, legacy_position, id')->get()
            ->map(function (ProductionWorksheetRow $r) use ($items, $orders, $progress) {
                $i = $items[$r->order_item_id];
                $o = $orders[$i->order_id];
                $p = $progress->get($i->id);
                // JS Object.values(attributes).filter(Boolean): every value as legacy holds it, empty ones left out
                $chars = array_filter(array_values((array) $i->attrs), fn ($v) => $v !== null && $v !== false && $v !== '' && $v !== 0 && $v !== 0.0);

                return [
                    'key' => $o->num.'|'.$i->line_no, 'num' => $o->num, 'pos' => $i->line_no + 1, 'channel' => $o->channel,
                    'date' => $o->due_date?->format('d.m.Y'), 'ship' => $o->delivery_date?->format('d.m.Y'),
                    'dateIso' => $o->due_date?->format('Y-m-d'), 'shipIso' => $o->delivery_date?->format('Y-m-d'),
                    'name' => $i->name, 'category' => $i->category, 'qty' => (float) $i->getRawOriginal('qty'),
                    'chars' => implode(', ', array_map(fn ($v) => is_array($v) ? implode(',', $v) : (string) $v, $chars)),
                    'stages' => collect($p?->stages ?? [])->map(fn ($s) => [
                        'stage' => (string) ($s['stage'] ?? ''), 'workshop' => (string) ($s['workshop'] ?? ''),
                        'posted' => (bool) ($s['posted'] ?? false), 'started' => (bool) ($s['started'] ?? false),
                        'assignees' => collect($s['assignees'] ?? [])->map(fn ($a) => (string) ($a['name'] ?? ''))->values(),
                    ])->values(),
                    'materials' => collect($p?->materials ?? [])->map(fn ($m) => ($n = (string) ($m['name'] ?? '')) === '' ? '?' : $n)->values(),
                    'completed' => (bool) $p?->completed,
                ];
            })->values();
    }
}
