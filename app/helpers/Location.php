<?php
/**
 * Location Helper - Distance calculation and location utilities
 */

class Location {
    
    // Earth's radius in kilometers
    const EARTH_RADIUS_KM = 6371;
    
    /**
     * Calculate distance between two coordinates using Haversine formula
     * 
     * @param float $lat1 Latitude of first point
     * @param float $lon1 Longitude of first point
     * @param float $lat2 Latitude of second point
     * @param float $lon2 Longitude of second point
     * @return float Distance in kilometers
     */
    public static function calculateDistance($lat1, $lon1, $lat2, $lon2) {
        // Convert degrees to radians
        $lat1Rad = deg2rad($lat1);
        $lon1Rad = deg2rad($lon1);
        $lat2Rad = deg2rad($lat2);
        $lon2Rad = deg2rad($lon2);
        
        // Calculate differences
        $latDiff = $lat2Rad - $lat1Rad;
        $lonDiff = $lon2Rad - $lon1Rad;
        
        // Haversine formula
        $a = sin($latDiff / 2) * sin($latDiff / 2) +
             cos($lat1Rad) * cos($lat2Rad) * sin($lonDiff / 2) * sin($lonDiff / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = self::EARTH_RADIUS_KM * $c;
        
        return round($distance, 2);
    }
    
    /**
     * Get nearby locations within radius
     * 
     * @param array $stations Array of stations with lat/lon
     * @param float $userLat User latitude
     * @param float $userLon User longitude
     * @param float $radiusKm Radius in kilometers
     * @return array Filtered stations sorted by distance
     */
    public static function getNearbyLocations($stations, $userLat, $userLon, $radiusKm = 5) {
        $nearby = [];
        
        foreach ($stations as $station) {
            $distance = self::calculateDistance(
                $userLat, 
                $userLon, 
                $station['latitude'], 
                $station['longitude']
            );
            
            if ($distance <= $radiusKm) {
                $station['distance'] = $distance;
                $nearby[] = $station;
            }
        }
        
        // Sort by distance ascending
        usort($nearby, function($a, $b) {
            return $a['distance'] <=> $b['distance'];
        });
        
        return $nearby;
    }
    
}

?>