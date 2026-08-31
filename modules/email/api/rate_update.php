<?php

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../../../includes/config.php';
    require_once __DIR__ . '/../../../includes/functions.php';
    require_once __DIR__ . '/../includes/update-badge.php';
    requireLogin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $payload = [];
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    if ($payload === []) {
        $payload = $_POST;
    }

    $rating = (int) ($payload['rating'] ?? 0);
    $comment = trim((string) ($payload['comment'] ?? ''));
    $version = trim((string) ($payload['version'] ?? EMAIL_MODULE_UPDATE_VERSION));
    if ($version === '') {
        $version = EMAIL_MODULE_UPDATE_VERSION;
    }

    if ($rating < 1 || $rating > 5) {
        echo json_encode(['status' => 'error', 'message' => 'Please choose a rating from 1 to 5.']);
        exit;
    }

    if (strlen($comment) > 2000) {
        $comment = substr($comment, 0, 2000);
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
        exit;
    }

    global $pdo;
    if (!($pdo instanceof PDO)) {
        echo json_encode(['status' => 'error', 'message' => 'Database unavailable.']);
        exit;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS module_update_ratings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            module_key VARCHAR(64) NOT NULL,
            update_version VARCHAR(64) NOT NULL,
            rating TINYINT NOT NULL,
            comment TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_module_version (user_id, module_key, update_version),
            KEY idx_module_version (module_key, update_version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $stmt = $pdo->prepare(
        'INSERT INTO module_update_ratings (user_id, module_key, update_version, rating, comment)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), created_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$userId, 'email', $version, $rating, $comment !== '' ? $comment : null]);

    email_module_mark_update_rated();

    echo json_encode([
        'status' => 'success',
        'message' => 'Thanks for your feedback.',
        'rating' => $rating,
    ]);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Could not save rating.']);
}
