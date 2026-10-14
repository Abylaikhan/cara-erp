<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;

/** Legacy newMaterialRequestModal (L3011): material, quantity, unit, comment (what it is for). */
class MaterialRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // page access is checked by the route and the policy
    }

    protected function prepareForValidation(): void
    {
        $blankToNull = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;
        $this->merge([
            'material_name' => trim((string) $this->input('material_name')),
            // Legacy parseFloat(...) || 0: an empty quantity is 0.
            'qty' => ($q = trim((string) $this->input('qty'))) === '' ? '0' : $q,
            'unit' => $blankToNull($this->input('unit')),
            'item_name' => $blankToNull($this->input('item_name')),
        ]);
    }

    public function rules(): array
    {
        return [
            'material_name' => ['required', 'string', 'max:255'],
            'qty' => ['numeric', 'min:0', 'max:9999999999', 'decimal:0,4'],
            'unit' => ['nullable', 'string', 'max:32'],
            'item_name' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'material_name.required' => 'Укажите материал.',
            'qty.numeric' => 'Количество — число',
            'qty.min' => 'Количество не может быть отрицательным',
            'qty.decimal' => 'Количество — не более четырёх знаков после запятой',
        ];
    }
}
