<?php

namespace App\Http\Requests\ExpenseCategories;

use App\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;

class MergeExpenseCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', ExpenseCategory::class);
    }

    public function rules(): array
    {
        return [
            'from_id' => ['required', 'integer'],
            'to_id' => ['required', 'integer', 'different:from_id'],
        ];
    }

    public function messages(): array
    {
        return ['to_id.different' => 'Выберите разные статьи'];
    }
}
