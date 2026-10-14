<?php

namespace App\Http\Requests\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One inline cell of «Все остатки» (legacy editableCell/startEditCell, L28400): name, SKU, unit, minimum, price. The quantity
 * is not a directory field: it changes only through warehouse documents (docs/adr/0008-warehouse.md).
 */
class MaterialCellRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // page access is checked by the route
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['name', 'unit'] as $field) {
            if ($this->has($field)) {
                $merge[$field] = trim((string) $this->input($field));
            }
        }
        if ($this->has('sku')) {
            $merge['sku'] = ($v = trim((string) $this->input('sku'))) === '' ? null : $v;
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:64'],
            'unit' => ['sometimes', 'required', 'string', 'max:16'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999999999.99', 'decimal:0,2'],
            'min_qty' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999999999999.999', 'decimal:0,3'],
            'qty' => ['prohibited'],
            'lock_version' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Введите наименование',
            'unit.required' => 'Введите единицу измерения',
            'price.min' => 'Цена не может быть отрицательной',
            'price.decimal' => 'Цена — не более двух знаков после запятой',
            'min_qty.min' => 'Минимум не может быть отрицательным',
            'qty.prohibited' => 'Остаток меняется только складскими документами (приход, перемещение, списание, инвентаризация).',
        ];
    }

    /** @return array<string, mixed> */
    public function changes(): array
    {
        return $this->safe()->only(['name', 'sku', 'unit', 'price', 'min_qty']);
    }
}
