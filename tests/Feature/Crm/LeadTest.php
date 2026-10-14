<?php

namespace Tests\Feature\Crm;

use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M10 CRM, «Лиды» and «Воронка лидов»: leads persist, follow the channel scope, lock version, status actions, rights. */
class LeadTest extends TestCase
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

    public function test_page_lists_leads_newest_first_with_the_legacy_dictionaries(): void
    {
        $older = $this->lead(['name' => 'Демо Первый']);
        $older->forceFill(['created_at' => now()->subDay()])->save();
        $this->lead(['name' => 'Демо Второй', 'budget' => '1500.50', 'tags' => ['опт'], 'segment' => 3, 'next_date' => '2026-10-20']);

        $this->actingAs($this->manager)->get('/crm')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Crm')
            ->where('leads.0.name', 'Демо Второй')
            ->where('leads.0.budget', 1500.5)
            ->where('leads.0.tags', ['опт'])
            ->where('leads.0.segment', 3)
            ->where('leads.0.next_date', '2026-10-20')
            ->where('leads.0.status', 'new')
            ->where('leads.1.name', 'Демо Первый')
            ->where('statuses.won.label', '✅ Выиграли')
            ->where('sources', fn ($s) => collect($s)->keys()->all() === ['instagram', 'referral', 'walk-in', 'call', 'site', 'other'])
            ->where('segments', fn ($s) => collect($s)->pluck('id')->all() === [1, 2, 3, 4, 5, 6, 7])
            ->where('funnelStages', fn ($s) => collect($s)->pluck('key')->all() === ['qualify', 'proposal', 'negotiation'])
            ->where('managers', fn ($m) => collect($m)->contains('Демо Продажи'))
            ->has('currentYear'));
    }

    public function test_leads_follow_the_channel_scope(): void
    {
        $armada = User::factory()->role('salon_armada')->create();
        $nazarbayeva = User::factory()->role('salon_nazarbayeva')->create();
        $this->lead(['name' => 'Демо Офис']);
        $this->lead(['name' => 'Демо Армада'], $armada);
        $this->lead(['name' => 'Демо Назарбаева'], $nazarbayeva);
        $names = fn (User $u) => collect($this->actingAs($u)->get('/crm')->viewData('page')['props']['leads'])->pluck('name')->sort()->values()->all();

        $this->assertSame(['Демо Армада', 'Демо Назарбаева', 'Демо Офис'], $names(User::factory()->role('director')->create()));
        $this->assertSame(['Демо Армада'], $names($armada));
        $this->assertSame(['Демо Армада', 'Демо Назарбаева'], $names(User::factory()->role('salon_armada_mgr')->create()));
        $this->assertSame('salon_armada', Lead::where('name', 'Демо Армада')->sole()->channel);

        // Another channel's lead is reported as missing, whatever the action.
        $office = Lead::where('name', 'Демо Офис')->sole();
        $this->actingAs($armada)->from('/crm');
        $this->put("/crm/leads/{$office->id}", ['form' => 'leads', 'name' => 'Чужой', 'lock_version' => 1])->assertSessionHasErrors(['lead' => LeadService::NOT_FOUND]);
        $this->put("/crm/leads/{$office->id}/status", ['status' => 'won', 'lock_version' => 1])->assertSessionHasErrors(['lead' => LeadService::NOT_FOUND]);
        $this->post("/crm/leads/{$office->id}/client", ['lock_version' => 1])->assertSessionHasErrors(['lead' => LeadService::NOT_FOUND]);
        $this->delete("/crm/leads/{$office->id}", ['lock_version' => 1])->assertSessionHasErrors(['lead' => LeadService::NOT_FOUND]);
        $this->assertSame(['Демо Офис', 'new', true], [$office->fresh()->name, $office->fresh()->status, $office->fresh()->active]);
    }

    public function test_salons_manager_cannot_create_a_lead_without_a_salon(): void
    {
        $this->actingAs(User::factory()->role('salon_armada_mgr')->create())->from('/crm')
            ->post('/crm/leads', ['form' => 'leads', 'name' => 'Демо'])
            ->assertSessionHasErrors(['channel' => 'Лида салона заводит сотрудник этого салона.']);
        $this->assertSame(0, Lead::count());
    }

    public function test_leads_form_creates_and_edits_a_lead(): void
    {
        $this->actingAs($this->manager)->from('/crm');
        $this->post('/crm/leads', ['form' => 'leads', 'name' => '  ', 'status' => 'new'])->assertSessionHasErrors(['name' => 'Введите имя']);
        $this->post('/crm/leads', [
            'form' => 'leads', 'name' => ' Демо Покупатель ', 'phone' => ' +7 700 000 0000 ', 'status' => 'contact', 'interest' => 'Стулья',
            'budget' => '250000', 'source' => 'instagram', 'comment' => '',
        ])->assertRedirect('/crm')->assertSessionHasNoErrors();

        $lead = Lead::sole();
        $this->assertSame(['Демо Покупатель', '+7 700 000 0000', 'contact', 'Стулья', '250000.00', 'instagram', null, 'office', 'Демо Продажи', 1],
            [$lead->name, $lead->phone, $lead->status, $lead->interest, $lead->budget, $lead->source, $lead->comment, $lead->channel, $lead->manager, $lead->lock_version]);
        $this->assertDatabaseHas('audit_events', ['action' => 'lead.created', 'subject_id' => (string) $lead->id, 'user_id' => $this->manager->id]);

        $this->put("/crm/leads/{$lead->id}", ['form' => 'leads', 'name' => 'Демо Покупатель', 'status' => 'meeting', 'budget' => '', 'source' => 'call', 'lock_version' => 1])
            ->assertSessionHasNoErrors();
        $lead->refresh();
        $this->assertSame(['meeting', '0.00', 'call', 2], [$lead->status, $lead->budget, $lead->source, $lead->lock_version]);
        $this->assertEqualsCanonicalizing(['status', 'budget', 'source'], array_keys(AuditEvent::where('action', 'lead.updated')->sole()->changes));

        $this->put("/crm/leads/{$lead->id}", ['form' => 'leads', 'name' => 'Демо', 'budget' => '-1', 'lock_version' => 2])->assertSessionHasErrors(['budget' => 'Бюджет не может быть отрицательным']);
        $this->put("/crm/leads/{$lead->id}", ['form' => 'leads', 'name' => 'Демо', 'source' => 'radio', 'lock_version' => 2])->assertSessionHasErrors('source');
        $this->put("/crm/leads/{$lead->id}", ['form' => 'leads', 'name' => 'Демо', 'status' => 'archived', 'lock_version' => 2])->assertSessionHasErrors('status');
    }

    public function test_funnel_form_keeps_segment_tags_and_the_next_action(): void
    {
        $this->actingAs($this->manager)->from('/crm');
        $this->post('/crm/leads', ['form' => 'funnel', 'name' => ''])->assertSessionHasErrors(['name' => 'Укажите название клиента или компании']);
        $this->post('/crm/leads', [
            'form' => 'funnel', 'name' => 'ТОО «Демо»', 'phone' => '', 'budget' => '1200000.5', 'manager' => 'Демо Менеджер', 'source' => 'site', 'status' => 'proposal',
            'next_date' => '2026-11-02', 'next_action' => 'Отправить КП', 'segment' => 5, 'tags' => [' опт ', 'опт', '', 'Астана'], 'comment' => 'Демо',
        ])->assertSessionHasNoErrors();

        $lead = Lead::sole();
        $this->assertSame(['proposal', '1200000.50', 'Демо Менеджер', 5, ['опт', 'Астана'], '2026-11-02', 'Отправить КП', null],
            [$lead->status, $lead->budget, $lead->manager, $lead->segment, $lead->tags, $lead->next_date->format('Y-m-d'), $lead->next_action, $lead->phone]);

        // The form sends the stage only when it was changed: a «Контакт» lead shown as «Квалификация» keeps its status.
        $lead->forceFill(['status' => 'contact'])->save();
        $this->put("/crm/leads/{$lead->id}", ['form' => 'funnel', 'name' => 'ТОО «Демо»', 'segment' => '', 'tags' => [], 'next_date' => '', 'lock_version' => 1])
            ->assertSessionHasNoErrors();
        $lead->refresh();
        $this->assertSame(['contact', null, [], null], [$lead->status, $lead->segment, $lead->tags, $lead->next_date]);

        $this->put("/crm/leads/{$lead->id}", ['form' => 'funnel', 'name' => 'ТОО «Демо»', 'segment' => 9, 'lock_version' => 2])->assertSessionHasErrors('segment');
        $this->put("/crm/leads/{$lead->id}", ['form' => 'funnel', 'name' => 'ТОО «Демо»', 'tags' => array_map(fn ($i) => "т$i", range(1, 21)), 'lock_version' => 2])
            ->assertSessionHasErrors(['tags' => 'Не больше 20 тегов']);
        $this->put("/crm/leads/{$lead->id}", ['form' => 'funnel', 'name' => 'ТОО «Демо»', 'next_date' => '02.11.2026', 'lock_version' => 2])->assertSessionHasErrors('next_date');
    }

    public function test_second_editor_gets_a_conflict(): void
    {
        $lead = $this->lead();
        app(LeadService::class)->update($lead->id, ['name' => 'Изменён первым'], 1, $this->manager);

        $this->actingAs($this->manager)->from('/crm')
            ->put("/crm/leads/{$lead->id}", ['form' => 'leads', 'name' => 'Изменён вторым', 'lock_version' => 1])
            ->assertSessionHasErrors(['lock_version' => LeadService::STALE]);
        $this->put("/crm/leads/{$lead->id}/status", ['status' => 'won', 'lock_version' => 1])->assertSessionHasErrors(['lock_version' => LeadService::STALE]);
        $this->assertSame(['Изменён первым', 'new'], [$lead->fresh()->name, $lead->fresh()->status]);
    }

    public function test_status_moves_and_lost_reason_is_added_to_the_comment(): void
    {
        $lead = $this->lead(['comment' => 'Звонил в октябре']);
        $this->actingAs($this->manager)->from('/crm');

        $this->put("/crm/leads/{$lead->id}/status", ['status' => 'proposal', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame(['proposal', 2], [$lead->fresh()->status, $lead->fresh()->lock_version]);
        $this->assertEquals(['status' => ['from' => 'new', 'to' => 'proposal']], AuditEvent::where('action', 'lead.status_changed')->sole()->changes);

        $this->put("/crm/leads/{$lead->id}/status", ['status' => 'lost', 'reason' => ' дорого ', 'lock_version' => 2])->assertSessionHasNoErrors();
        $this->assertSame(['lost', "Звонил в октябре\nОтказ: дорого"], [$lead->fresh()->status, $lead->fresh()->comment]);

        $other = $this->lead(['name' => 'Демо Без комментария']);
        $this->put("/crm/leads/{$other->id}/status", ['status' => 'lost', 'reason' => '', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('Отказ', $other->fresh()->comment);

        $this->put("/crm/leads/{$other->id}/status", ['status' => 'archived', 'lock_version' => 2])->assertSessionHasErrors('status');
    }

    public function test_lead_becomes_a_client_of_its_channel_and_is_won(): void
    {
        $armada = User::factory()->role('salon_armada')->create(['name' => 'Демо Салон']);
        $lead = $this->lead(['name' => 'Демо Клиентка', 'phone' => '+7 701 000 0000'], $armada);

        $this->actingAs($armada)->from('/crm')->post("/crm/leads/{$lead->id}/client", ['lock_version' => 1])
            ->assertRedirect('/crm')->assertSessionHasNoErrors()
            ->assertSessionHas('dialog', ['type' => 'alert', 'text' => '✅ Демо Клиентка добавлен в клиенты!']);

        $customer = Customer::sole();
        $this->assertSame(['Демо Клиентка', '+7 701 000 0000', 'client', 'Конвертирован из лида', 'salon_armada', 'Демо Салон'],
            [$customer->name, $customer->phone, $customer->type, $customer->comment, $customer->channel, $customer->created_by_name]);
        $this->assertSame(['won', 2], [$lead->fresh()->status, $lead->fresh()->lock_version]);
        $this->assertSame(1, AuditEvent::where('action', 'lead.converted')->count());

        // A closed lead is not converted twice.
        $this->post("/crm/leads/{$lead->id}/client", ['lock_version' => 2])->assertSessionHasErrors(['lead' => 'Лид уже закрыт — обновите страницу.']);
        $this->assertSame(1, Customer::count());
    }

    public function test_deleted_lead_leaves_the_lists(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->manager)->from('/crm')->delete("/crm/leads/{$lead->id}", ['lock_version' => 1])->assertSessionHasNoErrors();

        $this->assertFalse($lead->fresh()->active);
        $this->assertNotNull($lead->fresh()->deactivated_at);
        $this->get('/crm')->assertInertia(fn (Assert $page) => $page->where('leads', []));
        $this->put("/crm/leads/{$lead->id}/status", ['status' => 'won', 'lock_version' => 2])->assertSessionHasErrors(['lead' => LeadService::NOT_FOUND]);
        $this->assertDatabaseHas('audit_events', ['action' => 'lead.deactivated', 'subject_id' => (string) $lead->id]);
    }

    public function test_roles_without_crm_cannot_reach_leads(): void
    {
        $lead = $this->lead();
        $storekeeper = User::factory()->role('storekeeper')->create();

        $this->actingAs($storekeeper)->get('/crm')->assertForbidden();
        $this->post('/crm/leads', ['form' => 'leads', 'name' => 'Демо'])->assertForbidden();
        $this->put("/crm/leads/{$lead->id}/status", ['status' => 'won', 'lock_version' => 1])->assertForbidden();
        $this->post("/crm/leads/{$lead->id}/client", ['lock_version' => 1])->assertForbidden();
        $this->assertSame(['new', 1], [$lead->fresh()->status, Lead::count()]);
    }
}
