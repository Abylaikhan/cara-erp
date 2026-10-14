<?php

namespace Tests\Feature\Crm;

use App\Models\AuditEvent;
use App\Models\CrmTask;
use App\Models\Lead;
use App\Models\User;
use App\Services\CrmTaskService;
use App\Services\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M10 CRM, «Задачи»: tasks persist with date, time, manager, priority and lead; a task about a lead follows its channel. */
class CrmTaskTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->role('manager')->create(['name' => 'Демо Продажи']);
    }

    private function lead(array $attributes = [], ?User $by = null): Lead
    {
        return app(LeadService::class)->create($attributes + ['name' => 'Демо Лид'], $by ?? $this->manager);
    }

    private function task(array $attributes = [], ?User $by = null): CrmTask
    {
        return app(CrmTaskService::class)->create($attributes + ['title' => 'Демо задача', 'priority' => 'normal'], $by ?? $this->manager);
    }

    public function test_form_creates_and_edits_a_task(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->manager)->from('/crm');
        $this->post('/crm/tasks', ['title' => '  ', 'priority' => 'normal'])->assertSessionHasErrors(['title' => 'Укажите описание задачи']);
        $this->post('/crm/tasks', [
            'title' => ' Позвонить клиенту ', 'due_date' => '2026-10-20', 'due_time' => '10:00', 'manager' => 'Демо Менеджер', 'priority' => 'high', 'lead_id' => (string) $lead->id,
        ])->assertRedirect('/crm')->assertSessionHasNoErrors();

        $task = CrmTask::sole();
        $this->assertSame(['Позвонить клиенту', '2026-10-20', '10:00', 'Демо Менеджер', 'high', 'open', $lead->id, 1, 'Демо Продажи'],
            [$task->title, $task->due_date->format('Y-m-d'), $task->timeLabel(), $task->manager, $task->priority, $task->status, $task->lead_id, $task->lock_version, $task->created_by_name]);
        $this->assertDatabaseHas('audit_events', ['action' => 'crm_task.created', 'subject_id' => (string) $task->id]);

        $this->get('/crm')->assertInertia(fn (Assert $page) => $page
            ->where('tasks.0.title', 'Позвонить клиенту')
            ->where('tasks.0.due_time', '10:00')
            ->where('tasks.0.lead_id', $lead->id)
            ->where('managers', fn ($m) => collect($m)->contains('Демо Менеджер')));

        // Same time again is not a change; the general task keeps no date.
        $this->put("/crm/tasks/{$task->id}", ['title' => 'Позвонить клиенту', 'due_date' => '2026-10-20', 'due_time' => '10:00', 'manager' => 'Демо Менеджер', 'priority' => 'high', 'lead_id' => $lead->id, 'lock_version' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $task->fresh()->lock_version);
        $this->put("/crm/tasks/{$task->id}", ['title' => 'Отправить КП', 'due_date' => '', 'due_time' => '', 'manager' => '', 'priority' => '', 'lead_id' => '', 'lock_version' => 1])
            ->assertSessionHasNoErrors();
        $task->refresh();
        $this->assertSame(['Отправить КП', null, null, null, 'normal', null, 'open', 2], [$task->title, $task->due_date, $task->due_time, $task->manager, $task->priority, $task->lead_id, $task->status, $task->lock_version]);

        $this->put("/crm/tasks/{$task->id}", ['title' => 'Демо', 'priority' => 'urgent', 'lock_version' => 2])->assertSessionHasErrors('priority');
        $this->put("/crm/tasks/{$task->id}", ['title' => 'Демо', 'due_time' => '25:00', 'lock_version' => 2])->assertSessionHasErrors(['due_time' => 'Время напоминания — в формате ЧЧ:ММ']);
        $this->put("/crm/tasks/{$task->id}", ['title' => 'Демо', 'due_date' => '20.10.2026', 'lock_version' => 2])->assertSessionHasErrors('due_date');
    }

    public function test_checkbox_marks_done_and_reopens_and_delete_hides_the_task(): void
    {
        $task = $this->task();
        $this->actingAs($this->manager)->from('/crm');

        $this->put("/crm/tasks/{$task->id}/toggle", ['lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame(['done', 2], [$task->fresh()->status, $task->fresh()->lock_version]);
        $this->put("/crm/tasks/{$task->id}/toggle", ['lock_version' => 2])->assertSessionHasNoErrors();
        $this->assertSame('open', $task->fresh()->status);
        $this->assertSame(2, AuditEvent::where('action', 'crm_task.status_changed')->count());

        $this->put("/crm/tasks/{$task->id}/toggle", ['lock_version' => 1])->assertSessionHasErrors(['lock_version' => CrmTaskService::STALE]);

        $this->delete("/crm/tasks/{$task->id}", ['lock_version' => 3])->assertSessionHasNoErrors();
        $this->assertFalse($task->fresh()->active);
        $this->get('/crm')->assertInertia(fn (Assert $page) => $page->where('tasks', []));
        $this->put("/crm/tasks/{$task->id}/toggle", ['lock_version' => 4])->assertSessionHasErrors(['task' => CrmTaskService::NOT_FOUND]);
    }

    public function test_a_task_about_a_lead_follows_the_lead_channel(): void
    {
        $armada = User::factory()->role('salon_armada')->create();
        $nazarbayeva = User::factory()->role('salon_nazarbayeva')->create();
        $armadaLead = $this->lead(['name' => 'Демо Армада'], $armada);
        $officeLead = $this->lead(['name' => 'Демо Офис']);
        $this->task(['title' => 'По лиду Армады', 'lead_id' => $armadaLead->id]);
        $officeTask = $this->task(['title' => 'По лиду офиса', 'lead_id' => $officeLead->id]);
        $this->task(['title' => 'Общая']);
        $titles = fn (User $u) => collect($this->actingAs($u)->get('/crm')->viewData('page')['props']['tasks'])->pluck('title')->sort()->values()->all();

        $this->assertSame(['Общая', 'По лиду Армады', 'По лиду офиса'], $titles($this->manager));
        $this->assertSame(['Общая', 'По лиду Армады'], $titles($armada));
        $this->assertSame(['Общая'], $titles($nazarbayeva));

        // Another channel's task is reported as missing; a lead of another channel cannot be attached.
        $this->actingAs($armada)->from('/crm');
        $this->put("/crm/tasks/{$officeTask->id}/toggle", ['lock_version' => 1])->assertSessionHasErrors(['task' => CrmTaskService::NOT_FOUND]);
        $this->post('/crm/tasks', ['title' => 'Чужой лид', 'lead_id' => $officeLead->id])->assertSessionHasErrors(['lead_id' => CrmTaskService::LEAD_NOT_FOUND]);
        $this->post('/crm/tasks', ['title' => 'Свой лид', 'lead_id' => $armadaLead->id])->assertSessionHasNoErrors();
        $this->assertSame('open', $officeTask->fresh()->status);
    }

    public function test_roles_without_crm_cannot_reach_tasks(): void
    {
        $task = $this->task();
        $this->actingAs(User::factory()->role('storekeeper')->create());
        $this->post('/crm/tasks', ['title' => 'Демо'])->assertForbidden();
        $this->put("/crm/tasks/{$task->id}/toggle", ['lock_version' => 1])->assertForbidden();
        $this->delete("/crm/tasks/{$task->id}", ['lock_version' => 1])->assertForbidden();
        $this->assertSame(['open', true, 1], [$task->fresh()->status, $task->fresh()->active, CrmTask::count()]);
    }
}
