<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * PHP only populates `$_POST` / `$_FILES` for POST requests, but the client edits listings with
 * `PUT` + `multipart/form-data` (fields and photos). PHP 8.4+ ships `request_parse_body()` for
 * exactly this case; on older versions send `POST` with `_method=PUT` instead.
 */
class ParseMultipartBody
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            in_array($request->getRealMethod(), ['PUT', 'PATCH', 'DELETE'], true)
            && str_starts_with((string) $request->header('Content-Type'), 'multipart/form-data')
            && function_exists('request_parse_body')
        ) {
            [$fields, $files] = request_parse_body();

            $request->request->add($fields);
            $request->files->add($files);
            // `request_parse_body()` files were not received through PHP's own POST upload
            // handling, so Symfony would reject them as invalid; they are PHP-created temp
            // files from this very request, so mark them as trusted.
            $request->files->replace($this->trusted($request->files->all()));
        }

        return $next($request);
    }

    /**
     * @param  array<string, mixed>  $files
     * @return array<string, mixed>
     */
    private function trusted(array $files): array
    {
        return array_map(fn ($file) => match (true) {
            $file instanceof SymfonyUploadedFile => new UploadedFile(
                $file->getPathname(), $file->getClientOriginalName(), $file->getClientMimeType(), $file->getError(), test: true,
            ),
            is_array($file) => $this->trusted($file),
            default => $file,
        }, $files);
    }
}
