<?php

namespace Tests\Feature\Crm;

use App\Models\Lead;
use App\Models\LeadCommunication;
use App\Models\User;
use App\Services\LeadCommunicationService;
use App\Services\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** M10 CRM, lead card «История коммуникации»: entries persist (legacy kept them in page memory) and follow the lead's channel. */
class LeadCommunicationTest extends TestCase
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

    public function test_entries_are_added_listed_and_deleted(): void
    {
        $lead = $this->lead(['manager' => 'Демо Менеджер']);
        $this->actingAs($this->manager)->from('/crm');
        $this->post("/crm/leads/{$lead->id}/communications", ['type' => 'call', 'happened_on' => '2026-10-10', 'text' => ' Первичный звонок '])->assertSessionHasNoErrors();
        $this->post("/crm/leads/{$lead->id}/communications", ['type' => 'email', 'happened_on' => '', 'text' => 'Отправили КП'])->assertSessionHasNoErrors();
        $this->post("/crm/leads/{$lead->id}/communications", ['type' => 'call', 'text' => '  '])->assertSessionHasErrors(['text' => 'Укажите, что обсуждалось']);
        $this->post("/crm/leads/{$lead->id}/communications", ['type' => 'fax', 'text' => 'Демо'])->assertSessionHasErrors('type');

        $first = LeadCommunication::orderBy('id')->first();
        $this->assertSame(['call', '2026-10-10', 'Первичный звонок', 'Демо Менеджер', 'Демо Продажи'],
            [$first->type, $first->happened_on->format('Y-m-d'), $first->text, $first->manager, $first->created_by_name], 'signed with the lead manager, as in legacy');
        $this->getJson("/crm/leads/{$lead->id}/communications")->assertOk()->assertExactJson([
            ['id' => $first->id, 'type' => 'call', 'happened_on' => '2026-10-10', 'text' => 'Первичный звонок', 'manager' => 'Демо Менеджер'],
            ['id' => $first->id + 1, 'type' => 'email', 'happened_on' => null, 'text' => 'Отправили КП', 'manager' => 'Демо Менеджер'],
        ]);

        $this->delete("/crm/communications/{$first->id}")->assertSessionHasNoErrors();
        $this->assertFalse($first->fresh()->active);
        $this->getJson("/crm/leads/{$lead->id}/communications")->assertJsonCount(1);
        $this->delete("/crm/communications/{$first->id}")->assertSessionHasErrors(['communication' => LeadCommunicationService::NOT_FOUND]);
        $this->assertDatabaseHas('audit_events', ['action' => 'lead_communication.deactivated', 'subject_id' => (string) $lead->id]);
    }

    public function test_history_of_another_channel_is_not_reachable(): void
    {
        $armada = User::factory()->role('salon_armada')->create();
        $office = $this->lead(['name' => 'Демо Офис']);
        $entry = app(LeadCommunicationService::class)->add($office->id, ['type' => 'note', 'happened_on' => null, 'text' => 'Демо'], $this->manager);

        $this->actingAs($armada)->from('/crm');
        $this->getJson("/crm/leads/{$office->id}/communications")->assertStatus(422);
        $this->post("/crm/leads/{$office->id}/communications", ['type' => 'call', 'text' => 'Демо'])->assertSessionHasErrors(['lead' => LeadService::NOT_FOUND]);
        $this->delete("/crm/communications/{$entry->id}")->assertSessionHasErrors(['communication' => LeadCommunicationService::NOT_FOUND]);
        $this->assertSame([1, true], [LeadCommunication::count(), $entry->fresh()->active]);

        $this->actingAs(User::factory()->role('storekeeper')->create());
        $this->getJson("/crm/leads/{$office->id}/communications")->assertForbidden();
    }
}
