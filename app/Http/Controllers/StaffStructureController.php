<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Hr\StaffStructureService;
use App\Support\Erp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** «Сотрудники» → «Штатное расписание»: departments and positions (№ 76 — ADR-0051). */
class StaffStructureController extends Controller
{
    public function __construct(private readonly StaffStructureService $service) {}

    /** Those who see the tab: the page «Сотрудники», but not the head of the salons (legacy hides the tab from him). */
    public static function canEdit(User $user): bool
    {
        $role = Erp::role($user->role) ?? [];

        return Erp::canAccess($user, 'hr') && ! (($role['is_manager'] ?? false) && ($role['channel'] ?? null) === null);
    }

    public function storeDepartment(Request $request): RedirectResponse
    {
        return $this->department($request, null);
    }

    public function updateDepartment(Request $request, int $department): RedirectResponse
    {
        return $this->department($request, $department);
    }

    public function storePosition(Request $request): RedirectResponse
    {
        return $this->position($request, null);
    }

    public function updatePosition(Request $request, int $position): RedirectResponse
    {
        return $this->position($request, $position);
    }

    private function department(Request $request, ?int $id): RedirectResponse
    {
        abort_unless(self::canEdit($request->user()), 403);
        $request->merge(['name' => trim((string) $request->input('name', '')), 'icon' => trim((string) $request->input('icon', ''))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:16'],
            'lock_version' => $id === null ? ['nullable'] : ['required', 'integer'],
        ], ['name.required' => 'Укажите название подразделения']);
        $this->service->saveDepartment($id, isset($data['lock_version']) ? (int) $data['lock_version'] : null, ['name' => $data['name'], 'icon' => $data['icon'] ?? null], $request->user());

        return back();
    }

    private function position(Request $request, ?int $id): RedirectResponse
    {
        abort_unless(self::canEdit($request->user()), 403);
        $request->merge(['title' => trim((string) $request->input('title', ''))]);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slots' => ['required', 'integer', 'min:1', 'max:9999'],
            'salary_min' => ['required', 'integer', 'min:0', 'max:999999999'],
            'salary_max' => ['required', 'integer', 'gte:salary_min', 'max:999999999'],
            'lock_version' => $id === null ? ['nullable'] : ['required', 'integer'],
        ], ['title.required' => 'Укажите название должности', 'salary_max.gte' => 'Оклад максимальный меньше минимального']);
        $this->service->savePosition($id, isset($data['lock_version']) ? (int) $data['lock_version'] : null,
            ['title' => $data['title'], 'slots' => (int) $data['slots'], 'salary_min' => (int) $data['salary_min'], 'salary_max' => (int) $data['salary_max']], $request->user());

        return back();
    }
}
