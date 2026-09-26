<?php
require_once __DIR__ . '/functions.php';

if (!isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    // Merge JSON body into $_POST when present (or when form POST is empty).
    $rawIn = file_get_contents('php://input');
    if (is_string($rawIn) && $rawIn !== '') {
        $jsonBody = json_decode($rawIn, true);
        if (is_array($jsonBody)) {
            foreach ($jsonBody as $k => $v) {
                if (!array_key_exists($k, $_POST)) {
                    $_POST[$k] = $v;
                }
            }
        } elseif (empty($_POST)) {
            parse_str($rawIn, $parsed);
            if (is_array($parsed)) {
                foreach ($parsed as $k => $v) {
                    $_POST[$k] = $v;
                }
            }
        }
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'mark_all_read') {
        markAllNotificationsReadForCurrentUser();
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'mark_read') {
        $rawId = isset($_POST['id']) ? trim((string) $_POST['id']) : '';
        if ($rawId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid id']);
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
            echo json_encode(['ok' => false, 'error' => 'invalid id']);
            exit;
        }
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'dismiss') {
        $rawId = isset($_POST['id']) ? trim((string) $_POST['id']) : '';
        if ($rawId === '' || !dismissNotificationForCurrentUser($rawId)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid id']);
            exit;
        }
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'clear_read') {
        $cleared = clearReadNotificationsForCurrentUser();
        echo json_encode(['ok' => true, 'cleared' => $cleared]);
        exit;
    }
    if ($action === 'get_prefs' || $action === 'save_prefs') {
        $lib = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'notifications-ui' . DIRECTORY_SEPARATOR . 'lib.php';
        if (!is_file($lib)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Preferences library missing']);
            exit;
        }
        require_once $lib;
        if ($action === 'get_prefs') {
            echo json_encode([
                'ok' => true,
                'preferences' => notificationsUiGetPreferences(),
                'moduleOptions' => notificationsUiModuleOptions(),
            ]);
            exit;
        }

        $modules = $_POST['modules'] ?? [];
        if (is_string($modules)) {
            $decoded = json_decode($modules, true);
            $modules = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($modules)) {
            $modules = [];
        }
        $emailRaw = $_POST['emailAlerts'] ?? false;
        $emailAlerts = is_bool($emailRaw)
            ? $emailRaw
            : filter_var($emailRaw, FILTER_VALIDATE_BOOLEAN);

        $result = notificationsUiSavePreferencesResult([
            'modules' => $modules,
            'emailAlerts' => $emailAlerts,
        ]);
        if (empty($result['ok'])) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'error' => (string) ($result['error'] ?? 'save failed'),
            ]);
            exit;
        }
        echo json_encode([
            'ok' => true,
            'preferences' => $result['preferences'] ?? null,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'unknown action', 'action' => $action]);
    exit;
}

// GET -> list
$limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 10;
$data = [
    'unread' => getUnreadCountForCurrentUser(),
    'items' => getNotificationsForCurrentUser($limit),
];
echo json_encode($data);
