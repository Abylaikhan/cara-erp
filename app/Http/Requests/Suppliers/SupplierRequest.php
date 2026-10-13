<?php

namespace App\Http\Requests\Suppliers;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Supplier card of legacy saveSupplierCard (L18619): the same fields, including those legacy kept only in page memory
 * (address, lead time, yearly turnover, defect share). Numbers are exact and never negative.
 */
class SupplierRequest extends FormRequest
{
    private const TEXT = ['category', 'contact', 'phone', 'email', 'bin', 'address', 'comment'];

    public function authorize(): bool
    {
        return true; // page access is checked by the route
    }

    protected function prepareForValidation(): void
    {
        $trimOrNull = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;
        $numberOrNull = fn ($v) => $v === null || trim((string) $v) === '' ? null : $v;
        $merge = ['name' => trim((string) $this->input('name'))];
        foreach (self::TEXT as $field) {
            $merge[$field] = $trimOrNull($this->input($field));
        }
        foreach (['lead_time_days', 'turnover', 'defect_pct', 'expense_category_id', 'expense_article_id'] as $field) {
            $merge[$field] = $numberOrNull($this->input($field));
        }
        $merge['status'] = $this->input('status') ?: 'active';
        $merge['expense_source'] = $this->input('expense_source') ?: 'payment';
        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in(array_keys(Supplier::STATUSES))],
            'contact' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'string', 'max:255'],
            'bin' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'turnover' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999.99', 'decimal:0,2'],
            'defect_pct' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'expense_category_id' => ['nullable', 'integer'],
            'expense_article_id' => ['nullable', 'integer'],
            'expense_source' => ['required', Rule::in(Supplier::EXPENSE_SOURCES)],
            'lock_version' => [$this->isMethod('PUT') ? 'required' : 'nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Укажите название поставщика',
            'name.max' => 'Название — не длиннее 255 символов',
            'lead_time_days.integer' => 'Срок поставки — целое число дней',
            'lead_time_days.min' => 'Срок поставки не может быть отрицательным',
            'turnover.min' => 'Оборот не может быть отрицательным',
            'turnover.decimal' => 'Оборот — не более двух знаков после запятой',
            'defect_pct.min' => '% брака — от 0 до 100',
            'defect_pct.max' => '% брака — от 0 до 100',
        ];
    }

    /** @return array<string, mixed> */
    public function fields(): array
    {
        return $this->safe()->only(['name', 'category', 'status', 'contact', 'phone', 'email', 'bin', 'address', 'lead_time_days', 'turnover', 'defect_pct', 'comment', 'expense_source']);
    }
}
