<?php

namespace App\Http\Requests;

class StorePostRequest extends PostPayloadRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function presence(): string
    {
        return 'required';
    }
}
