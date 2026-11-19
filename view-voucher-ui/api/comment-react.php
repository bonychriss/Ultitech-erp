<?php
declare(strict_types=1);

if (!defined('APP_BASE_PATH')) {
    $docRoot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $appRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
    $base = '';
    if ($docRoot !== '' && strncmp($appRoot, $docRoot, strlen($docRoot)) === 0) {
        $base = trim(substr($appRoot, strlen($docRoot)), '/');
    }
    define('APP_BASE_PATH', $base);
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../load-data.php';
require_once __DIR__ . '/../comment-interactions.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}
if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Your session has expired. Refresh the page and try again.']);
    exit;
}

$voucherId = (int) ($_POST['voucher_id'] ?? 0);
$logId = (int) ($_POST['approval_log_id'] ?? 0);
$reaction = strtolower(trim((string) ($_POST['reaction'] ?? '')));
$catalog = vv_approval_reaction_catalog();

if ($voucherId <= 0 || $logId <= 0 || !isset($catalog[$reaction])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid reaction request.']);
    exit;
}

if (function_exists('voucher_bootstrap_operational_pdo')) {
    voucher_bootstrap_operational_pdo();
}

try {
    $view = vv_load_view_payload($pdo, $voucherId);
    if (!$view['ok']) {
        http_response_code((int) ($view['code'] ?? 403));
        echo json_encode(['ok' => false, 'error' => $view['error'] ?? 'Access denied.']);
        exit;
    }

    vv_ensure_approval_comment_tables($pdo);

    $check = $pdo->prepare(
        "SELECT id, user_id FROM approval_logs
         WHERE id = ? AND voucher_id = ?
           AND comments IS NOT NULL AND TRIM(comments) != ''
         LIMIT 1"
    );
    $check->execute([$logId, $voucherId]);
    if (!$check->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Comment not found.']);
        exit;
    }

    $userId = function_exists('resolveVoucherSessionUserId')
        ? (int) resolveVoucherSessionUserId($pdo)
        : (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Sign in to react.']);
        exit;
    }

    $existing = $pdo->prepare(
        'SELECT id FROM approval_comment_reactions
         WHERE approval_log_id = ? AND user_id = ? AND reaction = ?
         LIMIT 1'
    );
    $existing->execute([$logId, $userId, $reaction]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $del = $pdo->prepare('DELETE FROM approval_comment_reactions WHERE id = ?');
        $del->execute([(int) $row['id']]);
        $active = false;
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO approval_comment_reactions (approval_log_id, voucher_id, user_id, reaction)
             VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$logId, $voucherId, $userId, $reaction]);
        $active = true;
        vv_notify_approval_comment_activity($pdo, $voucherId, $logId, $userId, 'reaction', $reaction);
    }

    $reactions = vv_load_comment_reactions($pdo, [$logId], $userId);
    echo json_encode([
        'ok' => true,
        'active' => $active,
        'reactions' => $reactions[$logId] ?? [],
        'notified' => $active,
        'toast' => $active
            ? ('Reaction saved: ' . (string) ($catalog[$reaction]['label'] ?? 'OK'))
            : 'Reaction removed',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('comment-react: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save reaction.']);
}
