<?php

namespace App\Http\Requests\Money;

use App\Support\Erp;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Legacy bankAccountModal/saveBankAccount (L6691–6743, L15139): type, name, bank, account number (ИИК), БИК, opening balance,
 * currency. Legacy refused an empty name («Укажите название счёта») and its number field takes no negative balance.
 */
class MoneyAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Erp::canAccess($this->user(), 'money');
    }

    protected function prepareForValidation(): void
    {
        $text = fn (string $f) => ($v = trim((string) $this->input($f, ''))) === '' ? null : $v;
        $this->merge(['name' => $text('name'), 'bank' => $text('bank'), 'iik' => $text('iik'), 'bik' => $text('bik'),
            'opening' => $this->filled('opening') ? str_replace([' ', ','], ['', '.'], (string) $this->input('opening')) : '0']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:bank,cash'],
            'name' => ['required', 'string', 'max:100'],
            'bank' => ['nullable', 'string', 'max:255'],
            'iik' => ['nullable', 'string', 'max:34'],
            'bik' => ['nullable', 'string', 'max:16'],
            'opening' => ['required', 'numeric', 'min:0', 'max:9999999999999999', 'decimal:0,2'],
            'currency' => ['required', 'in:KZT,USD,EUR,RUB'],
            'lock_version' => $this->isMethod('post') ? ['nullable'] : ['required', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Укажите название счёта',
            'opening.numeric' => 'Начальный остаток — число',
            'opening.min' => 'Начальный остаток не может быть меньше нуля',
            'opening.decimal' => 'Начальный остаток — не больше двух знаков после запятой',
        ];
    }
}
