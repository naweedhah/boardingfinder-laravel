<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Server-side allow-list sanitising for rich-text fields (listing descriptions come from a
 * Quill editor as HTML). The client also runs DOMPurify before rendering, but the API must not
 * trust that: any other client, or a direct API call, could store a script payload.
 */
final class HtmlSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    /** Only the formatting the editor can produce. Links are forced to open safely. */
    private const ALLOWED = 'p,br,strong,b,em,i,u,s,ol,ul,li,blockquote,h1,h2,h3,a[href]';

    public static function clean(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        return trim(self::purifier()->purify($html));
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', self::ALLOWED);
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            $config->set('HTML.TargetBlank', true);
            $config->set('HTML.Nofollow', true);
            $config->set('AutoFormat.RemoveEmpty', true);

            $cacheDir = storage_path('framework/cache/htmlpurifier');
            if (! is_dir($cacheDir)) {
                @mkdir($cacheDir, 0775, true);
            }
            $config->set('Cache.SerializerPath', $cacheDir);

            self::$purifier = new HTMLPurifier($config);
        }

        return self::$purifier;
    }
}
