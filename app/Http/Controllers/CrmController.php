<?php

namespace App\Http\Controllers;

use App\Http\Requests\Crm\LeadRequest;
use App\Models\CrmTask;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Project;
use App\Models\ProjectCalc;
use App\Models\ProjectComment;
use App\Models\ProjectFile;
use App\Models\SalesChannel;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\CrmTaskService;
use App\Services\LeadService;
use App\Services\ProjectService;
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
 * to the channels the user may see, tasks about a lead follow the lead. A dealer account and the head of the shop see only
 * the tab «Проекты» (legacy prjApplyRole), so nothing of the other tabs is sent to them.
 */
class CrmController extends Controller
{
    public function __construct(private readonly LeadService $service) {}

    public function index(Request $request): Response
    {
        $projectsOnly = Erp::crmProjectsOnly($request->user());
        // Legacy loads leads newest first (order=created_at.desc).
        $leads = $projectsOnly ? collect()
            : ChannelScope::apply(Lead::query()->active(), $request->user())->orderByDesc('created_at')->orderByDesc('id')->get();
        $crmUsers = $projectsOnly ? collect()
            : User::query()->where('is_active', true)->orderBy('name')->get()->filter(fn (User $u) => Gate::forUser($u)->allows('viewAny', Lead::class))->pluck('name');
        // Legacy loads tasks newest first (order=created_at.desc); the tab sorts them itself.
        $tasks = $projectsOnly ? collect() : CrmTaskService::visible($request->user())->orderByDesc('created_at')->orderByDesc('id')->get();

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
            // Managers to choose in the funnel form: the CRM users and whoever already leads a visible lead or task.
            'managers' => $crmUsers->merge($leads->pluck('manager')->filter())->merge($tasks->pluck('manager')->filter())->unique()->sort()->values(),
            // The task form: the salon staff of «Сотрудники», as legacy openTaskModal lists them.
            'taskManagers' => $projectsOnly ? [] : $this->taskManagers(),
            'currentYear' => (int) now(config('erp.business_timezone'))->format('Y'),
            'timezone' => config('erp.business_timezone'),
            // Tab «Клиенты» (legacy renderClientsTab/openClientCard): the customers of the salons are the end clients of
            // their orders. Their number is shown on the tab at once; the orders are loaded when the tab is opened.
            'clientsCount' => fn () => $projectsOnly ? 0 : $this->salonClientsCount($request->user()),
            'clientOrders' => Inertia::optional(fn () => $projectsOnly ? [] : $this->clientOrders($request->user())),
            // Tabs «По производству» and «По оплате» (legacy renderOrdersKanban): orders that are not cancelled, with their
            // total and mean progress — loaded when one of the boards is opened.
            'boardOrders' => Inertia::optional(fn () => $projectsOnly ? [] : $this->boardOrders($request->user())),
            // Legacy hides the channel filter of the boards from a salon or dealer account (getChannelFilter()).
            'channelBound' => ChannelScope::filterFor($request->user()) !== null,
            'today' => now(config('erp.business_timezone'))->format('Y-m-d'),
            'projectsOnly' => $projectsOnly,
            // Tab «Проекты» (legacy loadProjects/renderProjects): the number of open projects at once, the projects with their
            // calculations, files and messages when the tab is opened. The customer gets neither cost prices, nor unsent
            // calculations, nor internal files and messages.
            'projectsCount' => fn () => ProjectService::visible($request->user())->whereIn('status', Project::OPEN_STATUSES)->count(),
            'projects' => Inertia::optional(fn () => $this->projects($request->user())),
            'projectMeta' => fn () => $this->projectMeta($request->user()),
        ]);
    }

    /** @return array<string, mixed> */
    private function projectMeta(User $user): array
    {
        $client = ProjectPolicy::isClient($user);
        $channels = ChannelScope::channelsFor($user);

        return [
            'client' => $client,
            'admin' => ProjectPolicy::isAdmin($user),
            'login' => $user->login,
            // Legacy SALES_CHANNELS: the customers to choose for a new project (only those the user may see).
            'channels' => SalesChannel::query()->ordered()->get(['code', 'name', 'channel_group'])
                ->map(fn (SalesChannel $c) => ['code' => $c->code, 'name' => $c->name, 'group' => $c->channel_group, 'own' => $channels === null || in_array($c->code, $channels, true)])->values(),
            // Legacy _USERS without a channel: who may be an executor (staff only see this list).
            'staff' => $client ? [] : User::query()->where('is_active', true)->orderBy('name')->get()
                ->filter(fn (User $u) => Erp::channelOf($u) === null)
                ->map(fn (User $u) => ['login' => $u->login, 'name' => $u->name, 'label' => Erp::role($u->role)['label'] ?? ''])->values(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function projects(User $user)
    {
        $client = ProjectPolicy::isClient($user);
        $projects = ProjectService::visible($user)->orderByDesc('created_at')->orderByDesc('id')
            ->with(['calcs' => fn ($q) => $q->orderBy('version'), 'files' => fn ($q) => $q->orderBy('created_at')->orderBy('id'), 'comments' => fn ($q) => $q->orderBy('created_at')->orderBy('id')])
            ->get();
        $names = User::query()->whereIn('login', $projects->flatMap(fn (Project $p) => $p->executors)->unique())->pluck('name', 'login');
        $iso = fn ($t) => $t?->toIso8601String();

        return $projects->map(fn (Project $p) => [
            'id' => $p->id,
            'num' => $p->num,
            'title' => $p->title,
            'channel' => $p->channel,
            'status' => $p->status,
            'customer_name' => $p->customer_name,
            'description' => $p->description,
            'due_date' => $p->due_date?->format('Y-m-d'),
            'executors' => collect($p->executors)->map(fn (string $l) => ['login' => $l, 'name' => $names[$l] ?? $l])->values(),
            'responsible_name' => $p->responsible_name,
            'order_num' => $p->order_num,
            'total_amount' => $p->total_amount,
            'created_at' => $iso($p->created_at),
            'updated_at' => $iso($p->updated_at),
            'lock_version' => $p->lock_version,
            'calcs' => $p->calcs->filter(fn (ProjectCalc $c) => ! $client || $c->sent_at !== null)->map(fn (ProjectCalc $c) => [
                'version' => $c->version,
                'items' => collect($c->items)->map(fn (array $i) => $client ? collect($i)->except('cost')->all() : $i)->values(),
                'total' => $c->total,
                'markup_pct' => $client ? null : $c->markup_pct,
                'sent_at' => $iso($c->sent_at),
                'created_by_name' => $client ? null : $c->created_by_name,
            ])->values(),
            'files' => $p->files->filter(fn (ProjectFile $f) => ! $client || ! $f->internal)->map(fn (ProjectFile $f) => [
                'id' => $f->id,
                'side' => $f->side,
                'internal' => $f->internal,
                'file_name' => $f->file_name,
                'image' => $f->isImage(),
                'size' => $f->size,
                'uploaded_by_login' => $f->uploaded_by_login,
                'uploaded_by_name' => $f->uploaded_by_name,
                'created_at' => $iso($f->created_at),
            ])->values(),
            'comments' => $p->comments->filter(fn (ProjectComment $c) => ! $client || ! $c->internal)->map(fn (ProjectComment $c) => [
                'id' => $c->id,
                'side' => $c->side,
                'internal' => $c->internal,
                'text' => $c->text,
                'author_name' => $c->author_name ?? $c->author_login,
                'created_at' => $iso($c->created_at),
            ])->values(),
        ])->values();
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

    /** @return Collection<int, array<string, mixed>> */
    private function boardOrders(User $user)
    {
        return ChannelScope::apply(Order::query()->where('orders.status', '!=', 'cancelled'), $user, 'orders.channel')
            ->leftJoinSub(DB::table('order_items')->groupBy('order_id')
                ->selectRaw('order_id, sum(qty * price) AS total, sum(progress) AS progress_sum, count(*) AS lines'), 'i', 'i.order_id', '=', 'orders.id')
            ->orderByDesc('orders.created_at')->orderByDesc('orders.id')
            ->get(['orders.*', DB::raw('coalesce(i.total, 0) AS total'), DB::raw('coalesce(i.progress_sum, 0) AS progress_sum'), DB::raw('coalesce(i.lines, 0) AS lines')])
            ->map(fn (Order $o) => [
                'num' => $o->num,
                'channel' => $o->channel,
                'customer' => $o->customer,
                'end_client' => $o->end_client,
                'created' => $o->order_date?->format('d.m.Y'),
                'date' => $o->due_date?->format('d.m.Y'),
                'total' => (float) $o->getAttribute('total'),
                'payment' => $o->payment_pct,
                'status' => $o->status,
                'progress' => (int) round((int) $o->getAttribute('progress_sum') / max((int) $o->getAttribute('lines'), 1), 0, PHP_ROUND_HALF_UP),
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

    /**
     * Legacy openTaskModal/fillSellers: the staff (as loadStaffFromDB orders it, by name) whose position says «Менеджер» or
     * «салон» or whose comment names a salon; when there is none, the whole staff. The form labels each with the comment, or
     * else the position.
     *
     * @return Collection<int, array{name: string, pos: string, comment: string}>
     */
    private function taskManagers(): Collection
    {
        $staff = Employee::query()->orderBy('name')->orderBy('id')->get(['name', 'position', 'comment'])
            ->map(fn (Employee $e) => ['name' => $e->name, 'pos' => (string) $e->getAttribute('position'), 'comment' => (string) $e->getAttribute('comment')]);
        $salon = $staff->filter(fn (array $e) => str_contains($e['pos'], 'Менеджер') || str_contains($e['pos'], 'салон')
            || str_contains($e['comment'], 'Армада') || str_contains($e['comment'], 'Назарбаева'));

        return ($salon->isNotEmpty() ? $salon : $staff)->values();
    }
}
