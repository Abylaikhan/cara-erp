<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadCommunication;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Communication history of a lead (M10, legacy saveCommEntry/deleteCommEntry of the lead card). As in legacy, an entry is
 * signed with the lead's manager; the author is kept as well.
 */
class LeadCommunicationService
{
    public const NOT_FOUND = 'Запись не найдена — обновите страницу.';

    /** @param array{type: string, happened_on: ?string, text: string} $data */
    public function add(int $leadId, array $data, User $by): LeadCommunication
    {
        return DB::transaction(function () use ($leadId, $data, $by) {
            $lead = $this->lead($leadId, $by);
            $entry = new LeadCommunication($data + ['manager' => $lead->manager]);
            $entry->lead_id = $lead->id;
            $entry->forceFill(['created_by_id' => $by->id, 'created_by_name' => $by->name, 'updated_by_id' => $by->id, 'updated_by_name' => $by->name])->save();
            Audit::record('lead_communication.created', 'lead', $lead->id, ['communication' => $entry->id, 'type' => $entry->type], $by);

            return $entry;
        });
    }

    /** Legacy ✕ of a history entry (it never worked there: the lead id was not quoted). */
    public function deactivate(int $id, User $by): LeadCommunication
    {
        return DB::transaction(function () use ($id, $by) {
            $entry = LeadCommunication::query()->active()->whereKey($id)->lockForUpdate()->first();
            if ($entry === null || ! Gate::forUser($by)->allows('update', $entry->lead)) {
                throw ValidationException::withMessages(['communication' => self::NOT_FOUND]);
            }
            $entry->forceFill(['active' => false, 'deactivated_at' => now(), 'updated_by_id' => $by->id, 'updated_by_name' => $by->name])->save();
            Audit::record('lead_communication.deactivated', 'lead', $entry->lead_id, ['communication' => $entry->id, 'type' => $entry->type], $by);

            return $entry;
        });
    }

    public function lead(int $leadId, User $by): Lead
    {
        $lead = Lead::query()->active()->find($leadId);
        if ($lead === null || ! Gate::forUser($by)->allows('update', $lead)) {
            throw ValidationException::withMessages(['lead' => LeadService::NOT_FOUND]);
        }

        return $lead;
    }
}
