<?php

namespace App\Services;

use App\Models\SalonWorksheetRow;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «Рабочий лист салонов» — legacy addSalonWbRow/updateSalonWbField/deleteSalonWbRow/clearSalonWbSheet (L9150–9205). A cell is
 * saved as soon as it is changed, like legacy; a transferred row is not changed or deleted. Every change goes to the audit trail,
 * a deleted row with its values.
 */
class SalonWorksheetService
{
    public const NOT_FOUND = 'Строка не найдена — обновите страницу.';

    public const TRANSFERRED = 'Строка уже перенесена в базу — её нельзя менять.';

    public function add(string $salon, User $by): SalonWorksheetRow
    {
        return DB::transaction(function () use ($salon, $by) {
            // Legacy dates a new row «today» (through UTC — in Almaty yesterday until 05:00); here the business date.
            $row = new SalonWorksheetRow(['uuid' => (string) Str::uuid(), 'salon' => $salon, 'order_date' => now(config('erp.business_timezone'))->format('Y-m-d')]);
            $row->forceFill(['created_by_id' => $by->id, 'created_by_name' => $by->name, 'updated_by_id' => $by->id, 'updated_by_name' => $by->name])->save();
            Audit::record('salon_worksheet.row_added', 'salon_worksheet_row', $row->id, ['salon' => $salon, 'order_date' => $row->order_date?->format('Y-m-d')], $by);

            return $row;
        });
    }

    /** Legacy updateSalonWbField: a number field takes parseFloat(value)||0, a date or text field null when empty. */
    public function update(int $id, string $field, ?string $value, User $by): SalonWorksheetRow
    {
        return DB::transaction(function () use ($id, $field, $value, $by) {
            $row = $this->editable($id);
            $value = trim($value ?? '');
            if (in_array($field, SalonWorksheetRow::NUMBER_FIELDS, true)) {
                $parsed = preg_match('/^-?\d{1,18}(\.\d{1,12})?$/', $value) ? $value : (preg_match('/^-?\d+(\.\d+)?$/', $value) ? null : '0');
                if ($parsed === null) {
                    throw ValidationException::withMessages(['value' => 'Слишком большое число.']);
                }
            } elseif (in_array($field, SalonWorksheetRow::DATE_FIELDS, true)) {
                $parsed = $value === '' ? null : $value;
                if ($parsed !== null && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $parsed) || ! checkdate((int) substr($parsed, 5, 2), (int) substr($parsed, 8, 2), (int) substr($parsed, 0, 4)))) {
                    throw ValidationException::withMessages(['value' => 'Неверная дата.']);
                }
            } elseif (in_array($field, SalonWorksheetRow::TEXT_FIELDS, true)) {
                $parsed = $value === '' ? null : $value;
                if ($parsed !== null && mb_strlen($parsed) > ($field === 'order_num' ? 64 : ($field === 'manager' ? 255 : 5000))) {
                    throw ValidationException::withMessages(['value' => 'Слишком длинный текст.']);
                }
            } else {
                throw ValidationException::withMessages(['field' => 'Неизвестное поле.']);
            }
            $before = $row->getRawOriginal($field);
            $row->forceFill([$field => $parsed]);
            if ($row->isDirty($field)) {
                $row->forceFill(['updated_by_id' => $by->id, 'updated_by_name' => $by->name])->save();
                Audit::record('salon_worksheet.row_changed', 'salon_worksheet_row', $row->id, [$field => ['from' => $before, 'to' => $row->getRawOriginal($field)]], $by);
            }

            return $row;
        });
    }

    public function delete(int $id, User $by): void
    {
        DB::transaction(function () use ($id, $by) {
            $row = $this->editable($id);
            Audit::record('salon_worksheet.row_deleted', 'salon_worksheet_row', $row->id, $row->getAttributes(), $by);
            $row->delete();
        });
    }

    /** Legacy clearSalonWbSheet: every row of the salon that has not been transferred. */
    public function clear(string $salon, User $by): int
    {
        return DB::transaction(function () use ($salon, $by) {
            $rows = SalonWorksheetRow::query()->where('salon', $salon)->where('transferred_to_db', false)->lockForUpdate()->get();
            Audit::record('salon_worksheet.cleared', 'salon_worksheet', null, ['salon' => $salon, 'rows' => $rows->map->getAttributes()->values()->all()], $by);
            SalonWorksheetRow::query()->whereIn('id', $rows->pluck('id'))->delete();

            return $rows->count();
        });
    }

    private function editable(int $id): SalonWorksheetRow
    {
        $row = SalonWorksheetRow::query()->whereKey($id)->lockForUpdate()->first();
        if ($row === null) {
            throw ValidationException::withMessages(['row' => self::NOT_FOUND]);
        }
        if ($row->transferred_to_db) {
            throw ValidationException::withMessages(['row' => self::TRANSFERRED]);
        }

        return $row;
    }
}
