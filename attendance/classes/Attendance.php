<?php
// staff/attendance/classes/Attendance.php

class Attendance {
    private $pdo;
    private $settings;

    /** Daily clock records (distinct from legacy GPS `attendance` table). */
    const RECORDS_TABLE = 'attendance_records';

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadSettings();
    }

    private function loadSettings() {
        try {
            $order = (function_exists('columnExists') && columnExists('attendance_settings', 'updated_at', $this->pdo))
                ? ' ORDER BY updated_at DESC'
                : '';
            $stmt = $this->pdo->query("SELECT * FROM attendance_settings WHERE id = 1{$order} LIMIT 1");
            $this->settings = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->settings = null;
        }

        // Default fallbacks if DB empty
        if (!$this->settings) {
            $this->settings = [
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'grace_period_minutes' => 15,
                'office_ip_address' => '127.0.0.1'
            ];
        }
    }

    public function getSettings() {
        return $this->settings;
    }

    public function getCurrentUserIp() {
        $candidates = [];
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $candidates[] = (string) $_SERVER['HTTP_CLIENT_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $candidates[] = (string) $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $candidates[] = (string) $_SERVER['REMOTE_ADDR'];
        }

        foreach ($candidates as $source) {
            foreach (explode(',', $source) as $piece) {
                $ip = trim($piece);
                if ($ip === '') {
                    continue;
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return false;
        }
        list($network, $maskBitsRaw) = explode('/', $cidr, 2);
        $network = trim($network);
        $maskBits = (int) trim($maskBitsRaw);

        $ipLong = ip2long($ip);
        $networkLong = ip2long($network);
        if ($ipLong === false || $networkLong === false || $maskBits < 0 || $maskBits > 32) {
            return false;
        }

        if ($maskBits === 0) {
            return true;
        }
        $mask = -1 << (32 - $maskBits);
        return (($ipLong & $mask) === ($networkLong & $mask));
    }

    private function ipMatchesWildcard(string $ip, string $pattern): bool
    {
        // Example: 102.205.251.* or 102.205.*.*
        if (strpos($pattern, '*') === false) {
            return false;
        }
        $ipParts = explode('.', $ip);
        $patParts = explode('.', $pattern);
        if (count($ipParts) !== 4 || count($patParts) !== 4) {
            return false;
        }
        for ($i = 0; $i < 4; $i++) {
            if ($patParts[$i] === '*') {
                continue;
            }
            if ($patParts[$i] !== $ipParts[$i]) {
                return false;
            }
        }
        return true;
    }

    private function isLoopbackIp(string $ip): bool
    {
        $ip = strtolower(trim($ip));
        return $ip === '127.0.0.1'
            || $ip === '::1'
            || $ip === '0:0:0:0:0:0:0:1';
    }

    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000; // Earth's radius in meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c; // Distance in meters
    }

    public function isIpAllowed($userIp) {
        $rawAllowed = (string) ($this->settings['office_ip_address'] ?? '');
        $allowedIps = preg_split('/[\s,;]+/', $rawAllowed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $allowedIps = array_values(array_unique(array_map(static function ($item) {
            return strtolower(trim((string) $item));
        }, $allowedIps)));

        // Explicit allow-all only.
        if (in_array('0.0.0.0', $allowedIps, true) || in_array('*', $allowedIps, true)) {
            return true;
        }
        if (empty($allowedIps)) {
            return false;
        }

        $normalizedUserIp = strtolower(trim((string) $userIp));
        if ($normalizedUserIp === 'localhost' || $normalizedUserIp === '0:0:0:0:0:0:0:1') {
            $normalizedUserIp = '::1';
        }
        if (!filter_var($normalizedUserIp, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Always allow loopback (localhost) in development environment to prevent lockouts
        if (defined('APP_ENV') && APP_ENV === 'development' && $this->isLoopbackIp($normalizedUserIp)) {
            return true;
        }

        foreach ($allowedIps as $allowedEntry) {
            if ($allowedEntry === 'localhost') {
                $allowedEntry = '::1';
            }
            if ($allowedEntry === '0:0:0:0:0:0:0:1') {
                $allowedEntry = '::1';
            }

            // Treat all loopback forms as equivalent in local/dev environments.
            if ($this->isLoopbackIp($normalizedUserIp) && $this->isLoopbackIp($allowedEntry)) {
                return true;
            }

            if ($normalizedUserIp === $allowedEntry) {
                return true;
            }

            if ($this->ipInCidr($normalizedUserIp, $allowedEntry)) {
                return true;
            }

            if ($this->ipMatchesWildcard($normalizedUserIp, $allowedEntry)) {
                return true;
            }
            
            // Prefix match if allowed entry ends with a dot (e.g. "192.168.1.")
            if (substr($allowedEntry, -1) === '.' && strpos($normalizedUserIp, $allowedEntry) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Append a newly observed office WAN IP to the allowlist (e.g. after ISP/router change).
     * Skips invalid IPs and entries already covered by the current allowlist.
     */
    public function rememberOfficeIp(string $ip): bool
    {
        $ip = strtolower(trim($ip));
        if ($ip === '' || $ip === '0.0.0.0' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if ($this->isLoopbackIp($ip)) {
            return false;
        }
        if ($this->isIpAllowed($ip)) {
            return false;
        }

        $raw = trim((string) ($this->settings['office_ip_address'] ?? ''));
        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = array_values(array_unique(array_map(static function ($item) {
            return trim((string) $item);
        }, $parts)));
        $parts[] = $ip;
        $parts = array_values(array_unique($parts));
        $newValue = implode(', ', $parts);

        try {
            $stmt = $this->pdo->prepare('UPDATE attendance_settings SET office_ip_address = ? WHERE id = 1');
            $ok = $stmt->execute([$newValue]);
            if ($ok && $stmt->rowCount() === 0) {
                // No row yet — create a minimal settings row (other fields use DB defaults / later admin save).
                $ins = $this->pdo->prepare(
                    'INSERT INTO attendance_settings (id, office_ip_address, start_time, end_time, grace_period_minutes)
                     VALUES (1, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE office_ip_address = VALUES(office_ip_address)'
                );
                $ok = $ins->execute([
                    $newValue,
                    (string) ($this->settings['start_time'] ?? '09:00:00'),
                    (string) ($this->settings['end_time'] ?? '17:00:00'),
                    (int) ($this->settings['grace_period_minutes'] ?? 15),
                ]);
            }
            if ($ok) {
                $this->settings['office_ip_address'] = $newValue;
                return true;
            }
        } catch (Throwable $e) {
            error_log('rememberOfficeIp failed: ' . $e->getMessage());
        }
        return false;
    }

    private function isGeofenceEnabled(): bool
    {
        if (isset($this->settings['geofence_enabled'])) {
            return (int) $this->settings['geofence_enabled'] === 1;
        }

        $officeLat = (isset($this->settings['latitude']) && $this->settings['latitude'] !== null && $this->settings['latitude'] !== '')
            ? (float) $this->settings['latitude'] : 0.0;
        $officeLon = (isset($this->settings['longitude']) && $this->settings['longitude'] !== null && $this->settings['longitude'] !== '')
            ? (float) $this->settings['longitude'] : 0.0;

        return $officeLat != 0.0 && $officeLon != 0.0;
    }

    /** Calendar date string (Y-m-d) used for lookups — keep in sync with clockIn INSERT. */
    public function getTodayDateString(): string {
        return date('Y-m-d');
    }

    public function getTodayRecord($userId) {
        $date = $this->getTodayDateString();
        try {
            $t = self::RECORDS_TABLE;
            $stmt = $this->pdo->prepare("SELECT * FROM `{$t}` WHERE user_id = ? AND `date` = ?");
            $stmt->execute([$userId, $date]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function clockIn($userId, $latitude = null, $longitude = null) {
        // 1. IP & Location Check
        $currentIp = $this->getCurrentUserIp();
        $ipAllowed = $this->isIpAllowed($currentIp);
        $locationAllowed = false;
        $distance = null;

        if (!$ipAllowed) {
            if (!$this->isGeofenceEnabled()) {
                return [
                    'success' => false,
                    'message' => 'Access Denied: You are not connected to the office network. Geofencing fallback is disabled.',
                ];
            }

            $officeLat = (isset($this->settings['latitude']) && $this->settings['latitude'] !== null && $this->settings['latitude'] !== '') ? (float) $this->settings['latitude'] : (defined('OFFICE_LAT') ? (float) OFFICE_LAT : 0.0);
            $officeLon = (isset($this->settings['longitude']) && $this->settings['longitude'] !== null && $this->settings['longitude'] !== '') ? (float) $this->settings['longitude'] : (defined('OFFICE_LON') ? (float) OFFICE_LON : 0.0);
            $officeRadius = (isset($this->settings['radius_meters']) && $this->settings['radius_meters'] !== null && $this->settings['radius_meters'] !== '') ? (float) $this->settings['radius_meters'] : (defined('OFFICE_RADIUS_M') ? (float) OFFICE_RADIUS_M : 100.0);

            if ($latitude !== null && $longitude !== null && $officeLat != 0.0 && $officeLon != 0.0) {
                $distance = $this->calculateDistance((float)$latitude, (float)$longitude, $officeLat, $officeLon);
                if ($distance <= $officeRadius) {
                    $locationAllowed = true;
                }
            }

            if (!$locationAllowed) {
                if ($latitude === null || $longitude === null) {
                    return [
                        'success' => false,
                        'message' => "Access Denied: You are not connected to the office network, and we couldn't get your GPS location. Please connect to the office network or enable location services."
                    ];
                } else {
                    $distStr = $distance !== null ? number_format($distance, 1) . "m" : "unknown";
                    return [
                        'success' => false,
                        'message' => "Access Denied: You are not connected to the office network, and you are too far from the office (Distance: $distStr, Max allowed: {$officeRadius}m)."
                    ];
                }
            }
        }

        // 2. Check existing
        $existing = $this->getTodayRecord($userId);
        if ($existing) {
            return ['success' => false, 'message' => 'You have already clocked in today.'];
        }

        // 3. Get User Signature
        $stmtUser = $this->pdo->prepare("SELECT signature_path FROM users WHERE id = ?");
        $stmtUser->execute([$userId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
        $signaturePath = $user['signature_path'] ?? null;

        if (!$signaturePath) {
             // Optional: Block if no signature? User requirement says "fetch signature... and use it".
             // Letting it proceed with null/warning might be safer unless strictly required.
             // For now, allow but maybe warn in message? No, clean logic first.
        }

        // 4. Calculate Status
        $now = date('H:i:s');
        $startTime = $this->settings['start_time'];
        $gracePeriod = $this->settings['grace_period_minutes'];
        
        // Logic: late if now > start_time + grace
        $status = 'On Time';
        $startTimestamp = strtotime($startTime);
        $graceTimestamp = $startTimestamp + ($gracePeriod * 60);
        $nowTimestamp = strtotime($now);

        if ($nowTimestamp > $graceTimestamp) {
            $status = 'Late';
        } elseif ($nowTimestamp < ($startTimestamp - (30 * 60))) { // Early if > 30mins before
            $status = 'Early'; 
        }

        // 5. Insert — bind same calendar date as getTodayRecord() (avoid CURRENT_DATE vs PHP timezone mismatch)
        $today = $this->getTodayDateString();
        $t = self::RECORDS_TABLE;
        $stmt = $this->pdo->prepare("
            INSERT INTO `{$t}` (user_id, `date`, time_in, status, signature_image, ip_address) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        try {
            $ok = $stmt->execute([$userId, $today, $now, $status, $signaturePath, $currentIp]);
        } catch (\PDOException $e) {
            // 1062: duplicate key (double-submit or race with existing row)
            $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
            if ($driverCode === 1062 || (strpos($e->getMessage(), 'Duplicate') !== false)) {
                return ['success' => false, 'message' => 'You have already clocked in today.'];
            }
            throw $e;
        }

        if ($ok) {
            if (!$ipAllowed && $locationAllowed) {
                $this->rememberOfficeIp($currentIp);
            }
            return [
                'success' => true,
                'message' => "Clocked In Successfully ($status)",
                'time_in' => $now,
                'status' => $status,
                'ip' => $currentIp,
                'date' => $today,
            ];
        }
        return ['success' => false, 'message' => 'Database error during clock in.'];
    }

    public function clockOut($userId, $latitude = null, $longitude = null) {
        // 1. Check existing
        $record = $this->getTodayRecord($userId);
        if (!$record) {
            return ['success' => false, 'message' => 'No active session found today.'];
        }
        if ($record['time_out']) {
            return ['success' => false, 'message' => 'You have already clocked out.'];
        }

        // 2. IP & Location Check
        $currentIp = $this->getCurrentUserIp();
        $ipAllowed = $this->isIpAllowed($currentIp);
        $locationAllowed = false;
        $distance = null;

        if (!$ipAllowed) {
            if (!$this->isGeofenceEnabled()) {
                return [
                    'success' => false,
                    'message' => 'Access Denied: You are not connected to the office network. Geofencing fallback is disabled.',
                ];
            }

            $officeLat = (isset($this->settings['latitude']) && $this->settings['latitude'] !== null && $this->settings['latitude'] !== '') ? (float) $this->settings['latitude'] : (defined('OFFICE_LAT') ? (float) OFFICE_LAT : 0.0);
            $officeLon = (isset($this->settings['longitude']) && $this->settings['longitude'] !== null && $this->settings['longitude'] !== '') ? (float) $this->settings['longitude'] : (defined('OFFICE_LON') ? (float) OFFICE_LON : 0.0);
            $officeRadius = (isset($this->settings['radius_meters']) && $this->settings['radius_meters'] !== null && $this->settings['radius_meters'] !== '') ? (float) $this->settings['radius_meters'] : (defined('OFFICE_RADIUS_M') ? (float) OFFICE_RADIUS_M : 100.0);

            if ($latitude !== null && $longitude !== null && $officeLat != 0.0 && $officeLon != 0.0) {
                $distance = $this->calculateDistance((float)$latitude, (float)$longitude, $officeLat, $officeLon);
                if ($distance <= $officeRadius) {
                    $locationAllowed = true;
                }
            }

            if (!$locationAllowed) {
                if ($latitude === null || $longitude === null) {
                    return [
                        'success' => false,
                        'message' => "Access Denied: You are not connected to the office network, and we couldn't get your GPS location. Please connect to the office network or enable location services."
                    ];
                } else {
                    $distStr = $distance !== null ? number_format($distance, 1) . "m" : "unknown";
                    return [
                        'success' => false,
                        'message' => "Access Denied: You are not connected to the office network, and you are too far from the office (Distance: $distStr, Max allowed: {$officeRadius}m)."
                    ];
                }
            }
        }

        // 3. Calculate Hours
        $now = date('H:i:s');
        $timeIn = strtotime($record['time_in']);
        $timeOut = strtotime($now);

        $totalSeconds = $timeOut - $timeIn;
        $totalHours = round($totalSeconds / 3600, 2);

        // Overtime: if worked > (EndTime - StartTime) ? Or if TimeOut > EndTime?
        // Usually Overtime is TimeOut > EndTime
        $endTime = strtotime($this->settings['end_time']);
        $overtimeHours = 0;
        
        if ($timeOut > $endTime) {
            $overtimeSeconds = $timeOut - $endTime;
            $overtimeHours = round($overtimeSeconds / 3600, 2);
        }

        // 4. Update
        $t = self::RECORDS_TABLE;
        $stmt = $this->pdo->prepare("
            UPDATE `{$t}` 
            SET time_out = ?, total_hours = ?, overtime_hours = ? 
            WHERE id = ?
        ");
        
        if ($stmt->execute([$now, $totalHours, $overtimeHours, $record['id']])) {
            if (!$ipAllowed && $locationAllowed) {
                $this->rememberOfficeIp($currentIp);
            }
            return [
                'success' => true,
                'message' => 'Clocked Out Successfully.',
                'time_out' => $now,
                'time_in' => $record['time_in'],
                'total_hours' => $totalHours,
                'overtime_hours' => $overtimeHours,
                'status' => $record['status'] ?? '',
                'date' => $record['date'] ?? date('Y-m-d'),
                'ip' => $currentIp,
            ];
        } else {
             return ['success' => false, 'message' => 'Database error during clock out.'];
        }
    }

    public function normalizeHistoryMonth(?string $month = null): string
    {
        $tz = new \DateTimeZone('Africa/Dar_es_Salaam');
        $now = new \DateTime('now', $tz);
        $month = is_string($month) ? trim($month) : '';
        if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $probe = \DateTime::createFromFormat('Y-m-d', $month . '-01', $tz);
            if ($probe instanceof \DateTime) {
                return $probe->format('Y-m');
            }
        }
        return $now->format('Y-m');
    }

    public function formatHistoryMonthLabel(string $month): string
    {
        $month = $this->normalizeHistoryMonth($month);
        $dt = \DateTime::createFromFormat('Y-m-d', $month . '-01');
        return $dt instanceof \DateTime ? $dt->format('F Y') : $month;
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    public function getHistoryMonthOptions(int $monthsBack = 12): array
    {
        $tz = new \DateTimeZone('Africa/Dar_es_Salaam');
        $cursor = new \DateTime('now', $tz);
        $cursor->modify('first day of this month');
        $options = [];
        $count = max(1, min(36, $monthsBack));
        for ($i = 0; $i < $count; $i++) {
            $value = $cursor->format('Y-m');
            $options[] = [
                'value' => $value,
                'label' => $cursor->format('F Y'),
            ];
            $cursor->modify('-1 month');
        }
        return $options;
    }

    public function getHistory($userId, $month = null) {
        try {
            $t = self::RECORDS_TABLE;
            $month = $this->normalizeHistoryMonth(is_string($month) ? $month : null);
            $start = $month . '-01';
            $endDt = \DateTime::createFromFormat('Y-m-d', $start);
            $end = $endDt instanceof \DateTime ? $endDt->format('Y-m-t') : $start;
            $stmt = $this->pdo->prepare("
                SELECT * FROM `{$t}` 
                WHERE user_id = ? AND `date` >= ? AND `date` <= ?
                ORDER BY date DESC 
            ");
            $stmt->execute([(int) $userId, $start, $end]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getStats($userId) {
        try {
            $t = self::RECORDS_TABLE;
            $monthStart = date('Y-m-01');
            $stmt = $this->pdo->prepare("
                SELECT 
                    SUM(total_hours) as total_hours,
                    SUM(overtime_hours) as total_ot,
                    COUNT(*) as total_days,
                    SUM(CASE WHEN status = 'On Time' THEN 1 ELSE 0 END) as on_time_days,
                    SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) as late_days
                FROM `{$t}` 
                WHERE user_id = ? AND `date` >= ?
            ");
            $stmt->execute([$userId, $monthStart]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return ['total_hours' => 0, 'total_ot' => 0, 'total_days' => 0, 'on_time_days' => 0, 'late_days' => 0];
        }
    }

    /**
     * Analytics desk payload for a rolling period (7 / 30 / 90 days)
     * or an explicit start/end date range.
     *
     * @param 'personal'|'team' $scope
     * @return array<string,mixed>
     */
    public function getAnalytics(
        int $userId,
        int $period = 30,
        string $scope = 'personal',
        ?string $rangeStart = null,
        ?string $rangeEnd = null
    ): array {
        if (!in_array($period, [7, 30, 90], true)) {
            $period = 30;
        }
        $scope = strtolower(trim($scope)) === 'team' ? 'team' : 'personal';

        $tz = new DateTimeZone('Africa/Dar_es_Salaam');
        $end = new DateTime('now', $tz);
        $start = (clone $end)->modify('-' . max(0, $period - 1) . ' days');
        $startDate = $start->format('Y-m-d');
        $endDate = $end->format('Y-m-d');
        $customRange = false;

        $normalized = $this->normalizeAnalyticsDateRange($rangeStart, $rangeEnd, $tz);
        if ($normalized !== null) {
            $startDate = $normalized['start'];
            $endDate = $normalized['end'];
            $start = new DateTime($startDate . ' 12:00:00', $tz);
            $end = new DateTime($endDate . ' 12:00:00', $tz);
            $customRange = true;
            $periodDays = max(1, (int) $start->diff($end)->days + 1);
            if (in_array($periodDays, [7, 30, 90], true)) {
                $period = $periodDays;
            }
        }

        $records = [];
        $teamHeadcount = 1;
        $employeeSeriesMap = [];
        try {
            $t = self::RECORDS_TABLE;
            if ($scope === 'team') {
                // Team view default: current calendar month unless a custom range is set.
                if (!$customRange) {
                    $start = new DateTime('now', $tz);
                    $end = clone $start;
                    $start = (clone $end)->modify('first day of this month');
                    $startDate = $start->format('Y-m-d');
                    $endDate = $end->format('Y-m-d');
                }

                $adminRoles = [
                    'admin', 'administrator', 'superadmin', 'super_admin',
                    'company_admin', 'company admin', 'owner', 'system_admin', 'platform_admin',
                ];
                $adminPlaceholders = implode(',', array_fill(0, count($adminRoles), '?'));

                $hasFullName = true;
                $hasUsername = true;
                $hasRole = true;
                try {
                    $this->pdo->query('SELECT full_name, username, role FROM users LIMIT 1');
                } catch (Throwable $e) {
                    try {
                        $this->pdo->query('SELECT username, role FROM users LIMIT 1');
                        $hasFullName = false;
                    } catch (Throwable $e2) {
                        $hasFullName = false;
                        $hasUsername = false;
                        $hasRole = false;
                    }
                }
                $nameSelect = $hasFullName
                    ? 'u.full_name, u.username'
                    : ($hasUsername ? 'u.username AS full_name, u.username' : 'NULL AS full_name, NULL AS username');

                $roleFilter = $hasRole
                    ? "AND LOWER(TRIM(COALESCE(u.role, 'employee'))) NOT IN ({$adminPlaceholders})"
                    : '';

                $sql = "SELECT r.`date`, r.time_in, r.time_out, r.status, r.total_hours, r.overtime_hours, r.user_id,
                               {$nameSelect}
                        FROM `{$t}` r
                        INNER JOIN users u ON u.id = r.user_id
                        WHERE r.`date` BETWEEN ? AND ?
                        {$roleFilter}
                        ORDER BY r.`date` ASC, r.user_id ASC";
                $stmt = $this->pdo->prepare($sql);
                $params = [$startDate, $endDate];
                if ($hasRole) {
                    $params = array_merge($params, $adminRoles);
                }
                $stmt->execute($params);
                $records = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                // Seed every non-admin employee so each gets a colored line (zeros if no clock-ins).
                try {
                    $userSelect = $hasFullName
                        ? 'u.id, u.full_name, u.username'
                        : ($hasUsername ? 'u.id, u.username AS full_name, u.username' : 'u.id, NULL AS full_name, NULL AS username');
                    $usersSql = "SELECT {$userSelect} FROM users u WHERE 1=1 {$roleFilter} ORDER BY "
                        . ($hasFullName ? 'u.full_name' : ($hasUsername ? 'u.username' : 'u.id'))
                        . ' ASC';
                    if ($hasRole) {
                        $usersStmt = $this->pdo->prepare($usersSql);
                        $usersStmt->execute($adminRoles);
                    } else {
                        $usersStmt = $this->pdo->query($usersSql);
                    }
                    $teamUsers = $usersStmt ? ($usersStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
                    foreach ($teamUsers as $tu) {
                        $uid = (int) ($tu['id'] ?? 0);
                        if ($uid <= 0) {
                            continue;
                        }
                        $label = trim((string) ($tu['full_name'] ?? ''));
                        if ($label === '') {
                            $label = trim((string) ($tu['username'] ?? ('User #' . $uid)));
                        }
                        $employeeSeriesMap[$uid] = [
                            'id' => $uid,
                            'name' => $label,
                            'byDate' => [],
                            'punctSum' => 0.0,
                            'punctDays' => 0,
                            'lateIns' => 0,
                            'missedOuts' => 0,
                            'earlyOuts' => 0,
                            'signInSum' => 0.0,
                            'signOutSum' => 0.0,
                        ];
                    }
                    $teamHeadcount = max(1, count($employeeSeriesMap));
                } catch (Throwable $e) {
                    try {
                        $countSql = "SELECT COUNT(*) FROM users u WHERE 1=1 {$roleFilter}";
                        if ($hasRole) {
                            $countStmt = $this->pdo->prepare($countSql);
                            $countStmt->execute($adminRoles);
                            $teamHeadcount = (int) $countStmt->fetchColumn();
                        } else {
                            $teamHeadcount = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                        }
                    } catch (Throwable $e2) {
                        $teamHeadcount = 1;
                    }
                    if ($teamHeadcount < 1) {
                        $teamHeadcount = 1;
                    }
                }
            } else {
                $stmt = $this->pdo->prepare(
                    "SELECT `date`, time_in, time_out, status, total_hours, overtime_hours, user_id
                     FROM `{$t}`
                     WHERE user_id = ? AND `date` BETWEEN ? AND ?
                     ORDER BY `date` ASC"
                );
                $stmt->execute([$userId, $startDate, $endDate]);
                $records = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {
            $records = [];
        }

        // One row per person-day from what was actually recorded (prefer completed clock-outs).
        $records = $this->collapseAttendanceRecordsForAnalytics($records, $userId);

        $presentDays = 0;
        $lateDays = 0;
        $missedSignOuts = 0;
        $totalHours = 0.0;
        $totalOt = 0.0;
        $longestStreak = 0;
        $currentStreak = 0;
        $lastDate = null;
        $dailyHours = [];
        $weeklyData = ['Mon' => 0.0, 'Tue' => 0.0, 'Wed' => 0.0, 'Thu' => 0.0, 'Fri' => 0.0, 'Sat' => 0.0, 'Sun' => 0.0];
        $uniqueMembers = [];
        $datesWithAttendance = [];
        $punctSumAll = 0.0;
        $punctDaysAll = 0;
        $signInSumAll = 0.0;
        $signOutSumAll = 0.0;
        // Always score against real calendar today (open sessions today are not missed yet).
        $todayStr = (new DateTime('now', $tz))->format('Y-m-d');
        $endTimeStr = (string) ($this->settings['end_time'] ?? '17:00:00');
        $graceMinutes = (int) ($this->settings['grace_period_minutes'] ?? 15);

        foreach ($records as $record) {
            $date = (string) ($record['date'] ?? '');
            if ($date === '') {
                continue;
            }
            $presentDays++;
            $datesWithAttendance[$date] = true;
            $recUserId = !empty($record['user_id']) ? (int) $record['user_id'] : 0;
            if ($recUserId > 0) {
                $uniqueMembers[$recUserId] = true;
            }

            $isLate = stripos((string) ($record['status'] ?? ''), 'late') !== false;
            if ($isLate) {
                $lateDays++;
            }

            $hours = 0.0;
            if (isset($record['total_hours']) && $record['total_hours'] !== null && $record['total_hours'] !== '') {
                $hours = (float) $record['total_hours'];
            } elseif (!empty($record['time_in']) && $this->hasValidAttendanceTimeOut($record['time_out'] ?? null)) {
                try {
                    $in = new DateTime((string) $record['time_in']);
                    $out = new DateTime((string) $record['time_out']);
                    $diff = $in->diff($out);
                    $hours = $diff->h + ($diff->i / 60) + ($diff->days * 24);
                } catch (Throwable $e) {
                    $hours = 0.0;
                }
            }

            $ot = isset($record['overtime_hours']) ? (float) $record['overtime_hours'] : 0.0;
            $totalHours += $hours;
            $totalOt += $ot;
            if (!isset($dailyHours[$date])) {
                $dailyHours[$date] = 0.0;
            }
            $dailyHours[$date] = round($dailyHours[$date] + $hours, 2);

            $dayKey = date('D', strtotime($date));
            if (isset($weeklyData[$dayKey])) {
                $weeklyData[$dayKey] += $hours;
            }

            $dayScore = $this->scoreAttendanceDay(
                (string) ($record['status'] ?? ''),
                $record['time_out'] ?? null,
                $date,
                $todayStr,
                $endTimeStr,
                $graceMinutes
            );
            $punctSumAll += $dayScore['combined'];
            $signInSumAll += $dayScore['signIn'];
            $signOutSumAll += $dayScore['signOut'];
            $punctDaysAll++;
            if ($dayScore['missedOut']) {
                $missedSignOuts++;
            }

            if ($scope === 'team' && $recUserId > 0) {
                if (!isset($employeeSeriesMap[$recUserId])) {
                    $label = trim((string) ($record['full_name'] ?? ''));
                    if ($label === '') {
                        $label = trim((string) ($record['username'] ?? ('User #' . $recUserId)));
                    }
                    $employeeSeriesMap[$recUserId] = [
                        'id' => $recUserId,
                        'name' => $label,
                        'byDate' => [],
                        'punctSum' => 0.0,
                        'punctDays' => 0,
                        'lateIns' => 0,
                        'missedOuts' => 0,
                        'earlyOuts' => 0,
                        'signInSum' => 0.0,
                        'signOutSum' => 0.0,
                    ];
                }
                // After collapse there is one row per person-day — set hours, do not stack duplicates.
                $employeeSeriesMap[$recUserId]['byDate'][$date] = round($hours, 2);
                $employeeSeriesMap[$recUserId]['punctSum'] += $dayScore['combined'];
                $employeeSeriesMap[$recUserId]['punctDays']++;
                $employeeSeriesMap[$recUserId]['signInSum'] += $dayScore['signIn'];
                $employeeSeriesMap[$recUserId]['signOutSum'] += $dayScore['signOut'];
                if ($dayScore['lateIn']) {
                    $employeeSeriesMap[$recUserId]['lateIns']++;
                }
                if ($dayScore['missedOut']) {
                    $employeeSeriesMap[$recUserId]['missedOuts']++;
                }
                if ($dayScore['earlyOut']) {
                    $employeeSeriesMap[$recUserId]['earlyOuts']++;
                }
            }
        }

        // Streak based on unique calendar days with attendance (works for personal + team).
        $sortedDates = array_keys($datesWithAttendance);
        sort($sortedDates);
        foreach ($sortedDates as $date) {
            if ($lastDate === null || (strtotime($date) - strtotime($lastDate)) === 86400) {
                $currentStreak++;
                $longestStreak = max($longestStreak, $currentStreak);
            } else {
                $currentStreak = 1;
            }
            $lastDate = $date;
        }

        $workingDays = 0;
        $cursor = clone $start;
        $endBound = clone $end;
        while ($cursor <= $endBound) {
            if ((int) $cursor->format('N') < 6) {
                $workingDays++;
            }
            $cursor->modify('+1 day');
        }

        $memberCount = $scope === 'team' ? $teamHeadcount : 1;
        $expectedSlots = $workingDays * max(1, $memberCount);
        $attendanceRate = $expectedSlots > 0 ? round(($presentDays / $expectedSlots) * 100, 1) : 0.0;
        $punctualityScore = $punctDaysAll > 0
            ? round($punctSumAll / $punctDaysAll, 1)
            : 0.0;
        $avgHoursPerDay = $presentDays > 0 ? round($totalHours / $presentDays, 1) : 0.0;
        $activeMembers = count($uniqueMembers);

        $insights = $this->buildAnalyticsInsights(
            $scope,
            $presentDays,
            $punctualityScore,
            $lateDays,
            $avgHoursPerDay,
            $longestStreak,
            $activeMembers,
            $memberCount
        );

        foreach ($dailyHours as $d => $h) {
            $dailyHours[$d] = round((float) $h, 2);
        }

        // Continuous date axis for team line chart (month-to-date).
        $lineLabels = [];
        $lineCursor = clone $start;
        while ($lineCursor <= $end) {
            $lineLabels[] = $lineCursor->format('Y-m-d');
            $lineCursor->modify('+1 day');
        }

        $palette = [
            '#3b82f6', '#8b5cf6', '#22c55e', '#f97316', '#14b8a6',
            '#ec4899', '#6366f1', '#eab308', '#38bdf8', '#84cc16',
            '#0ea5e9', '#a855f7', '#f59e0b', '#10b981', '#f43f5e',
            '#06b6d4', '#7c3aed', '#ea580c', '#2563eb', '#64748b',
        ];
        $lineSeries = [];
        $colorIdx = 0;
        $teamAvgIn = 0.0;
        $teamAvgOut = 0.0;
        $teamAvgCombined = 0.0;
        $scoredMembers = 0;
        $topScore = -1.0;
        $topName = '';
        $topId = 0;
        $userIdsForKpi = [];
        foreach ($employeeSeriesMap as $series) {
            $userIdsForKpi[] = (int) $series['id'];
        }
        if ($scope !== 'team') {
            $userIdsForKpi = [$userId];
        }
        $taskKpiByUser = $this->fetchTaskKpiPointsByUser($userIdsForKpi, $startDate, $endDate);

        foreach ($employeeSeriesMap as $series) {
            $values = [];
            foreach ($lineLabels as $d) {
                $values[] = isset($series['byDate'][$d]) ? round((float) $series['byDate'][$d], 2) : 0.0;
            }
            $days = (int) ($series['punctDays'] ?? 0);
            $empScore = $days > 0 ? round(((float) $series['punctSum']) / $days, 1) : null;
            $empIn = $days > 0 ? round(((float) $series['signInSum']) / $days, 1) : null;
            $empOut = $days > 0 ? round(((float) $series['signOutSum']) / $days, 1) : null;
            if ($empScore !== null) {
                $teamAvgCombined += $empScore;
                $teamAvgIn += (float) $empIn;
                $teamAvgOut += (float) $empOut;
                $scoredMembers++;
                if ($empScore > $topScore) {
                    $topScore = $empScore;
                    $topName = (string) $series['name'];
                    $topId = (int) $series['id'];
                }
            }
            $uid = (int) $series['id'];
            $taskBits = $taskKpiByUser[$uid] ?? [
                'dailyPoints' => 0.0,
                'weeklyPoints' => 0.0,
                'dailyCompleted' => 0,
                'weeklyCompleted' => 0,
                'weeklyTotal' => 0,
            ];
            $attendancePts = $empScore !== null ? round(($empScore / 100.0) * 40.0, 1) : 0.0;
            $dailyPts = (float) ($taskBits['dailyPoints'] ?? 0);
            $weeklyPts = (float) ($taskBits['weeklyPoints'] ?? 0);
            $kpiTotal = round(min(100.0, max(0.0, $attendancePts + $dailyPts + $weeklyPts)), 1);
            $lineSeries[] = [
                'id' => $uid,
                'name' => (string) $series['name'],
                'color' => $palette[$colorIdx % count($palette)],
                'values' => $values,
                'punctuality' => $empScore,
                'signInScore' => $empIn,
                'signOutScore' => $empOut,
                'lateIns' => (int) ($series['lateIns'] ?? 0),
                'missedOuts' => (int) ($series['missedOuts'] ?? 0),
                'earlyOuts' => (int) ($series['earlyOuts'] ?? 0),
                'kpiPoints' => $kpiTotal,
                'kpiBreakdown' => [
                    'attendance' => $attendancePts,
                    'dailyTasks' => $dailyPts,
                    'weeklyTasks' => $weeklyPts,
                    'max' => 100,
                    'weights' => [
                        'attendance' => 40,
                        'dailyTasks' => 30,
                        'weeklyTasks' => 30,
                    ],
                    'dailyCompleted' => (int) ($taskBits['dailyCompleted'] ?? 0),
                    'weeklyCompleted' => (int) ($taskBits['weeklyCompleted'] ?? 0),
                    'weeklyTotal' => (int) ($taskBits['weeklyTotal'] ?? 0),
                ],
            ];
            $colorIdx++;
        }
        usort($lineSeries, static function ($a, $b) {
            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        $personalKpi = null;
        if ($scope !== 'team') {
            $taskBits = $taskKpiByUser[$userId] ?? [
                'dailyPoints' => 0.0,
                'weeklyPoints' => 0.0,
                'dailyCompleted' => 0,
                'weeklyCompleted' => 0,
                'weeklyTotal' => 0,
            ];
            $attendancePts = round(($punctualityScore / 100.0) * 40.0, 1);
            $dailyPts = (float) ($taskBits['dailyPoints'] ?? 0);
            $weeklyPts = (float) ($taskBits['weeklyPoints'] ?? 0);
            $kpiTotal = round(min(100.0, max(0.0, $attendancePts + $dailyPts + $weeklyPts)), 1);
            $signInAvg = $punctDaysAll > 0 ? round($signInSumAll / $punctDaysAll, 1) : 0.0;
            $signOutAvg = $punctDaysAll > 0 ? round($signOutSumAll / $punctDaysAll, 1) : 0.0;
            $signInPts = round(($signInAvg / 100.0) * 20.0, 1);
            $signOutPts = round(($signOutAvg / 100.0) * 20.0, 1);
            $personalKpi = [
                'kpiPoints' => $kpiTotal,
                'kpiBreakdown' => [
                    'attendance' => $attendancePts,
                    'dailyTasks' => $dailyPts,
                    'weeklyTasks' => $weeklyPts,
                    'max' => 100,
                    'weights' => [
                        'attendance' => 40,
                        'dailyTasks' => 30,
                        'weeklyTasks' => 30,
                    ],
                    'dailyCompleted' => (int) ($taskBits['dailyCompleted'] ?? 0),
                    'weeklyCompleted' => (int) ($taskBits['weeklyCompleted'] ?? 0),
                    'weeklyTotal' => (int) ($taskBits['weeklyTotal'] ?? 0),
                ],
                'attendanceDetail' => [
                    'punctualityScore' => $punctualityScore,
                    'signInScore' => $signInAvg,
                    'signOutScore' => $signOutAvg,
                    'signInPoints' => $signInPts,
                    'signOutPoints' => $signOutPts,
                    'lateIns' => $lateDays,
                    'missedOuts' => $missedSignOuts,
                    'daysScored' => $punctDaysAll,
                    'rules' => [
                        'lateInPenalty' => 30,
                        'missedOutPenalty' => 40,
                        'earlyOutPenalty' => 15,
                        'note' => 'Attendance 40 = (sign-in avg / 100 × 20) + (sign-out avg / 100 × 20). Late in −30, missed out −40, early leave −15 on the day score.',
                    ],
                ],
                'grade' => $this->kpiGradeLabel($kpiTotal),
                'dailyTasksList' => $this->fetchDailyTaskListForUser($userId, $startDate, $endDate),
                'weeklyTasksList' => $this->fetchWeeklyTaskListForUser($userId, $startDate, $endDate),
            ];
        }

        $monthLabel = $start->format('F Y');
        $kpiAvg = 0.0;
        $kpiTop = -1.0;
        $kpiTopName = '';
        $kpiTopId = 0;
        $kpiCount = 0;
        foreach ($lineSeries as $row) {
            // Avg/Top KPI from people who actually have attendance recorded in this range.
            if ((int) ($row['punctDays'] ?? 0) <= 0) {
                continue;
            }
            $pts = (float) ($row['kpiPoints'] ?? 0);
            $kpiAvg += $pts;
            $kpiCount++;
            if ($pts > $kpiTop) {
                $kpiTop = $pts;
                $kpiTopName = (string) $row['name'];
                $kpiTopId = (int) $row['id'];
            }
        }
        $teamKpi = [
            'average' => $kpiCount > 0 ? round($kpiAvg / $kpiCount, 1) : 0.0,
            'top' => $kpiTop >= 0
                ? [
                    'id' => $kpiTopId,
                    'name' => $kpiTopName,
                    'score' => $kpiTop,
                    'grade' => $this->kpiGradeLabel($kpiTop),
                ]
                : null,
            'weights' => [
                'attendance' => 40,
                'dailyTasks' => 30,
                'weeklyTasks' => 30,
            ],
            'targets' => [
                'dailyTodos' => 5,
                'weeklyTasks' => 7,
            ],
            'grades' => [
                '90-100' => 'Outstanding',
                '80-89' => 'Exceeds Expectations',
                '70-79' => 'Meets Expectations',
                'below-70' => 'Improvement Plan Required',
            ],
            'note' => '100-pt KPI from Ultimate manual: Attendance 40 + Daily todos (target 5) 30 + Weekly tasks (target 7) 30.',
        ];
        $teamPunctuality = [
            'average' => $scoredMembers > 0 ? round($teamAvgCombined / $scoredMembers, 1) : 0.0,
            'averageSignIn' => $scoredMembers > 0 ? round($teamAvgIn / $scoredMembers, 1) : 0.0,
            'averageSignOut' => $scoredMembers > 0 ? round($teamAvgOut / $scoredMembers, 1) : 0.0,
            'top' => $topScore >= 0
                ? [
                    'id' => $topId,
                    'name' => $topName,
                    'score' => $topScore,
                ]
                : null,
            'missedSignOuts' => $missedSignOuts,
            'lateIns' => $lateDays,
            'rules' => [
                'lateInPenalty' => 30,
                'missedOutPenalty' => 40,
                'earlyOutPenalty' => 15,
                'note' => 'Late sign-in -30, forgotten sign-out -40, early leave -15. Open sessions today are not penalized.',
            ],
        ];
        if ($scope === 'team' && $scoredMembers > 0) {
            $punctualityScore = $teamPunctuality['average'];
        }

        return [
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
            'range' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'rangeBounds' => [
                'min' => (new DateTime('now', $tz))->modify('-365 days')->format('Y-m-d'),
                'max' => (new DateTime('now', $tz))->format('Y-m-d'),
            ],
            'metrics' => [
                'attendanceRate' => $attendanceRate,
                'presentDays' => $presentDays,
                'workingDays' => $workingDays,
                'expectedSlots' => $expectedSlots,
                'teamHeadcount' => $memberCount,
                'activeMembers' => $activeMembers,
                'punctualityScore' => $punctualityScore,
                'lateDays' => $lateDays,
                'missedSignOuts' => $missedSignOuts,
                'longestStreak' => $longestStreak,
                'currentStreak' => $currentStreak,
                'avgHoursPerDay' => $avgHoursPerDay,
                'totalHours' => round($totalHours, 1),
                'totalOt' => round($totalOt, 1),
            ],
            'charts' => [
                'daily' => [
                    'labels' => array_keys($dailyHours),
                    'values' => array_values($dailyHours),
                ],
                'weekly' => [
                    'labels' => array_keys($weeklyData),
                    'values' => array_map(static fn ($v) => round((float) $v, 2), array_values($weeklyData)),
                ],
                'teamLine' => [
                    'title' => $monthLabel . ' performance',
                    'labels' => $lineLabels,
                    'series' => $lineSeries,
                    'punctuality' => $scope === 'team' ? $teamPunctuality : null,
                    'kpi' => $scope === 'team' ? $teamKpi : null,
                ],
            ],
            'personalKpi' => $personalKpi,
            'insights' => $insights,
            'history' => array_reverse($records),
        ];
    }

    /**
     * Keep one recorded row per person-day (prefer a completed clock-out).
     *
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    private function collapseAttendanceRecordsForAnalytics(array $records, int $fallbackUserId = 0): array
    {
        $best = [];
        foreach ($records as $record) {
            $date = (string) ($record['date'] ?? '');
            if ($date === '') {
                continue;
            }
            $uid = !empty($record['user_id']) ? (int) $record['user_id'] : $fallbackUserId;
            $key = $uid . '|' . $date;
            $quality = 0;
            if ($this->hasValidAttendanceTimeOut($record['time_out'] ?? null)) {
                $quality += 100;
            }
            if (isset($record['total_hours']) && $record['total_hours'] !== null && $record['total_hours'] !== '') {
                $quality += (int) min(50, max(0, (float) $record['total_hours'] * 2));
            }
            if (!empty($record['time_in'])) {
                $quality += 5;
            }
            if (!isset($best[$key]) || $quality > (int) ($best[$key]['_quality'] ?? -1)) {
                $record['_quality'] = $quality;
                $best[$key] = $record;
            }
        }

        $out = array_values($best);
        usort($out, static function ($a, $b) {
            $da = (string) ($a['date'] ?? '');
            $db = (string) ($b['date'] ?? '');
            if ($da === $db) {
                return ((int) ($a['user_id'] ?? 0)) <=> ((int) ($b['user_id'] ?? 0));
            }
            return $da <=> $db;
        });
        foreach ($out as &$row) {
            unset($row['_quality']);
        }
        unset($row);

        return $out;
    }

    private function hasValidAttendanceTimeOut($timeOut): bool
    {
        if ($timeOut === null) {
            return false;
        }
        $s = trim((string) $timeOut);
        if ($s === '' || $s === '00:00:00') {
            return false;
        }
        if (strpos($s, '0000-00-00') === 0) {
            return false;
        }
        return true;
    }

    /**
     * @return array{start:string,end:string}|null
     */
    private function normalizeAnalyticsDateRange(?string $rangeStart, ?string $rangeEnd, DateTimeZone $tz): ?array
    {
        $startRaw = trim((string) $rangeStart);
        $endRaw = trim((string) $rangeEnd);
        if ($startRaw === '' && $endRaw === '') {
            return null;
        }

        $today = new DateTime('now', $tz);
        $todayStr = $today->format('Y-m-d');
        $minAllowed = (clone $today)->modify('-365 days')->format('Y-m-d');

        $startStr = $startRaw !== '' ? $startRaw : $endRaw;
        $endStr = $endRaw !== '' ? $endRaw : $startRaw;

        $start = DateTime::createFromFormat('Y-m-d', $startStr, $tz);
        $end = DateTime::createFromFormat('Y-m-d', $endStr, $tz);
        if (!$start instanceof DateTime || !$end instanceof DateTime) {
            return null;
        }
        if ($start->format('Y-m-d') !== $startStr || $end->format('Y-m-d') !== $endStr) {
            return null;
        }

        if ($start > $end) {
            $tmp = $start;
            $start = $end;
            $end = $tmp;
        }

        if ($start->format('Y-m-d') < $minAllowed) {
            $start = new DateTime($minAllowed . ' 12:00:00', $tz);
        }
        if ($end->format('Y-m-d') > $todayStr) {
            $end = new DateTime($todayStr . ' 12:00:00', $tz);
        }
        if ($start > $end) {
            $start = clone $end;
        }

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ];
    }

    private function kpiGradeLabel(float $score): string
    {
        if ($score >= 90) {
            return 'Outstanding';
        }
        if ($score >= 80) {
            return 'Exceeds Expectations';
        }
        if ($score >= 70) {
            return 'Meets Expectations';
        }
        return 'Improvement Plan Required';
    }

    /**
     * Task management points for KPI (daily todos target 5 → 30pts, weekly tasks target 7 → 30pts).
     *
     * @param list<int> $userIds
     * @return array<int, array{dailyPoints:float,weeklyPoints:float,dailyCompleted:int,weeklyCompleted:int,weeklyTotal:int}>
     */
    private function fetchTaskKpiPointsByUser(array $userIds, string $startDate, string $endDate): array
    {
        $out = [];
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn ($id) => $id > 0)));
        foreach ($userIds as $uid) {
            $out[$uid] = [
                'dailyPoints' => 0.0,
                'weeklyPoints' => 0.0,
                'dailyCompleted' => 0,
                'weeklyCompleted' => 0,
                'weeklyTotal' => 0,
            ];
        }
        if ($userIds === []) {
            return $out;
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $weekdayCount = 0;
        try {
            $cursor = new DateTime($startDate . ' 12:00:00');
            $endBound = new DateTime($endDate . ' 12:00:00');
            while ($cursor <= $endBound) {
                if ((int) $cursor->format('N') < 6) {
                    $weekdayCount++;
                }
                $cursor->modify('+1 day');
            }
        } catch (Throwable $e) {
            $weekdayCount = max(1, (int) ((strtotime($endDate) - strtotime($startDate)) / 86400) + 1);
        }
        $weekdayCount = max(1, $weekdayCount);
        $dailyTarget = 5 * $weekdayCount;

        // Daily / checklist todos from user_tasks
        try {
            $sql = "SELECT user_id,
                           SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END) AS completed_count
                    FROM user_tasks
                    WHERE user_id IN ({$placeholders})
                      AND task_date BETWEEN ? AND ?
                    GROUP BY user_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_merge($userIds, [$startDate, $endDate]));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $uid = (int) ($row['user_id'] ?? 0);
                if ($uid <= 0 || !isset($out[$uid])) {
                    continue;
                }
                $completed = (int) ($row['completed_count'] ?? 0);
                $out[$uid]['dailyCompleted'] = $completed;
                $out[$uid]['dailyPoints'] = round(min(1.0, $completed / $dailyTarget) * 30.0, 1);
            }
        } catch (Throwable $e) {
            // table may not exist in some tenants
        }

        // Fallback: tasks.type = daily marked completed in range
        try {
            $sql = "SELECT user_id, COUNT(*) AS completed_count
                    FROM tasks
                    WHERE user_id IN ({$placeholders})
                      AND type = 'daily'
                      AND is_completed = 1
                      AND DATE(COALESCE(updated_at, created_at)) BETWEEN ? AND ?
                    GROUP BY user_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_merge($userIds, [$startDate, $endDate]));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $uid = (int) ($row['user_id'] ?? 0);
                if ($uid <= 0 || !isset($out[$uid])) {
                    continue;
                }
                $completed = (int) ($row['completed_count'] ?? 0);
                if ($completed > (int) $out[$uid]['dailyCompleted']) {
                    $out[$uid]['dailyCompleted'] = $completed;
                    $out[$uid]['dailyPoints'] = round(min(1.0, $completed / $dailyTarget) * 30.0, 1);
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        // Weekly plan items overlapping the range
        $weeklyTarget = 7;
        try {
            $weeks = max(1, (int) ceil($weekdayCount / 5));
            $periodWeeklyTarget = max($weeklyTarget, $weeklyTarget * $weeks);

            $sql = "SELECT p.user_id,
                           COUNT(i.id) AS total_items,
                           SUM(CASE WHEN i.is_completed = 1 THEN 1 ELSE 0 END) AS completed_items
                    FROM weekly_plans p
                    INNER JOIN weekly_plan_items i ON i.plan_id = p.id
                    WHERE p.user_id IN ({$placeholders})
                      AND p.week_start_date <= ?
                      AND DATE_ADD(p.week_start_date, INTERVAL 6 DAY) >= ?
                    GROUP BY p.user_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_merge($userIds, [$endDate, $startDate]));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $uid = (int) ($row['user_id'] ?? 0);
                if ($uid <= 0 || !isset($out[$uid])) {
                    continue;
                }
                $total = (int) ($row['total_items'] ?? 0);
                $completed = (int) ($row['completed_items'] ?? 0);
                $out[$uid]['weeklyTotal'] = $total;
                $out[$uid]['weeklyCompleted'] = $completed;
                $denom = max($periodWeeklyTarget, $total > 0 ? $total : $periodWeeklyTarget);
                $out[$uid]['weeklyPoints'] = round(min(1.0, $completed / $denom) * 30.0, 1);
            }
        } catch (Throwable $e) {
            // ignore
        }

        // Fallback: weekly_missions completion in overlapping weeks
        try {
            $sql = "SELECT user_id,
                           COUNT(*) AS total_items,
                           SUM(CASE WHEN completed_at IS NOT NULL OR status = 'Completed' THEN 1 ELSE 0 END) AS completed_items
                    FROM weekly_missions
                    WHERE user_id IN ({$placeholders})
                      AND week_start <= ?
                      AND week_end >= ?
                    GROUP BY user_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_merge($userIds, [$endDate, $startDate]));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $uid = (int) ($row['user_id'] ?? 0);
                if ($uid <= 0 || !isset($out[$uid])) {
                    continue;
                }
                $total = (int) ($row['total_items'] ?? 0);
                $completed = (int) ($row['completed_items'] ?? 0);
                if ($completed > (int) $out[$uid]['weeklyCompleted'] || (int) $out[$uid]['weeklyTotal'] === 0) {
                    $out[$uid]['weeklyTotal'] = $total;
                    $out[$uid]['weeklyCompleted'] = $completed;
                    $weeks = max(1, (int) ceil($weekdayCount / 5));
                    $periodWeeklyTarget = max(7, 7 * $weeks);
                    $denom = max($periodWeeklyTarget, $total > 0 ? $total : $periodWeeklyTarget);
                    $out[$uid]['weeklyPoints'] = round(min(1.0, $completed / $denom) * 30.0, 1);
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $out;
    }

    /**
     * Daily todo rows for personal KPI popup (user_tasks, else tasks.type=daily).
     *
     * @return list<array{id:int,title:string,completed:bool,taskDate:?string,source:string}>
     */
    private function fetchDailyTaskListForUser(int $userId, string $startDate, string $endDate): array
    {
        if ($userId <= 0) {
            return [];
        }

        $items = [];

        try {
            $sql = "SELECT id, task_description, is_completed, task_date
                    FROM user_tasks
                    WHERE user_id = ?
                      AND task_date BETWEEN ? AND ?
                    ORDER BY task_date DESC, id ASC
                    LIMIT 50";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$userId, $startDate, $endDate]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = trim((string) ($row['task_description'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $items[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'title' => $title,
                    'completed' => ((int) ($row['is_completed'] ?? 0)) === 1,
                    'taskDate' => isset($row['task_date']) ? (string) $row['task_date'] : null,
                    'source' => 'user_tasks',
                ];
            }
        } catch (Throwable $e) {
            // table may not exist
        }

        if ($items !== []) {
            return $items;
        }

        try {
            $sql = "SELECT id, description, is_completed,
                           DATE(COALESCE(updated_at, created_at)) AS task_date
                    FROM tasks
                    WHERE user_id = ?
                      AND type = 'daily'
                      AND DATE(COALESCE(updated_at, created_at)) BETWEEN ? AND ?
                    ORDER BY task_date DESC, id ASC
                    LIMIT 50";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$userId, $startDate, $endDate]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = trim((string) ($row['description'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $items[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'title' => $title,
                    'completed' => ((int) ($row['is_completed'] ?? 0)) === 1,
                    'taskDate' => isset($row['task_date']) ? (string) $row['task_date'] : null,
                    'source' => 'tasks',
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $items;
    }

    /**
     * Weekly task rows for personal KPI popup (plan items, else missions).
     *
     * @return list<array{id:int,title:string,completed:bool,weekStart:?string,source:string}>
     */
    private function fetchWeeklyTaskListForUser(int $userId, string $startDate, string $endDate): array
    {
        if ($userId <= 0) {
            return [];
        }

        $items = [];

        try {
            $sql = "SELECT i.id,
                           i.task_description,
                           i.is_completed,
                           p.week_start_date
                    FROM weekly_plans p
                    INNER JOIN weekly_plan_items i ON i.plan_id = p.id
                    WHERE p.user_id = ?
                      AND p.week_start_date <= ?
                      AND DATE_ADD(p.week_start_date, INTERVAL 6 DAY) >= ?
                    ORDER BY p.week_start_date DESC, i.id ASC
                    LIMIT 50";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$userId, $endDate, $startDate]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = trim((string) ($row['task_description'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $items[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'title' => $title,
                    'completed' => ((int) ($row['is_completed'] ?? 0)) === 1,
                    'weekStart' => isset($row['week_start_date']) ? (string) $row['week_start_date'] : null,
                    'source' => 'plan',
                ];
            }
        } catch (Throwable $e) {
            // table may not exist
        }

        if ($items !== []) {
            return $items;
        }

        try {
            $sql = "SELECT id, title, status, completed_at, week_start
                    FROM weekly_missions
                    WHERE user_id = ?
                      AND week_start <= ?
                      AND week_end >= ?
                    ORDER BY week_start DESC, id ASC
                    LIMIT 50";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$userId, $endDate, $startDate]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $status = strtolower((string) ($row['status'] ?? ''));
                $completed = !empty($row['completed_at']) || $status === 'completed';
                $items[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'title' => $title,
                    'completed' => $completed,
                    'weekStart' => isset($row['week_start']) ? (string) $row['week_start'] : null,
                    'source' => 'mission',
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $items;
    }

    /**
     * Score one attendance day for punctuality (sign-in + sign-out).
     * Late sign-in and forgotten sign-out reduce the score.
     * Today's open session (no time_out yet) is not penalized.
     *
     * @return array{combined:float,signIn:float,signOut:float,lateIn:bool,missedOut:bool,earlyOut:bool}
     */
    private function scoreAttendanceDay(
        string $status,
        $timeOut,
        string $date,
        string $today,
        string $endTime,
        int $graceMinutes
    ): array {
        $lateIn = stripos($status, 'late') !== false;
        // Sign-in: full credit unless late (-30 equivalent → 70).
        $signIn = $lateIn ? 70.0 : 100.0;

        $hasOut = $this->hasValidAttendanceTimeOut($timeOut);
        $missedOut = false;
        $earlyOut = false;
        $signOut = 100.0;

        if (!$hasOut) {
            if ($date < $today) {
                $missedOut = true;
                $signOut = 60.0; // forgotten sign-out (-40)
            }
        } else {
            $outTs = strtotime((string) $timeOut);
            $endTs = strtotime($endTime);
            if ($outTs !== false && $endTs !== false) {
                $outCmp = strtotime(date('H:i:s', $outTs));
                $endCmp = strtotime(date('H:i:s', $endTs));
                $earlyOk = $endCmp - max(0, $graceMinutes) * 60;
                if ($outCmp !== false && $earlyOk !== false && $outCmp < $earlyOk) {
                    $earlyOut = true;
                    $signOut = 85.0; // early leave (-15)
                }
            }
        }

        $combined = max(0.0, min(100.0, ($signIn + $signOut) / 2.0));

        return [
            'combined' => round($combined, 1),
            'signIn' => round($signIn, 1),
            'signOut' => round($signOut, 1),
            'lateIn' => $lateIn,
            'missedOut' => $missedOut,
            'earlyOut' => $earlyOut,
        ];
    }

    /**
     * @return list<array{tone:string,icon:string,title:string,body:string}>
     */
    private function buildAnalyticsInsights(
        string $scope,
        int $presentDays,
        float $punctualityScore,
        int $lateDays,
        float $avgHoursPerDay,
        int $longestStreak,
        int $activeMembers,
        int $teamHeadcount
    ): array {
        $insights = [];
        $isTeam = $scope === 'team';

        if ($presentDays <= 0) {
            $insights[] = [
                'tone' => 'info',
                'icon' => 'fa-info-circle',
                'title' => $isTeam ? 'No team attendance yet' : 'No attendance yet',
                'body' => $isTeam
                    ? 'No clock-ins were recorded for the team in this period.'
                    : 'Clock in from the attendance desk to start building your stats.',
            ];
            return $insights;
        }

        if ($punctualityScore >= 90) {
            $insights[] = [
                'tone' => 'success',
                'icon' => 'fa-check-circle',
                'title' => $isTeam ? 'Strong team punctuality' : 'Excellent punctuality',
                'body' => $isTeam
                    ? 'Most clock-ins this period were on time.'
                    : 'You are consistently on time. Keep it up.',
            ];
        } elseif ($punctualityScore >= 70) {
            $insights[] = [
                'tone' => 'warning',
                'icon' => 'fa-exclamation-triangle',
                'title' => $isTeam ? 'Team punctuality can improve' : 'Room to improve punctuality',
                'body' => "Late arrivals: {$lateDays}. Aim for earlier starts.",
            ];
        } else {
            $insights[] = [
                'tone' => 'danger',
                'icon' => 'fa-exclamation-circle',
                'title' => $isTeam ? 'Team punctuality needs attention' : 'Punctuality needs attention',
                'body' => "Late arrivals: {$lateDays}. Try setting an earlier morning routine.",
            ];
        }

        if ($avgHoursPerDay >= 8) {
            $insights[] = [
                'tone' => 'success',
                'icon' => 'fa-hourglass-half',
                'title' => $isTeam ? 'Healthy team hours' : 'Solid daily hours',
                'body' => "Averaging {$avgHoursPerDay}h per attendance day in this period.",
            ];
        } elseif ($avgHoursPerDay > 0) {
            $insights[] = [
                'tone' => 'info',
                'icon' => 'fa-hourglass-half',
                'title' => 'Hours tracked',
                'body' => "Averaging {$avgHoursPerDay}h per attendance day. Remember to clock out so totals stay accurate.",
            ];
        }

        if ($isTeam) {
            $insights[] = [
                'tone' => 'info',
                'icon' => 'fa-users',
                'title' => 'Team coverage',
                'body' => "{$activeMembers} of {$teamHeadcount} people clocked in during this period.",
            ];
        } elseif ($longestStreak >= 5) {
            $insights[] = [
                'tone' => 'success',
                'icon' => 'fa-fire',
                'title' => 'Strong streak',
                'body' => "Your longest streak this period is {$longestStreak} days.",
            ];
        }

        return $insights;
    }
}
?>
