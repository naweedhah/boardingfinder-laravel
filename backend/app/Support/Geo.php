<?php

namespace App\Support;

/**
 * Small, dependency-free geo helpers used by the "near a campus" search.
 *
 * The search runs in two steps so it works the same on SQLite, MySQL and PostgreSQL
 * (SQLite has no trigonometric functions by default):
 *   1. a bounding-box WHERE on the indexed latitude/longitude columns narrows the rows cheaply;
 *   2. the exact great-circle (Haversine) distance is computed in PHP for that short list.
 */
final class Geo
{
    public const EARTH_RADIUS_KM = 6371.0;

    /** Sri Lanka's extent (with a small margin), used to reject obviously wrong coordinates. */
    public const SRI_LANKA = [
        'minLat' => 5.85,
        'maxLat' => 9.9,
        'minLng' => 79.5,
        'maxLng' => 81.95,
    ];

    /** Great-circle distance between two points in kilometres. */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * The lat/lng rectangle that fully contains a circle of $radiusKm around the point.
     *
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     */
    public static function boundingBox(float $lat, float $lng, float $radiusKm): array
    {
        $latDelta = rad2deg($radiusKm / self::EARTH_RADIUS_KM);
        // Lines of longitude converge towards the poles, so the same distance spans more degrees.
        $lngDelta = rad2deg($radiusKm / (self::EARTH_RADIUS_KM * max(cos(deg2rad($lat)), 1e-6)));

        return [
            'minLat' => $lat - $latDelta,
            'maxLat' => $lat + $latDelta,
            'minLng' => $lng - $lngDelta,
            'maxLng' => $lng + $lngDelta,
        ];
    }
}
