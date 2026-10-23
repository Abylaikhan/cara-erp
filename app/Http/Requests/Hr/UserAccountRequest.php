<?php

namespace App\Http\Requests\Hr;

use App\Services\UserAccountService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Legacy userModal/saveAppUser (L7412–7492, L22077): login (new accounts only), password, name, role label, sales channel,
 * sections. Legacy asked for at least 4 characters of password; here at least 8 (ADR-0002, item 114). The login is kept as
 * legacy keeps it: trimmed and lower-case.
 */
class UserAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return UserAccountService::canManage($this->user());
    }

    protected function prepareForValidation(): void
    {
        $text = fn (string $f) => ($v = trim((string) $this->input($f, ''))) === '' ? null : $v;
        $this->merge([
            'login' => $this->isMethod('post') ? mb_strtolower(trim((string) $this->input('login', ''))) : null,
            'password' => $text('password'),
            'name' => $text('name'),
            'label' => $text('label'),
            'channel' => $text('channel'),
            'pages' => array_values(array_filter((array) $this->input('pages', []), 'is_string')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $new = $this->isMethod('post');

        return [
            'login' => $new ? ['required', 'string', 'max:64', 'regex:/^[a-z0-9_.\-]+$/'] : ['nullable'],
            'password' => [$new ? 'required' : 'nullable', 'string', 'min:8', 'max:128'],
            'name' => ['nullable', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:128'],
            // legacy u-channel: the channels of the salon and dealer roles; empty — the office
            'channel' => ['nullable', Rule::in(array_values(array_unique(array_filter(array_column(config('erp.roles'), 'channel')))))],
            'pages' => ['array'],
            'pages.*' => [Rule::in(UserAccountService::FORM_PAGES)],
            'lock_version' => $new ? ['nullable'] : ['required', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'login.required' => 'Введите логин',
            'login.regex' => 'Логин — латинские буквы, цифры, точка, дефис, подчёркивание',
            'password.required' => 'Введите пароль',
            'password.min' => 'Пароль минимум 8 символов',
        ];
    }
}
