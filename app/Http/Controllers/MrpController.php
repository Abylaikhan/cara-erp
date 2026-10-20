<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductionProgress;
use App\Support\ChannelScope;
use App\Support\Erp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M02 «Производство», part 1 — legacy page-mrp (L2233–2300), tab «Производственные заказы»: showPage('mrp') (L16332),
 * mrpCollectAllPositions/mrpEffectiveStatus/isItemRealized (L33448–33480), renderMrpOrdersJournal (L36074). Every order line
 * with its effective status: realized → done, a queued line with recorded work → in work. View only; the shop floor, the
 * worksheet, technological cards, workload and the work log come with ERP-041…044.
 */
class MrpController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(Erp::canAccess($request->user(), 'mrp'), 403);
        $orders = ChannelScope::apply(Order::query(), $request->user())->get(['id', 'num'])->keyBy('id');
        $items = OrderItem::query()->whereIn('order_id', $orders->keys())->orderBy('order_id')->orderBy('line_no')->get();
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
            'operations' => config('erp.production_operations'),
        ]);
    }
}
