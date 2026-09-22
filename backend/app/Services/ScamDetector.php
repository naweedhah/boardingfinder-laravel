<?php

namespace App\Services;

use App\Enums\ScamFlag;

/**
 * Naive keyword screening for classic rental-scam phrasing. Cheap enough to run on every
 * message; anything flagged is surfaced to the recipient and to admins.
 */
class ScamDetector
{
    public function detect(string $text): ScamFlag
    {
        $text = mb_strtolower($text);

        if (str_contains($text, 'advance payment') || str_contains($text, 'send money')) {
            return ScamFlag::Danger;
        }

        if (str_contains($text, 'urgent')) {
            return ScamFlag::Warning;
        }

        return ScamFlag::Safe;
    }
}
