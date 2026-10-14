<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Quick-add form of the lead card's communication history (legacy saveCommEntry, L29468): type, date, what was discussed. */
class LeadCommunicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the lead's channel is checked by LeadCommunicationService
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'text' => trim((string) $this->input('text')),
            'happened_on' => ($d = trim((string) $this->input('happened_on'))) === '' ? null : $d,
        ]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(config('erp.communication_types'))],
            'happened_on' => ['nullable', 'date_format:Y-m-d'],
            'text' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return ['text.required' => 'Укажите, что обсуждалось', 'text.max' => 'Запись — не длиннее 5000 символов'];
    }
}
