<?php

namespace App\Http\Requests;

use App\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'fullName' => is_string($this->fullName) ? trim($this->fullName) : $this->fullName,
            'username' => is_string($this->username) ? trim($this->username) : $this->username,
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            'phone' => is_string($this->phone) ? trim($this->phone) : $this->phone,
        ]);
    }

    /**
     * Rules run in the order the registration form asks for the fields, and `bail` keeps it to
     * one message per field.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'fullName' => ['bail', 'required', 'string', 'min:2', "regex:/^[A-Za-z\\s'.-]+$/"],
            'username' => ['bail', 'required', 'string', 'regex:/^[a-zA-Z0-9_]{3,20}$/'],
            'email' => ['bail', 'required', 'string', 'email'],
            'phone' => ['bail', 'required', 'string', 'regex:/^\d{10}$/'],
            'password' => ['bail', 'required', 'string', 'min:8'],
            'gender' => ['bail', 'required', Rule::enum(Gender::class)],
            // Admins are provisioned by hand, never through public sign-up.
            'role' => ['bail', 'required', Rule::in(['student', 'boardingOwner'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fullName.required' => 'Full name must be at least 2 characters long.',
            'fullName.min' => 'Full name must be at least 2 characters long.',
            'fullName.regex' => 'Full name can only contain letters and common name symbols.',
            'username.required' => 'Username must be 3-20 characters and use only letters, numbers, or underscores.',
            'username.regex' => 'Username must be 3-20 characters and use only letters, numbers, or underscores.',
            'email.required' => 'Enter a valid email address.',
            'email.email' => 'Enter a valid email address.',
            'phone.required' => 'Phone number must contain exactly 10 digits.',
            'phone.regex' => 'Phone number must contain exactly 10 digits.',
            'password.required' => 'Password must be at least 8 characters long.',
            'password.min' => 'Password must be at least 8 characters long.',
            'gender.required' => 'Select a valid gender.',
            'gender.enum' => 'Select a valid gender.',
            'role.required' => 'Select a valid user type.',
            'role.in' => 'Select a valid user type.',
        ];
    }
}
