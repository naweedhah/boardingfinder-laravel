<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PushTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // Expo issues tokens shaped like ExponentPushToken[xxxxxxxx].
            'token' => ['bail', 'required', 'string', 'max:255', 'regex:/^Expo(nent)?PushToken\[[A-Za-z0-9_\-]+\]$/'],
            'platform' => ['required', 'in:ios,android'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'token.*' => 'A valid Expo push token is required.',
            'platform.*' => 'Platform must be ios or android.',
        ];
    }
}
