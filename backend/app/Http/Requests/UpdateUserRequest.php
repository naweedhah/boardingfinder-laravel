<?php

namespace App\Http\Requests;

use App\Enums\Gender;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('user'));
    }

    /**
     * The profile form submits every field, blank when unchanged, so blanks mean "keep as is".
     * Only these fields are accepted: role, activation and verification are never user-editable.
     */
    protected function prepareForValidation(): void
    {
        $blankToNull = fn ($value) => is_string($value) && trim($value) === '' ? null : $value;

        $this->merge(array_map($blankToNull, $this->only([
            'username', 'email', 'password', 'fullName', 'phone', 'gender', 'avatar',
        ])));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'fullName' => ['nullable', 'string', 'min:2', "regex:/^[A-Za-z\\s'.-]+$/"],
            'username' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9_]{3,20}$/', Rule::unique('users', 'username')->ignore($user)],
            'email' => ['nullable', 'string', 'email', Rule::unique('users', 'email')->ignore($user)],
            'phone' => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'password' => ['nullable', 'string', 'min:8'],
            'avatar' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fullName.min' => 'Full name must be at least 2 characters long.',
            'fullName.regex' => 'Full name can only contain letters and common name symbols.',
            'username.regex' => 'Username must be 3-20 characters and use only letters, numbers, or underscores.',
            'username.unique' => 'That username is already taken.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'That email is already registered.',
            'phone.regex' => 'Phone number must contain exactly 10 digits.',
            'gender.enum' => 'Select a valid gender.',
            'password.min' => 'Password must be at least 8 characters long.',
        ];
    }
}
