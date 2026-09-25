<?php
require_once __DIR__ . '/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_all_read') {
        markAllNotificationsReadForCurrentUser();
        echo json_encode(['ok' => true]);
        exit;
    } elseif ($action === 'mark_read') {
        $rawId = isset($_POST['id']) ? trim((string) $_POST['id']) : '';
        if ($rawId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'invalid id']);
            exit;
        }
        if (preg_match('/^s(\d+)$/i', $rawId, $m)) {
            markNotificationRead((int) $m[1]);
        } elseif (preg_match('/^c(\d+)$/i', $rawId, $m)) {
            markCoreNotificationRead((int) $m[1]);
        } elseif (ctype_digit($rawId)) {
            markNotificationRead((int) $rawId);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'invalid id']);
            exit;
        }
        echo json_encode(['ok' => true]);
        exit;
    } elseif ($action === 'dismiss') {
        $rawId = isset($_POST['id']) ? trim((string) $_POST['id']) : '';
        if ($rawId === '' || !dismissNotificationForCurrentUser($rawId)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid id']);
            exit;
        }
        echo json_encode(['ok' => true]);
        exit;
    } elseif ($action === 'clear_read') {
        $cleared = clearReadNotificationsForCurrentUser();
        echo json_encode(['ok' => true, 'cleared' => $cleared]);
        exit;
    } elseif ($action === 'get_prefs' || $action === 'save_prefs') {
        $lib = dirname(__DIR__) . '/notifications-ui/lib.php';
        if (is_file($lib)) {
            require_once $lib;
        }
        if ($action === 'get_prefs') {
            echo json_encode([
                'ok' => true,
                'preferences' => function_exists('notificationsUiGetPreferences')
                    ? notificationsUiGetPreferences()
                    : ['modules' => [], 'emailAlerts' => false],
                'moduleOptions' => function_exists('notificationsUiModuleOptions')
                    ? notificationsUiModuleOptions()
                    : [],
            ]);
            exit;
        }
        $modules = $_POST['modules'] ?? null;
        if (is_string($modules)) {
            $decoded = json_decode($modules, true);
            $modules = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($modules)) {
            $modules = [];
        }
        $emailAlerts = !empty($_POST['emailAlerts']) && (string) $_POST['emailAlerts'] !== '0';
        $saved = function_exists('notificationsUiSavePreferences')
            ? notificationsUiSavePreferences([
                'modules' => $modules,
                'emailAlerts' => $emailAlerts,
            ])
            : null;
        if ($saved === null) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'save failed']);
            exit;
        }
        echo json_encode(['ok' => true, 'preferences' => $saved]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
    exit;
} else {
    // GET -> list
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
    $data = [
        'unread' => getUnreadCountForCurrentUser(),
        'items' => getNotificationsForCurrentUser($limit),
    ];
    echo json_encode($data);
    exit;
}
