<?php
/**
 * Secure download for module_email_attachments.
 */
error_reporting(0);

try {
    require_once __DIR__ . '/../../../includes/config.php';
    require_once __DIR__ . '/../../../includes/functions.php';
    require_once __DIR__ . '/../includes/email_bootstrap.php';
    requireLogin();

    $user_id = (int) ($_SESSION['user_id'] ?? 0);
    $attId = (int) ($_GET['id'] ?? 0);
    if ($attId <= 0) {
        http_response_code(400);
        exit('Missing attachment id');
    }

    $emailDb = email_module_pdo();
    if (!($emailDb instanceof PDO)) {
        http_response_code(500);
        exit('Database unavailable');
    }

    $stmt = $emailDb->prepare(
        "SELECT a.id, a.file_name, a.file_path, a.file_type, a.email_id
         FROM module_email_attachments a
         INNER JOIN module_emails e ON e.id = a.email_id
         WHERE a.id = ? AND (e.user_id = ? OR e.user_id = 0)
         LIMIT 1"
    );
    $stmt->execute([$attId, $user_id]);
    $att = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$att) {
        http_response_code(404);
        exit('Attachment not found');
    }

    $rel = str_replace('\\', '/', (string) ($att['file_path'] ?? ''));
    $rel = ltrim($rel, '/');
    if ($rel === '' || strpos($rel, '..') !== false) {
        http_response_code(400);
        exit('Invalid path');
    }

    $full = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($full)) {
        // Fallback: filename only under uploads/email_attachments
        $fallback = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'email_attachments' . DIRECTORY_SEPARATOR . basename($rel);
        if (is_file($fallback)) {
            $full = $fallback;
        } else {
            http_response_code(404);
            exit('File missing on disk');
        }
    }

    $name = basename((string) ($att['file_name'] ?? 'attachment'));
    $mime = (string) ($att['file_type'] ?? '');
    if ($mime === '' || $mime === 'application/octet-stream') {
        $mime = function_exists('mime_content_type') ? (string) (mime_content_type($full) ?: 'application/octet-stream') : 'application/octet-stream';
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($full));
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($full);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    exit('Download failed');
}
