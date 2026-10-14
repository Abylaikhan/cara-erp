<?php

namespace Tests\Feature\Purchases;

use App\Models\AuditEvent;
use App\Models\Material;
use App\Models\MaterialRequest;
use App\Models\User;
use App\Services\MaterialRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M04 «Закупки»: material requests persist, move one step at a time without touching stock; «План-факт» from requests and stock. */
class MaterialRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $storekeeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storekeeper = User::factory()->role('storekeeper')->create(['name' => 'Демо Склад']);
    }

    private function request(array $data = []): MaterialRequest
    {
        return app(MaterialRequestService::class)->create($data + ['material_name' => 'Поролон Демо', 'qty' => '10', 'unit' => 'м²', 'item_name' => null], $this->storekeeper);
    }

    private function material(string $name, string $qty, string $unit = 'лист'): Material
    {
        $m = new Material(['name' => $name, 'category' => 'Прочее', 'unit' => $unit, 'price' => '100.00', 'min_qty' => 0, 'warehouse_code' => 'wh_plates']);
        $m->forceFill(['qty' => $qty])->save();

        return $m;
    }

    public function test_manual_request_is_created_with_its_author(): void
    {
        $this->actingAs($this->storekeeper)->from('/purchases');
        $this->post('/purchases/requests', ['material_name' => '  ', 'qty' => '1'])->assertSessionHasErrors(['material_name' => 'Укажите материал.']);
        $this->post('/purchases/requests', ['material_name' => 'Демо', 'qty' => '-1'])->assertSessionHasErrors(['qty' => 'Количество не может быть отрицательным']);
        $this->post('/purchases/requests', ['material_name' => ' Поролон Демо ', 'qty' => '', 'unit' => ' м² ', 'item_name' => 'Для заказа'])
            ->assertRedirect('/purchases')->assertSessionHasNoErrors();

        $r = MaterialRequest::sole();
        $this->assertSame(['Поролон Демо', '0.0000', 'м²', 'Для заказа', 'requested', 'Демо Склад', 1], [$r->material_name, $r->qty, $r->unit, $r->item_name, $r->status, $r->requested_by, $r->lock_version]);
        $this->assertDatabaseHas('audit_events', ['action' => 'material_request.created', 'subject_id' => (string) $r->id]);
        $this->get('/purchases')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Purchases')
            ->where('requests.0.material_name', 'Поролон Демо')
            ->where('requests.0.requested_by', 'Демо Склад')
            ->where('workshops.paint', 'Малярка'));
    }

    public function test_status_moves_one_step_at_a_time_and_stock_is_not_touched(): void
    {
        $stock = $this->material('Поролон Демо', '3.5', 'м²');
        $r = $this->request();
        $this->actingAs($this->storekeeper)->from('/purchases');

        $this->put("/purchases/requests/{$r->id}/status", ['status' => 'received', 'lock_version' => 1])->assertSessionHasErrors(['status' => MaterialRequestService::WRONG_STEP]);
        $this->put("/purchases/requests/{$r->id}/status", ['status' => 'in_purchase', 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->put("/purchases/requests/{$r->id}/status", ['status' => 'received', 'lock_version' => 1])->assertSessionHasErrors(['lock_version' => MaterialRequestService::STALE]);
        $this->put("/purchases/requests/{$r->id}/status", ['status' => 'received', 'lock_version' => 2])->assertSessionHasNoErrors();
        $this->put("/purchases/requests/{$r->id}/status", ['status' => 'received', 'lock_version' => 3])->assertSessionHasErrors(['status' => MaterialRequestService::WRONG_STEP]);
        $this->put("/purchases/requests/{$r->id}/status", ['status' => 'requested', 'lock_version' => 3])->assertSessionHasErrors('status');

        $this->assertSame(['received', 3], [$r->fresh()->status, $r->fresh()->lock_version]);
        $this->assertSame('3.500', $stock->fresh()->qty, '«Пришло на склад» does not move stock');
        $this->assertSame(2, AuditEvent::where('action', 'material_request.status_changed')->count());
    }

    public function test_plan_fact_adds_open_requests_and_subtracts_stock(): void
    {
        $this->material('Поролон Демо', '3.5', 'м²');
        $this->material('Ткань Демо', '100', 'м');
        $this->request(['material_name' => 'Поролон Демо', 'qty' => '10', 'unit' => 'м²']);
        $this->request(['material_name' => 'Поролон Демо', 'qty' => '2.25', 'unit' => 'кг']);
        $this->request(['material_name' => 'Ткань Демо', 'qty' => '5', 'unit' => null]);
        $done = $this->request(['material_name' => 'Клей Демо', 'qty' => '7']);
        app(MaterialRequestService::class)->advance($done->id, 'in_purchase', 1, $this->storekeeper);
        app(MaterialRequestService::class)->advance($done->id, 'received', 2, $this->storekeeper);

        $this->actingAs($this->storekeeper)->get('/purchases')->assertInertia(fn (Assert $page) => $page
            ->where('planFact', [
                ['name' => 'Поролон Демо', 'unit' => 'кг', 'need' => 12.25, 'bought' => 0, 'stock' => 3.5, 'to_buy' => 8.75],
                ['name' => 'Ткань Демо', 'unit' => 'м', 'need' => 5, 'bought' => 0, 'stock' => 100, 'to_buy' => 0],
            ]));
    }

    public function test_roles_without_the_page_cannot_reach_requests(): void
    {
        $r = $this->request();
        $this->actingAs(User::factory()->role('manager')->create());
        $this->get('/purchases')->assertForbidden();
        $this->post('/purchases/requests', ['material_name' => 'Демо'])->assertForbidden();
        $this->put("/purchases/requests/{$r->id}/status", ['status' => 'in_purchase', 'lock_version' => 1])->assertForbidden();
        $this->assertSame(['requested', 1], [$r->fresh()->status, MaterialRequest::count()]);
    }
}
