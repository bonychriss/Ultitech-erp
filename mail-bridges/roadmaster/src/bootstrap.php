<?php

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(array(
        'status' => 'error',
        'message' => 'Missing config.php. Copy config.sample.php to config.php and fill in credentials.',
    ));
    exit;
}

/** @var array $CONFIG */
$CONFIG = require $configPath;
if (!is_array($CONFIG)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(array(
        'status' => 'error',
        'message' => 'config.php must return an array.',
    ));
    exit;
}

require_once __DIR__ . '/JsonResponse.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/ImapService.php';
require_once __DIR__ . '/SmtpService.php';

function bridge_json_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return !empty($_POST) ? $_POST : array();
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : (!empty($_POST) ? $_POST : array());
}
