<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductionProgress;
use App\Support\ChannelScope;
use App\Support\Erp;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M18 «Сдельная оплата», part 1 — legacy page-wages (L8022–8120), showPage('wages') (L17066), renderPieceWages/showPieceDetail/
 * renderPieceTariffs (L17852–18050) on collectWorkLogEntries/computeStageAmountFor (L19627–19656): the production staff by what
 * they earned in a month from the stages posted on the production worksheet, the detail of an employee and the grid of rates.
 * Every amount is counted here exactly by the rule of legacy — the rate of the stage × its quantity, or its percent of the
 * product's amount in the order — and shared among the executors by КТУ; the page adds them up for the month and the filters,
 * as legacy does. View only: the plan of an employee, entering output and the rates wait for the rules of piece wages
 * (ERP-055, decision D-06) — ADR-0042.
 */
class WagesController extends Controller
{
    private const SCALE = 12;

    public function index(Request $request): Response
    {
        abort_unless(Erp::canAccess($request->user(), 'wages'), 403);
        $orders = ChannelScope::apply(Order::query(), $request->user())->pluck('num', 'id');
        $items = OrderItem::query()->whereIn('order_id', $orders->keys())->get()->keyBy('id');

        return Inertia::render('Wages', [
            'today' => now(config('erp.business_timezone'))->format('Y-m-d'),
            // Legacy prodStaff: the staff as loadStaffFromDB orders it (by name) whose type is «prod» (an empty type is «prod»).
            'staff' => Employee::query()->orderBy('name')->orderBy('id')->get()
                ->filter(fn (Employee $e) => ($e->getAttribute('type') ?: 'prod') === 'prod')
                ->map(fn (Employee $e) => [
                    'name' => $e->name, 'pos' => $e->getAttribute('position') ?? '', 'ws' => $e->getAttribute('workshop') ?? '',
                    // legacy s.piece_goal||400
                    'goal' => (float) self::orNumber($e->getRawOriginal('piece_goal'), '400'),
                ])->values(),
            'entries' => $this->entries($items, $orders->all()),
            // Legacy catalogData (loadCatalogFromDB: active products): the categories of the filter, sorted by the page.
            'categories' => Product::query()->where('active', true)->whereNotNull('category')->where('category', '<>', '')->distinct()->pluck('category')->values(),
        ]);
    }

    /**
     * Legacy collectWorkLogEntries: one entry per posted stage with its date and per executor (none — «—» with КТУ 1), with the
     * executor's share of the stage's amount.
     *
     * @param  Collection<int, OrderItem>  $items
     * @param  array<int, string>  $orders
     * @return list<array<string, mixed>>
     */
    private function entries($items, array $orders): array
    {
        $entries = [];
        $progress = ProductionProgress::query()->whereIn('order_item_id', $items->keys())->orderBy('id')->get();
        foreach ($progress as $p) {
            $item = $items[$p->order_item_id];
            foreach ((array) $p->stages as $s) {
                if (! is_array($s) || ! self::truthy($s['posted'] ?? null) || ! self::truthy($s['postedDate'] ?? null)) {
                    continue;
                }
                $assignees = is_array($s['assignees'] ?? null) && $s['assignees'] !== [] ? $s['assignees'] : [['name' => '—', 'ktu' => 1]];
                $total = self::stageAmount($s, $item);
                $ktu = fn (array $a) => self::or($a['ktu'] ?? null, '1');
                $ktuSum = array_reduce($assignees, fn (string $sum, $a) => bcadd($sum, $ktu((array) $a), self::SCALE), '0');
                if (bccomp($ktuSum, '0', self::SCALE) === 0) {
                    $ktuSum = '1';
                }
                foreach ($assignees as $a) {
                    $a = (array) $a;
                    $entries[] = [
                        'date' => (string) $s['postedDate'], 'workshop' => self::truthy($s['workshop'] ?? null) ? (string) $s['workshop'] : '',
                        'exec' => (string) ($a['name'] ?? ''), 'num' => $orders[$item->order_id], 'pos' => $item->line_no + 1, 'name' => $item->name,
                        'category' => $item->category ?: '—', 'stage' => self::truthy($s['stage'] ?? null) ? (string) $s['stage'] : '(без названия)',
                        'amount' => (float) bcdiv(bcmul($total, $ktu($a), self::SCALE), $ktuSum, self::SCALE),
                    ];
                }
            }
        }

        return $entries;
    }

    /**
     * Legacy computeStageAmountFor: «% от суммы заказа по товару» — quantity × price of the line × percent / 100 (legacy loads
     * the line's quantity as qty||1 and its price as price||0); otherwise the rate × the quantity of the stage (rate||0,
     * qty||1).
     *
     * @param  array<string, mixed>  $s
     */
    public static function stageAmount(array $s, OrderItem $item): string
    {
        if (($s['payMethod'] ?? null) === 'percent') {
            $qty = self::orNumber($item->getRawOriginal('qty'), '1');
            $price = self::orNumber($item->getRawOriginal('price'), '0');

            return bcdiv(bcmul(bcmul($qty, $price, self::SCALE), self::or($s['percent'] ?? null, '0'), self::SCALE), '100', self::SCALE);
        }

        return bcmul(self::or($s['rate'] ?? null, '0'), self::or($s['qty'] ?? null, '1'), self::SCALE);
    }

    /**
     * JavaScript `v || fallback` for a value of the stages (JSON): a number that is zero gives the fallback, a text is kept
     * when it is not empty («0» included), as JavaScript multiplies it. A text that is no number (JavaScript: NaN) gives the
     * fallback.
     */
    private static function or(mixed $v, string $fallback): string
    {
        if (is_string($v)) {
            return preg_match('/^-?\d+(\.\d+)?$/', $v) === 1 ? $v : $fallback;
        }
        if ((! is_int($v) && ! is_float($v)) || $v == 0) {
            return $fallback;
        }

        return is_float($v) ? sprintf('%.'.self::SCALE.'F', $v) : (string) $v;
    }

    /** The same for a number stored here (legacy reads it as a number: null and zero give the fallback). */
    private static function orNumber(?string $v, string $fallback): string
    {
        return $v === null || bccomp($v, '0', self::SCALE) === 0 ? $fallback : $v;
    }

    /** JavaScript truthiness of a JSON value. */
    private static function truthy(mixed $v): bool
    {
        return ! ($v === null || $v === false || $v === '' || $v === 0 || $v === 0.0);
    }
}
