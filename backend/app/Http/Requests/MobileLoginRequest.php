<?php

namespace App\Http\Requests;

/** Same credentials as the web login, plus a device label so users can tell their sessions apart. */
class MobileLoginRequest extends LoginRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'deviceName' => ['bail', 'required', 'string', 'max:60'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'deviceName.*' => 'Device name is required (max 60 characters).',
        ];
    }
}
