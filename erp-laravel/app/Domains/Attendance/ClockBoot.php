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
        // Do not require attendance/config/database.php from a method scope ? its
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
                    'todayPresent' => [],
                    'teamBoard' => [],
                    'punctualityBoard' => ['monthLabel' => '', 'rows' => []],
                    'history' => [],
                    'stats' => [],
                    'pendingTasks' => [],
                    'isIpAllowed' => false,
                    'currentIp' => '',
                    'activeAway' => null,
                    'awayReasons' => [],
                    'todayTimeline' => [],
                    'message' => 'Attendance bootstrap failed.',
                    'msgType' => 'error',
                    'clockInSuccess' => null,
                    'clockOutSuccess' => null,
                    'user' => ['id' => 0, 'name' => '', 'department' => '', 'role' => '', 'signatureUrl' => ''],
                    'shift' => [
                        'start' => '09:00',
                        'end' => '17:00',
                        'windowLabel' => '09:00–17:00',
                        'dailyTargetHours' => 8.0,
                    ],
                    'apiUrl' => $this->apiUrl((string) ($erp['company_slug'] ?? '')),
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
        $historyMonth = $attendance->normalizeHistoryMonth(null);

        $biometric = [
            'required' => false,
            'enrolled' => false,
            'count' => 0,
            'max' => 2,
            'devices' => [],
            'library_ok' => false,
            'secure_ok' => false,
            'rp_id' => '',
        ];
        try {
            $waFile = $root . '/attendance/classes/AttendanceWebAuthn.php';
            if (is_file($waFile)) {
                require_once $waFile;
            }
            if (class_exists('AttendanceWebAuthn')) {
                $wa = new \AttendanceWebAuthn($pdo);
                $biometric = $wa->statusForUser($userId);
            }
        } catch (Throwable $e) {
            // Keep defaults; desk still renders.
        }

        return [
            'page' => 'clock',
            'data' => [
                'timeZone' => 'Africa/Dar_es_Salaam',
                'dateSummary' => $attClockNow->format('l, d M Y'),
                'todayRecord' => $attendance->getTodayRecord($userId) ?: null,
                'todayPresent' => $attendance->getTodayPresentList(),
                'teamBoard' => $attendance->getTeamHoursBoard($userId),
                'punctualityBoard' => $attendance->getPunctualityBoard($userId),
                'history' => $attendance->getHistory($userId, $historyMonth) ?: [],
                'historyMonth' => $historyMonth,
                'historyMonthLabel' => $attendance->formatHistoryMonthLabel($historyMonth),
                'historyMonths' => $attendance->getHistoryMonthOptions(12),
                'stats' => $attendance->getStats($userId) ?: [],
                'pendingTasks' => $pendingTasks,
                'isIpAllowed' => (bool) $attendance->isIpAllowed($currentIp),
                'currentIp' => (string) $currentIp,
                'biometric' => $biometric,
                'activeAway' => $attendance->getActiveAwaySession($userId),
                'awayReasons' => $attendance->getAwayReasonOptions(),
                'todayTimeline' => $attendance->getTodayTimeline($userId),
                'message' => '',
                'msgType' => '',
                'clockInSuccess' => null,
                'clockOutSuccess' => null,
                'user' => [
                    'id' => $userId,
                    'name' => (string) ($user['full_name'] ?? $user['username'] ?? ''),
                    'department' => (string) ($user['department'] ?? ''),
                    'role' => (string) ($user['role'] ?? ''),
                    'signatureUrl' => $signatureUrl,
                ],
                'shift' => $this->shiftPayload($attendance),
                'apiUrl' => $this->apiUrl((string) ($erp['company_slug'] ?? '')),
                'links' => $this->links(),
                'wallpapers' => $this->wallpapers(),
                'engine' => 'erp-laravel Domains/Attendance',
            ],
        ];
    }

    /**
     * @return array{start:string,end:string,windowLabel:string,dailyTargetHours:float}
     */
    private function shiftPayload(\Attendance $attendance): array
    {
        $settings = $attendance->getSettings() ?: [];
        $startRaw = (string) ($settings['start_time'] ?? '09:00:00');
        $endRaw = (string) ($settings['end_time'] ?? '17:00:00');
        $start = substr($startRaw, 0, 5);
        $end = substr($endRaw, 0, 5);
        if (!preg_match('/^\d{2}:\d{2}$/', $start)) {
            $start = '09:00';
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $end)) {
            $end = '17:00';
        }
        $startParts = array_map('intval', explode(':', $start));
        $endParts = array_map('intval', explode(':', $end));
        $startMinutes = ($startParts[0] * 60) + ($startParts[1] ?? 0);
        $endMinutes = ($endParts[0] * 60) + ($endParts[1] ?? 0);
        $diff = $endMinutes - $startMinutes;
        if ($diff <= 0) {
            $diff += 24 * 60;
        }
        $dailyTargetHours = round($diff / 60, 1);
        if ($dailyTargetHours <= 0) {
            $dailyTargetHours = 8.0;
        }

        $graceMinutes = (int) ($settings['grace_period_minutes'] ?? 15);
        if ($graceMinutes < 0) {
            $graceMinutes = 0;
        }

        return [
            'start' => $start,
            'end' => $end,
            'windowLabel' => $start . '–' . $end,
            'dailyTargetHours' => $dailyTargetHours,
            'graceMinutes' => $graceMinutes,
        ];
    }

    public function wallpaperUrl(): string
    {
        $attClockTz = new \DateTimeZone('Africa/Dar_es_Salaam');
        $attClockNow = new \DateTime('now', $attClockTz);
        $hour = (int) $attClockNow->format('G');
        if ($hour >= 17) {
            $wallpaperFile = 'att-hero-dusk.jpg';
        } elseif ($hour >= 11) {
            $wallpaperFile = 'att-hero-noon.jpg';
        } else {
            $wallpaperFile = 'att-hero-morning.jpg';
        }
        // New folder path bypasses StackCDN entries cached under /wallpapers/?v=hd2.
        $path = '/assets/att-lock/v1/' . $wallpaperFile . '?v=20261125lock1';
        if (function_exists('app_url')) {
            return (string) app_url($path);
        }

        return $path;
    }

    private function apiUrl(string $companySlug = ''): string
    {
        $companySlug = trim($companySlug);
        if ($companySlug === '') {
            $slugCandidates = [
                (string) ($_SESSION['company_slug'] ?? ''),
                (string) ($_GET['company_slug'] ?? ''),
                (string) ($GLOBALS['ERP_ATTENDANCE_CONTEXT']['company_slug'] ?? ''),
            ];
            if (function_exists('getRequestedCompanySlug')) {
                $slugCandidates[] = (string) getRequestedCompanySlug();
            }
            foreach ($slugCandidates as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '') {
                    $companySlug = $candidate;
                    break;
                }
            }
        }
        if ($companySlug === '') {
            $requestPaths = [
                (string) ($_SERVER['REQUEST_URI'] ?? ''),
                (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
                (string) ($GLOBALS['ERP_ATTENDANCE_CONTEXT']['attendance_url'] ?? ''),
            ];
            foreach ($requestPaths as $requestPath) {
                $requestPath = (string) (parse_url($requestPath, PHP_URL_PATH) ?? '');
                if (preg_match('#^/([A-Za-z0-9-]+)/attendance(?:/|$)#i', $requestPath, $matches)) {
                    $companySlug = strtolower($matches[1]);
                    break;
                }
            }
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
     * @return array{modules:string,logout:string,settings:string,profileSecurity:string}
     */
    private function links(): array
    {
        $profileSecurity = function_exists('user_profile_settings_url')
            ? (string) user_profile_settings_url(null, 'attendance')
            : (function_exists('company_url')
                ? (string) company_url('employee/account.php?module=attendance')
                : (function_exists('app_url')
                    ? (string) app_url('/employee/account.php?module=attendance')
                    : '/employee/account.php?module=attendance'));
        if ($profileSecurity !== '' && strpos($profileSecurity, '#') === false) {
            $profileSecurity .= '#change-password';
        }

        return [
            'modules' => function_exists('app_url') ? (string) app_url('/select-module.php') : '/select-module.php',
            'logout' => function_exists('app_url') ? (string) app_url('/logout.php') : '/logout.php',
            'settings' => function_exists('app_url')
                ? (string) app_url('/attendance/settings.php')
                : '/attendance/settings.php',
            'profileSecurity' => $profileSecurity,
        ];
    }

    /**
     * @return array{
     *   morning:string,
     *   noon:string,
     *   dusk:string,
     *   sunrise:string,
     *   sunset:string,
     *   slides:list<string>
     * }
     */
    private function wallpapers(): array
    {
        // New folder path bypasses StackCDN entries cached under /wallpapers/?v=hd2.
        $assetVer = '20261125lock1';
        $url = static function (string $file) use ($assetVer): string {
            $path = '/assets/att-lock/v1/' . $file . '?v=' . $assetVer;
            return function_exists('app_url')
                ? (string) app_url($path)
                : $path;
        };

        $morning = $url('att-hero-morning.jpg');
        $noon = $url('att-hero-noon.jpg');
        $dusk = $url('att-hero-dusk.jpg');

        // Daily lock-screen pool — HD 16:9 landscapes only (no soft phone crops).
        $slideFiles = [
            'att-hero-morning.jpg',
            'att-hero-noon.jpg',
            'att-hero-dusk.jpg',
            'att-hero-sunburst.jpg',
            'sunset.jpg',
            'att-hero-forest.jpg',
            'att-hero-lake.jpg',
            'att-hero-desert.jpg',
            'att-hero-aurora.jpg',
            'att-hero-city.jpg',
            'att-hero-ocean.jpg',
            'att-hero-snow.jpg',
            'att-hero-fields.jpg',
            'att-hero-canyon.jpg',
            'att-hero-fog.jpg',
            'att-hero-waterfall.jpg',
            'att-hero-valley.jpg',
        ];
        $root = rtrim((string) (function_exists('config') ? (string) config('erp.app_root') : ''), '\\/');
        if ($root === '') {
            $root = rtrim((string) ($GLOBALS['ERP_ATTENDANCE_CONTEXT']['app_root'] ?? dirname(__DIR__, 4)), '\\/');
        }
        // Always publish the full HD pool URLs so the desk can rotate.
        // Prefer the CDN-safe assets path; fall back to legacy /wallpapers.
        $slides = [];
        $wallpaperDir = $root !== '' ? $root . '/assets/att-lock/v1' : '';
        if ($wallpaperDir === '' || !is_dir($wallpaperDir)) {
            $wallpaperDir = $root !== '' ? $root . '/wallpapers' : '';
        }
        $canCheckDisk = $wallpaperDir !== '' && is_dir($wallpaperDir);
        foreach ($slideFiles as $file) {
            if ($canCheckDisk && !is_file($wallpaperDir . '/' . $file)) {
                continue;
            }
            $slides[] = $url($file);
        }
        if ($slides === []) {
            foreach ($slideFiles as $file) {
                $slides[] = $url($file);
            }
        }
        $slides = array_values(array_unique($slides));

        return [
            'morning' => $morning,
            'noon' => $noon,
            'dusk' => $dusk,
            // Legacy aliases kept for older clients.
            'sunrise' => $morning,
            'sunset' => $dusk,
            'slides' => $slides,
        ];
    }
}
