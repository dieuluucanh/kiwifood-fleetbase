<?php

namespace App\Support;

/**
 * Pure-PHP geo / duration helpers used by the driver-activity aggregator.
 *
 * Kept dependency-free (no PHP geospatial extension required) so it runs in any
 * Fleetbase image. Distance between two GPS coordinates uses the haversine
 * formula on a spherical earth model (R = 6371 km) — accurate enough for
 * point-to-point driving distance summation.
 */
class Geo
{
    /** Earth's mean radius in kilometers. */
    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * Great-circle distance between two WGS84 points, in kilometers.
     */
    public static function haversineKm($lat1, $lng1, $lat2, $lng2): float
    {
        $lat1 = (float) $lat1;
        $lng1 = (float) $lng1;
        $lat2 = (float) $lat2;
        $lng2 = (float) $lng2;

        if ($lat1 === $lat2 && $lng1 === $lng2) {
            return 0.0;
        }

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Format a duration in seconds as `H:MM` (hours may exceed 24, e.g. `25:30`).
     */
    public static function formatHoursMinutes($seconds): string
    {
        $seconds = max(0, (int) $seconds);

        $hours   = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours . ':' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT);
    }
}
