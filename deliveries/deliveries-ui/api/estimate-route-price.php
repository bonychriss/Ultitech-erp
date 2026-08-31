<?php

declare(strict_types=1);

/**
 * Estimate delivery route price from pickup and destination.
 */
require_once __DIR__ . '/../../config/database.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

/**
 * @return array{lat:float,lng:float,label:string}|null
 */
function deliveries_geocode_location(string $query, ?float $lat = null, ?float $lng = null): ?array
{
    $query = trim($query);
    if ($query === '' && ($lat === null || $lng === null)) {
        return null;
    }

    if ($lat !== null && $lng !== null) {
        return ['lat' => $lat, 'lng' => $lng, 'label' => $query !== '' ? $query : "{$lat},{$lng}"];
    }

    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . rawurlencode($query);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: ERP-Deliveries/1.0\r\nAccept: application/json\r\n",
            'timeout' => 12,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return null;
    }
    $rows = json_decode($raw, true);
    if (!is_array($rows) || !isset($rows[0]['lat'], $rows[0]['lon'])) {
        return null;
    }

    return [
        'lat' => (float) $rows[0]['lat'],
        'lng' => (float) $rows[0]['lon'],
        'label' => (string) ($rows[0]['display_name'] ?? $query),
    ];
}

/**
 * @return array{distanceM:float,durationS:float}|null
 */
function deliveries_fetch_route_metrics(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
{
    $url = sprintf(
        'https://router.project-osrm.org/route/v1/driving/%F,%F;%F,%F?overview=false',
        $fromLng,
        $fromLat,
        $toLng,
        $toLat
    );
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: ERP-Deliveries/1.0\r\nAccept: application/json\r\n",
            'timeout' => 12,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return null;
    }
    $json = json_decode($raw, true);
    if (!is_array($json) || ($json['code'] ?? '') !== 'Ok' || empty($json['routes'][0])) {
        return null;
    }
    $route = $json['routes'][0];

    return [
        'distanceM' => (float) ($route['distance'] ?? 0),
        'durationS' => (float) ($route['duration'] ?? 0),
    ];
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $pickup = trim((string) ($input['pickup'] ?? ''));
    $destination = trim((string) ($input['destination'] ?? ''));
    $pickupLat = isset($input['pickup_lat']) && $input['pickup_lat'] !== '' ? (float) $input['pickup_lat'] : null;
    $pickupLng = isset($input['pickup_lng']) && $input['pickup_lng'] !== '' ? (float) $input['pickup_lng'] : null;
    $destLat = isset($input['destination_lat']) && $input['destination_lat'] !== '' ? (float) $input['destination_lat'] : null;
    $destLng = isset($input['destination_lng']) && $input['destination_lng'] !== '' ? (float) $input['destination_lng'] : null;

    if ($destination === '') {
        echo json_encode(['ok' => false, 'error' => 'Destination is required for a route estimate.']);
        exit;
    }
    if ($pickup === '' && ($pickupLat === null || $pickupLng === null)) {
        echo json_encode(['ok' => false, 'error' => 'Enter a start location (From) to estimate route price.']);
        exit;
    }

    $from = deliveries_geocode_location($pickup, $pickupLat, $pickupLng);
    $to = deliveries_geocode_location($destination, $destLat, $destLng);
    if ($from === null || $to === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'Could not locate one or both addresses. Try pinning them on the map.',
        ]);
        exit;
    }

    $metrics = deliveries_fetch_route_metrics($from['lat'], $from['lng'], $to['lat'], $to['lng']);
    if ($metrics === null) {
        echo json_encode(['ok' => false, 'error' => 'Could not calculate a driving route between these points.']);
        exit;
    }

    $pricing = is_array($input['pricing'] ?? null) ? $input['pricing'] : [];
    $baseFare = max(0, (float) ($pricing['baseFare'] ?? 5000));
    $ratePerKm = max(0, (float) ($pricing['ratePerKm'] ?? 1500));
    $minimumFare = max(0, (float) ($pricing['minimumFare'] ?? 8000));
    $currency = trim((string) ($pricing['currency'] ?? 'TZS')) ?: 'TZS';

    $distanceKm = round($metrics['distanceM'] / 1000, 1);
    $durationMin = max(1, (int) round($metrics['durationS'] / 60));
    $estimatedPrice = round(max($minimumFare, $baseFare + ($distanceKm * $ratePerKm)), 2);

    echo json_encode([
        'ok' => true,
        'data' => [
            'distanceKm' => $distanceKm,
            'durationMin' => $durationMin,
            'estimatedPrice' => $estimatedPrice,
            'currency' => $currency,
            'baseFare' => $baseFare,
            'ratePerKm' => $ratePerKm,
            'minimumFare' => $minimumFare,
            'note' => "Estimate based on {$distanceKm} km driving distance.",
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('deliveries/deliveries-ui/api/estimate-route-price.php failed: ' . $e->getMessage());
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'Route price estimate is unavailable right now.']);
}
