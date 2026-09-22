<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->username)) {
            $this->merge(['username' => trim($this->username)]);
        }
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'username' => ['bail', 'required', 'string', 'min:3'],
            'password' => ['bail', 'required', 'string', 'min:8'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'username.*' => 'Enter a valid username to continue.',
            'password.*' => 'Password must be at least 8 characters long.',
        ];
    }
}
