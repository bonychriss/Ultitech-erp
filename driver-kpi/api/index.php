<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../includes/driver_kpi_helpers.php';

header('Content-Type: application/json; charset=utf-8');

function dkpi_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    requireLogin();
} catch (Throwable $e) {
    dkpi_json(['ok' => false, 'error' => 'Unauthorized'], 401);
}

global $pdo;
if (!$pdo instanceof PDO) {
    dkpi_json(['ok' => false, 'error' => 'Database unavailable'], 500);
}

$wmHelpers = __DIR__ . '/../../todo/includes/weekly_mission_helpers.php';
if (is_file($wmHelpers)) {
    require_once $wmHelpers;
}

if (!dkpi_ensure_tables($pdo)) {
    dkpi_json(['ok' => false, 'error' => 'Could not initialize driver KPI tables'], 500);
}

$viewerId = (int) ($_SESSION['user_id'] ?? 0);
$viewerRole = strtolower(trim((string) ($_SESSION['role'] ?? 'employee')));
$isAdmin = in_array($viewerRole, ['admin', 'administrator', 'superadmin', 'super_admin', 'company_admin'], true)
    || (function_exists('isAdmin') && isAdmin());
$viewerIsDriver = dkpi_user_is_driver($pdo, $viewerId);

$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? 'list')));
$input = $_POST;
if (empty($input) && strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw ?: '', true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$weekOffset = (int) ($input['week_offset'] ?? $_GET['week_offset'] ?? 0);
$bounds = dkpi_week_bounds();
if ($weekOffset !== 0) {
    $bounds = dkpi_shift_week($bounds['week_start'], $weekOffset);
}
$weekStart = (string) ($input['week_start'] ?? $_GET['week_start'] ?? $bounds['week_start']);
$bounds = dkpi_week_bounds($weekStart);
$service = dkpi_normalize_service($input['service'] ?? $_GET['service'] ?? 'delivery');

/**
 * Only Driver-department users can own work-log / KPI entries.
 * Admins may record on behalf of a driver account.
 */
function dkpi_require_driver_target(PDO $pdo, int $userId, bool $isAdmin, int $viewerId): void
{
    if ($userId <= 0) {
        dkpi_json(['ok' => false, 'error' => 'Select a driver'], 422);
    }
    if (!$isAdmin && $userId !== $viewerId) {
        dkpi_json(['ok' => false, 'error' => 'Not allowed'], 403);
    }
    if (!dkpi_user_is_driver($pdo, $userId)) {
        dkpi_json([
            'ok' => false,
            'error' => 'Only accounts with department Driver can use the Work log.',
        ], 422);
    }
}

switch ($action) {
    case 'metrics':
        dkpi_json([
            'ok' => true,
            'metrics' => array_values(dkpi_metrics($service)),
            'services' => array_values(dkpi_services()),
            'service' => $service,
            'week' => $bounds,
        ]);

    case 'employees':
        if (!$isAdmin) {
            dkpi_json(['ok' => false, 'error' => 'Admin only'], 403);
        }
        dkpi_json(['ok' => true, 'employees' => dkpi_list_employees($pdo)]);

    case 'get':
        $userId = (int) ($input['user_id'] ?? $_GET['user_id'] ?? $viewerId);
        if (!$isAdmin && $userId !== $viewerId) {
            dkpi_json(['ok' => false, 'error' => 'Not allowed'], 403);
        }
        $entry = dkpi_get_entry($pdo, $userId, $bounds['week_start'], $service);
        dkpi_json([
            'ok' => true,
            'week' => $bounds,
            'service' => $service,
            'entry' => $entry,
            'metrics' => array_values(dkpi_metrics($service)),
        ]);

    case 'list':
        $filterUser = $isAdmin ? (int) ($input['user_id'] ?? $_GET['user_id'] ?? 0) : $viewerId;
        $rows = dkpi_list_entries(
            $pdo,
            $bounds['week_start'],
            $filterUser > 0 ? $filterUser : ($isAdmin ? null : $viewerId),
            $service
        );
        dkpi_json([
            'ok' => true,
            'week' => $bounds,
            'service' => $service,
            'metrics' => array_values(dkpi_metrics($service)),
            'services' => array_values(dkpi_services()),
            'entries' => $rows,
        ]);

    case 'save':
        $userId = (int) ($input['user_id'] ?? $viewerId);
        dkpi_require_driver_target($pdo, $userId, $isAdmin, $viewerId);
        $entry = dkpi_save_entry(
            $pdo,
            $userId,
            $bounds['week_start'],
            (float) ($input['on_time_pct'] ?? 0),
            (float) ($input['vehicle_care_pct'] ?? 0),
            (float) ($input['documentation_pct'] ?? 0),
            $viewerId,
            isset($input['notes']) ? (string) $input['notes'] : null,
            $service,
            $input['work_log'] ?? null
        );
        dkpi_json([
            'ok' => true,
            'entry' => $entry,
            'service' => $service,
            'message' => 'Saved and shared to Performance.',
        ]);

    case 'analyze':
        $userId = (int) ($input['user_id'] ?? $_GET['user_id'] ?? $viewerId);
        dkpi_require_driver_target($pdo, $userId, $isAdmin, $viewerId);
        $existing = dkpi_get_entry($pdo, $userId, $bounds['week_start'], $service) ?: [];
        if (array_key_exists('work_log', $input) && $input['work_log'] !== null) {
            $workLog = dkpi_normalize_work_log($input['work_log']);
        } elseif (is_array($existing['work_log'] ?? null)) {
            $workLog = $existing['work_log'];
        } else {
            $workLog = dkpi_normalize_work_log($existing['work_log_json'] ?? []);
        }
        $analysis = dkpi_analyze_from_work_log($workLog, $service);
        $entry = dkpi_save_entry(
            $pdo,
            $userId,
            $bounds['week_start'],
            (float) $analysis['on_time_pct'],
            (float) $analysis['vehicle_care_pct'],
            (float) $analysis['documentation_pct'],
            $viewerId,
            $existing['notes'] ?? null,
            $service,
            $workLog
        );
        dkpi_json([
            'ok' => true,
            'entry' => $entry,
            'service' => $service,
            'analysis' => $analysis,
            'metrics' => array_values(dkpi_metrics($service)),
            'week' => $bounds,
            'message' => 'Performance calculated from this week\'s work log.',
        ]);

    case 'upload':
        $userId = (int) ($input['user_id'] ?? $_GET['user_id'] ?? $viewerId);
        dkpi_require_driver_target($pdo, $userId, $isAdmin, $viewerId);
        $attachments = [];
        try {
            $fileBag = null;
            if (!empty($_FILES['file']) && is_array($_FILES['file'])) {
                $fileBag = $_FILES['file'];
            } elseif (!empty($_FILES['document']) && is_array($_FILES['document'])) {
                $fileBag = $_FILES['document'];
            } elseif (!empty($_FILES['voucher']) && is_array($_FILES['voucher'])) {
                $fileBag = $_FILES['voucher'];
            } elseif (!empty($_FILES['letter']) && is_array($_FILES['letter'])) {
                $fileBag = $_FILES['letter'];
            }
            if ($fileBag) {
                $attachments['document'] = dkpi_store_upload($fileBag, $userId, 'document');
            }
        } catch (Throwable $e) {
            dkpi_json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        if ($attachments === []) {
            dkpi_json(['ok' => false, 'error' => 'Choose a file to upload.'], 422);
        }
        dkpi_json([
            'ok' => true,
            'attachments' => $attachments,
            'message' => 'File uploaded.',
        ]);

    case 'attach_options':
        dkpi_json([
            'ok' => true,
            'vouchers' => dkpi_list_selectable_vouchers($pdo, $viewerId, $isAdmin),
            'letters' => dkpi_list_selectable_letters($pdo, $viewerId, $isAdmin),
        ]);

    case 'work_types':
        $types = [];
        foreach (dkpi_work_log_types() as $key => $label) {
            $types[] = ['key' => $key, 'label' => $label];
        }
        dkpi_json(['ok' => true, 'types' => $types]);

    default:
        dkpi_json(['ok' => false, 'error' => 'Unknown action'], 400);
}
