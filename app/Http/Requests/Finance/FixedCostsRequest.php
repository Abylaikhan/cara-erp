<?php

namespace App\Http\Requests\Finance;

use App\Support\Erp;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Legacy fc-plan-table (L5763–5790, renderFCPlanTable): name, article, plan, day of payment and «✓ Проведено» of each row of
 * a month. Legacy saved any value; here the name is required, the plan is not negative and the day is one of the month (ADR-0055).
 */
class FixedCostsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Erp::canAccess($this->user(), 'finance');
    }

    protected function prepareForValidation(): void
    {
        $rows = $this->input('rows', []);
        if (is_array($rows)) {
            $rows = array_map(fn ($r) => is_array($r) ? [...$r, 'name' => trim((string) ($r['name'] ?? '')),
                'plan' => ($p = trim(str_replace([' ', ','], ['', '.'], (string) ($r['plan'] ?? '')))) === '' ? '0' : $p,
                'pay_day' => ($d = $r['pay_day'] ?? null) === '' ? null : $d] : $r, $rows);
        }
        $this->merge(['rows' => $rows]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['present', 'nullable', 'string', 'max:64'],
            'rows' => ['present', 'array', 'max:200'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.category' => ['required', 'string', 'max:64'],
            'rows.*.plan' => ['required', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'rows.*.approved' => ['required', 'boolean'],
            'rows.*.pay_day' => ['nullable', 'integer', 'between:1,31'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['rows.*.name.required' => 'Введите название статьи затрат', 'rows.*.plan.min' => 'План не может быть отрицательным',
            'rows.*.pay_day.between' => 'День оплаты — от 1 до 31'];
    }
}
