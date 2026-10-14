<?php

namespace App\Http\Controllers;

use App\Http\Requests\Customers\CustomerRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SalesChannel;
use App\Services\CustomerService;
use App\Support\ChannelScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** M08 «Покупатели» — legacy page-customers (L3640): tab «Контрагенты» (loadCustomersFromDB/renderPersons/openPersonModal/savePerson), dealers list. */
class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $service) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);
        // Legacy renderPersons: office customers in the order of the database, one row per name (first one wins).
        $customers = ChannelScope::apply(Customer::query()->with('salesChannel'), $request->user())
            ->officeDirect()->orderBy('name')->orderBy('created_at')->orderBy('id')->get()
            ->uniqueStrict('name');

        // Legacy renderPersons: turnover and number of the office orders whose customer is this name (all time).
        $officeStats = DB::table('orders')->where('orders.channel', SalesChannel::OFFICE)->whereIn('orders.customer', $customers->pluck('name'))
            ->leftJoinSub(DB::table('order_items')->groupBy('order_id')->selectRaw('order_id, sum(qty * price) AS total'), 'i', 'i.order_id', '=', 'orders.id')
            ->groupBy('orders.customer')->selectRaw('orders.customer, count(*) AS orders, coalesce(sum(i.total), 0) AS turnover')->get()->keyBy('customer');

        return Inertia::render('Customers', [
            'persons' => $customers->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
                'is_legal' => $c->isLegal(),
                'inn' => $c->inn,
                'phone' => $c->phone,
                'source' => $c->salesChannel->name,
                'turnover' => (float) ($officeStats[$c->name]->turnover ?? 0),
                'orders' => (int) ($officeStats[$c->name]->orders ?? 0),
                'lock_version' => $c->lock_version,
            ])->values(),
            // Legacy recalcAllCustomerTurnover counts a dealer's orders for the chosen period: the orders of its channel with
            // their «Срок», total and paid share; the page does the counting, as legacy does.
            'dealerOrders' => ChannelScope::apply(Order::query()->where('orders.channel', 'like', 'dealer\_%'), $request->user(), 'orders.channel')
                ->leftJoinSub(DB::table('order_items')->groupBy('order_id')->selectRaw('order_id, sum(qty * price) AS total'), 'i', 'i.order_id', '=', 'orders.id')
                ->get(['orders.channel', 'orders.due_date', 'orders.payment_pct', DB::raw('coalesce(i.total, 0) AS total')])
                ->map(fn (Order $o) => ['channel' => $o->channel, 'date' => $o->due_date?->format('Y-m-d'), 'total' => (float) $o->getAttribute('total'), 'payment' => $o->payment_pct])
                ->values(),
            'today' => now(config('erp.business_timezone'))->format('Y-m-d'),
            // Legacy dealersData: added dealers on top (unshift), then the five of the code in their order.
            'dealers' => ChannelScope::apply(SalesChannel::query()->dealers(), $request->user(), 'code')
                ->orderByDesc('created_at')->orderBy('sort_order')->get()
                ->map(fn (SalesChannel $d) => [
                    'code' => $d->code,
                    'name' => $d->name,
                    'region' => $d->region,
                    'lock_version' => $d->lock_version,
                ])->values(),
        ]);
    }

    public function store(CustomerRequest $request): RedirectResponse
    {
        Gate::authorize('create', Customer::class);
        $this->service->create($request->fields(), $request->user());

        return back();
    }

    public function update(CustomerRequest $request, int $customer): RedirectResponse
    {
        $this->service->update($customer, $request->fields(), (int) $request->validated('lock_version'), $request->user());

        return back();
    }
}
