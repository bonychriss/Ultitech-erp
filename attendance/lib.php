<?php
/**
 * Shared helpers for admin attendance records (React desk).
 */

if (!function_exists('attendanceAdminRecordsTable')) {
    function attendanceAdminRecordsTable(PDO $pdo): string
    {
        if (function_exists('tableExists') && tableExists('attendance_records', $pdo)) {
            return 'attendance_records';
        }
        if (function_exists('tableExists') && tableExists('attendance', $pdo) && function_exists('columnExists') && columnExists('attendance', 'time_in', $pdo)) {
            return 'attendance';
        }
        return 'attendance_records';
    }
}

if (!function_exists('attendanceAdminFetchPayload')) {
    /**
     * @return array{records: array, users: array, stats: array, filters: array, links: array}
     */
    function attendanceAdminFetchPayload(PDO $pdo, array $filters = []): array
    {
        // Empty date = all dates (view all). Only filter when a concrete day is set.
        $dateFilter = array_key_exists('date', $filters) ? trim((string) $filters['date']) : '';
        $userFilter = isset($filters['user_id']) ? (int) $filters['user_id'] : 0;
        $moduleKey = isset($filters['module']) ? trim((string) $filters['module']) : 'attendance';

        $table = attendanceAdminRecordsTable($pdo);
        $whereConditions = [];
        $params = [];

        if ($dateFilter !== '') {
            $whereConditions[] = 'a.`date` = ?';
            $params[] = $dateFilter;
        }
        if ($userFilter > 0) {
            $whereConditions[] = 'a.user_id = ?';
            $params[] = $userFilter;
        }

        $hasCompanyId = function_exists('columnExists') && columnExists('users', 'company_id', $pdo);
        $company_id = function_exists('currentCompanyId') ? (int) currentCompanyId() : 0;
        if ($hasCompanyId && $company_id > 0) {
            $whereConditions[] = 'u.company_id = ?';
            $params[] = $company_id;
        }

        $whereClause = $whereConditions !== [] ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

        $records = [];
        $stats = [
            'total_present' => 0,
            'total_late' => 0,
            'total_early' => 0,
            'total_overtime' => 0,
            'not_arrived' => 0,
        ];
        $allUsers = [];

        try {
            $stmt = $pdo->prepare("
                SELECT
                    a.id,
                    a.user_id,
                    a.date,
                    a.time_in,
                    a.time_out,
                    a.status,
                    a.total_hours,
                    a.overtime_hours,
                    a.ip_address,
                    a.signature_image,
                    u.full_name,
                    u.username,
                    u.department
                FROM `{$table}` a
                INNER JOIN users u ON a.user_id = u.id
                {$whereClause}
                ORDER BY a.date DESC, a.time_in DESC
                LIMIT 500
            ");
            $stmt->execute($params);
            $raw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($raw as $row) {
                $sigPath = trim((string) ($row['signature_image'] ?? ''));
                $sigUrl = '';
                if ($sigPath !== '') {
                    if (strpos($sigPath, 'data:') === 0) {
                        $sigUrl = $sigPath;
                    } else {
                        $sigUrl = function_exists('app_url')
                            ? app_url('/' . ltrim($sigPath, '/'))
                            : '/' . ltrim($sigPath, '/');
                    }
                }
                $records[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'user_id' => (int) ($row['user_id'] ?? 0),
                    'date' => (string) ($row['date'] ?? ''),
                    'time_in' => $row['time_in'] ?? null,
                    'time_out' => $row['time_out'] ?? null,
                    'status' => (string) ($row['status'] ?? ''),
                    'total_hours' => $row['total_hours'] !== null && $row['total_hours'] !== '' ? (float) $row['total_hours'] : null,
                    'overtime_hours' => $row['overtime_hours'] !== null && $row['overtime_hours'] !== '' ? (float) $row['overtime_hours'] : null,
                    'ip_address' => (string) ($row['ip_address'] ?? ''),
                    'signature_url' => $sigUrl,
                    'full_name' => (string) ($row['full_name'] ?? ''),
                    'username' => (string) ($row['username'] ?? ''),
                    'department' => (string) ($row['department'] ?? ''),
                ];
            }

            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) as total_present,
                    SUM(CASE WHEN a.status = 'Late' OR a.status LIKE '%Late%' THEN 1 ELSE 0 END) as total_late,
                    SUM(CASE WHEN a.status = 'Early' OR a.status LIKE '%Early%' THEN 1 ELSE 0 END) as total_early,
                    SUM(CASE WHEN a.overtime_hours > 0 THEN 1 ELSE 0 END) as total_overtime
                FROM `{$table}` a
                INNER JOIN users u ON a.user_id = u.id
                {$whereClause}
            ");
            $stmt->execute($params);
            $statsRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $stats['total_present'] = (int) ($statsRow['total_present'] ?? 0);
            $stats['total_late'] = (int) ($statsRow['total_late'] ?? 0);
            $stats['total_early'] = (int) ($statsRow['total_early'] ?? 0);
            $stats['total_overtime'] = (int) ($statsRow['total_overtime'] ?? 0);

            if ($hasCompanyId && $company_id > 0) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE is_active = 1 AND company_id = ?');
                $stmt->execute([$company_id]);
                $totalEmployees = (int) $stmt->fetchColumn();
                $stmt = $pdo->prepare('SELECT id, full_name, username FROM users WHERE is_active = 1 AND company_id = ? ORDER BY full_name');
                $stmt->execute([$company_id]);
            } else {
                $totalEmployees = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
                $stmt = $pdo->query('SELECT id, full_name, username FROM users WHERE is_active = 1 ORDER BY full_name');
            }
            $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT a.user_id) FROM `{$table}` a INNER JOIN users u ON a.user_id = u.id {$whereClause}");
            $stmt->execute($params);
            $uniquePresent = (int) $stmt->fetchColumn();
            $stats['not_arrived'] = max(0, $totalEmployees - $uniquePresent);
        } catch (Throwable $e) {
            error_log('attendanceAdminFetchPayload: ' . $e->getMessage());
        }

        $viewAttBase = function_exists('company_url')
            ? company_url('admin/view-attendance.php')
            : (function_exists('app_url') ? app_url('/admin/view-attendance.php') : '/admin/view-attendance.php');
        $exportBase = function_exists('company_url')
            ? company_url('admin/export-attendance.php')
            : (function_exists('app_url') ? app_url('/admin/export-attendance.php') : '/admin/export-attendance.php');
        $apiUrl = function_exists('app_url')
            ? app_url('/attendance/api/admin-list.php')
            : '/attendance/api/admin-list.php';

        return [
            'records' => $records,
            'users' => array_map(static function ($u) {
                return [
                    'id' => (int) ($u['id'] ?? 0),
                    'full_name' => (string) ($u['full_name'] ?? ''),
                    'username' => (string) ($u['username'] ?? ''),
                ];
            }, $allUsers),
            'stats' => $stats,
            'filters' => [
                'date' => $dateFilter,
                'user_id' => $userFilter,
                'module' => $moduleKey,
            ],
            'links' => [
                'page' => $viewAttBase,
                'export' => $exportBase,
                'api' => $apiUrl,
                'clock' => function_exists('company_url')
                    ? company_url('attendance/')
                    : (function_exists('app_url') ? app_url('/attendance/') : '/attendance/'),
            ],
            'source_table' => $table,
        ];
    }
}

