<?php

namespace App\Http\Requests\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

/** Alias form of legacy aliasModal/saveAlias (L2672, L27485): material, supplier's name, optional supplier, coefficient, comment. */
class MaterialAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // page access is checked by the route
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'material_name' => trim((string) $this->input('material_name')),
            'alias_name' => trim((string) $this->input('alias_name')),
            'supplier_id' => $this->input('supplier_id') ?: null,
            'coeff' => $this->input('coeff') === null || $this->input('coeff') === '' ? 1 : $this->input('coeff'),
            'comment' => ($v = trim((string) $this->input('comment'))) === '' ? null : $v,
        ]);
    }

    public function rules(): array
    {
        return [
            'material_name' => ['required', 'string', 'max:255'],
            'alias_name' => ['required', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'integer'],
            'coeff' => ['numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'material_name.required' => 'Выберите основной материал',
            'alias_name.required' => 'Введите алиас',
            'alias_name.max' => 'Алиас — не длиннее 255 символов',
            'coeff.gt' => 'Коэффициент пересчёта должен быть больше нуля',
            'coeff.decimal' => 'Коэффициент — не более трёх знаков после запятой',
        ];
    }
}
