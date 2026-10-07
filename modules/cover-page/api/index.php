<?php
/**
 * Cover Page module JSON API.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/cover-page-lib.php';

header('Content-Type: application/json; charset=utf-8');

$rawBody = file_get_contents('php://input') ?: '';
$jsonBody = json_decode($rawBody, true);
if (!is_array($jsonBody)) {
    $jsonBody = [];
}

$action = strtolower(trim((string) ($_GET['action'] ?? ($jsonBody['action'] ?? 'list'))));

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

try {
    $pdo = coverPageBootstrap();
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        $respond(401, ['success' => false, 'message' => 'Please log in again.']);
    }

    if ($action === 'list') {
        $respond(200, ['success' => true, 'data' => coverPageFetchPayload($pdo)]);
    }

    $isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST';
    $isXhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if (!$isPost || !$isXhr) {
        $respond(405, ['success' => false, 'message' => 'Invalid request.']);
    }

    if ($action === 'save') {
        $id = (int) ($jsonBody['id'] ?? 0);
        if ($id > 0 && coverPageFindEditable($pdo, $id) === null) {
            $respond(404, ['success' => false, 'message' => 'Cover page not found.']);
        }
        $result = coverPageValidate($jsonBody);
        if ($result['errors']) {
            $respond(422, ['success' => false, 'message' => 'Please fix the highlighted fields.', 'errors' => $result['errors']]);
        }
        $savedId = coverPageSave($pdo, $result['data'], $id > 0 ? $id : null);
        $respond(200, [
            'success' => true,
            'message' => $id > 0 ? 'Cover page details updated.' : 'Cover page details saved.',
            'id' => $savedId,
            'covers' => coverPageList($pdo),
        ]);
    }

    if ($action === 'delete') {
        $id = (int) ($jsonBody['id'] ?? 0);
        if ($id <= 0 || coverPageFindEditable($pdo, $id) === null) {
            $respond(404, ['success' => false, 'message' => 'Cover page not found.']);
        }
        $pdo->prepare('DELETE FROM cover_pages WHERE id = ?')->execute([$id]);
        $respond(200, ['success' => true, 'message' => 'Cover page deleted.', 'covers' => coverPageList($pdo)]);
    }

    $respond(400, ['success' => false, 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('cover-page api: ' . $e->getMessage());
    $respond(500, ['success' => false, 'message' => 'Something went wrong. Please try again.']);
}