if (!function_exists('att_format_time_input')) {
    function att_format_time_input($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^(\d{2}:\d{2})/', $value, $m)) {
            return $m[1];
        }
        return $value;
    }
}

if (!function_exists('att_parse_ip_entries')) {
    function att_parse_ip_entries(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = array_map('trim', $parts);
        $parts = array_filter($parts, static function ($v) {
            return $v !== '';
        });
        return array_values(array_unique($parts));
    }
}

if (!function_exists('attendanceSettingsFetchPayload')) {
    /**
     * @return array{settings: array, links: array, current_ip: string}
     */
    function attendanceSettingsFetchPayload(PDO $pdo): array
    {
        if (!class_exists('Attendance', false)) {
            require_once __DIR__ . '/classes/Attendance.php';
        }

        $settings = null;
        try {
            if (function_exists('ensureAttendanceClockModuleSchema')) {
                ensureAttendanceClockModuleSchema();
            }
            $order = (function_exists('columnExists') && columnExists('attendance_settings', 'updated_at', $pdo))
                ? ' ORDER BY updated_at DESC'
                : '';
            $stmt = $pdo->query('SELECT * FROM attendance_settings WHERE id = 1' . $order . ' LIMIT 1');
            $settings = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        } catch (Throwable $e) {
            error_log('attendanceSettingsFetchPayload: ' . $e->getMessage());
            $settings = false;
        }

        if (!$settings || !is_array($settings)) {
            $settings = [
                'office_ip_address' => '127.0.0.1',
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'grace_period_minutes' => 15,
                'latitude' => null,
                'longitude' => null,
                'radius_meters' => 100,
                'geofence_enabled' => 1,
            ];
        }

        $officeIps = att_parse_ip_entries((string) ($settings['office_ip_address'] ?? ''));
        if ($officeIps === []) {
            $officeIps = [''];
        }

        $geofenceEnabled = true;
        if (array_key_exists('geofence_enabled', $settings)) {
            $geofenceEnabled = (int) $settings['geofence_enabled'] === 1;
        } else {
            $geofenceEnabled = trim((string) ($settings['latitude'] ?? '')) !== ''
                && trim((string) ($settings['longitude'] ?? '')) !== '';
        }

        $currentIp = '';
        try {
            $att = new Attendance($pdo);
            $currentIp = (string) $att->getCurrentUserIp();
        } catch (Throwable $e) {
            $currentIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        }

        $settingsUrl = function_exists('company_url')
            ? company_url('attendance/settings.php?module=attendance')
            : (function_exists('app_url') ? app_url('/attendance/settings.php?module=attendance') : '/attendance/settings.php');
        $apiUrl = function_exists('app_url')
            ? app_url('/attendance/api/admin-settings.php')
            : '/attendance/api/admin-settings.php';

        return [
            'settings' => [
                'start_time' => att_format_time_input($settings['start_time'] ?? '09:00'),
                'end_time' => att_format_time_input($settings['end_time'] ?? '17:00'),
                'grace_period_minutes' => (int) ($settings['grace_period_minutes'] ?? 15),
                'office_ips' => $officeIps,
                'geofence_enabled' => $geofenceEnabled,
                'latitude' => $settings['latitude'] !== null && $settings['latitude'] !== ''
                    ? (float) $settings['latitude'] : null,
                'longitude' => $settings['longitude'] !== null && $settings['longitude'] !== ''
                    ? (float) $settings['longitude'] : null,
                'radius_meters' => (int) ($settings['radius_meters'] ?? 100),
            ],
            'current_ip' => $currentIp,
            'links' => [
                'page' => $settingsUrl,
                'api' => $apiUrl,
                'hub' => function_exists('company_url')
                    ? company_url('admin/settings.php?module=settings')
                    : (function_exists('app_url') ? app_url('/admin/settings.php?module=settings') : '/admin/settings.php'),
                'clock' => function_exists('company_url')
                    ? company_url('attendance/')
                    : (function_exists('app_url') ? app_url('/attendance/') : '/attendance/'),
            ],
        ];
    }
}

