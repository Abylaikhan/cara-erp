<?php

namespace App\Http\Controllers;

use App\Http\Requests\Crm\LeadRequest;
use App\Models\CrmTask;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use App\Services\CrmTaskService;
use App\Services\LeadService;
use App\Support\ChannelScope;
use App\Support\Erp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M10 CRM — legacy page-crm (L4134–5346): tabs «Лиды» (loadLeadsFromDB/renderLeadsTab/saveLead/quickChangeLeadStatus/
 * convertLeadToClient), «Воронка лидов» (renderCrmKanban/crmDrop/leadWon/leadLost/saveCrmLead, the lead card) and «Задачи»
 * (loadTasksFromDB/renderTasks), «Клиенты» (renderClientsTab/openClientCard, from the orders). Leads and orders are limited
 * to the channels the user may see, tasks about a lead follow the lead. The order boards come next.
 */
class CrmController extends Controller
{
    public function __construct(private readonly LeadService $service) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Lead::class);
        // Legacy loads leads newest first (order=created_at.desc).
        $leads = ChannelScope::apply(Lead::query()->active(), $request->user())->orderByDesc('created_at')->orderByDesc('id')->get();
        $crmUsers = User::query()->where('is_active', true)->orderBy('name')->get()->filter(fn (User $u) => Erp::canAccess($u, 'crm'))->pluck('name');
        // Legacy loads tasks newest first (order=created_at.desc); the tab sorts them itself.
        $tasks = CrmTaskService::visible($request->user())->orderByDesc('created_at')->orderByDesc('id')->get();

        return Inertia::render('Crm', [
            'leads' => $leads->map(fn (Lead $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'phone' => $l->phone,
                'status' => $l->status,
                'interest' => $l->interest,
                'budget' => (float) $l->budget,
                'source' => $l->source,
                'comment' => $l->comment,
                'manager' => $l->manager,
                'segment' => $l->segment,
                'tags' => $l->tags ?? [],
                'next_date' => $l->next_date?->format('Y-m-d'),
                'next_action' => $l->next_action,
                'created_at' => $l->created_at?->toIso8601String(),
                'lock_version' => $l->lock_version,
            ])->values(),
            'statuses' => config('erp.lead_statuses'),
            'sources' => config('erp.lead_sources'),
            'segments' => collect(config('erp.lead_segments'))->map(fn (array $s, int $id) => ['id' => $id] + $s)->values(),
            'funnelStages' => collect(config('erp.funnel_stages'))->map(fn (array $s, string $key) => ['key' => $key] + $s)->values(),
            'stageOrder' => config('erp.stage_order'),
            'tasks' => $tasks->map(fn (CrmTask $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'due_date' => $t->due_date?->format('Y-m-d'),
                'due_time' => $t->timeLabel(),
                'manager' => $t->manager,
                'priority' => $t->priority,
                'status' => $t->status,
                'lead_id' => $t->lead_id,
                'lock_version' => $t->lock_version,
            ])->values(),
            // Managers to choose in the funnel and task forms: the CRM users and whoever already leads a visible lead or task.
            'managers' => $crmUsers->merge($leads->pluck('manager')->filter())->merge($tasks->pluck('manager')->filter())->unique()->sort()->values(),
            'currentYear' => (int) now(config('erp.business_timezone'))->format('Y'),
            'timezone' => config('erp.business_timezone'),
            // Tab «Клиенты» (legacy renderClientsTab/openClientCard): the customers of the salons are the end clients of
            // their orders. Their number is shown on the tab at once; the orders are loaded when the tab is opened.
            'clientsCount' => fn () => $this->salonClientsCount($request->user()),
            'clientOrders' => Inertia::optional(fn () => $this->clientOrders($request->user())),
        ]);
    }

    /** Legacy renderClientsTab: distinct trimmed end clients of the salon orders, «NaT» (an empty cell of an import) aside. */
    private function salonClientsCount(User $user): int
    {
        return (int) ChannelScope::apply(Order::query()->where('channel', 'like', 'salon\_%'), $user)
            ->whereNotNull('end_client')->whereRaw("btrim(end_client) NOT IN ('', 'NaT')")
            ->selectRaw('count(DISTINCT btrim(end_client)) AS n')->value('n');
    }

    /**
     * Orders with an end client, for the tab and the client card: the card lists every order of the client, the tab
     * counts those of the salons.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function clientOrders(User $user)
    {
        $orders = ChannelScope::apply(Order::query()->whereNotNull('end_client'), $user)
            ->leftJoinSub(DB::table('order_items')->groupBy('order_id')->selectRaw('order_id, sum(qty * price) AS total'), 'i', 'i.order_id', '=', 'orders.id')
            ->orderByDesc('orders.created_at')->orderByDesc('orders.id')
            ->get(['orders.*', DB::raw('coalesce(i.total, 0) AS total')]);
        $products = DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))->orderBy('order_id')->orderBy('line_no')->get(['order_id', 'name'])
            ->groupBy('order_id');

        return $orders->map(fn (Order $o) => [
            'num' => $o->num,
            'channel' => $o->channel,
            'end_client' => $o->end_client,
            'seller' => $o->seller,
            'created' => $o->order_date?->format('d.m.Y'),
            'date' => $o->due_date?->format('d.m.Y'),
            'total' => (float) $o->getAttribute('total'),
            'payment' => $o->payment_pct,
            'status' => $o->status,
            'products' => ($products[$o->id] ?? collect())->pluck('name')->values(),
        ])->values();
    }

    public function store(LeadRequest $request): RedirectResponse
    {
        Gate::authorize('create', Lead::class);
        $this->service->create($request->fields(), $request->user());

        return back();
    }

    public function update(LeadRequest $request, int $lead): RedirectResponse
    {
        $this->service->update($lead, $request->fields(), (int) $request->validated('lock_version'), $request->user());

        return back();
    }

    public function status(Request $request, int $lead): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(config('erp.lead_statuses')))],
            'reason' => ['nullable', 'string', 'max:500'],
            'lock_version' => ['required', 'integer'],
        ]);
        $this->service->changeStatus($lead, $data['status'], $data['reason'] ?? null, (int) $data['lock_version'], $request->user());

        return back();
    }

    public function client(Request $request, int $lead): RedirectResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer']]);
        $customer = $this->service->convertToClient($lead, (int) $data['lock_version'], $request->user());

        return back()->with('dialog', ['type' => 'alert', 'text' => "✅ {$customer->name} добавлен в клиенты!"]);
    }

    public function destroy(Request $request, int $lead): RedirectResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer']]);
        $this->service->deactivate($lead, (int) $data['lock_version'], $request->user());

        return back();
    }
}
