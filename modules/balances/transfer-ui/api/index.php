<?php
/**
 * Internal Transfer JSON API (React UI).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/tf-lib.php';

function tf_api_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $flags);
    if ($json === false) {
        $json = json_encode(['success' => false, 'error' => 'Failed to encode API response.'], JSON_UNESCAPED_UNICODE);
    }
    echo $json;
    exit;
}

function tf_api_error(string $message, int $code = 400): void
{
    tf_api_json(['success' => false, 'error' => $message], $code);
}

try {
    tfRequireAccess();
    $pdo = tfBootstrap();
} catch (Throwable $e) {
    tf_api_error($e->getMessage(), 500);
}

$action = (string) ($_GET['action'] ?? '');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    switch ($action) {
        case 'init':
            tf_api_json([
                'success' => true,
                ...tfBuildInitPayload($pdo),
            ]);
            break;

        case 'create':
            if ($method !== 'POST') {
                tf_api_error('Method not allowed', 405);
            }
            $raw = file_get_contents('php://input') ?: '';
            $body = json_decode($raw, true);
            if (!is_array($body)) {
                $body = $_POST;
            }
            $result = tfCreateTransfer($pdo, $body);
            tf_api_json($result);
            break;

        default:
            tf_api_error('Unknown action.', 404);
    }
} catch (InvalidArgumentException $e) {
    tf_api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    tf_api_error($e->getMessage(), 500);
}
