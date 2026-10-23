<?php

namespace App\Http\Requests\Warehouse;

use App\Support\Erp;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Legacy whDocModal for «📥 Приходная накладная» (L6440–6530): date, number, supplier, ESF (основание), note, lines of
 * material, quantity and price. «✅ Провести» posts, «Сохранить черновик» does not (`post`).
 */
class StockReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Erp::canAccess($this->user(), 'warehouse');
    }

    protected function prepareForValidation(): void
    {
        $text = fn (string $f) => ($v = trim((string) $this->input($f, ''))) === '' ? null : $v;
        $num = fn ($v) => $v === null || $v === '' ? '0' : str_replace([' ', ','], ['', '.'], (string) $v);
        $this->merge([
            'num' => $text('num'), 'supplier' => $text('supplier'), 'esf_ref' => $text('esf_ref'), 'note' => $text('note'),
            'lines' => array_values(array_map(fn ($l) => ['material_id' => (int) ($l['material_id'] ?? 0), 'qty' => $num($l['qty'] ?? null), 'price' => $num($l['price'] ?? null)],
                array_filter((array) $this->input('lines', []), 'is_array'))),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'num' => ['nullable', 'string', 'max:32'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'esf_ref' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:1000'],
            'post' => ['required', 'boolean'],
            'lines' => ['array', 'max:500'],
            'lines.*.material_id' => ['integer', 'min:0'],
            'lines.*.qty' => ['numeric', 'min:0', 'max:999999999999999', 'decimal:0,3'],
            'lines.*.price' => ['numeric', 'min:0', 'max:9999999999999999', 'decimal:0,2'],
            'lock_version' => $this->isMethod('post') ? ['nullable'] : ['required', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date.required' => 'Укажите дату',
            'lines.*.qty.decimal' => 'Количество — не больше трёх знаков после запятой',
            'lines.*.price.decimal' => 'Цена — не больше двух знаков после запятой',
            'lines.*.qty.min' => 'Количество не может быть меньше нуля',
            'lines.*.price.min' => 'Цена не может быть меньше нуля',
        ];
    }
}
