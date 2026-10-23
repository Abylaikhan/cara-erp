<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Realization;
use App\Models\SalesChannel;
use App\Models\SalesWorksheetRow;
use App\Models\User;
use App\Services\OrderService;
use App\Support\ChannelScope;
use App\Support\Erp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M09 «Продажи», part 1 — legacy page-sales (L3824–4133): loadOrdersFromDB (L1356), renderOrders/getOrderSortValue/
 * resolveCustomerName/setOrderYear/clearOrderFilters/sortOrders (L29892–30237), updateOrderTabCounts, showSalesTab,
 * markOrderDone (L31140), renderSalesJournal/sortSJ/setSjPeriod/setSjMonthYear (L30830–31096), renderOrderReport/
 * getReportOrders/fillRepMonths/toggleRepPeriod/sortReport (L32129–32315). Orders are shown, not
 * edited: the order form, new orders and payments wait for decisions D-02, D-03, D-12 (ERP-029/030). Only orders and
 * realizations of the user's channels are read.
 */
class SalesController extends Controller
{
    public function __construct(private readonly OrderService $service) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Order::class);
        // Totals and progress of the lines, realized revenue — counted in the database; legacy loads orders newest first.
        $orders = ChannelScope::apply(Order::query(), $request->user(), 'orders.channel')
            ->leftJoinSub(DB::table('order_items')->groupBy('order_id')
                ->selectRaw('order_id, sum(qty * price) AS total, sum(progress) AS progress_sum, count(*) AS lines'), 'i', 'i.order_id', '=', 'orders.id')
            ->leftJoinSub(DB::table('realizations')->whereNotNull('order_id')->groupBy('order_id')
                ->selectRaw('order_id, sum(revenue) AS realized'), 'r', 'r.order_id', '=', 'orders.id')
            ->select('orders.*', DB::raw('coalesce(i.total, 0) AS total'), DB::raw('coalesce(i.progress_sum, 0) AS progress_sum'),
                DB::raw('coalesce(i.lines, 0) AS lines'), DB::raw('coalesce(r.realized, 0) AS realized'))
            ->orderByDesc('orders.created_at')->orderByDesc('orders.id')->get();

        return Inertia::render('Sales', [
            'orders' => $orders->map(fn (Order $o) => [
                'id' => $o->id,
                'num' => $o->num,
                'channel' => $o->channel,
                'customer' => $o->customer,
                'end_client' => $o->end_client,
                'seller' => $o->seller,
                'created' => $o->order_date?->format('d.m.Y'),
                'date' => $o->due_date?->format('d.m.Y'),
                'payment' => $o->payment_pct,
                'status' => $o->status,
                'total' => (float) $o->getAttribute('total'),
                // Legacy Math.round of the mean progress of the lines.
                'progress' => (int) round((int) $o->getAttribute('progress_sum') / max((int) $o->getAttribute('lines'), 1), 0, PHP_ROUND_HALF_UP),
                'can_complete' => OrderService::canComplete($o, (string) $o->getAttribute('total'), (string) $o->getAttribute('realized')),
                'lock_version' => $o->lock_version,
            ])->values(),
            'channels' => SalesChannel::query()->ordered()->pluck('name', 'code'),
            // Tab «Реализация» (legacy renderSalesJournal): loaded when the tab is opened, as legacy does.
            'realizations' => Inertia::optional(fn () => $this->realizations($request->user())),
            // Tab «Отчёт» (legacy renderOrderReport): the lines of the orders with their characteristics, the comment and
            // the delivery date — loaded when the tab is opened.
            'report' => Inertia::optional(fn () => $this->report($request->user())),
            // Tab «Рабочий лист реализации» (legacy swReload/swRender): the lines of the delivery notes, loaded with the tab; a
            // salon or dealer account gets none (legacy swApplyRole hides the tab from it).
            'worksheet' => Inertia::optional(fn () => Erp::channelOf($request->user()) !== null ? [] : $this->worksheet($request->user())),
            // Legacy SW_ADMINS: the account that edits any note. Who wrote a note is told per line («mine»), not by logins.
            'swAdmin' => $request->user()->login === 'director',
            'today' => now(config('erp.business_timezone'))->format('Y-m-d'),
            // Legacy swApplyRole: a salon or dealer account does not see the worksheet and the restorations.
            'channelAccount' => Erp::channelOf($request->user()) !== null,
        ]);
    }

    /** @return array{lines: Collection<int, array<string, mixed>>, notes: Collection<int, array<string, mixed>>} */
    private function report(User $user): array
    {
        $orders = ChannelScope::apply(Order::query(), $user)->get(['id', 'comment', 'delivery_date']);
        $lines = DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))->orderBy('order_id')->orderBy('line_no')
            ->get(['order_id', 'name', 'qty', 'attrs']);

        return [
            'lines' => $lines->map(fn ($l) => ['order_id' => $l->order_id, 'name' => $l->name, 'qty' => (float) $l->qty, 'attrs' => json_decode($l->attrs)])->values(),
            'notes' => $orders->filter(fn (Order $o) => $o->comment !== null || $o->delivery_date !== null)
                ->mapWithKeys(fn (Order $o) => [$o->id => ['comment' => $o->comment, 'delivery' => $o->delivery_date?->format('d.m.Y')]]),
        ];
    }

    /**
     * Realizations of the user's channels. Legacy shows an office user also the lines whose order number names no order;
     * a channel-bound user sees only lines of its orders. Legacy sorts by date only; within a date — by posting time.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function realizations(User $user)
    {
        $channels = ChannelScope::channelsFor($user);
        $query = Realization::query()->orderByDesc('doc_date')->orderBy('created_at')->orderBy('id');
        if ($channels !== null) {
            $query->whereIn('order_id', Order::query()->select('id')->whereIn('channel', $channels));
        }

        return $query->get()->map(fn (Realization $r) => [
            'id' => $r->id,
            'date' => $r->doc_date->format('Y-m-d'),
            'doc_num' => $r->doc_num,
            'order_num' => $r->order_num,
            'customer' => $r->customer,
            'name' => $r->name,
            'qty' => (float) $r->qty,
            'revenue' => (float) $r->revenue,
        ])->values();
    }

    public function complete(Request $request, int $order): RedirectResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer']]);
        $done = $this->service->complete($order, (int) $data['lock_version'], $request->user());

        return back()->with('toast', "✅ {$done->num} завершён");
    }

    /**
     * Legacy swReload: order=doc_date.desc,doc_num.desc,line_no.asc. The product number in its order is the one saved on the
     * line, or — for older notes — the place of the only line of that order with the same name (legacy swItemNo).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function worksheet(User $user): Collection
    {
        $rows = SalesWorksheetRow::query()->orderByDesc('doc_date')->orderByDesc('doc_num')->orderBy('line_no')->orderBy('id')->get();
        // Legacy swIsDriverUser/swFiltered: a driver sees only the lines of the notes they drive.
        if (self::isDriver($user)) {
            $rows = $rows->filter(fn (SalesWorksheetRow $r) => self::driverMatches($r->driver, $user->name));
        }
        $names = DB::table('order_items')->whereIn('order_id', $rows->pluck('order_id')->filter()->unique())->orderBy('line_no')
            ->get(['order_id', 'line_no', 'name'])->groupBy('order_id');
        // Legacy plNormProductName: String.trim() (any Unicode space) and toLowerCase().
        $norm = fn (?string $s) => mb_strtolower(self::jsTrim((string) $s));

        return $rows->map(function (SalesWorksheetRow $r) use ($names, $norm, $user) {
            $no = $r->item_no > 0 ? $r->item_no : null;
            if ($no === null && $r->order_id !== null) {
                $hits = ($names->get($r->order_id) ?? collect())->filter(fn ($i) => $norm($i->name) === $norm($r->item_name));
                $no = $hits->count() === 1 ? $hits->first()->line_no + 1 : null;
            }

            return [
                'id' => $r->uuid, 'doc_id' => $r->doc_id, 'doc_num' => $r->doc_num, 'doc_date' => $r->doc_date->format('Y-m-d'), 'line_no' => $r->line_no,
                'line_kind' => $r->line_kind, 'order_num' => $r->order_num, 'customer' => $r->customer, 'item_name' => $r->item_name, 'item_no' => $no,
                'attrs' => $r->attrs, 'attrs_short' => $r->attrs_short, 'unit' => $r->unit, 'qty' => (float) $r->getRawOriginal('qty'),
                'price' => (float) $r->getRawOriginal('price'), 'amount' => (float) $r->getRawOriginal('amount'), 'driver' => $r->driver, 'note' => $r->note,
                'posted' => $r->posted, 'delivered' => $r->delivered, 'delivered_by' => $r->delivered_by,
                'delivered_at' => $r->delivered_at?->toIso8601String(), 'mine' => $r->created_by !== null && $r->created_by === $user->login,
                'created_day' => $r->created_at?->timezone(config('erp.business_timezone'))->format('Y-m-d'),
            ];
        })->values();
    }

    /** Legacy swIsDriverUser: the role or the name of the account says «водитель». */
    public static function isDriver(User $user): bool
    {
        return preg_match('/водител/iu', (Erp::role($user->role)['label'] ?? '').' '.$user->name) === 1;
    }

    /** Legacy swDriverMatches: the driver of a note and the name of the account, one within the other. */
    public static function driverMatches(?string $driver, ?string $name): bool
    {
        $a = mb_strtolower(self::jsTrim((string) $driver));
        $b = mb_strtolower(self::jsTrim((string) $name));

        return $a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a));
    }

    private static function jsTrim(string $s): string
    {
        return (string) preg_replace('/^[\s\p{Zs}\x{FEFF}\x{2028}\x{2029}]+|[\s\p{Zs}\x{FEFF}\x{2028}\x{2029}]+$/u', '', $s);
    }
}
