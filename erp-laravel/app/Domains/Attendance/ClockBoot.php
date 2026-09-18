<?php

declare(strict_types=1);

namespace App\Domains\Attendance;

use PDO;
use Throwable;

/**
 * Build window.__ATTENDANCE_PAGE__ boot payload for the React clock desk.
 */
final class ClockBoot
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

        // $pdo is already bootstrapped by attendance.php ? includes/functions.php.
        // Do not require attendance/config/database.php from a method scope — its
        // isset($pdo) check only sees locals and dies with a false "connection failed".
        global $pdo;
        if (!($pdo instanceof PDO) && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
        }

        if (function_exists('ensureAttendanceClockModuleSchema')) {
            try {
                ensureAttendanceClockModuleSchema();
            } catch (Throwable $e) {
                // Schema ensure is best-effort; clock UI can still render.
            }
        }

        if (!($pdo instanceof PDO) || !class_exists('Attendance')) {
            return [
                'page' => 'clock',
                'data' => [
                    'timeZone' => 'Africa/Dar_es_Salaam',
                    'dateSummary' => '',
                    'todayRecord' => null,
                    'history' => [],
                    'stats' => [],
                    'pendingTasks' => [],
                    'isIpAllowed' => false,
                    'currentIp' => '',
                    'message' => 'Attendance bootstrap failed.',
                    'msgType' => 'error',
                    'clockInSuccess' => null,
                    'clockOutSuccess' => null,
                    'user' => ['id' => 0, 'name' => '', 'signatureUrl' => ''],
                    'apiUrl' => $this->apiUrl(),
                    'links' => $this->links(),
                    'wallpapers' => $this->wallpapers(),
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

        $pendingTasks = [];
        try {
            if (function_exists('tableExists') && tableExists('user_tasks', $pdo)) {
                $pendingTasksStmt = $pdo->prepare(
                    'SELECT id, task_description, task_date FROM user_tasks WHERE user_id = ? AND is_completed = 0 ORDER BY task_date ASC'
                );
                $pendingTasksStmt->execute([$userId]);
                $pendingTasks = $pendingTasksStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {
            $pendingTasks = [];
        }

        $signatureUrl = '';
        $sigPath = (string) ($user['signature_path'] ?? '');
        if ($sigPath !== '') {
            $fsPath = $root . '/' . ltrim($sigPath, '/');
            if (is_file($fsPath) && function_exists('app_url')) {
                $signatureUrl = app_url('/' . ltrim($sigPath, '/'));
            }
        }

        $attClockTz = new \DateTimeZone('Africa/Dar_es_Salaam');
        $attClockNow = new \DateTime('now', $attClockTz);
        $currentIp = $attendance->getCurrentUserIp();

        return [
            'page' => 'clock',
            'data' => [
                'timeZone' => 'Africa/Dar_es_Salaam',
                'dateSummary' => $attClockNow->format('l, d M Y'),
                'todayRecord' => $attendance->getTodayRecord($userId) ?: null,
                'history' => $attendance->getHistory($userId) ?: [],
                'stats' => $attendance->getStats($userId) ?: [],
                'pendingTasks' => $pendingTasks,
                'isIpAllowed' => (bool) $attendance->isIpAllowed($currentIp),
                'currentIp' => (string) $currentIp,
                'message' => '',
                'msgType' => '',
                'clockInSuccess' => null,
                'clockOutSuccess' => null,
                'user' => [
                    'id' => $userId,
                    'name' => (string) ($user['full_name'] ?? $user['username'] ?? ''),
                    'signatureUrl' => $signatureUrl,
                ],
                'apiUrl' => $this->apiUrl(),
                'links' => $this->links(),
                'wallpapers' => $this->wallpapers(),
                'engine' => 'erp-laravel Domains/Attendance',
            ],
        ];
    }

    public function wallpaperUrl(): string
    {
        $attClockTz = new \DateTimeZone('Africa/Dar_es_Salaam');
        $attClockNow = new \DateTime('now', $attClockTz);
        $wallpaperFile = ((int) $attClockNow->format('G')) >= 12 ? 'sunset.jpg' : 'sunrise2.jpg';
        if (function_exists('app_url')) {
            return (string) app_url('/wallpapers/' . $wallpaperFile);
        }

        return '/wallpapers/' . $wallpaperFile;
    }

    private function apiUrl(): string
    {
        if (function_exists('app_url')) {
            return rtrim((string) app_url('/attendance'), '/') . '/api/action.php';
        }

        return '/attendance/api/action.php';
    }

    /**
     * @return array{modules:string,logout:string,settings:string}
     */
    private function links(): array
    {
        return [
            'modules' => function_exists('app_url') ? (string) app_url('/select-module.php') : '/select-module.php',
            'logout' => function_exists('app_url') ? (string) app_url('/logout.php') : '/logout.php',
            'settings' => function_exists('app_url')
                ? (string) app_url('/attendance/settings.php')
                : '/attendance/settings.php',
        ];
    }

    /**
     * @return array{sunrise:string,sunset:string}
     */
    private function wallpapers(): array
    {
        return [
            'sunrise' => function_exists('app_url') ? (string) app_url('/wallpapers/sunrise2.jpg') : '/wallpapers/sunrise2.jpg',
            'sunset' => function_exists('app_url') ? (string) app_url('/wallpapers/sunset.jpg') : '/wallpapers/sunset.jpg',
        ];
    }
}
