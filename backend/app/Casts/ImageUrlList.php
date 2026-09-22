<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A JSON list of image references. Listings created through the web form store absolute
 * (Cloudinary) URLs, while listings created through the owner dashboard store a path on the
 * public disk. Reading always yields URLs the browser can load.
 *
 * @implements CastsAttributes<list<string>, list<string>|null>
 */
class ImageUrlList implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        $paths = $value ? json_decode($value, true) : [];

        return array_values(array_map(
            fn (string $path) => preg_match('#^https?://#i', $path)
                ? $path
                : $this->absolute(Storage::disk('public')->url($path)),
            is_array($paths) ? $paths : [],
        ));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $base = rtrim($this->absolute(Storage::disk('public')->url('')), '/').'/';

        // Round-tripping a value we produced ourselves must not double-prefix it.
        $paths = array_map(
            fn (string $path) => str_starts_with($path, $base) ? substr($path, strlen($base)) : $path,
            array_values($value ?? []),
        );

        return json_encode($paths, JSON_UNESCAPED_SLASHES);
    }

    /** The browser talks to a different origin than the API, so URLs must never be relative. */
    private function absolute(string $url): string
    {
        return preg_match('#^https?://#i', $url) ? $url : url($url);
    }
}
