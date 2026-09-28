<?php

declare(strict_types=1);

/**
 * Weekly tasks dashboard React UI helpers.
 */

function weeklyTasksUiWebBasePath(): string
{
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/weekly-tasks-ui'), '/');
    }

    return '/weekly-tasks-ui';
}

function weeklyTasksUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

    return weeklyTasksUiWebBasePath() . '/' . $relativePath;
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function weeklyTasksUiLoadReactAssets(): ?array
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
    if ($jsFile === '' || $cssFile === '') {
        return null;
    }

    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    return [
        'assetBase' => weeklyTasksUiPublicUrl('frontend/dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

function weeklyTasksUiMonthUrl($monthOffset = 0, int $userId = 0, string $measure = ''): string
{
    $base = function_exists('company_url')
        ? company_url('weekly_tasks/ai_assistant.php')
        : (function_exists('app_url') ? app_url('/weekly_tasks/ai_assistant.php') : '/weekly_tasks/ai_assistant.php');
    $offsets = is_array($monthOffset) ? $monthOffset : [$monthOffset];
    $offsets = array_values(array_unique(array_map('intval', $offsets)));
    rsort($offsets);
    $query = ['module' => 'tasks'];
    if (count($offsets) > 1) {
        $query['months'] = implode(',', $offsets);
    } elseif (count($offsets) === 1 && $offsets[0] !== 0) {
        $query['month'] = $offsets[0];
    }
    if ($userId > 0) {
        $query['user'] = $userId;
    }
    if ($measure !== '') {
        $query['measure'] = $measure;
    }

    return $base . '?' . http_build_query($query);
}

/**
 * @return array<int,int>
 */
function weeklyTasksUiSelectedOffsets(): array
{
    $raw = [];
    if (isset($_GET['months']) && (string) $_GET['months'] !== '') {
        $raw = explode(',', (string) $_GET['months']);
    } elseif (isset($_GET['month'])) {
        $raw = [(string) $_GET['month']];
    } else {
        $raw = [(string) ($GLOBALS['ERP_CONTEXT']['month'] ?? 0)];
    }
    $offsets = [];
    foreach ($raw as $part) {
        $part = trim($part);
        if ($part === '' || !is_numeric($part)) {
            continue;
        }
        $offset = (int) $part;
        if ($offset > 0 || $offset < -11) {
            continue;
        }
        $offsets[$offset] = $offset;
    }
    if (!$offsets) {
        $offsets = [0 => 0];
    }
    $list = array_values($offsets);
    rsort($list);

    return $list;
}

function weeklyTasksUiDepartmentName(string $department, string $role): string
{
    $blob = strtolower(trim($department . ' ' . $role));
    if (preg_match('/\bit\b|information technology/', $blob)) {
        return 'IT';
    }
    $map = [
        'sales' => 'Sales',
        'procurement' => 'Procurement',
        'purchas' => 'Procurement',
        'finance' => 'Finance',
        'account' => 'Finance',
        'store' => 'Store',
        'warehouse' => 'Store',
        'stock' => 'Store',
        'driver' => 'Drivers',
        'logistic' => 'Drivers',
    ];
    foreach ($map as $needle => $label) {
        if (str_contains($blob, $needle)) {
            return $label;
        }
    }

    return 'Other';
}

function weeklyTasksUiJoinList(array $parts): string
{
    $parts = array_values(array_filter($parts, static function ($part) {
        return $part !== '';
    }));
    $count = count($parts);
    if ($count === 0) {
        return '';
    }
    if ($count === 1) {
        return $parts[0];
    }
    $last = array_pop($parts);

    return implode(', ', $parts) . ' and ' . $last;
}

function weeklyTasksUiBand(int $score): array
{
    if ($score >= 90) {
        return ['key' => 'outstanding', 'label' => 'Outstanding'];
    }
    if ($score >= 80) {
        return ['key' => 'exceeds', 'label' => 'Exceeds'];
    }
    if ($score >= 70) {
        return ['key' => 'meets', 'label' => 'Meets'];
    }

    return ['key' => 'improve', 'label' => 'Improvement plan'];
}

/**
 * @return array{0:string,1:string,2:string,3:bool,4:int,5:int,6:array<int,string>}
 */
function weeklyTasksUiMonthWindow(int $offset): array
{
    $start = new DateTime('first day of this month');
    if ($offset !== 0) {
        $start->modify(($offset > 0 ? '+' : '') . $offset . ' month');
    }
    $end = (clone $start)->modify('last day of this month');
    $mondays = [];
    $cursor = (clone $start);
    if ($cursor->format('N') !== '1') {
        $cursor->modify('next monday');
    }
    while ($cursor <= $end) {
        $mondays[] = $cursor->format('Y-m-d');
        $cursor->modify('+7 days');
    }
    if (!$mondays) {
        $fallback = (clone $start)->modify('monday this week');
        $mondays[] = $fallback->format('Y-m-d');
    }

    $workEnd = clone $end;
    $today = new DateTime('today');
    if ($offset === 0 && $today < $end) {
        $workEnd = $today;
    }
    $weekdays = 0;
    $day = clone $start;
    while ($day <= $workEnd) {
        $n = (int) $day->format('N');
        if ($n <= 5) {
            $weekdays++;
        }
        $day->modify('+1 day');
    }

    return [
        $start->format('Y-m-d'),
        $end->format('Y-m-d'),
        $start->format('F Y'),
        $offset === 0,
        max(1, count($mondays)),
        max(1, $weekdays),
        $mondays,
    ];
}

/**
 * @return array<int,array{done:int,assigned:int}>
 */
function weeklyTasksUiMonthTasks(PDO $pdo, string $start, string $end): array
{
    $sources = [];
    $queries = [];
    if (function_exists('tableExists') && tableExists('weekly_missions', $pdo)) {
        $queries[] = "SELECT user_id,
                COUNT(*) AS assigned,
                SUM(CASE WHEN status = 'Completed' OR completed_at IS NOT NULL THEN 1 ELSE 0 END) AS done
            FROM weekly_missions
            WHERE week_start BETWEEN ? AND ?
            GROUP BY user_id";
    }
    if (function_exists('tableExists') && tableExists('weekly_plans', $pdo) && tableExists('weekly_plan_items', $pdo)) {
        $queries[] = 'SELECT p.user_id,
                COUNT(i.id) AS assigned,
                SUM(CASE WHEN i.is_completed = 1 THEN 1 ELSE 0 END) AS done
            FROM weekly_plans p
            INNER JOIN weekly_plan_items i ON i.plan_id = p.id
            WHERE p.week_start_date BETWEEN ? AND ?
            GROUP BY p.user_id';
    }
    foreach ($queries as $sql) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$start, $end]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $id = (int) ($row['user_id'] ?? 0);
                if ($id < 1) {
                    continue;
                }
                $sources[$id][] = [
                    'done' => (int) ($row['done'] ?? 0),
                    'assigned' => (int) ($row['assigned'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
        }
    }
    $out = [];
    foreach ($sources as $id => $options) {
        $best = ['done' => 0, 'assigned' => 0];
        foreach ($options as $option) {
            if ($option['assigned'] > $best['assigned'] || ($option['assigned'] === $best['assigned'] && $option['done'] > $best['done'])) {
                $best = $option;
            }
        }
        $out[$id] = $best;
    }

    return $out;
}

/**
 * @return array<int,array{done:int,assigned:int}>
 */
function weeklyTasksUiMonthTodos(PDO $pdo, string $start, string $end): array
{
    if (!function_exists('tableExists') || !tableExists('user_tasks', $pdo)) {
        return [];
    }
    try {
        $st = $pdo->prepare(
            'SELECT user_id, COUNT(*) AS assigned, SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END) AS done
             FROM user_tasks
             WHERE task_date BETWEEN ? AND ?
             GROUP BY user_id'
        );
        $st->execute([$start, $end]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) ($row['user_id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $out[$id] = [
                'done' => (int) ($row['done'] ?? 0),
                'assigned' => (int) ($row['assigned'] ?? 0),
            ];
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return array<int,int>
 */
function weeklyTasksUiMonthAttendance(PDO $pdo, string $start, string $end): array
{
    $days = [];
    $queries = [];
    if (function_exists('tableExists') && tableExists('attendance', $pdo)) {
        $queries[] = 'SELECT user_id, `date` AS day FROM attendance WHERE `date` BETWEEN ? AND ?';
        $queries[] = 'SELECT user_id, DATE(signed_at) AS day FROM attendance WHERE signed_at IS NOT NULL AND DATE(signed_at) BETWEEN ? AND ?';
    }
    if (function_exists('tableExists') && tableExists('attendance_records', $pdo)) {
        $queries[] = 'SELECT user_id, `date` AS day FROM attendance_records WHERE `date` BETWEEN ? AND ?';
    }
    foreach ($queries as $sql) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$start, $end]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $id = (int) ($row['user_id'] ?? 0);
                $day = (string) ($row['day'] ?? '');
                if ($id < 1 || $day === '' || $day === '0000-00-00') {
                    continue;
                }
                $days[$id][$day] = true;
            }
        } catch (Throwable $e) {
        }
    }
    $out = [];
    foreach ($days as $id => $set) {
        $out[$id] = count($set);
    }

    return $out;
}

/**
 * @param array<int,string> $mondays
 * @return array<int,array{score:int,onTime:int,vehicle:int,documents:int}>
 */
function weeklyTasksUiMonthDriverScores(PDO $pdo, array $mondays): array
{
    $fromEntries = weeklyTasksUiDriverEntryScores($pdo, $mondays);
    if ($fromEntries !== null) {
        return $fromEntries;
    }
    if (!function_exists('dkpi_module_scores_for_week')) {
        return [];
    }
    $sums = [];
    foreach ($mondays as $monday) {
        try {
            $rows = dkpi_module_scores_for_week($pdo, $monday);
        } catch (Throwable $e) {
            continue;
        }
        foreach ($rows as $userId => $row) {
            $id = (int) $userId;
            $payload = [];
            if (!empty($row['payload_json'])) {
                $decoded = json_decode((string) $row['payload_json'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
            if (!isset($sums[$id])) {
                $sums[$id] = ['score' => 0.0, 'onTime' => 0.0, 'vehicle' => 0.0, 'documents' => 0.0, 'n' => 0];
            }
            $sums[$id]['score'] += (float) ($row['score_pct'] ?? 0);
            $sums[$id]['onTime'] += (float) ($payload['on_time_pct'] ?? 0);
            $sums[$id]['vehicle'] += (float) ($payload['vehicle_care_pct'] ?? 0);
            $sums[$id]['documents'] += (float) ($payload['documentation_pct'] ?? 0);
            $sums[$id]['n']++;
        }
    }
    $out = [];
    foreach ($sums as $id => $sum) {
        $n = (int) ($sum['n'] ?? 0);
        if ($n < 1) {
            continue;
        }
        $out[$id] = [
            'score' => (int) round($sum['score'] / $n),
            'onTime' => (int) round($sum['onTime'] / $n),
            'vehicle' => (int) round($sum['vehicle'] / $n),
            'documents' => (int) round($sum['documents'] / $n),
            'recorded' => true,
        ];
    }

    return $out;
}

/**
 * Scores from driver KPI recordings. Null when that table is not available.
 *
 * @param array<int,string> $mondays
 * @return array<int,array{score:int,onTime:int,vehicle:int,documents:int,recorded:bool}>|null
 */
function weeklyTasksUiDriverEntryScores(PDO $pdo, array $mondays): ?array
{
    if (!function_exists('tableExists') || !tableExists('driver_kpi_entries', $pdo) || !$mondays) {
        return $mondays ? null : [];
    }
    $placeholders = implode(',', array_fill(0, count($mondays), '?'));
    try {
        $st = $pdo->prepare(
            "SELECT user_id, week_start,
                AVG(on_time_pct) AS on_time,
                AVG(vehicle_care_pct) AS vehicle,
                AVG(documentation_pct) AS documents,
                AVG(weighted_score) AS score
             FROM driver_kpi_entries
             WHERE week_start IN ({$placeholders})
             GROUP BY user_id, week_start"
        );
        $st->execute(array_values($mondays));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return null;
    }
    $sums = [];
    foreach ($rows as $row) {
        $id = (int) ($row['user_id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        if (!isset($sums[$id])) {
            $sums[$id] = ['score' => 0.0, 'onTime' => 0.0, 'vehicle' => 0.0, 'documents' => 0.0, 'n' => 0];
        }
        $sums[$id]['score'] += (float) ($row['score'] ?? 0);
        $sums[$id]['onTime'] += (float) ($row['on_time'] ?? 0);
        $sums[$id]['vehicle'] += (float) ($row['vehicle'] ?? 0);
        $sums[$id]['documents'] += (float) ($row['documents'] ?? 0);
        $sums[$id]['n']++;
    }
    $out = [];
    foreach ($sums as $id => $sum) {
        $n = (int) $sum['n'];
        if ($n < 1) {
            continue;
        }
        $out[$id] = [
            'score' => (int) round($sum['score'] / $n),
            'onTime' => (int) round($sum['onTime'] / $n),
            'vehicle' => (int) round($sum['vehicle'] / $n),
            'documents' => (int) round($sum['documents'] / $n),
            'recorded' => true,
        ];
    }

    return $out;
}

/**
 * @param array<int,int> $offsets
 * @return array<int,array{title:string,when:string,status:string}>
 */
function weeklyTasksUiDriverLines(PDO $pdo, int $userId, array $offsets, string $metric): array
{
    $columns = [
        'on-time-delivery' => ['column' => 'on_time_pct', 'target' => 95, 'label' => 'On-time delivery'],
        'vehicle-care' => ['column' => 'vehicle_care_pct', 'target' => 100, 'label' => 'Vehicle care'],
        'delivery-documents' => ['column' => 'documentation_pct', 'target' => 100, 'label' => 'Delivery documents'],
    ];
    if (!isset($columns[$metric])) {
        return [];
    }
    if ($metric === 'delivery-documents') {
        return weeklyTasksUiDeliveryRecipientRows($pdo, $userId, $offsets);
    }
    $spec = $columns[$metric];
    $byWeek = [];
    if (function_exists('tableExists') && tableExists('driver_kpi_entries', $pdo)) {
        foreach ($offsets as $offset) {
            [$start, $end] = weeklyTasksUiMonthWindow((int) $offset);
            try {
                $st = $pdo->prepare(
                    'SELECT week_start, service_type, on_time_pct, vehicle_care_pct, documentation_pct, notes, work_log_json
                     FROM driver_kpi_entries
                     WHERE user_id = ? AND week_start BETWEEN ? AND ?
                     ORDER BY week_start, service_type'
                );
                $st->execute([$userId, $start, $end]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $byWeek[(string) $row['week_start']][] = $row;
                }
            } catch (Throwable $e) {
            }
        }
    }

    $rows = [];
    foreach ($offsets as $offset) {
        $window = weeklyTasksUiMonthWindow((int) $offset);
        foreach ($window[6] as $monday) {
            $when = 'Week of ' . date('j M Y', strtotime($monday));
            $entries = $byWeek[$monday] ?? [];
            if ($metric === 'vehicle-care') {
                $rows = array_merge($rows, weeklyTasksUiVehicleCareWeekRows($entries, $when));
                continue;
            }
            if (!$entries) {
                $rows[] = [
                    'title' => $spec['label'],
                    'when' => $when,
                    'status' => 'Not recorded',
                ];
                continue;
            }
            foreach ($entries as $entry) {
                $service = (string) ($entry['service_type'] ?? 'delivery');
                $serviceLabel = function_exists('dkpi_services')
                    ? (string) (dkpi_services()[$service]['label'] ?? ucfirst($service))
                    : ucfirst($service);
                $actual = (int) round((float) ($entry[$spec['column']] ?? 0));
                $rows[] = [
                    'title' => $serviceLabel . ' · ' . $actual . '%',
                    'when' => $when,
                    'status' => $actual >= $spec['target'] ? 'Met' : 'Short',
                ];
            }
        }
    }

    return $rows;
}

function weeklyTasksUiDeliveryNoteUrl(int $noteId): string
{
    $path = 'deliveries/view_delivery_note.php?id=' . $noteId . '&embed=1';
    if (function_exists('company_url')) {
        return company_url($path);
    }
    if (function_exists('app_url')) {
        return app_url('/' . $path);
    }

    return '/' . $path;
}

/**
 * One row per customer who received a delivery. Documents are recorded when the delivery is signed.
 *
 * @param array<int,int> $offsets
 * @return array<int,array{title:string,when:string,status:string}>
 */
function weeklyTasksUiDeliveryRecipientRows(PDO $pdo, int $userId, array $offsets): array
{
    if (!function_exists('tableExists') || !tableExists('delivery_orders', $pdo)) {
        return [];
    }
    $hasNotes = tableExists('delivery_notes', $pdo);
    $hasTrips = tableExists('delivery_trips', $pdo);
    $rows = [];
    $seen = [];
    foreach ($offsets as $offset) {
        [$start, $end] = weeklyTasksUiMonthWindow((int) $offset);
        $sql = 'SELECT o.id, o.client_name, o.delivery_note_id, o.completion_time, o.created_at, o.signature_path';
        if ($hasNotes) {
            $sql .= ', dn.customer_name, dn.receiver_signature_path';
        }
        $sql .= ' FROM delivery_orders o';
        if ($hasTrips) {
            $sql .= ' LEFT JOIN delivery_trips t ON o.trip_id = t.id';
        }
        if ($hasNotes) {
            $sql .= ' LEFT JOIN delivery_notes dn ON o.delivery_note_id = dn.id';
        }
        $driverSql = $hasTrips
            ? '(o.requested_driver_id = ? OR t.driver_id = ?)'
            : 'o.requested_driver_id = ?';
        $sql .= " WHERE {$driverSql} AND COALESCE(o.completion_time, o.created_at) BETWEEN ? AND ? ORDER BY COALESCE(o.completion_time, o.created_at)";
        try {
            $st = $pdo->prepare($sql);
            $params = $hasTrips
                ? [$userId, $userId, $start . ' 00:00:00', $end . ' 23:59:59']
                : [$userId, $start . ' 00:00:00', $end . ' 23:59:59'];
            $st->execute($params);
            $found = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $found = [];
        }
        foreach ($found as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $who = trim((string) ($row['client_name'] ?? ''));
            if ($who === '' && $hasNotes) {
                $who = trim((string) ($row['customer_name'] ?? ''));
            }
            if ($who === '') {
                $who = 'Customer';
            }
            $signed = trim((string) ($row['signature_path'] ?? '')) !== '';
            if ($hasNotes) {
                $signed = $signed || trim((string) ($row['receiver_signature_path'] ?? '')) !== '';
            }
            $whenRaw = trim((string) (($row['completion_time'] ?? '') !== '' ? $row['completion_time'] : ($row['created_at'] ?? '')));
            $noteId = (int) ($row['delivery_note_id'] ?? 0);
            $rows[] = [
                'title' => $who,
                'when' => $whenRaw !== '' ? date('j M Y', strtotime($whenRaw)) : '',
                'status' => $signed ? 'Met' : 'Not recorded',
                'documentUrl' => $noteId > 0 ? weeklyTasksUiDeliveryNoteUrl($noteId) : '',
            ];
        }
    }

    return $rows;
}

/**
 * @param array<int,array<string,mixed>> $entries
 * @return array<int,array{title:string,when:string,status:string}>
 */
function weeklyTasksUiAttachmentUrl(?array $attachment): string
{
    if ($attachment === null) {
        return '';
    }
    $url = trim((string) ($attachment['url'] ?? ''));
    $path = trim((string) ($attachment['path'] ?? ''));
    $target = $url !== '' ? $url : $path;
    if ($target === '' || str_contains($target, '..') || str_starts_with(strtolower($target), 'javascript:')) {
        return '';
    }
    if (preg_match('#^https?://#i', $target)) {
        return $target;
    }
    $target = '/' . ltrim($target, '/');
    if (function_exists('app_url')) {
        return app_url($target);
    }

    return $target;
}

/**
 * @param array<string,mixed> $item
 */
function weeklyTasksUiWorkLogFile(array $item): string
{
    foreach (['document', 'letter', 'voucher'] as $key) {
        $attachment = function_exists('dkpi_normalize_attachment')
            ? dkpi_normalize_attachment($item[$key] ?? null)
            : (is_array($item[$key] ?? null) ? $item[$key] : null);
        $url = weeklyTasksUiAttachmentUrl(is_array($attachment) ? $attachment : null);
        if ($url !== '') {
            return $url;
        }
    }
    $vouchers = $item['vouchers'] ?? null;
    if (is_array($vouchers)) {
        foreach ($vouchers as $voucher) {
            $attachment = function_exists('dkpi_normalize_attachment')
                ? dkpi_normalize_attachment($voucher)
                : (is_array($voucher) ? $voucher : null);
            $url = weeklyTasksUiAttachmentUrl(is_array($attachment) ? $attachment : null);
            if ($url !== '') {
                return $url;
            }
        }
    }

    return '';
}

/**
 * @param array<string,mixed> $entry
 * @return array<int,array{title:string,when:string,status:string,documentUrl:string,documentText:string}>
 */
function weeklyTasksUiVehicleCareLogRows(array $entry, string $weekWhen): array
{
    $decoded = [];
    if (!empty($entry['work_log_json'])) {
        $parsed = json_decode((string) $entry['work_log_json'], true);
        if (is_array($parsed)) {
            $decoded = isset($parsed['work_log']) && is_array($parsed['work_log']) ? $parsed['work_log'] : $parsed;
        }
    }
    $care = [];
    $other = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = function_exists('dkpi_normalize_work_log_type')
            ? dkpi_normalize_work_log_type((string) ($item['type'] ?? 'vehicle_care'))
            : 'vehicle_care';
        $text = trim((string) ($item['task_description'] ?? $item['text'] ?? $item['description'] ?? ''));
        $file = weeklyTasksUiWorkLogFile($item);
        if ($text === '' && $file === '') {
            continue;
        }
        $at = trim((string) ($item['at'] ?? ''));
        $when = preg_match('/^\d{4}-\d{2}-\d{2}$/', $at) ? date('j M Y', strtotime($at)) : $weekWhen;
        $labels = function_exists('dkpi_work_log_types') ? dkpi_work_log_types() : [];
        $label = (string) ($labels[$type] ?? 'Vehicle care');
        $row = [
            'title' => $text !== '' ? $text : $label,
            'when' => $when,
            'status' => 'Recorded',
            'documentUrl' => $file,
            'documentText' => $text !== '' ? $text : $label,
        ];
        if (in_array($type, ['vehicle_care', 'maintenance', 'inspection'], true)) {
            $care[] = $row;
        } else {
            $other[] = $row;
        }
    }
    if ($care) {
        return $care;
    }
    if ($other && (float) ($entry['vehicle_care_pct'] ?? 0) > 0) {
        return $other;
    }
    $notes = trim((string) ($entry['notes'] ?? ''));
    if ($notes !== '') {
        return [[
            'title' => $notes,
            'when' => $weekWhen,
            'status' => 'Recorded',
            'documentUrl' => '',
            'documentText' => $notes,
        ]];
    }

    return [];
}

/**
 * @param array<int,array<string,mixed>> $entries
 * @return array<int,array{title:string,when:string,status:string}>
 */
function weeklyTasksUiVehicleCareWeekRows(array $entries, string $when): array
{
    if (!$entries) {
        return [
            ['title' => 'Vehicle care', 'when' => $when, 'status' => 'Not recorded'],
        ];
    }

    $rows = [];
    foreach ($entries as $entry) {
        $logged = weeklyTasksUiVehicleCareLogRows($entry, $when);
        if ($logged) {
            $rows = array_merge($rows, $logged);
            continue;
        }
        $rows[] = [
            'title' => 'Vehicle care',
            'when' => $when,
            'status' => 'Not recorded',
        ];
    }

    return $rows;
}

/**
 * @param array<int,int> $offsets
 * @return array<int,array{title:string,when:string,status:string}>
 */
function weeklyTasksUiTaskLines(PDO $pdo, int $userId, array $offsets): array
{
    $missions = [];
    $plans = [];
    foreach ($offsets as $offset) {
        [$start, $end] = weeklyTasksUiMonthWindow((int) $offset);
        if (function_exists('tableExists') && tableExists('weekly_missions', $pdo)) {
            try {
                $st = $pdo->prepare('SELECT title, status, week_start, completed_at FROM weekly_missions WHERE user_id = ? AND week_start BETWEEN ? AND ? ORDER BY week_start, title');
                $st->execute([$userId, $start, $end]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $done = (($row['status'] ?? '') === 'Completed') || !empty($row['completed_at']);
                    $missions[] = [
                        'title' => (string) ($row['title'] ?? 'Weekly task'),
                        'when' => 'Week of ' . date('j M Y', strtotime((string) $row['week_start'])),
                        'status' => $done ? 'Completed' : (string) ($row['status'] ?? 'Pending'),
                    ];
                }
            } catch (Throwable $e) {
            }
        }
        if (function_exists('tableExists') && tableExists('weekly_plans', $pdo) && tableExists('weekly_plan_items', $pdo)) {
            try {
                $st = $pdo->prepare('SELECT i.task_description, i.is_completed, p.week_start_date FROM weekly_plans p INNER JOIN weekly_plan_items i ON i.plan_id = p.id WHERE p.user_id = ? AND p.week_start_date BETWEEN ? AND ? ORDER BY p.week_start_date, i.id');
                $st->execute([$userId, $start, $end]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $plans[] = [
                        'title' => (string) ($row['task_description'] ?? 'Task'),
                        'when' => 'Week of ' . date('j M Y', strtotime((string) $row['week_start_date'])),
                        'status' => !empty($row['is_completed']) ? 'Completed' : 'Pending',
                    ];
                }
            } catch (Throwable $e) {
            }
        }
    }

    return count($plans) > count($missions) ? $plans : $missions;
}

/**
 * @param array<int,int> $offsets
 * @return array<int,array{title:string,when:string,status:string}>
 */
function weeklyTasksUiTodoLines(PDO $pdo, int $userId, array $offsets): array
{
    if (!function_exists('tableExists') || !tableExists('user_tasks', $pdo)) {
        return [];
    }
    $rows = [];
    foreach ($offsets as $offset) {
        [$start, $end] = weeklyTasksUiMonthWindow((int) $offset);
        try {
            $st = $pdo->prepare('SELECT task_description, is_completed, task_date FROM user_tasks WHERE user_id = ? AND task_date BETWEEN ? AND ? ORDER BY task_date, id');
            $st->execute([$userId, $start, $end]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $rows[] = [
                    'title' => (string) ($row['task_description'] ?? 'To-do'),
                    'when' => date('j M Y', strtotime((string) $row['task_date'])),
                    'status' => !empty($row['is_completed']) ? 'Completed' : 'Pending',
                ];
            }
        } catch (Throwable $e) {
        }
    }

    return $rows;
}

/**
 * @param array<int,int> $offsets
 * @return array<int,array{title:string,when:string,status:string}>
 */
function weeklyTasksUiAttendanceLines(PDO $pdo, int $userId, array $offsets): array
{
    $rows = [];
    foreach ($offsets as $offset) {
        [$start, $end] = weeklyTasksUiMonthWindow((int) $offset);
        $dates = [];
        $queries = [];
        if (function_exists('tableExists') && tableExists('attendance', $pdo)) {
            $queries[] = 'SELECT DISTINCT `date` AS day FROM attendance WHERE user_id = ? AND `date` BETWEEN ? AND ?';
            $queries[] = 'SELECT DISTINCT DATE(signed_at) AS day FROM attendance WHERE user_id = ? AND signed_at IS NOT NULL AND DATE(signed_at) BETWEEN ? AND ?';
        }
        if (function_exists('tableExists') && tableExists('attendance_records', $pdo)) {
            $queries[] = 'SELECT DISTINCT `date` AS day FROM attendance_records WHERE user_id = ? AND `date` BETWEEN ? AND ?';
        }
        foreach ($queries as $sql) {
            try {
                $st = $pdo->prepare($sql);
                $st->execute([$userId, $start, $end]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $day) {
                    if ($day) {
                        $dates[] = $day;
                    }
                }
            } catch (Throwable $e) {
            }
        }
        foreach ($dates as $day) {
            if (!$day) {
                continue;
            }
            $rows[(string) $day] = [
                'title' => date('l', strtotime((string) $day)),
                'when' => date('j M Y', strtotime((string) $day)),
                'status' => 'Present',
            ];
        }
    }
    ksort($rows);

    return array_values($rows);
}

/**
 * @param array{score:int,onTime:int,vehicle:int,documents:int}|null $driver
 * @return array<string,mixed>
 */
function weeklyTasksUiPersonDetail(
    int $userId,
    string $name,
    string $department,
    int $score,
    array $band,
    int $done,
    int $assigned,
    int $todoDone,
    int $todoAssigned,
    int $taskScore,
    int $activity,
    int $days,
    int $weekdays,
    int $taskTarget,
    int $todoTarget,
    ?array $driver,
    int $monthOffset,
    string $photo = '',
    string $role = ''
): array {
    $items = [
        [
            'name' => 'Weekly tasks',
            'expected' => 'At least ' . $taskTarget . ' completed',
            'actual' => $done . ' completed, ' . $assigned . ' assigned',
            'configured' => true,
            'met' => $done >= $taskTarget,
            'note' => 'Weekly Tasks: ' . $done . ' of ' . $taskTarget . ' completed',
            'spoken' => 'weekly tasks are ' . $done . ' of ' . $taskTarget . ' completed',
        ],
        [
            'name' => 'To-do list',
            'expected' => 'At least ' . $todoTarget . ' achieved',
            'actual' => $todoDone . ' achieved, ' . $todoAssigned . ' assigned',
            'configured' => true,
            'met' => $todoDone >= $todoTarget,
            'note' => 'To-Do List: ' . $todoDone . ' of ' . $todoTarget . ' completed',
            'spoken' => 'the to-do list is ' . $todoDone . ' of ' . $todoTarget . ' completed',
        ],
        [
            'name' => 'Attendance',
            'expected' => $weekdays . ' weekdays',
            'actual' => $days . ' days present',
            'configured' => true,
            'met' => $days >= $weekdays,
            'note' => 'Attendance: ' . $days . ' of ' . $weekdays . ' days',
            'spoken' => 'attendance is ' . $days . ' of ' . $weekdays . ' days',
        ],
    ];

    $driverLines = [
        ['key' => 'onTime', 'name' => 'On-time delivery', 'expected' => '95% or better'],
        ['key' => 'vehicle', 'name' => 'Vehicle care', 'expected' => '100%'],
        ['key' => 'documents', 'name' => 'Delivery documents', 'expected' => '100%'],
    ];
    if ($department === 'Drivers') {
        $driver = $driver ?: ['onTime' => 0, 'vehicle' => 0, 'documents' => 0];
        foreach ($driverLines as $line) {
            $actualPct = (int) ($driver[$line['key']] ?? 0);
            $target = $line['key'] === 'onTime' ? 95 : 100;
            $items[] = [
                'name' => $line['name'],
                'expected' => $line['expected'],
                'actual' => $actualPct . '%',
                'configured' => true,
                'met' => $actualPct >= $target,
                'note' => $line['name'] . ': ' . $actualPct . ' of ' . $target . '%',
                'spoken' => strtolower($line['name']) . ' is ' . $actualPct . ' of ' . $target . '%',
            ];
        }
    } else {
        $label = $department === 'Other' ? 'Role' : $department;
        $items[] = [
            'name' => $department === 'Other' ? 'Role performance' : $department . ' performance',
            'expected' => 'Not configured',
            'actual' => 'Not configured',
            'configured' => false,
            'met' => false,
            'note' => $label . ': Target not set',
            'unsetLabel' => $label,
        ];
    }

    $improvements = [];
    $behind = [];
    $unset = [];
    foreach ($items as $item) {
        if ($item['configured'] && $item['met']) {
            continue;
        }
        if (!empty($item['note'])) {
            $improvements[] = $item['note'];
        }
        if (!$item['configured']) {
            $unset[] = (string) ($item['unsetLabel'] ?? '');
            continue;
        }
        if (!empty($item['spoken'])) {
            $behind[] = $item['spoken'];
        }
    }
    $insight = '';
    if ($behind) {
        $insight = ucfirst(weeklyTasksUiJoinList($behind)) . '.';
    }
    if ($unset) {
        $names = weeklyTasksUiJoinList($unset);
        $insight .= ($insight !== '' ? ' ' : '') . $names . (count($unset) === 1 ? ' has no target set.' : ' have no target set.');
    }
    if (!$improvements) {
        $improvements[] = 'Nothing to flag this month.';
    }

    return [
        'id' => $userId,
        'name' => $name,
        'department' => $department,
        'role' => trim($role),
        'score' => $score,
        'band' => $band['label'],
        'bandKey' => $band['key'],
        'photo' => $photo,
        'tasksDone' => $done,
        'tasksAssigned' => $assigned,
        'activity' => $activity,
        'scoreNote' => 'The score averages weekly tasks (' . $taskScore . '%) and recorded activity (' . $activity . '%).',
        'backUrl' => weeklyTasksUiMonthUrl($monthOffset),
        'prevUrl' => weeklyTasksUiMonthUrl($monthOffset - 1, $userId),
        'thisUrl' => weeklyTasksUiMonthUrl(0, $userId),
        'nextUrl' => $monthOffset < 0 ? weeklyTasksUiMonthUrl($monthOffset + 1, $userId) : '',
        'items' => $items,
        'improvements' => $improvements,
        'insight' => $insight,
    ];
}

/**
 * @param array<int,array<string,mixed>> $users
 * @return array<string,mixed>
 */
function weeklyTasksUiTeamTrend(PDO $pdo, array $users): array
{
    $points = [];
    $previous = null;
    for ($offset = -5; $offset <= 0; $offset++) {
        [$start, $end, $label, , $weekCount, $weekdays, $mondays] = weeklyTasksUiMonthWindow($offset);
        $tasks = weeklyTasksUiMonthTasks($pdo, $start, $end);
        $todos = weeklyTasksUiMonthTodos($pdo, $start, $end);
        $attendance = weeklyTasksUiMonthAttendance($pdo, $start, $end);
        $drivers = weeklyTasksUiMonthDriverScores($pdo, $mondays);
        $taskTarget = max(1, 7 * $weekCount);
        $todoTarget = max(1, 5 * $weekCount);
        $weekdays = max(1, $weekdays);
        $scores = [];
        foreach ($users as $user) {
            $id = (int) ($user['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $department = weeklyTasksUiDepartmentName((string) ($user['department'] ?? ''), (string) ($user['role'] ?? ''));
            $done = (int) ($tasks[$id]['done'] ?? 0);
            $todoDone = (int) ($todos[$id]['done'] ?? 0);
            $taskScore = (int) min(100, round(($done / $taskTarget) * 100));
            $days = (int) ($attendance[$id] ?? 0);
            $attendanceScore = (int) min(100, round(($days / $weekdays) * 100));
            $driver = $drivers[$id] ?? null;
            if (($department === 'Drivers' || $driver !== null) && $driver && !empty($driver['recorded'])) {
                $activity = (int) $driver['score'];
            } else {
                $todoScore = (int) min(100, round(($todoDone / $todoTarget) * 100));
                $activity = (int) round(($attendanceScore + $todoScore) / 2);
            }
            $scores[$id] = (int) round(($taskScore + $activity) / 2);
        }
        $values = array_values($scores);
        $average = $values ? (int) round(array_sum($values) / count($values)) : 0;
        $up = 0;
        $down = 0;
        if (is_array($previous)) {
            foreach ($scores as $id => $score) {
                if (!array_key_exists($id, $previous)) {
                    continue;
                }
                if ($score > $previous[$id]) {
                    $up++;
                } elseif ($score < $previous[$id]) {
                    $down++;
                }
            }
        }
        $points[] = [
            'label' => $label,
            'short' => date('M', strtotime($start)),
            'average' => $average,
            'up' => $up,
            'down' => $down,
        ];
        $previous = $scores;
    }
    $last = $points[count($points) - 1];
    $before = $points[count($points) - 2] ?? null;

    return [
        'points' => $points,
        'change' => $before ? ((int) $last['average'] - (int) $before['average']) : 0,
        'improved' => (int) $last['up'],
        'declined' => (int) $last['down'],
    ];
}

/**
 * @return array<string,mixed>
 */
function weeklyTasksUiBuildPayload(): array
{
    $boot = dirname(__DIR__) . '/weekly_tasks/includes/performance_bootstrap.php';
    if (is_file($boot)) {
        require_once $boot;
    }

    global $pdo;
    $viewerId = isset($viewerId) ? (int) $viewerId : (int) ($_SESSION['user_id'] ?? 0);
    $selectedId = isset($_GET['user']) ? (int) $_GET['user'] : 0;
    $offsets = weeklyTasksUiSelectedOffsets();
    $monthOffset = $offsets[0];
    $weekCount = 0;
    $weekdays = 0;
    $mondays = [];
    $labels = [];
    $tasks = [];
    $todos = [];
    $attendance = [];
    foreach ($offsets as $offset) {
        [$monthStart, $monthEnd, $label, , $weeks, $days, $monthMondays] = weeklyTasksUiMonthWindow($offset);
        $weekCount += $weeks;
        $weekdays += $days;
        $mondays = array_merge($mondays, $monthMondays);
        $labels[] = $label;
        if (!($pdo instanceof PDO)) {
            continue;
        }
        foreach (weeklyTasksUiMonthTasks($pdo, $monthStart, $monthEnd) as $id => $row) {
            if (!isset($tasks[$id])) {
                $tasks[$id] = ['done' => 0, 'assigned' => 0];
            }
            $tasks[$id]['done'] += (int) $row['done'];
            $tasks[$id]['assigned'] += (int) $row['assigned'];
        }
        foreach (weeklyTasksUiMonthTodos($pdo, $monthStart, $monthEnd) as $id => $row) {
            if (!isset($todos[$id])) {
                $todos[$id] = ['done' => 0, 'assigned' => 0];
            }
            $todos[$id]['done'] += (int) $row['done'];
            $todos[$id]['assigned'] += (int) $row['assigned'];
        }
        foreach (weeklyTasksUiMonthAttendance($pdo, $monthStart, $monthEnd) as $id => $daysPresent) {
            $attendance[$id] = (int) ($attendance[$id] ?? 0) + (int) $daysPresent;
        }
    }
    $monthLabel = count($labels) > 1 ? $labels[count($labels) - 1] . ' – ' . $labels[0] : ($labels[0] ?? 'This month');
    $isCurrent = $offsets === [0];

    $users = [];
    $driverScores = [];
    if ($pdo instanceof PDO) {
        try {
            $st = $pdo->query('SELECT id, full_name, department, role, profile_photo FROM users WHERE is_active = 1 ORDER BY full_name ASC');
            $users = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            $users = [];
        }
        $driverScores = weeklyTasksUiMonthDriverScores($pdo, $mondays);
    }

    $taskTarget = 7 * $weekCount;
    $todoTarget = 5 * $weekCount;
    $detail = null;
    $groups = [];
    $bandCounts = [
        'outstanding' => 0,
        'exceeds' => 0,
        'meets' => 0,
        'improve' => 0,
    ];

    foreach ($users as $user) {
        $id = (int) ($user['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $department = weeklyTasksUiDepartmentName((string) ($user['department'] ?? ''), (string) ($user['role'] ?? ''));
        $done = (int) ($tasks[$id]['done'] ?? 0);
        $assigned = (int) ($tasks[$id]['assigned'] ?? 0);
        $todoDone = (int) ($todos[$id]['done'] ?? 0);
        $todoAssigned = (int) ($todos[$id]['assigned'] ?? 0);
        $taskScore = (int) min(100, round(($done / $taskTarget) * 100));
        $days = (int) ($attendance[$id] ?? 0);
        $attendanceScore = (int) min(100, round(($days / $weekdays) * 100));
        $driver = $driverScores[$id] ?? null;
        $isDriver = $department === 'Drivers' || $driver !== null;
        if ($isDriver && $driver && !empty($driver['recorded'])) {
            $activity = (int) $driver['score'];
        } else {
            $todoScore = (int) min(100, round(($todoDone / $todoTarget) * 100));
            $activity = (int) round(($attendanceScore + $todoScore) / 2);
        }
        $score = (int) round(($taskScore + $activity) / 2);
        $band = weeklyTasksUiBand($score);
        $bandCounts[$band['key']]++;
        $personName = (string) ($user['full_name'] ?? 'Employee');
        if ($selectedId === $id) {
            $detail = weeklyTasksUiPersonDetail(
                $id,
                $personName,
                $department,
                $score,
                $band,
                $done,
                $assigned,
                $todoDone,
                $todoAssigned,
                $taskScore,
                $activity,
                $days,
                $weekdays,
                $taskTarget,
                $todoTarget,
                $driver,
                $monthOffset,
                function_exists('perf_user_avatar_url')
                    ? perf_user_avatar_url((string) ($user['profile_photo'] ?? ''), $personName)
                    : '',
                (string) ($user['role'] ?? '')
            );
        }
        $groups[$department][] = [
            'id' => $id,
            'name' => $personName,
            'score' => $score,
            'band' => $band['label'],
            'bandKey' => $band['key'],
            'tasksDone' => $done,
            'tasksAssigned' => $assigned,
            'activity' => $activity,
            'isViewer' => $id === $viewerId,
            'href' => weeklyTasksUiMonthUrl($offsets, $id),
        ];
    }

    if (is_array($detail)) {
        $detail['backUrl'] = weeklyTasksUiMonthUrl($offsets);
        $measureNames = [
            'Weekly tasks' => 'tasks',
            'To-do list' => 'todo',
            'Attendance' => 'attendance',
        ];
        foreach ($detail['items'] as $index => $item) {
            $name = (string) ($item['name'] ?? '');
            $key = $measureNames[$name] ?? trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name)), '-');
            $detail['items'][$index]['key'] = $key;
            $detail['items'][$index]['href'] = weeklyTasksUiMonthUrl($offsets, $selectedId, $key);
        }
    }

    $measureView = null;
    $measureKey = isset($_GET['measure']) ? (string) preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $_GET['measure'])) : '';
    if ($measureKey !== '' && is_array($detail) && $pdo instanceof PDO) {
        $match = null;
        foreach ($detail['items'] as $item) {
            if (($item['key'] ?? '') === $measureKey) {
                $match = $item;
                break;
            }
        }
        if ($match) {
            $rows = [];
            $empty = 'Nothing recorded in this period.';
            if ($measureKey === 'tasks') {
                $rows = weeklyTasksUiTaskLines($pdo, $selectedId, $offsets);
                $empty = 'No tasks recorded in this period.';
            } elseif ($measureKey === 'todo') {
                $rows = weeklyTasksUiTodoLines($pdo, $selectedId, $offsets);
                $empty = 'No to-do items recorded in this period.';
            } elseif ($measureKey === 'attendance') {
                $rows = weeklyTasksUiAttendanceLines($pdo, $selectedId, $offsets);
                $empty = 'No attendance recorded in this period.';
            } elseif (in_array($measureKey, ['on-time-delivery', 'vehicle-care', 'delivery-documents'], true)) {
                $rows = weeklyTasksUiDriverLines($pdo, $selectedId, $offsets, $measureKey);
                $empty = $measureKey === 'delivery-documents'
                    ? 'No deliveries in this period.'
                    : 'No driver performance recorded in this period.';
            }
            $measureView = [
                'key' => $measureKey,
                'title' => (string) $match['name'],
                'summary' => !empty($match['configured']) ? ((string) $match['expected'] . ' · ' . (string) $match['actual']) : 'Target not set',
                'configured' => (bool) ($match['configured'] ?? false),
                'rows' => !empty($match['configured']) ? $rows : [],
                'empty' => $empty,
                'backUrl' => weeklyTasksUiMonthUrl($offsets, $selectedId),
            ];
        }
    }

    $order = ['Sales', 'Procurement', 'Finance', 'IT', 'Store', 'Drivers', 'Other'];
    $departments = [];
    foreach ($order as $name) {
        if (empty($groups[$name])) {
            continue;
        }
        $people = $groups[$name];
        usort($people, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return strcasecmp($a['name'], $b['name']);
            }

            return $b['score'] <=> $a['score'];
        });
        $departments[] = ['name' => $name, 'people' => $people];
    }

    $monthOptions = [];
    for ($i = 0; $i >= -11; $i--) {
        $optionWindow = weeklyTasksUiMonthWindow($i);
        $monthOptions[] = [
            'offset' => $i,
            'label' => $optionWindow[2],
            'url' => weeklyTasksUiMonthUrl($i, $selectedId),
        ];
    }

    return [
        'month' => [
            'offset' => $monthOffset,
            'selected' => $offsets,
            'label' => $monthLabel,
            'isCurrent' => $isCurrent,
            'options' => $monthOptions,
            'prevUrl' => weeklyTasksUiMonthUrl($monthOffset - 1, $selectedId),
            'thisUrl' => weeklyTasksUiMonthUrl(0, $selectedId),
            'selfUrl' => weeklyTasksUiMonthUrl($monthOffset, $selectedId),
            'nextUrl' => $monthOffset < 0 ? weeklyTasksUiMonthUrl($monthOffset + 1, $selectedId) : '',
        ],
        'bands' => [
            ['key' => 'outstanding', 'label' => 'Outstanding', 'count' => $bandCounts['outstanding']],
            ['key' => 'exceeds', 'label' => 'Exceeds', 'count' => $bandCounts['exceeds']],
            ['key' => 'meets', 'label' => 'Meets', 'count' => $bandCounts['meets']],
            ['key' => 'improve', 'label' => 'Improvement plan', 'count' => $bandCounts['improve']],
        ],
        'departments' => $departments,
        'trend' => ($selectedId === 0 && $measureView === null && $pdo instanceof PDO && $users)
            ? weeklyTasksUiTeamTrend($pdo, $users)
            : null,
        'detail' => $detail,
        'detailMissing' => $selectedId > 0 && $detail === null,
        'measure' => $measureView,
    ];
}
