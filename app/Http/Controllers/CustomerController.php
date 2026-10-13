<?php

namespace App\Http\Controllers;

use App\Http\Requests\Customers\CustomerRequest;
use App\Models\Customer;
use App\Services\CustomerService;
use App\Support\ChannelScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** M08 «Покупатели» — legacy page-customers (L3640), tab «Контрагенты»: loadCustomersFromDB/renderPersons/openPersonModal/savePerson. */
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

        return Inertia::render('Customers', [
            'persons' => $customers->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
                'is_legal' => $c->isLegal(),
                'inn' => $c->inn,
                'phone' => $c->phone,
                'source' => $c->salesChannel->name,
                'lock_version' => $c->lock_version,
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
