<?php

declare(strict_types=1);

/**
 * Time settings React UI helpers + get/save payload.
 */

require_once dirname(__DIR__, 2) . '/includes/functions.php';

function timeSettingsUiWebBasePath(): string
{
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/admin'), '/');
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        $dir = rtrim(dirname($script), '/');
        if (preg_match('#^(.*?)/[A-Za-z0-9-]+/admin$#', $dir, $m)) {
            return rtrim($m[1] . '/admin', '/') ?: '/admin';
        }
        if (substr($dir, -6) === '/admin') {
            return $dir;
        }
    }
    return '/admin';
}

function timeSettingsUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    return timeSettingsUiWebBasePath() . '/time-settings-ui/' . $relativePath;
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function timeSettingsUiLoadReactAssets(): ?array
{
    $uiDir = __DIR__ . '/frontend';
    $distIndex = $uiDir . '/dist/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '') {
        return null;
    }

    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    return [
        'assetBase' => timeSettingsUiPublicUrl('frontend/dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

function timeSettingsUiShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
    ];
    if (function_exists('app_url')) {
        $erpStylePath = dirname(__DIR__, 2) . '/assets/css/style.css';
        $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
        if (function_exists('erp_dark_theme_css_url')) {
            $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
        }
    }
    return implode("\n    ", $parts);
}

function timeSettingsUiRequireAdmin(): void
{
    requireAdmin();
    ensureSystemSettingsSchema();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['active_module'] = 'settings';
}

function timeSettingsUiPdo(): PDO
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }
    return $pdo;
}

function timeSettingsUiGetSetting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val === false || $val === null ? $default : (string) $val;
    } catch (Throwable $e) {
        return $default;
    }
}

function timeSettingsUiSetSetting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ');
    $stmt->execute([$key, $value]);
}

function timeSettingsUiDatetimeLocal(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $dt = date_create($value);
    return $dt ? $dt->format('Y-m-d\TH:i') : '';
}

/**
 * @return list<array{value:string,label:string}>
 */
function timeSettingsUiTimezoneOptions(): array
{
    return [
        ['value' => 'Africa/Dar_es_Salaam', 'label' => 'Africa/Dar es Salaam (EAT, GMT+3)'],
        ['value' => 'Africa/Nairobi', 'label' => 'Africa/Nairobi (EAT, GMT+3)'],
        ['value' => 'Africa/Kampala', 'label' => 'Africa/Kampala (EAT, GMT+3)'],
        ['value' => 'Africa/Kigali', 'label' => 'Africa/Kigali (CAT, GMT+2)'],
        ['value' => 'Africa/Johannesburg', 'label' => 'Africa/Johannesburg (SAST, GMT+2)'],
        ['value' => 'UTC', 'label' => 'UTC (Standard Time)'],
    ];
}

/**
 * @return array{form:array<string,mixed>,links:array<string,string>,meta:array<string,mixed>,options:array<string,mixed>}
 */
function timeSettingsUiGetPayload(PDO $pdo): array
{
    $slug = trim((string) ($_SESSION['company_slug'] ?? ''));
    $backUrl = $slug !== ''
        ? company_url('admin/settings.php?module=settings', $slug)
        : (function_exists('app_url') ? app_url('/admin/settings.php?module=settings') : '/admin/settings.php?module=settings');

    $timezone = timeSettingsUiGetSetting($pdo, 'system_timezone', 'Africa/Dar_es_Salaam');
    $format = timeSettingsUiGetSetting($pdo, 'system_time_format', '24');
    $overrideEnabled = timeSettingsUiGetSetting($pdo, 'system_time_override_enabled', '0') === '1';
    $overrideTime = timeSettingsUiDatetimeLocal(timeSettingsUiGetSetting($pdo, 'system_override_time'));

    $attendanceLib = dirname(__DIR__, 2) . '/attendance/lib.php';
    $att = [
        'start_time' => '09:00',
        'end_time' => '17:00',
        'grace_period_minutes' => 15,
        'office_ips' => [''],
        'geofence_enabled' => true,
        'latitude' => null,
        'longitude' => null,
        'radius_meters' => 100,
    ];
    $currentIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (is_file($attendanceLib)) {
        require_once $attendanceLib;
        if (function_exists('ensureAttendanceClockModuleSchema')) {
            try {
                ensureAttendanceClockModuleSchema();
            } catch (Throwable $e) {
                // keep defaults
            }
        }
        if (function_exists('attendanceSettingsFetchPayload')) {
            try {
                $payload = attendanceSettingsFetchPayload($pdo);
                if (is_array($payload['settings'] ?? null)) {
                    $att = array_merge($att, $payload['settings']);
                }
                if (!empty($payload['current_ip'])) {
                    $currentIp = (string) $payload['current_ip'];
                }
            } catch (Throwable $e) {
                // keep defaults
            }
        }
    }

    return [
        'form' => [
            'timezone' => $timezone,
            'timeFormat' => $format === '12' ? '12' : '24',
            'overrideEnabled' => $overrideEnabled,
            'overrideTime' => $overrideTime,
            'startTime' => (string) ($att['start_time'] ?? '09:00'),
            'endTime' => (string) ($att['end_time'] ?? '17:00'),
            'gracePeriodMinutes' => (int) ($att['grace_period_minutes'] ?? 15),
            'officeIps' => is_array($att['office_ips'] ?? null) && $att['office_ips'] !== []
                ? array_values(array_map('strval', $att['office_ips']))
                : [''],
            'geofenceEnabled' => !empty($att['geofence_enabled']),
            'latitude' => $att['latitude'] ?? null,
            'longitude' => $att['longitude'] ?? null,
            'radiusMeters' => (int) ($att['radius_meters'] ?? 100),
        ],
        'links' => [
            'backUrl' => $backUrl,
        ],
        'meta' => [
            'companySlug' => $slug,
            'serverNow' => date('Y-m-d H:i:s'),
            'currentIp' => $currentIp,
        ],
        'options' => [
            'timezones' => timeSettingsUiTimezoneOptions(),
            'formats' => [
                ['value' => '24', 'label' => '24-Hour (e.g. 14:30)'],
                ['value' => '12', 'label' => '12-Hour (e.g. 2:30 PM)'],
            ],
        ],
    ];
}

