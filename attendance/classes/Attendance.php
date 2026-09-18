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
     * Analytics desk payload for a rolling period (7 / 30 / 90 days).
     *
     * @param 'personal'|'team' $scope
     * @return array<string,mixed>
     */
    public function getAnalytics(int $userId, int $period = 30, string $scope = 'personal'): array
    {
        if (!in_array($period, [7, 30, 90], true)) {
            $period = 30;
        }
        $scope = strtolower(trim($scope)) === 'team' ? 'team' : 'personal';

        $tz = new DateTimeZone('Africa/Dar_es_Salaam');
        $end = new DateTime('now', $tz);
        $start = (clone $end)->modify('-' . max(0, $period - 1) . ' days');
        $startDate = $start->format('Y-m-d');
        $endDate = $end->format('Y-m-d');

        $records = [];
        $teamHeadcount = 1;
        $employeeSeriesMap = [];
        try {
            $t = self::RECORDS_TABLE;
            if ($scope === 'team') {
                // Team view: current calendar month, non-admin employees only.
                $start = (clone $end)->modify('first day of this month');
                $startDate = $start->format('Y-m-d');
                $endDate = $end->format('Y-m-d');

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

        $presentDays = 0;
        $lateDays = 0;
        $totalHours = 0.0;
        $totalOt = 0.0;
        $longestStreak = 0;
        $currentStreak = 0;
        $lastDate = null;
        $dailyHours = [];
        $weeklyData = ['Mon' => 0.0, 'Tue' => 0.0, 'Wed' => 0.0, 'Thu' => 0.0, 'Fri' => 0.0, 'Sat' => 0.0, 'Sun' => 0.0];
        $uniqueMembers = [];
        $datesWithAttendance = [];

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

            if (stripos((string) ($record['status'] ?? ''), 'late') !== false) {
                $lateDays++;
            }

            $hours = 0.0;
            if (isset($record['total_hours']) && $record['total_hours'] !== null && $record['total_hours'] !== '') {
                $hours = (float) $record['total_hours'];
            } elseif (!empty($record['time_in']) && !empty($record['time_out'])) {
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
                    ];
                }
                if (!isset($employeeSeriesMap[$recUserId]['byDate'][$date])) {
                    $employeeSeriesMap[$recUserId]['byDate'][$date] = 0.0;
                }
                $employeeSeriesMap[$recUserId]['byDate'][$date] = round(
                    $employeeSeriesMap[$recUserId]['byDate'][$date] + $hours,
                    2
                );
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
        $punctualityScore = $presentDays > 0
            ? round((($presentDays - $lateDays) / $presentDays) * 100, 1)
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
        foreach ($employeeSeriesMap as $series) {
            $values = [];
            foreach ($lineLabels as $d) {
                $values[] = isset($series['byDate'][$d]) ? round((float) $series['byDate'][$d], 2) : 0.0;
            }
            $lineSeries[] = [
                'id' => (int) $series['id'],
                'name' => (string) $series['name'],
                'color' => $palette[$colorIdx % count($palette)],
                'values' => $values,
            ];
            $colorIdx++;
        }
        usort($lineSeries, static function ($a, $b) {
            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        $monthLabel = $start->format('F Y');

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
            'metrics' => [
                'attendanceRate' => $attendanceRate,
                'presentDays' => $presentDays,
                'workingDays' => $workingDays,
                'expectedSlots' => $expectedSlots,
                'teamHeadcount' => $memberCount,
                'activeMembers' => $activeMembers,
                'punctualityScore' => $punctualityScore,
                'lateDays' => $lateDays,
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
                ],
            ],
            'insights' => $insights,
            'history' => array_reverse($records),
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
