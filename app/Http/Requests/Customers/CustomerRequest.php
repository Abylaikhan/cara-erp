<?php

namespace App\Http\Requests\Customers;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Person form of legacy savePerson (L18950): same fields and messages; the IIN/BIN is 12 digits or empty. */
class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // page access is checked by the route, the channel of the customer by CustomerPolicy
    }

    protected function prepareForValidation(): void
    {
        $trimOrNull = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'type' => $this->input('type') ?: Customer::LEGAL,
            'inn' => $trimOrNull($this->input('inn')),
            'phone' => $trimOrNull($this->input('phone')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Customer::FORM_TYPES)],
            'inn' => ['nullable', 'string', 'regex:/^[0-9]{12}$/'],
            'phone' => ['nullable', 'string', 'max:64'],
            'lock_version' => [$this->isMethod('PUT') ? 'required' : 'nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Укажите название/ФИО',
            'name.max' => 'Название/ФИО — не длиннее 255 символов',
            'type.in' => 'Выберите тип: Юр. лицо или Физ. лицо',
            'inn.regex' => ($this->input('type') === Customer::LEGAL ? 'БИН' : 'ИИН').' должен содержать ровно 12 цифр',
            'phone.max' => 'Телефон — не длиннее 64 символов',
        ];
    }

    /** @return array{name: string, type: string, inn: string|null, phone: string|null} */
    public function fields(): array
    {
        return $this->safe()->only(['name', 'type', 'inn', 'phone']);
    }
}