/**
 * @param array<string,mixed> $input
 * @return array{form:array<string,mixed>,links:array<string,string>,meta:array<string,mixed>,options:array<string,mixed>}
 */
function timeSettingsUiSavePayload(PDO $pdo, array $input): array
{
    $allowedTz = array_column(timeSettingsUiTimezoneOptions(), 'value');
    $timezone = trim((string) ($input['timezone'] ?? 'Africa/Dar_es_Salaam'));
    if (!in_array($timezone, $allowedTz, true)) {
        $timezone = 'Africa/Dar_es_Salaam';
    }

    $format = trim((string) ($input['timeFormat'] ?? '24'));
    $format = $format === '12' ? '12' : '24';

    $overrideEnabled = !empty($input['overrideEnabled']);
    $overrideTime = trim((string) ($input['overrideTime'] ?? ''));
    if ($overrideTime !== '') {
        $overrideTime = str_replace('T', ' ', $overrideTime);
        if (strlen($overrideTime) === 16) {
            $overrideTime .= ':00';
        }
    }
    if (!$overrideEnabled) {
        $overrideTime = '';
    }

    timeSettingsUiSetSetting($pdo, 'system_timezone', $timezone);
    timeSettingsUiSetSetting($pdo, 'system_time_format', $format);
    timeSettingsUiSetSetting($pdo, 'system_time_override_enabled', $overrideEnabled ? '1' : '0');
    timeSettingsUiSetSetting($pdo, 'system_override_time', $overrideTime);

    $attendanceLib = dirname(__DIR__, 2) . '/attendance/lib.php';
    if (is_file($attendanceLib)) {
        require_once $attendanceLib;
        if (function_exists('attendanceSettingsSave')) {
            $officeIps = $input['officeIps'] ?? $input['office_ips'] ?? [];
            if (!is_array($officeIps)) {
                $officeIps = [trim((string) $officeIps)];
            }
            attendanceSettingsSave($pdo, [
                'start_time' => (string) ($input['startTime'] ?? $input['start_time'] ?? '09:00'),
                'end_time' => (string) ($input['endTime'] ?? $input['end_time'] ?? '17:00'),
                'grace_period_minutes' => (int) ($input['gracePeriodMinutes'] ?? $input['grace_period_minutes'] ?? 15),
                'office_ips' => $officeIps,
                'geofence_enabled' => !empty($input['geofenceEnabled'] ?? $input['geofence_enabled']),
                'latitude' => $input['latitude'] ?? null,
                'longitude' => $input['longitude'] ?? null,
                'radius_meters' => (int) ($input['radiusMeters'] ?? $input['radius_meters'] ?? 100),
            ]);
        }
    }

    return timeSettingsUiGetPayload($pdo);
}

/**
 * @return array{form:array<string,mixed>,links:array<string,string>,meta:array<string,mixed>,options:array<string,mixed>}
 */
function timeSettingsUiAddCurrentIp(PDO $pdo): array
{
    $attendanceLib = dirname(__DIR__, 2) . '/attendance/lib.php';
    $attendanceClass = dirname(__DIR__, 2) . '/attendance/classes/Attendance.php';
    if (is_file($attendanceLib)) {
        require_once $attendanceLib;
    }
    if (is_file($attendanceClass)) {
        require_once $attendanceClass;
    }
    if (class_exists('Attendance', false)) {
        $attendance = new Attendance($pdo);
        $currentIp = (string) $attendance->getCurrentUserIp();
        if ($currentIp !== '') {
            $attendance->rememberOfficeIp($currentIp);
        }
    }
    return timeSettingsUiGetPayload($pdo);
}
