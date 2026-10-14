<?php

namespace App\Http\Controllers;

use App\Http\Requests\Crm\LeadCommunicationRequest;
use App\Models\LeadCommunication;
use App\Services\LeadCommunicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** M10 lead card «История коммуникации» — legacy renderCommTimeline/saveCommEntry/deleteCommEntry (L29416–29484). */
class LeadCommunicationController extends Controller
{
    public function __construct(private readonly LeadCommunicationService $service) {}

    /** The history of one lead, oldest first (the card shows it newest first, as legacy reverses it). */
    public function index(Request $request, int $lead): JsonResponse
    {
        $lead = $this->service->lead($lead, $request->user());

        return response()->json(LeadCommunication::query()->active()->where('lead_id', $lead->id)->orderBy('id')->get()
            ->map(fn (LeadCommunication $c) => [
                'id' => $c->id,
                'type' => $c->type,
                'happened_on' => $c->happened_on?->format('Y-m-d'),
                'text' => $c->text,
                'manager' => $c->manager,
            ])->values());
    }

    public function store(LeadCommunicationRequest $request, int $lead): RedirectResponse
    {
        $this->service->add($lead, $request->validated(), $request->user());

        return back();
    }

    public function destroy(Request $request, int $communication): RedirectResponse
    {
        $this->service->deactivate($communication, $request->user());

        return back();
    }
}
