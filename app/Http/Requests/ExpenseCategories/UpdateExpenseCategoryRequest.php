<?php

namespace App\Http\Requests\ExpenseCategories;

use App\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', ExpenseCategory::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer'],
            'is_fixed' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:-2147483648,2147483647'],
            'lock_version' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'Введите название'];
    }

    /** @return array{name: string, parent_id: int|null, is_fixed: bool, sort_order: int} */
    public function payload(): array
    {
        return [
            'name' => $this->validated('name'),
            'parent_id' => $this->validated('parent_id') !== null ? (int) $this->validated('parent_id') : null,
            'is_fixed' => $this->boolean('is_fixed'),
            'sort_order' => $this->validated('sort_order') ?? 50,
        ];
    }
}
