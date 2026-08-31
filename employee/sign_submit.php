<?php
require_once '../includes/functions.php';
requireLogin();
header('Content-Type: application/json');

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) { echo json_encode(['ok'=>false,'error'=>'Invalid payload']); exit; }
    $type = $data['type'] ?? '';
    $lat = isset($data['lat']) ? (float)$data['lat'] : 0.0;
    $lon = isset($data['lon']) ? (float)$data['lon'] : 0.0;

    if ($type !== 'in' && $type !== 'out') { echo json_encode(['ok'=>false,'error'=>'Invalid type']); exit; }

    // Map to our function's expected values
    $signType = ($type === 'out') ? 'sign_out' : 'sign_in';
    $device = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $res = recordAttendanceWithAccountSignature((int)$_SESSION['user_id'], $signType, $lat, $lon, 0.0, $device, $ip);
    if (!empty($res['ok'])) {
        // Compute distance for echo (server source of truth)
        $distance = 0;
        if (defined('OFFICE_LAT') && defined('OFFICE_LON')) {
            $distance = haversineDistanceMeters($lat, $lon, (float)OFFICE_LAT, (float)OFFICE_LON);
        }
        echo json_encode(['ok'=>true, 'distance'=>$distance]);
    } else {
        echo json_encode(['ok'=>false, 'error'=>$res['error'] ?? 'Failed to sign']);
    }
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>'Server error']);
}
