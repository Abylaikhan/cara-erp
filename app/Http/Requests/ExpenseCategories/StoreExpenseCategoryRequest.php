<?php

namespace App\Http\Requests\ExpenseCategories;

use App\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;

/** Messages repeat legacy saveExpCat (L11174) so the user sees the same texts. */
class StoreExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', ExpenseCategory::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'code' => trim((string) $this->input('code')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/', 'unique:expense_categories,code'],
            'is_top' => ['required', 'boolean'],
            'parent_id' => ['exclude_if:is_top,true', 'required', 'integer'],
            'is_fixed' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:-2147483648,2147483647'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Введите название',
            'code.required' => 'Введите код',
            'code.regex' => 'Код — только латиница и цифры',
            'code.unique' => 'Такой код уже существует',
            'parent_id.required' => 'Выберите категорию первого уровня',
        ];
    }

    /** @return array{name: string, code: string, parent_id: int|null, is_fixed: bool, sort_order: int} */
    public function payload(): array
    {
        return [
            'name' => $this->validated('name'),
            'code' => $this->validated('code'),
            'parent_id' => $this->boolean('is_top') ? null : (int) $this->validated('parent_id'),
            'is_fixed' => $this->boolean('is_fixed'),
            'sort_order' => $this->validated('sort_order') ?? 50,
        ];
    }
}
