<?php
/**
 * Liquidity Dashboard JSON API (React UI).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/ld-lib.php';

function ld_api_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
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

function ld_api_error(string $message, int $code = 400): void
{
    ld_api_json(['success' => false, 'error' => $message], $code);
}

try {
    ldRequireAccess();
    $pdo = ldBootstrap();
} catch (Throwable $e) {
    ld_api_error($e->getMessage(), 500);
}

$action = (string) ($_GET['action'] ?? 'init');

try {
    switch ($action) {
        case 'init':
            ld_api_json([
                'success' => true,
                ...ldBuildInitPayload($pdo),
            ]);
            break;

        default:
            ld_api_error('Unknown action.', 404);
    }
} catch (Throwable $e) {
    ld_api_error($e->getMessage(), 500);
}
