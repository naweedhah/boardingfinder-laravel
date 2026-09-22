<?php

namespace App\Support;

use Illuminate\Support\Str;

class Keys
{
    /**
     * The client sends camelCase JSON; columns are snake_case.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function snake(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $out[Str::snake($key)] = $value;
        }

        return $out;
    }
}
