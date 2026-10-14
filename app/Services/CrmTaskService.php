<?php

namespace App\Services;

use App\Models\CrmTask;
use App\Models\Lead;
use App\Models\User;
use App\Support\Audit;
use App\Support\ChannelScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * CRM tasks (M10, legacy saveTask/toggleTask/deleteTask). A task about a lead follows the lead's channel; changes check the
 * record version and go to the audit trail.
 */
class CrmTaskService
{
    public const STALE = 'Задача изменена другим пользователем — обновите страницу и повторите.';

    public const NOT_FOUND = 'Задача не найдена — обновите страницу.';

    public const LEAD_NOT_FOUND = 'Лид не найден — обновите страницу.';

    private const FIELDS = ['title', 'due_date', 'due_time', 'manager', 'priority', 'lead_id'];

    /**
     * Tasks the user may see: general ones and those about a lead of the user's channels.
     *
     * @return Builder<CrmTask>
     */
    public static function visible(User $user): Builder
    {
        $channels = ChannelScope::channelsFor($user);
        $query = CrmTask::query()->active();

        return $channels === null ? $query : $query->where(fn (Builder $q) => $q->whereNull('lead_id')
            ->orWhereIn('lead_id', Lead::query()->select('id')->whereIn('channel', $channels)));
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $by): CrmTask
    {
        return DB::transaction(function () use ($data, $by) {
            $this->checkLead($data['lead_id'] ?? null, $by);
            $task = new CrmTask(array_intersect_key($data, array_flip(self::FIELDS)) + ['status' => 'open']);
            $task->forceFill(['created_by_id' => $by->id, 'created_by_name' => $by->name, 'updated_by_id' => $by->id, 'updated_by_name' => $by->name])->save();
            Audit::record('crm_task.created', 'crm_task', $task->id, $task->only([...self::FIELDS, 'status']), $by);

            return $task;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data, int $lockVersion, User $by): CrmTask
    {
        return DB::transaction(function () use ($id, $data, $lockVersion, $by) {
            $task = $this->locked($id, $lockVersion, $by);
            if (array_key_exists('lead_id', $data) && $data['lead_id'] !== $task->lead_id) {
                $this->checkLead($data['lead_id'], $by);
            }
            $this->apply($task, array_intersect_key($data, array_flip(self::FIELDS)), 'crm_task.updated', $by);

            return $task;
        });
    }

    /** Legacy toggleTask: the round checkbox marks a task done or opens it again. */
    public function toggle(int $id, int $lockVersion, User $by): CrmTask
    {
        return DB::transaction(function () use ($id, $lockVersion, $by) {
            $task = $this->locked($id, $lockVersion, $by);
            $this->apply($task, ['status' => $task->status === 'done' ? 'open' : 'done'], 'crm_task.status_changed', $by);

            return $task;
        });
    }

    /** Legacy deleteTask set the status «deleted»; here the task is deactivated. */
    public function deactivate(int $id, int $lockVersion, User $by): CrmTask
    {
        return DB::transaction(function () use ($id, $lockVersion, $by) {
            $task = $this->locked($id, $lockVersion, $by);
            $task->forceFill(['active' => false, 'deactivated_at' => now(), 'updated_by_id' => $by->id, 'updated_by_name' => $by->name]);
            $task->lock_version++;
            $task->save();
            Audit::record('crm_task.deactivated', 'crm_task', $task->id, ['title' => $task->title], $by);

            return $task;
        });
    }

    private function checkLead(?int $leadId, User $by): void
    {
        if ($leadId === null) {
            return;
        }
        $lead = Lead::query()->active()->find($leadId);
        if ($lead === null || ! Gate::forUser($by)->allows('update', $lead)) {
            throw ValidationException::withMessages(['lead_id' => self::LEAD_NOT_FOUND]);
        }
    }

    /** @param array<string, mixed> $changes */
    private function apply(CrmTask $task, array $changes, string $action, User $by): void
    {
        $before = $task->only([...self::FIELDS, 'status']);
        $task->fill($changes);
        $diff = collect($task->getDirty())->map(fn ($new, $key) => ['from' => $before[$key] ?? null, 'to' => $task->{$key}])->all();
        if ($diff) {
            $task->forceFill(['updated_by_id' => $by->id, 'updated_by_name' => $by->name]);
            $task->lock_version++;
            $task->save();
            Audit::record($action, 'crm_task', $task->id, $diff, $by);
        }
    }

    private function locked(int $id, int $lockVersion, User $by): CrmTask
    {
        $task = self::visible($by)->whereKey($id)->lockForUpdate()->first();
        if ($task === null || ! Gate::forUser($by)->allows('update', $task)) {
            throw ValidationException::withMessages(['task' => self::NOT_FOUND]);
        }
        if ($task->lock_version !== $lockVersion) {
            throw ValidationException::withMessages(['lock_version' => self::STALE]);
        }

        return $task;
    }
}
