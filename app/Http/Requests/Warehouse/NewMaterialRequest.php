<?php

namespace App\Http\Requests\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

/**
 * «+ Материал» of legacy addMaterialModal/saveNewMaterial (L4550, L28758): the name is the chosen standard name plus the
 * characteristics. The starting quantity is not taken here: stock arrives by a receipt document (docs/adr/0008-warehouse.md).
 */
class NewMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // page access is checked by the route
    }

    protected function prepareForValidation(): void
    {
        $base = trim((string) $this->input('name'));
        $attrs = trim((string) $this->input('attrs'));
        $orNull = fn ($v) => $v === null || trim((string) $v) === '' ? null : $v;
        $this->merge([
            'name' => $base,
            'full_name' => $attrs !== '' ? $base.' '.$attrs : $base,
            'category' => trim((string) $this->input('category')),
            'unit' => trim((string) $this->input('unit')) ?: 'шт',
            'price' => $orNull($this->input('price')) ?? 0,
            'min_qty' => $orNull($this->input('min_qty')) ?? 0,
            'sku' => $orNull(is_string($this->input('sku')) ? trim($this->input('sku')) : $this->input('sku')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'full_name' => ['string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:16'],
            'price' => ['numeric', 'min:0', 'max:9999999999999999.99', 'decimal:0,2'],
            'min_qty' => ['numeric', 'min:0', 'max:999999999999999.999', 'decimal:0,3'],
            'sku' => ['nullable', 'string', 'max:64'],
            'qty' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Введите или выберите наименование материала',
            'full_name.max' => 'Наименование с характеристиками — не длиннее 255 символов',
            'category.required' => 'Выберите категорию',
            'price.min' => 'Цена не может быть отрицательной',
            'price.decimal' => 'Цена — не более двух знаков после запятой',
            'min_qty.min' => 'Минимальный остаток не может быть отрицательным',
            'qty.prohibited' => 'Начальный остаток вносится приходом на склад.',
        ];
    }

    /** @return array{name: string, category: string, unit: string, price: mixed, min_qty: mixed, sku: string|null} */
    public function fields(): array
    {
        return ['name' => $this->validated('full_name')] + $this->safe()->only(['category', 'unit', 'price', 'min_qty', 'sku']);
    }
}
