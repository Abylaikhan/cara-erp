<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lead forms of legacy CRM: «Лиды» (leadModal/saveLead, L32044) and «Воронка» (crmLeadModal/saveCrmLead, L29215).
 * Both edit one lead; `form` tells which one sent it, for the legacy message about an empty name.
 */
class LeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // page access is checked by the route, the lead's channel by LeadService
    }

    protected function prepareForValidation(): void
    {
        $trimOrNull = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;
        $merge = [];
        foreach (['name'] as $f) {
            if ($this->has($f)) {
                $merge[$f] = trim((string) $this->input($f));
            }
        }
        foreach (['phone', 'interest', 'source', 'comment', 'manager', 'next_action', 'next_date'] as $f) {
            if ($this->has($f)) {
                $merge[$f] = $trimOrNull($this->input($f));
            }
        }
        if ($this->has('budget')) {
            $merge['budget'] = $this->input('budget') === null || $this->input('budget') === '' ? 0 : $this->input('budget');
        }
        if ($this->has('segment')) {
            $merge['segment'] = $this->input('segment') === null || $this->input('segment') === '' ? null : $this->input('segment');
        }
        if ($this->has('tags')) {
            $merge['tags'] = array_values(array_unique(array_filter(array_map(fn ($t) => trim((string) $t), (array) $this->input('tags')), fn ($t) => $t !== '')));
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        $update = $this->isMethod('PUT');

        return [
            'form' => ['required', Rule::in(['leads', 'funnel'])],
            'name' => [...($update ? ['sometimes'] : []), 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'required', Rule::in(array_keys(config('erp.lead_statuses')))],
            'interest' => ['sometimes', 'nullable', 'string', 'max:500'],
            'budget' => ['sometimes', 'numeric', 'min:0', 'max:9999999999999999.99', 'decimal:0,2'],
            'source' => ['sometimes', 'nullable', Rule::in(array_keys(config('erp.lead_sources')))],
            'comment' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'manager' => ['sometimes', 'nullable', 'string', 'max:255'],
            'segment' => ['sometimes', 'nullable', 'integer', Rule::in(array_keys(config('erp.lead_segments')))],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
            'next_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'next_action' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lock_version' => [$update ? 'required' : 'nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => $this->input('form') === 'funnel' ? 'Укажите название клиента или компании' : 'Введите имя',
            'budget.min' => 'Бюджет не может быть отрицательным',
            'budget.decimal' => 'Бюджет — не более двух знаков после запятой',
            'tags.max' => 'Не больше 20 тегов',
            'tags.*.max' => 'Тег — не длиннее 50 символов',
        ];
    }

    /** @return array<string, mixed> */
    public function fields(): array
    {
        return $this->safe()->except(['form', 'lock_version']);
    }
}
