<?php

namespace Tests\Unit;

use App\Support\Geo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GeoTest extends TestCase
{
    #[Test]
    public function distance_between_colombo_and_kandy_matches_the_known_great_circle_distance(): void
    {
        // Colombo Fort -> Kandy city centre is ~94 km in a straight line.
        $this->assertEqualsWithDelta(94.3, Geo::distanceKm(6.9271, 79.8612, 7.2906, 80.6337), 1.0);
    }

    #[Test]
    public function distance_is_zero_for_the_same_point_and_symmetric(): void
    {
        $this->assertSame(0.0, Geo::distanceKm(6.9, 79.9, 6.9, 79.9));
        $this->assertEqualsWithDelta(
            Geo::distanceKm(6.9, 79.9, 7.1, 80.2),
            Geo::distanceKm(7.1, 80.2, 6.9, 79.9),
            1e-9,
        );
    }

    #[Test]
    public function the_bounding_box_contains_every_point_on_the_circle(): void
    {
        $lat = 6.9147;
        $lng = 79.9729;
        $radius = 10.0;
        $box = Geo::boundingBox($lat, $lng, $radius);

        // Walk the circle and check each point sits inside the box.
        for ($bearing = 0; $bearing < 360; $bearing += 15) {
            $b = deg2rad($bearing);
            $d = $radius / Geo::EARTH_RADIUS_KM;
            $lat1 = deg2rad($lat);
            $lat2 = asin(sin($lat1) * cos($d) + cos($lat1) * sin($d) * cos($b));
            $lng2 = deg2rad($lng) + atan2(sin($b) * sin($d) * cos($lat1), cos($d) - sin($lat1) * sin($lat2));

            $this->assertGreaterThanOrEqual($box['minLat'] - 1e-9, rad2deg($lat2));
            $this->assertLessThanOrEqual($box['maxLat'] + 1e-9, rad2deg($lat2));
            $this->assertGreaterThanOrEqual($box['minLng'] - 1e-9, rad2deg($lng2));
            $this->assertLessThanOrEqual($box['maxLng'] + 1e-9, rad2deg($lng2));
        }
    }
}
