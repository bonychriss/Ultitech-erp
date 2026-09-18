<?php

declare(strict_types=1);

namespace App\Domains\Attendance;

use PDO;
use Throwable;

/**
 * Build window.__ATTENDANCE_PAGE__ boot payload for the React analytics desk.
 */
final class AnalyticsBoot
{
    /**
     * @param array<string,mixed> $erp
     * @return array{page:string,data:array<string,mixed>}
     */
    public function build(array $erp = [], ?int $periodDays = null): array
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

        $period = $this->normalizePeriod($periodDays);
        $scope = $this->normalizeScope(isset($erp['analytics_scope']) ? (string) $erp['analytics_scope'] : null);
        $userId = (int) ($erp['user_id'] ?? ($_SESSION['user_id'] ?? 0));

        $apiUrl = function_exists('app_url')
            ? rtrim((string) app_url('/attendance'), '/') . '/api/action.php'
            : '/attendance/api/action.php';
        $links = [
            'clock' => function_exists('app_url')
                ? (string) app_url('/attendance/') . '?module=attendance'
                : '/attendance/?module=attendance',
            'modules' => function_exists('app_url')
                ? (string) app_url('/select-module.php')
                : '/select-module.php',
        ];

        if (!($pdo instanceof PDO) || !class_exists('Attendance')) {
            return [
                'page' => 'analytics',
                'data' => [
                    'period' => $period,
                    'scope' => $scope,
                    'scopeOptions' => [
                        ['value' => 'personal', 'label' => 'Personal'],
                        ['value' => 'team', 'label' => 'Team'],
                    ],
                    'periodOptions' => [
                        ['value' => 7, 'label' => '7 Days'],
                        ['value' => 30, 'label' => '30 Days'],
                        ['value' => 90, 'label' => '90 Days'],
                    ],
                    'metrics' => [],
                    'charts' => [
                        'daily' => ['labels' => [], 'values' => []],
                        'weekly' => ['labels' => [], 'values' => []],
                    ],
                    'insights' => [],
                    'history' => [],
                    'apiUrl' => $apiUrl,
                    'links' => $links,
                    'engine' => 'erp-laravel Domains/Attendance',
                    'user' => ['id' => $userId, 'name' => ''],
                ],
            ];
        }

        $attendance = new \Attendance($pdo);
        $data = $attendance->getAnalytics($userId, $period, $scope);
        $data['apiUrl'] = $apiUrl;
        $data['links'] = $links;
        $data['engine'] = 'erp-laravel Domains/Attendance';
        $data['user'] = [
            'id' => $userId,
            'name' => (string) ($erp['full_name'] ?? ($_SESSION['full_name'] ?? '')),
        ];

        return [
            'page' => 'analytics',
            'data' => $data,
        ];
    }

    public function normalizePeriod(?int $periodDays): int
    {
        $period = (int) ($periodDays ?? ($_GET['period'] ?? 30));
        return in_array($period, [7, 30, 90], true) ? $period : 30;
    }

    public function normalizeScope(?string $scope): string
    {
        $scope = strtolower(trim((string) ($scope ?? ($_GET['scope'] ?? 'personal'))));
        return $scope === 'team' ? 'team' : 'personal';
    }
}
