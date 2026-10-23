<?php

namespace Tests\Feature\Hr;

use App\Legacy\Importers\StaffStructureImporter;
use App\Models\StaffDepartment;
use App\Models\StaffPosition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** «Сотрудники» → «Штатное расписание» as a directory (№ 76 — ADR-0051): carried over from the code of legacy, then changed here. */
class StaffStructureTest extends TestCase
{
    use RefreshDatabase;

    // Synthetic fragment shaped like legacy staffDepts / staffPositions (one object per line, comments between).
    private const HTML = <<<'JS'
        <script>
        let staffDepts = [
          {id:'dept_a',   name:'Цех Демо',       icon:'🏭'},
          {id:'dept_b',   name:'Офис Демо',      icon:'🏢'},
        ];

        let staffPositions = [
          // Цех
          {id:1, deptId:'dept_a', title:'Столяр демо',  grade:'4р', salaryMin:0, salaryMax:0, slots:3, wageType:'piecework'},
          {id:2, deptId:'dept_b', title:'Бухгалтер демо', grade:'АУП', salaryMin:1100, salaryMax:1300, slots:1, wageType:'salary'},
        ];
        </script>
        JS;

    public function test_the_structure_is_carried_over_from_the_code_of_legacy_and_what_was_changed_here_stays(): void
    {
        $stats = (new StaffStructureImporter(self::HTML))->import('S1');
        $this->assertSame([2, 2, 4], [$stats['departments'], $stats['positions'], $stats['created']]);
        $this->assertSame([['Столяр демо', 'Цех Демо', '4р', 'piecework', 0, 3], ['Бухгалтер демо', 'Офис Демо', 'АУП', 'salary', 1300, 1]],
            StaffPosition::query()->orderBy('sort_order')->get()->map(fn ($p) => [$p->title, StaffDepartment::find($p->department_id)->name, $p->grade, $p->wage_type, $p->salary_max, $p->slots])->all());

        StaffPosition::query()->where('legacy_key', '2')->update(['title' => 'Главный бухгалтер демо', 'lock_version' => 2]);
        $again = (new StaffStructureImporter(self::HTML))->import('S1');
        $this->assertSame([0, 0, 3, 1], [$again['created'], $again['updated'], $again['unchanged'], $again['kept']]);
        $this->assertSame('Главный бухгалтер демо', StaffPosition::query()->where('legacy_key', '2')->value('title'));
    }

    public function test_departments_and_positions_are_saved_as_legacy_asks_for_them(): void
    {
        (new StaffStructureImporter(self::HTML))->import('S1');
        $this->actingAs(User::factory()->role('hr')->create())->from('/hr');

        $this->post('/hr/structure/departments', ['name' => ' ', 'icon' => ''])->assertSessionHasErrors(['name' => 'Укажите название подразделения']);
        $this->post('/hr/structure/departments', ['name' => 'Склад Демо', 'icon' => ''])->assertRedirect('/hr')->assertSessionHasNoErrors();
        $this->assertSame(['🏢', 2], [StaffDepartment::query()->where('name', 'Склад Демо')->value('icon'), StaffDepartment::query()->where('name', 'Склад Демо')->value('sort_order')]);

        // legacy: a new position goes to the first department; a salary of 0 makes it piecework
        $this->post('/hr/structure/positions', ['title' => 'Обивщик демо', 'slots' => 2, 'salary_min' => 0, 'salary_max' => 0])->assertSessionHasNoErrors();
        $new = StaffPosition::query()->where('title', 'Обивщик демо')->sole();
        $this->assertSame(['dept_a', 'piecework', '', 2], [StaffDepartment::find($new->department_id)->legacy_key, $new->wage_type, $new->grade, $new->slots]);

        $pos = StaffPosition::query()->where('legacy_key', '2')->sole();
        $this->put("/hr/structure/positions/{$pos->id}", ['title' => 'Бухгалтер демо', 'slots' => 2, 'salary_min' => 1500, 'salary_max' => 1400, 'lock_version' => 1])
            ->assertSessionHasErrors(['salary_max' => 'Оклад максимальный меньше минимального']);
        $this->put("/hr/structure/positions/{$pos->id}", ['title' => 'Бухгалтер демо', 'slots' => 2, 'salary_min' => 1500, 'salary_max' => 1700, 'lock_version' => 1])->assertSessionHasNoErrors();
        $pos->refresh();
        $this->assertSame([2, 1500, 1700, 2, 'salary'], [$pos->slots, $pos->salary_min, $pos->salary_max, $pos->lock_version, $pos->wage_type]);
        $this->put("/hr/structure/positions/{$pos->id}", ['title' => 'X', 'slots' => 1, 'salary_min' => 0, 'salary_max' => 0, 'lock_version' => 1])->assertSessionHasErrors('lock_version');
        $this->assertDatabaseHas('audit_events', ['action' => 'staff_position.updated', 'subject_id' => (string) $pos->id]);

        $this->get('/hr')->assertInertia(fn (Assert $page) => $page->where('canEditStructure', true)->has('staffDepts', 3)->has('staffPositions', 3)
            ->where('staffPositions.1.title', 'Бухгалтер демо')->where('staffPositions.1.salaryMax', 1700));
    }

    public function test_the_head_of_the_salons_neither_sees_nor_changes_the_structure(): void
    {
        (new StaffStructureImporter(self::HTML))->import('S1');
        $this->actingAs(User::factory()->role('salon_armada_mgr')->create());
        $this->get('/hr')->assertInertia(fn (Assert $page) => $page->where('canEditStructure', false)->where('staffDepts', [])->where('staffPositions', []));
        $this->post('/hr/structure/departments', ['name' => 'X'])->assertForbidden();
        $this->actingAs(User::factory()->role('dealer_astana')->create())->post('/hr/structure/positions', ['title' => 'X', 'slots' => 1, 'salary_min' => 0, 'salary_max' => 0])->assertForbidden();
        $this->assertSame(2, StaffDepartment::query()->count());
    }
}
