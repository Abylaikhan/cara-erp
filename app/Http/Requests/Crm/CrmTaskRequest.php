<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Legacy taskModal/saveTask (L4744, L29778): description, reminder date and time, manager, priority, the lead it is about. */
class CrmTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // page access is checked by the route, the task's lead by CrmTaskService
    }

    protected function prepareForValidation(): void
    {
        $blankToNull = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;
        $this->merge([
            'title' => trim((string) $this->input('title')),
            'due_date' => $blankToNull($this->input('due_date')),
            'due_time' => $blankToNull($this->input('due_time')),
            'manager' => $blankToNull($this->input('manager')),
            'priority' => $blankToNull($this->input('priority')) ?? 'normal',
            'lead_id' => $blankToNull($this->input('lead_id')),
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:2000'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'manager' => ['nullable', 'string', 'max:255'],
            'priority' => ['required', Rule::in(config('erp.task_priorities'))],
            'lead_id' => ['nullable', 'integer'],
            'lock_version' => [$this->isMethod('PUT') ? 'required' : 'nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Укажите описание задачи',
            'title.max' => 'Описание задачи — не длиннее 2000 символов',
            'due_date.date_format' => 'Дата напоминания — в формате ГГГГ-ММ-ДД',
            'due_time.date_format' => 'Время напоминания — в формате ЧЧ:ММ',
        ];
    }

    /** @return array<string, mixed> */
    public function fields(): array
    {
        $fields = $this->safe()->except(['lock_version']);
        $fields['lead_id'] = $fields['lead_id'] === null ? null : (int) $fields['lead_id'];

        return $fields;
    }
}