if (!function_exists('attendanceSettingsSave')) {
    /**
     * @param array $input
     * @return array{success:bool,message:string,data?:array}
     */
    function attendanceSettingsSave(PDO $pdo, array $input): array
    {
        if (function_exists('ensureAttendanceClockModuleSchema')) {
            ensureAttendanceClockModuleSchema();
        }

        $officeIps = $input['office_ips'] ?? [];
        if (!is_array($officeIps)) {
            $officeIps = att_parse_ip_entries(trim((string) $officeIps));
        } else {
            $officeIps = array_values(array_unique(array_filter(array_map('trim', $officeIps), static function ($v) {
                return $v !== '';
            })));
        }
        $officeIp = implode(', ', $officeIps);
        $startTime = trim((string) ($input['start_time'] ?? '09:00'));
        $endTime = trim((string) ($input['end_time'] ?? '17:00'));
        // Normalize HTML time (HH:MM) to MySQL TIME (HH:MM:SS).
        if (preg_match('/^\d{2}:\d{2}$/', $startTime)) {
            $startTime .= ':00';
        }
        if (preg_match('/^\d{2}:\d{2}$/', $endTime)) {
            $endTime .= ':00';
        }
        $gracePeriod = (int) ($input['grace_period_minutes'] ?? $input['grace_period'] ?? 15);
        $latitudeRaw = $input['latitude'] ?? null;
        $longitudeRaw = $input['longitude'] ?? null;
        $radiusRaw = $input['radius_meters'] ?? 100;
        $latitude = ($latitudeRaw !== null && $latitudeRaw !== '') ? round((float) $latitudeRaw, 8) : null;
        $longitude = ($longitudeRaw !== null && $longitudeRaw !== '') ? round((float) $longitudeRaw, 8) : null;
        $radiusMeters = ($radiusRaw !== null && $radiusRaw !== '') ? (int) $radiusRaw : 100;
        $geofenceEnabled = !empty($input['geofence_enabled']) ? 1 : 0;

        try {
            $hasGeofenceCol = function_exists('columnExists') && columnExists('attendance_settings', 'geofence_enabled', $pdo);
            $setSql = "
                office_ip_address = ?,
                start_time = ?,
                end_time = ?,
                grace_period_minutes = ?,
                latitude = ?,
                longitude = ?,
                radius_meters = ?
            ";
            $saveParams = [$officeIp, $startTime, $endTime, $gracePeriod, $latitude, $longitude, $radiusMeters];
            if ($hasGeofenceCol) {
                $setSql .= ", geofence_enabled = ?";
                $saveParams[] = $geofenceEnabled;
            }

            $upd = $pdo->prepare("UPDATE attendance_settings SET {$setSql} WHERE id = 1");
            $upd->execute($saveParams);
            if ($upd->rowCount() === 0) {
                $exists = (int) $pdo->query('SELECT COUNT(*) FROM attendance_settings WHERE id = 1')->fetchColumn();
                if ($exists === 0) {
                    $cols = 'id, office_ip_address, start_time, end_time, grace_period_minutes, latitude, longitude, radius_meters';
                    $vals = '1, ?, ?, ?, ?, ?, ?, ?';
                    $insParams = [$officeIp, $startTime, $endTime, $gracePeriod, $latitude, $longitude, $radiusMeters];
                    if ($hasGeofenceCol) {
                        $cols .= ', geofence_enabled';
                        $vals .= ', ?';
                        $insParams[] = $geofenceEnabled;
                    }
                    $ins = $pdo->prepare("INSERT INTO attendance_settings ({$cols}) VALUES ({$vals})");
                    if (!$ins->execute($insParams)) {
                        return ['success' => false, 'message' => 'Failed to update settings.'];
                    }
                }
                // rowCount 0 with existing row usually means values were unchanged — still success.
            }
        } catch (Throwable $e) {
            error_log('attendanceSettingsSave: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to update settings: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => 'Attendance settings updated successfully.',
            'data' => attendanceSettingsFetchPayload($pdo),
        ];
    }
}
