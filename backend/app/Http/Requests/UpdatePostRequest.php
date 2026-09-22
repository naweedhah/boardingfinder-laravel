<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class UpdatePostRequest extends PostPayloadRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('post'));
    }

    protected function presence(): string
    {
        return 'sometimes';
    }
}
