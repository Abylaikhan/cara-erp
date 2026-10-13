<?php

namespace App\Http\Requests\Customers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Dealer form of legacy saveDealer (L19773) and the inline cells of renderDealers (patched, L38569): name and region.
 * A new dealer needs a name; an inline edit sends one field with the lock version.
 */
class DealerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // page access is checked by the route, the dealer's channel by SalesChannelPolicy
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('name')) {
            $merge['name'] = trim((string) $this->input('name'));
        }
        if ($this->has('region')) {
            // Legacy writes «—» for an empty region.
            $region = trim((string) $this->input('region'));
            $merge['region'] = in_array($region, ['', '—'], true) ? null : $region;
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        $update = $this->isMethod('PUT');

        return [
            'name' => [...($update ? ['sometimes'] : []), 'required', 'string', 'max:100'],
            'region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'lock_version' => [$update ? 'required' : 'nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Укажите название дилера',
            'name.max' => 'Название дилера — не длиннее 100 символов',
            'region.max' => 'Регион — не длиннее 100 символов',
        ];
    }

    /** @return array{name?: string, region?: string|null} */
    public function changes(): array
    {
        return $this->safe()->only(['name', 'region']);
    }
}
