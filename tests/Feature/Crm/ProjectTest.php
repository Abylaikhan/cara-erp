<?php

namespace Tests\Feature\Crm;

use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M10 «Проекты» (ERP-079): who sees and changes what, calculation versions and totals, the customer's decision, files. */
class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private User $manager;

    private User $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->director = User::factory()->role('director')->create(['login' => 'demo_director', 'name' => 'Демо Директор']);
        $this->manager = User::factory()->role('manager')->create(['login' => 'demo_manager', 'name' => 'Демо Менеджер']);
        $this->dealer = User::factory()->role('dealer_astana')->create(['login' => 'demo_dealer', 'name' => 'Демо Дилер']);
    }

    private function project(User $by, array $data = []): Project
    {
        return app(ProjectService::class)->create($data + ['title' => 'Демо кухня', 'channel' => 'office'], $by);
    }

    /** @return array<int, array<string, mixed>> */
    private function projects(User $user): array
    {
        $result = [];
        $this->actingAs($user)->get('/crm')->assertOk()->assertInertia(function (Assert $page) use (&$result) {
            $page->missing('projects')->reloadOnly('projects', function (Assert $reload) use (&$result) {
                $result = $reload->toArray()['props']['projects'];
            });
        });

        return $result;
    }

    /** A real 1×1 PNG (the test image has no GD here). */
    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }

    private function line(string $name, string $qty, string $cost, string $price): array
    {
        return ['name' => $name, 'attrs' => '', 'qty' => $qty, 'cost' => $cost, 'price' => $price];
    }

    public function test_staff_and_customer_create_projects_numbered_from_their_id(): void
    {
        $this->actingAs($this->manager)->from('/crm')->post('/crm/projects', ['title' => ' Демо шкаф ', 'channel' => 'dealer_astana', 'customer_name' => '', 'due_date' => '2026-11-01'])
            ->assertRedirect('/crm')->assertSessionHas('created');
        $staff = Project::query()->latest('id')->first();
        $this->assertSame(['ПР-'.str_pad((string) $staff->id, 4, '0', STR_PAD_LEFT), 'Демо шкаф', 'dealer_astana', 'request', 'Дилер Астана', '2026-11-01'],
            [$staff->num, $staff->title, $staff->channel, $staff->status, $staff->customer_name, $staff->due_date->format('Y-m-d')]);

        // The customer's request is of its own channel whatever is sent.
        $this->actingAs($this->dealer)->post('/crm/projects', ['title' => 'Демо запрос', 'channel' => 'office', 'customer_name' => 'Чужой'])->assertSessionHasNoErrors();
        $own = Project::query()->latest('id')->first();
        $this->assertSame(['dealer_astana', 'Дилер Астана', 'demo_dealer'], [$own->channel, $own->customer_name, $own->created_by_login]);

        $this->actingAs($this->manager)->post('/crm/projects', ['title' => ''])->assertSessionHasErrors(['title' => 'Введите название проекта']);
        $this->actingAs($this->manager)->post('/crm/projects', ['title' => 'Демо', 'channel' => 'nowhere'])->assertSessionHasErrors('channel');
        $this->assertSame(1, AuditEvent::query()->where('action', 'project.created')->where('subject_id', (string) $own->id)->count());
    }

    public function test_projects_follow_the_channel_and_the_customer_does_not_get_costs_drafts_or_internal_things(): void
    {
        $office = $this->project($this->manager, ['title' => 'Демо офис']);
        $dealer = $this->project($this->manager, ['title' => 'Демо дилер', 'channel' => 'dealer_astana']);
        $this->project($this->manager, ['title' => 'Демо салон', 'channel' => 'salon_armada']);
        $service = app(ProjectService::class);
        $service->sendCalc($dealer->id, null, [$this->line('Шкаф', '1', '700', '1000')], '30', $this->manager);
        $service->saveCalc($dealer->id, 1, [$this->line('Шкаф', '1', '800', '1200')], '30', false, $this->manager); // sent → v2 draft
        $service->addComment($dealer->id, 'Внутреннее', true, $this->manager);
        $service->addFile($dealer->id, 'designer', true, UploadedFile::fake()->create('demo.pdf', 10, 'application/pdf'), $this->manager);
        $service->addFile($dealer->id, 'designer', false, $this->png('demo.png'), $this->manager);

        $this->assertSame(['Демо дилер', 'Демо офис', 'Демо салон'], collect($this->projects($this->director))->pluck('title')->sort()->values()->all());
        $this->assertSame(['Демо салон'], collect($this->projects(User::factory()->role('salon_armada_mgr')->create()))->pluck('title')->all());

        $seen = $this->projects($this->dealer);
        $this->assertSame(['Демо дилер'], array_column($seen, 'title'));
        $this->assertSame([1], array_column($seen[0]['calcs'], 'version'));
        $this->assertArrayNotHasKey('cost', $seen[0]['calcs'][0]['items'][0]);
        $this->assertSame(["📤 Отправлен расчёт v1 на сумму ₸1\u{00A0}000."], array_column($seen[0]['comments'], 'text'));
        $this->assertSame(['demo.png'], array_column($seen[0]['files'], 'file_name'));
        $this->assertSame([1, 2], array_column(collect($this->projects($this->manager))->firstWhere('title', 'Демо дилер')['calcs'], 'version'));

        // Another channel's project is missing for every action.
        $this->actingAs($this->dealer)->from('/crm');
        $this->put("/crm/projects/{$office->id}", ['title' => 'Чужой', 'lock_version' => 1])->assertSessionHasErrors(['project' => ProjectService::NOT_FOUND]);
        $this->post("/crm/projects/{$office->id}/comments", ['text' => 'Чужой'])->assertSessionHasErrors(['project' => ProjectService::NOT_FOUND]);
        $this->actingAs(User::factory()->role('storekeeper')->create())->post('/crm/projects', ['title' => 'Демо'])->assertForbidden();
    }

    public function test_calculation_versions_totals_and_status(): void
    {
        $project = $this->project($this->dealer);
        $this->actingAs($this->manager)->from('/crm');
        $this->post("/crm/projects/{$project->id}/calcs", ['version' => null, 'markup' => '30', 'items' => [
            $this->line('Корпус', '2.5', '800.10', '1000.10'), $this->line('', '1', '0', ''), $this->line('', '3', '', '15.5'),
        ]])->assertSessionHas('created', 1);
        $project->refresh();
        $calc = $project->calcs()->sole();
        // Empty lines are dropped; 2.5 × 1000.10 + 3 × 15.50, counted exactly.
        $this->assertSame(['2546.75', 2, 'calc', ['demo_manager'], 'Демо Менеджер', '2546.75', '30.00'],
            [$calc->total, count($calc->items), $project->status, $project->executors, $project->responsible_name, $project->total_amount, $calc->markup_pct]);

        // An unsent version is updated; «Новая версия» makes the next one.
        $this->post("/crm/projects/{$project->id}/calcs", ['version' => 1, 'items' => [$this->line('Корпус', '1', '1', '10')]])->assertSessionHas('created', 1);
        $this->post("/crm/projects/{$project->id}/calcs", ['version' => 1, 'as_new' => true, 'items' => [$this->line('Корпус', '1', '1', '20')]])->assertSessionHas('created', 2);
        $this->post("/crm/projects/{$project->id}/calcs", ['version' => 2, 'items' => [$this->line('', '0', '0', '0')]])->assertSessionHasErrors(['items' => ProjectService::NO_ITEMS]);
        $this->post("/crm/projects/{$project->id}/calcs", ['version' => 2, 'items' => [$this->line('Корпус', '-1', '0', '1')]])->assertSessionHasErrors('items.0.qty');

        // Sending saves the version and waits for the customer; a sent version is not changed any more.
        $this->post("/crm/projects/{$project->id}/calcs/send", ['version' => 2, 'items' => [$this->line('Корпус', '1', '1', '1234567.5')]])->assertSessionHas('created', 2);
        $this->assertSame(['sent', '1234567.50'], [$project->fresh()->status, $project->fresh()->total_amount]);
        $this->assertSame("📤 Отправлен расчёт v2 на сумму ₸1\u{00A0}234\u{00A0}568.", $project->comments()->sole()->text);
        $this->post("/crm/projects/{$project->id}/calcs", ['version' => 2, 'items' => [$this->line('Корпус', '1', '1', '5')]])->assertSessionHas('created', 3);
        $this->assertSame(['10.00', '1234567.50', '5.00'], $project->calcs()->orderBy('version')->pluck('total')->all());

        // The customer neither calculates nor manages executors.
        $this->actingAs($this->dealer)->post("/crm/projects/{$project->id}/calcs", ['items' => [$this->line('Х', '1', '1', '1')]])->assertSessionHasErrors(['project' => ProjectService::FORBIDDEN]);
        $this->post("/crm/projects/{$project->id}/executors", ['login' => 'demo_director'])->assertSessionHasErrors(['project' => ProjectService::FORBIDDEN]);
    }

    public function test_customer_decides_on_a_sent_calculation_only(): void
    {
        $service = app(ProjectService::class);
        $accepted = $this->project($this->dealer, ['title' => 'Демо 1']);
        $declined = $this->project($this->dealer, ['title' => 'Демо 2']);
        $this->actingAs($this->dealer)->from('/crm')->post("/crm/projects/{$accepted->id}/decision", ['accept' => true])->assertSessionHasErrors(['status' => ProjectService::NOT_SENT]);
        $service->sendCalc($accepted->id, null, [$this->line('Шкаф', '1', '1', '100')], null, $this->manager);
        $service->sendCalc($declined->id, null, [$this->line('Шкаф', '1', '1', '100')], null, $this->manager);

        $this->actingAs($this->manager)->post("/crm/projects/{$accepted->id}/decision", ['accept' => true])->assertSessionHasErrors(['project' => ProjectService::FORBIDDEN]);
        $this->actingAs($this->dealer)->post("/crm/projects/{$accepted->id}/decision", ['accept' => true])->assertSessionHasNoErrors();
        $this->post("/crm/projects/{$declined->id}/decision", ['accept' => false, 'reason' => ' дорого '])->assertSessionHasNoErrors();
        $this->assertSame(['approved', 'closed'], [$accepted->fresh()->status, $declined->fresh()->status]);
        $this->assertSame(['client', '✖ Заказчик отказался. Причина: дорого'], [$declined->comments()->latest('id')->first()->side, $declined->comments()->latest('id')->first()->text]);
        $this->assertSame('✅ Расчёт принят заказчиком.', $accepted->comments()->latest('id')->first()->text);
    }

    public function test_head_changes_follow_the_rights_and_the_record_version(): void
    {
        $project = $this->project($this->dealer);
        $this->actingAs($this->dealer)->from('/crm')
            ->put("/crm/projects/{$project->id}", ['title' => 'Демо новая', 'description' => 'Размеры', 'due_date' => '2026-12-01', 'status' => 'closed', 'lock_version' => 1])
            ->assertSessionHasNoErrors();
        $project->refresh();
        $this->assertSame(['Демо новая', 'Размеры', 'request', 2], [$project->title, $project->description, $project->status, $project->lock_version]); // the customer cannot set a status

        $this->put("/crm/projects/{$project->id}", ['title' => 'Старая форма', 'lock_version' => 1])->assertSessionHasErrors(['lock_version' => ProjectService::STALE]);

        // An order number makes the project an order; the customer cannot edit it any more.
        $this->actingAs($this->manager)->put("/crm/projects/{$project->id}", ['title' => '', 'status' => 'calc', 'order_num' => 'МО-100', 'lock_version' => 2])->assertSessionHasNoErrors();
        $this->assertSame(['Демо новая', 'order', 'МО-100'], [$project->fresh()->title, $project->fresh()->status, $project->fresh()->order_num]);
        $this->actingAs($this->dealer)->put("/crm/projects/{$project->id}", ['title' => 'Поздно', 'lock_version' => 3])->assertSessionHasErrors(['project' => ProjectService::FORBIDDEN]);
    }

    public function test_executors_are_staff_users(): void
    {
        $project = $this->project($this->dealer);
        $this->actingAs($this->manager)->from('/crm');
        $this->post("/crm/projects/{$project->id}/executors", ['login' => 'demo_director'])->assertSessionHasNoErrors();
        $this->post("/crm/projects/{$project->id}/executors", ['login' => 'demo_dealer'])->assertSessionHasErrors('login');
        $this->post("/crm/projects/{$project->id}/executors", ['login' => 'demo_manager'])->assertSessionHasNoErrors();
        $this->assertSame([['demo_director', 'demo_manager'], 'Демо Директор, Демо Менеджер'], [$project->fresh()->executors, $project->fresh()->responsible_name]);
        $this->delete("/crm/projects/{$project->id}/executors/demo_director")->assertSessionHasNoErrors();
        $this->assertSame([['demo_manager'], 'Демо Менеджер'], [$project->fresh()->executors, $project->fresh()->responsible_name]);
        $this->assertSame(1, $project->fresh()->lock_version); // executors do not touch the head version
    }

    public function test_files_sides_downloads_and_deleting(): void
    {
        $project = $this->project($this->dealer);
        $this->actingAs($this->dealer)->from('/crm');
        $this->post("/crm/projects/{$project->id}/files", ['side' => 'designer', 'file' => $this->png('a.png')])->assertSessionHasErrors(['side' => ProjectService::FORBIDDEN]);
        $this->post("/crm/projects/{$project->id}/files", ['side' => 'customer', 'internal' => true, 'file' => $this->png('чертёж.png')])->assertSessionHasNoErrors();
        $own = ProjectFile::query()->sole();
        $this->assertSame([false, 'image/png', 'чертёж.png', 'demo_dealer'], [$own->internal, $own->mime, $own->file_name, $own->uploaded_by_login]);
        Storage::disk('local')->assertExists($own->storage_path);

        $this->actingAs($this->manager)->post("/crm/projects/{$project->id}/files", ['side' => 'designer', 'internal' => true, 'file' => UploadedFile::fake()->create('смета.html', 5, 'text/html')])->assertSessionHasNoErrors();
        $internal = ProjectFile::query()->where('internal', true)->sole();

        // Images are shown in place, anything else is downloaded; the customer cannot reach an internal file.
        $this->actingAs($this->dealer)->get("/crm/projects/files/{$own->id}")->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get("/crm/projects/files/{$internal->id}")->assertNotFound();
        $download = $this->actingAs($this->manager)->get("/crm/projects/files/{$internal->id}")->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringStartsWith('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->actingAs(User::factory()->role('dealer_aktau')->create())->get("/crm/projects/files/{$own->id}")->assertNotFound();

        // Too large a file is refused.
        $this->actingAs($this->manager)->post("/crm/projects/{$project->id}/files", ['side' => 'designer', 'file' => UploadedFile::fake()->create('big.zip', 26 * 1024)])->assertSessionHasErrors('file');

        // The customer deletes its own files only; staff delete any.
        $this->actingAs($this->manager)->post("/crm/projects/{$project->id}/files", ['side' => 'designer', 'file' => $this->png('b.png')]);
        $staffFile = ProjectFile::query()->where('file_name', 'b.png')->sole();
        $this->actingAs($this->dealer)->delete("/crm/projects/files/{$staffFile->id}")->assertSessionHasErrors(['file' => ProjectService::FORBIDDEN]);
        $this->delete("/crm/projects/files/{$own->id}")->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($own->storage_path);
        $this->actingAs($this->manager)->delete("/crm/projects/files/{$staffFile->id}")->assertSessionHasNoErrors();
        $this->assertSame([$internal->id], ProjectFile::query()->pluck('id')->all());
    }

    public function test_only_the_director_deletes_a_project_and_it_is_kept_deactivated(): void
    {
        $project = $this->project($this->dealer);
        $this->actingAs($this->manager)->from('/crm')->delete("/crm/projects/{$project->id}", ['lock_version' => 1])->assertSessionHasErrors(['project' => ProjectService::FORBIDDEN]);
        $this->actingAs($this->director)->delete("/crm/projects/{$project->id}", ['lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertFalse($project->fresh()->active);
        $this->assertSame([], $this->projects($this->director));
        $this->assertSame(1, AuditEvent::query()->where('action', 'project.deactivated')->count());
    }

    public function test_open_projects_are_counted_and_staff_get_the_executor_list(): void
    {
        $this->project($this->dealer, ['title' => 'Демо 1']);
        $closed = $this->project($this->dealer, ['title' => 'Демо 2']);
        app(ProjectService::class)->updateHead($closed->id, ['title' => 'Демо 2', 'status' => 'closed'], 1, $this->manager);

        $this->actingAs($this->dealer)->get('/crm')->assertInertia(fn (Assert $page) => $page
            ->where('projectsCount', 1)
            ->where('projectMeta.client', true)
            ->where('projectMeta.staff', [])
            ->where('projectsOnly', true));
        $this->actingAs($this->manager)->get('/crm')->assertInertia(fn (Assert $page) => $page
            ->where('projectMeta.client', false)
            ->where('projectMeta.admin', false)
            ->where('projectMeta.staff', fn ($staff) => collect($staff)->pluck('login')->sort()->values()->all() === ['demo_director', 'demo_manager']));
    }
}
