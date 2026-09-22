<?php

namespace App\Enums;

/**
 * Small helpers shared by the string-backed enums.
 */
trait HasValues
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
