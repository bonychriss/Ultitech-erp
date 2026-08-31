<?php
/**
 * Backup module JSON API.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/backup-lib.php';

$rawBody = file_get_contents('php://input') ?: '';
$jsonBody = json_decode($rawBody, true);
if (!is_array($jsonBody)) {
    $jsonBody = [];
}

$action = strtolower(trim((string) (
    $_GET['action']
    ?? $_POST['action']
    ?? ($jsonBody['action'] ?? '')
)));
if ($action === '') {
    $action = 'list';
}

try {
    backupDeskBootstrap();
    requireLogin();

    global $pdo;
    $companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : (int) ($_SESSION['company_id'] ?? 0);
    $company = function_exists('getCurrentCompany') ? (getCurrentCompany() ?: []) : [];

    if ($action === 'download') {
        $id = trim((string) ($_GET['id'] ?? ''));
        $path = backupEngineGetZipPath($companyId, $id);
        if ($path === null) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Backup not found.']);
            exit;
        }

        // Prevent ERP HTML/font output buffers from corrupting the binary ZIP.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @set_time_limit(0);

        $filename = basename($path);
        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Could not open backup file.']);
            exit;
        }

        header('Content-Type: application/zip');
        header('Content-Transfer-Encoding: binary');
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
        header('Content-Length: ' . (string) $size);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Stream in chunks so large archives do not get truncated/emptied.
        while (!feof($handle)) {
            $chunk = fread($handle, 1024 * 1024);
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            @flush();
        }
        fclose($handle);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'create') {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'POST required.']);
            exit;
        }

        // Stream live progress as NDJSON so the UI can show percentage.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Accel-Buffering: no');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        $emit = static function (array $payload): void {
            echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            @flush();
        };

        try {
            $emit(['type' => 'progress', 'percent' => 1, 'label' => 'Starting backup...']);
            $result = backupEngineCreate(
                $pdo,
                $companyId,
                $company,
                static function (int $percent, string $label) use ($emit): void {
                    $emit([
                        'type' => 'progress',
                        'percent' => $percent,
                        'label' => $label,
                    ]);
                }
            );
            $emit([
                'type' => 'done',
                'success' => true,
                'message' => 'Backup created successfully.',
                'backup' => $result,
                'backups' => backupEngineList($companyId),
            ]);
        } catch (Throwable $e) {
            error_log('backup create stream: ' . $e->getMessage());
            $emit([
                'type' => 'error',
                'success' => false,
                'message' => $e->getMessage() ?: 'Backup operation failed.',
            ]);
        }
        exit;
    }

    if ($action === 'delete') {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'POST required.']);
            exit;
        }

        $id = trim((string) ($jsonBody['id'] ?? $_POST['id'] ?? ''));
        if (!backupEngineDelete($companyId, $id)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Backup not found or could not be deleted.']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Backup deleted.',
            'backups' => backupEngineList($companyId),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // list / init
    echo json_encode([
        'success' => true,
        'data' => backupDeskFetchPayload($pdo),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('backup api: ' . $e->getMessage());
    if ($action === 'download') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Backup download failed.';
        exit;
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ?: 'Backup operation failed.',
    ]);
}
