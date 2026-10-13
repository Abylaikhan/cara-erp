<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Product form of legacy saveProduct (L1094): same messages; prices are exact decimals, never negative. */
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // page access is checked by the route, field rights by ProductService/ProductPolicy
    }

    protected function prepareForValidation(): void
    {
        $trimOrNull = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'category' => trim((string) $this->input('category')),
            'sku' => $trimOrNull($this->input('sku')),
            'description' => $trimOrNull($this->input('description')),
            'price_retail' => $this->input('price_retail') === '' || $this->input('price_retail') === null ? 0 : $this->input('price_retail'),
            'price_wholesale' => $this->input('price_wholesale') === '' || $this->input('price_wholesale') === null ? 0 : $this->input('price_wholesale'),
            'unit' => $this->input('unit') ?: 'шт',
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'price_retail' => ['numeric', 'min:0', 'max:9999999999999999.99', 'decimal:0,2'],
            'price_wholesale' => ['numeric', 'min:0', 'max:9999999999999999.99', 'decimal:0,2'],
            'unit' => ['required', Rule::in(Product::UNITS)],
            'sku' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:5000'],
            'delete_requested' => ['boolean'],
            'lock_version' => [$this->isMethod('PUT') ? 'required' : 'nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Введите наименование',
            'category.required' => 'Введите категорию',
            'price_retail.min' => 'Цена не может быть отрицательной',
            'price_wholesale.min' => 'Цена не может быть отрицательной',
            'price_retail.decimal' => 'Цена — не более двух знаков после запятой',
            'price_wholesale.decimal' => 'Цена — не более двух знаков после запятой',
        ];
    }

    /** @return array<string, mixed> */
    public function fields(): array
    {
        return $this->safe()->only(['name', 'category', 'price_retail', 'price_wholesale', 'unit', 'sku', 'description']);
    }
}
