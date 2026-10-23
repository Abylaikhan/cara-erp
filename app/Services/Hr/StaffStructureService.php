<?php

namespace App\Services\Hr;

use App\Models\StaffDepartment;
use App\Models\StaffPosition;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Сотрудники» → «Штатное расписание»: «+ Подразделение» / ✏️ of a department and «+ Должность» / ✏️ of a position (legacy
 * openStaffDeptModal/openStaffPositionModal, L21777–21834; № 76 — ADR-0051). Legacy asked in prompts and kept the change in the
 * page; here it is saved, under the version of the record and in the audit trail. A new position goes to the first department
 * with no grade and the kind of pay of its salary (0 — piecework), as in legacy; editing changes what legacy asks for.
 */
class StaffStructureService
{
    public const STALE = 'Штатное расписание изменено другим пользователем — обновите страницу и повторите.';

    /** @param array{name: string, icon: ?string} $data */
    public function saveDepartment(?int $id, ?int $lockVersion, array $data, User $by): StaffDepartment
    {
        return DB::transaction(function () use ($id, $lockVersion, $data, $by) {
            $dept = $id === null
                ? new StaffDepartment(['sort_order' => (int) StaffDepartment::query()->lockForUpdate()->pluck('sort_order')->max() + 1])
                : $this->locked(StaffDepartment::class, $id, $lockVersion);
            $before = $dept->only(['name', 'icon']);
            $dept->fill(['name' => $data['name'], 'icon' => $data['icon'] ?: '🏢']);
            if ($dept->exists && $dept->isDirty()) {
                $dept->lock_version++;
            }
            if (! $dept->exists || $dept->isDirty()) {
                $dept->save();
                Audit::record($id === null ? 'staff_department.created' : 'staff_department.updated', 'staff_department', $dept->id, ['before' => $id === null ? null : $before, 'after' => $dept->only(['name', 'icon'])], $by);
            }

            return $dept;
        });
    }

    /** @param array{title: string, slots: int, salary_min: int, salary_max: int} $data */
    public function savePosition(?int $id, ?int $lockVersion, array $data, User $by): StaffPosition
    {
        return DB::transaction(function () use ($id, $lockVersion, $data, $by) {
            if ($id === null) {
                $first = StaffDepartment::query()->orderBy('sort_order')->orderBy('id')->first()
                    ?? throw ValidationException::withMessages(['title' => 'Сначала добавьте подразделение']);
                $pos = new StaffPosition(['department_id' => $first->id, 'grade' => '', 'wage_type' => $data['salary_min'] === 0 ? 'piecework' : 'salary',
                    'sort_order' => (int) StaffPosition::query()->lockForUpdate()->pluck('sort_order')->max() + 1]);
            } else {
                $pos = $this->locked(StaffPosition::class, $id, $lockVersion);
            }
            $fields = ['title', 'slots', 'salary_min', 'salary_max'];
            $before = $pos->only($fields);
            $pos->fill(array_intersect_key($data, array_flip($fields)));
            if ($pos->exists && $pos->isDirty()) {
                $pos->lock_version++;
            }
            if (! $pos->exists || $pos->isDirty()) {
                $pos->save();
                Audit::record($id === null ? 'staff_position.created' : 'staff_position.updated', 'staff_position', $pos->id, ['before' => $id === null ? null : $before, 'after' => $pos->only($fields)], $by);
            }

            return $pos;
        });
    }

    /**
     * @template T of StaffDepartment|StaffPosition
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function locked(string $class, int $id, ?int $lockVersion): StaffDepartment|StaffPosition
    {
        $row = $class::query()->lockForUpdate()->find($id) ?? throw ValidationException::withMessages(['lock_version' => 'Запись не найдена — обновите страницу.']);
        if ($lockVersion === null || $row->lock_version !== $lockVersion) {
            throw ValidationException::withMessages(['lock_version' => self::STALE]);
        }

        return $row;
    }
}
