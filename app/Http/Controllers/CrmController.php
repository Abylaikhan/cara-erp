<?php

namespace App\Http\Controllers;

use App\Http\Requests\Crm\LeadRequest;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadService;
use App\Support\ChannelScope;
use App\Support\Erp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M10 CRM — legacy page-crm (L4134–5346): tabs «Лиды» (loadLeadsFromDB/renderLeadsTab/saveLead/quickChangeLeadStatus/
 * convertLeadToClient) and «Воронка лидов» (renderCrmKanban/crmDrop/leadWon/leadLost/saveCrmLead). Leads are limited to the
 * channels the user may see. Clients, order boards and tasks wait for orders (M09) and the next part.
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
            // Managers to choose in the funnel form: the CRM users and whoever already leads a visible lead.
            'managers' => $crmUsers->merge($leads->pluck('manager')->filter())->unique()->sort()->values(),
            'currentYear' => (int) now(config('erp.business_timezone'))->format('Y'),
        ]);
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
