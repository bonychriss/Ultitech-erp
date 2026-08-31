<?php
/**
 * Transaction Ledger JSON API (React UI).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/tl-lib.php';

function tl_api_json(array $payload, int $code = 200): void
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

function tl_api_error(string $message, int $code = 400): void
{
    tl_api_json(['success' => false, 'error' => $message], $code);
}

try {
    tlRequireAccess();
    $pdo = tlBootstrap();
} catch (Throwable $e) {
    tl_api_error($e->getMessage(), 500);
}

$action = (string) ($_GET['action'] ?? '');
$filters = tlParseFilters($_GET);
$perPageRaw = (string) ($_GET['per_page'] ?? 'all');
$page = max(1, (int) ($_GET['page'] ?? 1));

try {
    switch ($action) {
        case 'init':
            tl_api_json([
                'success' => true,
                ...tlBuildInitPayload($pdo, $filters),
            ]);
            break;

        case 'list':
            tl_api_json([
                'success' => true,
                ...tlBuildListPayload($pdo, $filters, $perPageRaw, $page),
            ]);
            break;

        case 'ai_search':
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($method !== 'POST' && $method !== 'GET') {
                tl_api_error('Method not allowed', 405);
            }
            $body = [];
            if ($method === 'POST') {
                $raw = file_get_contents('php://input') ?: '';
                $decodedBody = json_decode($raw, true);
                if (is_array($decodedBody)) {
                    $body = $decodedBody;
                } else {
                    $body = $_POST;
                }
            }
            $query = trim((string) ($body['q'] ?? $_GET['q'] ?? ''));
            if ($query === '') {
                tl_api_error('Search query is required.', 400);
            }
            $aiPerPage = (string) ($body['per_page'] ?? $_GET['per_page'] ?? 'all');
            $result = tlRunAiSearch($pdo, $query, $aiPerPage);
            tl_api_json([
                'success' => true,
                ...$result,
            ]);
            break;

        default:
            tl_api_error('Unknown action.', 404);
    }
} catch (Throwable $e) {
    tl_api_error($e->getMessage(), 500);
}
