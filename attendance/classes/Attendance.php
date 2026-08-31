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

    public function getHistory($userId, $limit = 30) {
        try {
            $t = self::RECORDS_TABLE;
            $stmt = $this->pdo->prepare("
                SELECT * FROM `{$t}` 
                WHERE user_id = ? 
                ORDER BY date DESC 
                LIMIT ?
            ");
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
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
}
?>
