<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\SalesChannel;
use App\Models\User;
use App\Support\Audit;
use App\Support\ChannelScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** CRM leads (M10): channel scope on every change, lock_version against lost updates, audit trail. */
class LeadService
{
    public const STALE = 'Лид изменён другим пользователем — обновите страницу и повторите.';

    public const NOT_FOUND = 'Лид не найден — обновите страницу.';

    private const FIELDS = ['name', 'phone', 'status', 'interest', 'budget', 'source', 'comment', 'manager', 'segment', 'tags', 'next_date', 'next_action'];

    /**
     * Legacy saveLead: the lead belongs to the author's channel (getChannelFilter() || 'office'); the manager is the author
     * unless the funnel form names one.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $by): Lead
    {
        $channel = ChannelScope::filterFor($by) ?? SalesChannel::OFFICE;
        if ($channel === ChannelScope::SALONS) {
            // Legacy would store the marker itself; a lead belongs to one salon.
            throw ValidationException::withMessages(['channel' => 'Лида салона заводит сотрудник этого салона.']);
        }

        return DB::transaction(function () use ($data, $channel, $by) {
            $lead = new Lead(array_intersect_key($data, array_flip(self::FIELDS)) + ['status' => 'new', 'manager' => $by->name]);
            $lead->forceFill(['channel' => $channel, 'created_by_id' => $by->id, 'created_by_name' => $by->name, 'updated_by_id' => $by->id, 'updated_by_name' => $by->name])->save();
            Audit::record('lead.created', 'lead', $lead->id, $lead->only([...self::FIELDS, 'channel']), $by);

            return $lead;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data, int $lockVersion, User $by): Lead
    {
        return DB::transaction(function () use ($id, $data, $lockVersion, $by) {
            $lead = $this->locked($id, $lockVersion, $by);
            $this->apply($lead, array_intersect_key($data, array_flip(self::FIELDS)), 'lead.updated', $by);

            return $lead;
        });
    }

    /**
     * Legacy quickChangeLeadStatus / crmDrop / leadWon / leadLost. «Проиграли» adds «Отказ: <причина>» to the comment — legacy
     * replaced the comment with it, losing what was written before.
     */
    public function changeStatus(int $id, string $status, ?string $lostReason, int $lockVersion, User $by): Lead
    {
        if (! array_key_exists($status, config('erp.lead_statuses'))) {
            throw ValidationException::withMessages(['status' => 'Неизвестный статус лида']);
        }

        return DB::transaction(function () use ($id, $status, $lostReason, $lockVersion, $by) {
            $lead = $this->locked($id, $lockVersion, $by);
            $changes = ['status' => $status];
            if ($status === 'lost') {
                $note = trim((string) $lostReason) !== '' ? 'Отказ: '.trim((string) $lostReason) : 'Отказ';
                $changes['comment'] = $lead->comment ? $lead->comment."\n".$note : $note;
            }
            $this->apply($lead, $changes, 'lead.status_changed', $by);

            return $lead;
        });
    }

    /**
     * Legacy convertLeadToClient: the lead becomes a customer of its channel («Конвертирован из лида») and is won — both or
     * neither (legacy wrote them separately, so a failed write could leave one without the other).
     */
    public function convertToClient(int $id, int $lockVersion, User $by): Customer
    {
        return DB::transaction(function () use ($id, $lockVersion, $by) {
            $lead = $this->locked($id, $lockVersion, $by);
            if (in_array($lead->status, Lead::CLOSED, true)) {
                throw ValidationException::withMessages(['lead' => 'Лид уже закрыт — обновите страницу.']);
            }
            $customer = new Customer(['name' => $lead->name, 'phone' => $lead->phone, 'type' => 'client', 'comment' => 'Конвертирован из лида']);
            $customer->forceFill(['channel' => $lead->channel, 'created_by_id' => $by->id, 'created_by_name' => $by->name, 'updated_by_id' => $by->id, 'updated_by_name' => $by->name])->save();
            Audit::record('customer.created', 'customer', $customer->id, ['name' => $customer->name, 'channel' => $customer->channel, 'from_lead' => $lead->id], $by);
            $this->apply($lead, ['status' => 'won'], 'lead.converted', $by);

            return $customer;
        });
    }

    /** The funnel form's «Удалить»: legacy removed the lead from the page only; here it is deactivated. */
    public function deactivate(int $id, int $lockVersion, User $by): Lead
    {
        return DB::transaction(function () use ($id, $lockVersion, $by) {
            $lead = $this->locked($id, $lockVersion, $by);
            $lead->forceFill(['active' => false, 'deactivated_at' => now(), 'updated_by_id' => $by->id, 'updated_by_name' => $by->name]);
            $lead->lock_version++;
            $lead->save();
            Audit::record('lead.deactivated', 'lead', $lead->id, ['name' => $lead->name], $by);

            return $lead;
        });
    }

    /** @param array<string, mixed> $changes */
    private function apply(Lead $lead, array $changes, string $action, User $by): void
    {
        $before = $lead->only(self::FIELDS);
        $lead->fill($changes);
        $diff = collect($lead->getDirty())->map(fn ($new, $key) => ['from' => $before[$key] ?? null, 'to' => $lead->{$key}])->all();
        if ($diff) {
            $lead->forceFill(['updated_by_id' => $by->id, 'updated_by_name' => $by->name]);
            $lead->lock_version++;
            $lead->save();
            Audit::record($action, 'lead', $lead->id, $diff, $by);
        }
    }

    private function locked(int $id, int $lockVersion, User $by): Lead
    {
        $lead = Lead::query()->active()->whereKey($id)->lockForUpdate()->first();
        if ($lead === null || ! Gate::forUser($by)->allows('update', $lead)) {
            // Another channel's lead is reported exactly like a missing one (AC-11).
            throw ValidationException::withMessages(['lead' => self::NOT_FOUND]);
        }
        if ($lead->lock_version !== $lockVersion) {
            throw ValidationException::withMessages(['lock_version' => self::STALE]);
        }

        return $lead;
    }
}
