<?php

declare(strict_types=1);

namespace App\Domains\Attendance;

use PDO;
use Throwable;

/**
 * Build window.__ATTENDANCE_PAGE__ boot payload for the Logs desk.
 */
final class LogsBoot
{
    /**
     * @param array<string,mixed> $erp
     * @return array{page:string,data:array<string,mixed>}
     */
    public function build(array $erp = []): array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $classFile = $root . '/attendance/classes/Attendance.php';
        if (is_file($classFile)) {
            require_once $classFile;
        }

        global $pdo;
        if (!($pdo instanceof PDO) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
        }

        if (function_exists('ensureAttendanceClockModuleSchema')) {
            try {
                ensureAttendanceClockModuleSchema();
            } catch (Throwable $e) {
                // best-effort
            }
        }

        $apiUrl = $this->apiUrl((string) ($erp['company_slug'] ?? ''));

        if (!($pdo instanceof PDO) || !class_exists('Attendance')) {
            return [
                'page' => 'logs',
                'data' => [
                    'timeZone' => 'Africa/Dar_es_Salaam',
                    'dateSummary' => '',
                    'todayLogs' => ['date' => '', 'dateLabel' => '', 'rows' => []],
                    'user' => ['id' => 0, 'name' => '', 'department' => '', 'role' => ''],
                    'apiUrl' => $apiUrl,
                    'links' => $this->links(),
                    'message' => 'Attendance logs bootstrap failed.',
                    'msgType' => 'error',
                    'engine' => 'erp-laravel Domains/Attendance',
                ],
            ];
        }

        $userId = (int) ($erp['user_id'] ?? ($_SESSION['user_id'] ?? 0));
        $attendance = new \Attendance($pdo);

        $user = [];
        try {
            $stmtUser = $pdo->prepare('SELECT * FROM users WHERE id = ?');
            $stmtUser->execute([$userId]);
            $user = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $user = [];
        }

        $tz = new \DateTimeZone('Africa/Dar_es_Salaam');
        $now = new \DateTime('now', $tz);
        $logs = $attendance->getTodayLogsBoard($userId);

        return [
            'page' => 'logs',
            'data' => [
                'timeZone' => 'Africa/Dar_es_Salaam',
                'dateSummary' => $now->format('l, d M Y'),
                'todayLogs' => $logs,
                'user' => [
                    'id' => $userId,
                    'name' => (string) ($user['full_name'] ?? $user['username'] ?? ''),
                    'department' => (string) ($user['department'] ?? ''),
                    'role' => (string) ($user['role'] ?? ''),
                ],
                'apiUrl' => $apiUrl,
                'links' => $this->links(),
                'message' => '',
                'msgType' => '',
                'engine' => 'erp-laravel Domains/Attendance',
            ],
        ];
    }

    private function apiUrl(string $companySlug = ''): string
    {
        $companySlug = trim($companySlug);
        if ($companySlug === '') {
            $companySlug = trim((string) ($_SESSION['company_slug'] ?? ''));
        }
        if (function_exists('company_url')) {
            return (string) company_url(
                'attendance/api/action.php',
                $companySlug !== '' ? $companySlug : null
            );
        }
        $path = ($companySlug !== '' ? '/' . rawurlencode($companySlug) : '')
            . '/attendance/api/action.php';
        return function_exists('app_url') ? (string) app_url($path) : $path;
    }

    /**
     * @return array{clock:string,logs:string,stats:string,overtime:string}
     */
    private function links(): array
    {
        $withModule = static function (string $url): string {
            if ($url === '') {
                return $url;
            }
            if (strpos($url, 'module=') !== false) {
                return $url;
            }
            return $url . (strpos($url, '?') !== false ? '&' : '?') . 'module=attendance';
        };
        $route = static function (string $path) {
            if (function_exists('company_url')) {
                return (string) company_url($path);
            }
            return function_exists('app_url') ? (string) app_url('/' . ltrim($path, '/')) : '/' . ltrim($path, '/');
        };

        return [
            'clock' => $withModule($route('attendance/')),
            'logs' => $withModule($route('employee/attendance-logs.php')),
            'stats' => $withModule($route('employee/attendance-analytics.php')),
            'overtime' => $withModule($route('employee/attendance-overtime.php')),
        ];
    }
}
