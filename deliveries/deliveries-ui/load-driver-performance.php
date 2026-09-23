<?php

declare(strict_types=1);

/**
 * Driver performance from live delivery ops + vehicle-care work logs.
 *
 * Weights (Ultimate General Trading KPI Manual):
 *   On-time Delivery  target 95%  weight 40%
 *   Vehicle Care      target 100% weight 30%
 *   Documentation     target 100% weight 30%
 */

/**
 * @return array{week_start:string,week_end:string}
 */
function deliveries_performance_week_bounds(?string $weekStart = null): array
{
    $helpers = dirname(__DIR__, 2) . '/driver-kpi/includes/driver_kpi_helpers.php';
    if (is_file($helpers)) {
        require_once $helpers;
        if (function_exists('dkpi_week_bounds')) {
            return dkpi_week_bounds($weekStart);
        }
    }

    $tz = new DateTimeZone('Africa/Dar_es_Salaam');
    if ($weekStart && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
        $monday = new DateTime($weekStart . ' 12:00:00', $tz);
    } else {
        $now = new DateTime('now', $tz);
        $w = (int) $now->format('w');
        $diff = $w === 0 ? -6 : 1 - $w;
        $monday = clone $now;
        $monday->modify($diff . ' days');
        $monday->setTime(0, 0, 0);
    }
    $sunday = clone $monday;
    $sunday->modify('+6 days');

    return [
        'week_start' => $monday->format('Y-m-d'),
        'week_end' => $sunday->format('Y-m-d'),
    ];
}

/**
 * @return array{achievements:array{on_time:float,vehicle_care:float,documentation:float},weighted_score:float}
 */
function deliveries_performance_weighted_score(float $onTime, float $vehicleCare, float $documentation): array
{
    $helpers = dirname(__DIR__, 2) . '/driver-kpi/includes/driver_kpi_helpers.php';
    if (is_file($helpers)) {
        require_once $helpers;
        if (function_exists('dkpi_compute_weighted_score')) {
            return dkpi_compute_weighted_score($onTime, $vehicleCare, $documentation);
        }
    }

    $achieve = static function (float $actual, float $target): float {
        if ($target <= 0) {
            return 0.0;
        }
        return round(min(100.0, ($actual / $target) * 100.0), 2);
    };

    $parts = [
        'on_time' => $achieve($onTime, 95.0),
        'vehicle_care' => $achieve($vehicleCare, 100.0),
        'documentation' => $achieve($documentation, 100.0),
    ];

    return [
        'achievements' => $parts,
        'weighted_score' => round(
            ($parts['on_time'] * 0.40) + ($parts['vehicle_care'] * 0.30) + ($parts['documentation'] * 0.30),
            2
        ),
    ];
}

/**
 * @return array{driver_id:int,driver_name:string,scope:string}
 */
function deliveries_performance_resolve_driver(PDO $pdo, array $query = []): array
{
    $forceId = (int) ($query['force_driver_id'] ?? 0);
    if ($forceId > 0) {
        $name = 'Driver';
        try {
            $nameStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
            $nameStmt->execute([$forceId]);
            $name = trim((string) ($nameStmt->fetchColumn() ?: 'Driver'));
        } catch (Throwable $e) {
            /* keep default */
        }
        return [
            'driver_id' => $forceId,
            'driver_name' => $name !== '' ? $name : 'Driver',
            'scope' => 'forced',
        ];
    }

    if (!empty($query['force_fleet'])) {
        return [
            'driver_id' => 0,
            'driver_name' => 'All drivers',
            'scope' => 'fleet',
        ];
    }

    $orderId = (int) ($query['sel'] ?? $query['order_id'] ?? 0);
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    $sessionName = trim((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Driver'));
    $sessionDept = trim((string) ($_SESSION['department'] ?? ''));

    if ($orderId > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT o.id, t.driver_id, u.full_name AS driver_name
                FROM delivery_orders o
                LEFT JOIN delivery_trips t ON o.trip_id = t.id
                LEFT JOIN users u ON t.driver_id = u.id
                WHERE o.id = ?
                LIMIT 1
            ");
            $stmt->execute([$orderId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $driverId = (int) ($row['driver_id'] ?? 0);
            if ($driverId > 0) {
                $name = trim((string) ($row['driver_name'] ?? ''));
                if ($name === '') {
                    $nameStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
                    $nameStmt->execute([$driverId]);
                    $name = trim((string) ($nameStmt->fetchColumn() ?: 'Driver'));
                }
                return [
                    'driver_id' => $driverId,
                    'driver_name' => $name !== '' ? $name : 'Driver',
                    'scope' => 'selected_order',
                ];
            }

            $reqStmt = $pdo->prepare('SELECT o.requested_driver_id, u.full_name
                FROM delivery_orders o
                LEFT JOIN users u ON o.requested_driver_id = u.id
                WHERE o.id = ? LIMIT 1');
            $reqStmt->execute([$orderId]);
            $req = $reqStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $reqId = (int) ($req['requested_driver_id'] ?? 0);
            if ($reqId > 0) {
                $name = trim((string) ($req['full_name'] ?? 'Driver'));
                return [
                    'driver_id' => $reqId,
                    'driver_name' => $name !== '' ? $name : 'Driver',
                    'scope' => 'selected_order',
                ];
            }
        } catch (Throwable $e) {
            /* fall through */
        }
    }

    $isDriver = strcasecmp($sessionDept, 'Driver') === 0;
    if ($isDriver && $sessionUserId > 0) {
        return [
            'driver_id' => $sessionUserId,
            'driver_name' => $sessionName !== '' ? $sessionName : 'Driver',
            'scope' => 'session_driver',
        ];
    }

    return [
        'driver_id' => 0,
        'driver_name' => 'All drivers',
        'scope' => 'fleet',
    ];
}

/**
 * @return array<string,mixed>
 */
function deliveries_compute_driver_performance(PDO $pdo, array $query = []): array
{
    $week = deliveries_performance_week_bounds(isset($query['week_start']) ? (string) $query['week_start'] : null);
    $weekStart = $week['week_start'];
    $weekEnd = $week['week_end'];
    $driver = deliveries_performance_resolve_driver($pdo, $query);
    $driverId = (int) $driver['driver_id'];

    $driverFilterSql = '';
    $params = [$weekStart . ' 00:00:00', $weekEnd . ' 23:59:59'];
    if ($driverId > 0) {
        $driverFilterSql = ' AND (t.driver_id = ? OR o.requested_driver_id = ? OR o.created_by = ?)';
        $params[] = $driverId;
        $params[] = $driverId;
        $params[] = $driverId;
    }

    $completedStatuses = "('delivered', 'completed')";
    $rows = [];
    try {
        $sql = "
            SELECT o.id, o.client_name, o.client_phone, o.delivery_address, o.status,
                   o.delivery_deadline, o.completion_time, o.signature_path, o.customer_rating,
                   o.customer_feedback, o.created_at, o.invoice_ref, o.delivery_note_id,
                   dn.note_number AS delivery_number, dn.receiver_signature_path AS dn_sig,
                   t.driver_id, u.full_name AS driver_name
            FROM delivery_orders o
            LEFT JOIN delivery_trips t ON o.trip_id = t.id
            LEFT JOIN delivery_notes dn ON o.delivery_note_id = dn.id
            LEFT JOIN users u ON COALESCE(t.driver_id, o.requested_driver_id) = u.id
            WHERE o.status IN {$completedStatuses}
              AND o.completion_time IS NOT NULL
              AND o.completion_time BETWEEN ? AND ?
              {$driverFilterSql}
            ORDER BY o.completion_time DESC
            LIMIT 300
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    $createdCount = 0;
    try {
        $createdSql = "
            SELECT COUNT(*) FROM delivery_orders o
            LEFT JOIN delivery_trips t ON o.trip_id = t.id
            WHERE o.created_at BETWEEN ? AND ?
            {$driverFilterSql}
        ";
        $createdParams = [$weekStart . ' 00:00:00', $weekEnd . ' 23:59:59'];
        if ($driverId > 0) {
            $createdParams[] = $driverId;
            $createdParams[] = $driverId;
            $createdParams[] = $driverId;
        }
        $stmtCreated = $pdo->prepare($createdSql);
        $stmtCreated->execute($createdParams);
        $createdCount = (int) $stmtCreated->fetchColumn();
    } catch (Throwable $e) {
        $createdCount = 0;
    }

    $completed = 0;
    $onTime = 0;
    $late = 0;
    $noDeadline = 0;
    $signed = 0;
    $unsigned = 0;
    $rated = 0;
    $ratingSum = 0;
    $items = [];
    $onTimeTasks = [];
    $documentationTasks = [];
    $vehicleTasks = [];

    foreach ($rows as $row) {
        $completed++;
        $deadlineRaw = trim((string) ($row['delivery_deadline'] ?? ''));
        $completionRaw = trim((string) ($row['completion_time'] ?? ''));
        $isOnTime = false;
        $countsTowardOnTime = false;
        $timingLabel = 'No deadline';

        if ($deadlineRaw !== '' && $completionRaw !== '') {
            $deadlineTs = strtotime($deadlineRaw);
            $completionTs = strtotime($completionRaw);
            if ($deadlineTs && $completionTs) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadlineRaw)) {
                    $deadlineTs = strtotime($deadlineRaw . ' 23:59:59') ?: $deadlineTs;
                }
                $countsTowardOnTime = true;
                $isOnTime = $completionTs <= $deadlineTs;
                $timingLabel = $isOnTime ? 'On time' : 'Late';
                if ($isOnTime) {
                    $onTime++;
                } else {
                    $late++;
                }
            } else {
                $noDeadline++;
                $isOnTime = false;
                $timingLabel = 'Completed (invalid deadline)';
            }
        } elseif ($completionRaw !== '') {
            // No agreed deadline: cannot judge on-time, so exclude from on-time rate
            $noDeadline++;
            $isOnTime = false;
            $timingLabel = 'Completed (no deadline)';
        } else {
            $noDeadline++;
            $timingLabel = 'Missing completion time';
        }

        $sig = trim((string) ($row['signature_path'] ?? ''));
        $dnSig = trim((string) ($row['dn_sig'] ?? ''));
        $isSigned = $sig !== '' || $dnSig !== '';
        if ($isSigned) {
            $signed++;
        } else {
            $unsigned++;
        }

        $rating = $row['customer_rating'] !== null && $row['customer_rating'] !== ''
            ? (int) $row['customer_rating']
            : 0;
        if ($rating > 0) {
            $rated++;
            $ratingSum += $rating;
        }

        $deliveryNumber = trim((string) ($row['delivery_number'] ?? ''));
        if ($deliveryNumber === '') {
            $deliveryNumber = '#' . (int) ($row['id'] ?? 0);
        }

        $clientName = (string) ($row['client_name'] ?? '');
        $completedAt = $completionRaw !== '' ? $completionRaw : (string) ($row['created_at'] ?? '');
        $item = [
            'id' => (int) ($row['id'] ?? 0),
            'deliveryNumber' => $deliveryNumber,
            'clientName' => $clientName,
            'clientPhone' => (string) ($row['client_phone'] ?? ''),
            'destination' => (string) ($row['delivery_address'] ?? ''),
            'status' => $timingLabel,
            'createdAt' => $completedAt,
            'signed' => $isSigned,
            'rating' => $rating,
            'feedback' => (string) ($row['customer_feedback'] ?? ''),
            'driverName' => (string) ($row['driver_name'] ?? ''),
            'onTime' => $isOnTime,
            'deadline' => $deadlineRaw,
            'onTimeScored' => $countsTowardOnTime,
        ];
        $items[] = $item;

        $onTimeDetail = $timingLabel;
        if ($deadlineRaw !== '') {
            $onTimeDetail .= ' | deadline ' . $deadlineRaw;
        }
        if ($completionRaw !== '') {
            $onTimeDetail .= ' | completed ' . $completionRaw;
        }
        if ($countsTowardOnTime) {
            $onTimeResult = $isOnTime ? 'Counted toward on-time score' : 'Late - did not count as on-time';
        } else {
            $onTimeResult = 'No deadline set - not scored for on-time';
        }
        $onTimeTasks[] = [
            'id' => 'ot-' . (int) ($row['id'] ?? 0),
            'title' => $deliveryNumber . ' - ' . ($clientName !== '' ? $clientName : 'Client'),
            'detail' => $onTimeDetail,
            'result' => $onTimeResult,
            'ok' => $countsTowardOnTime ? $isOnTime : false,
            'at' => $completedAt,
        ];

        $docParts = [];
        $docParts[] = $isSigned ? 'Signed / returned' : 'Missing signature';
        if ($rating > 0) {
            $docParts[] = 'Customer rating ' . $rating . '/5';
        } else {
            $docParts[] = 'No customer rating yet';
        }
        $feedback = trim((string) ($row['customer_feedback'] ?? ''));
        if ($feedback !== '') {
            $docParts[] = 'Feedback noted';
        }
        $documentationTasks[] = [
            'id' => 'doc-' . (int) ($row['id'] ?? 0),
            'title' => $deliveryNumber . ' - ' . ($clientName !== '' ? $clientName : 'Client'),
            'detail' => implode(' | ', $docParts),
            'result' => $isSigned
                ? 'Counted toward documentation'
                : 'Unsigned - lowers documentation',
            'ok' => $isSigned,
            'at' => $completedAt,
        ];
    }

    if ($completed <= 0) {
        $onTimePct = 0.0;
        $onTimeHow = 'No completed deliveries this week, so on-time scored 0%.';
    } else {
        $scoredForOnTime = $onTime + $late;
        if ($scoredForOnTime <= 0) {
            $onTimePct = 0.0;
            $onTimeHow = $completed . ' completed delivery/deliveries this week, but none had an agreed deadline, so on-time scored 0%.';
        } else {
            $onTimePct = round(($onTime / $scoredForOnTime) * 100, 2);
            $onTimeHow = $onTime . ' of ' . $scoredForOnTime . ' deliveries with a set deadline were on or before that deadline'
                . ' - on-time ' . rtrim(rtrim(number_format($onTimePct, 1), '0'), '.') . '%.';
        }
        if ($noDeadline > 0) {
            $onTimeHow .= ' ' . $noDeadline . ' completed without a deadline were not scored for on-time.';
        }
        if ($createdCount > 0) {
            $onTimeHow .= ' ' . $createdCount . ' delivery request(s) were created this week.';
        }
    }

    $vehiclePct = 0.0;
    $vehicleHow = 'No vehicle care / maintenance work logged this week - vehicle care scored 0%.';
    $workLogCount = 0;
    $appendVehicleTasks = static function (array $workLog) use (&$vehicleTasks): void {
        foreach ($workLog as $idx => $log) {
            if (!is_array($log)) {
                continue;
            }
            $type = trim((string) ($log['type'] ?? 'vehicle_care'));
            $label = trim((string) ($log['label'] ?? ''));
            if ($label === '') {
                $label = str_replace('_', ' ', $type !== '' ? $type : 'Vehicle care');
                $label = ucwords($label);
            }
            $task = trim((string) ($log['task_description'] ?? ''));
            $text = trim((string) ($log['text'] ?? ''));
            $detail = $task !== '' ? $task : $text;
            if ($detail === '') {
                $detail = 'Logged ' . strtolower($label) . ' activity';
            }
            $at = trim((string) ($log['at'] ?? ''));
            $id = trim((string) ($log['id'] ?? ''));
            if ($id === '') {
                $id = 'vc-' . $idx . '-' . ($at !== '' ? $at : (string) $idx);
            }
            $vehicleTasks[] = [
                'id' => $id,
                'title' => $label,
                'detail' => $detail,
                'result' => 'Counted toward vehicle care',
                'ok' => true,
                'at' => $at,
                'type' => $type,
            ];
        }
    };
    $helpers = dirname(__DIR__, 2) . '/driver-kpi/includes/driver_kpi_helpers.php';
    if (is_file($helpers)) {
        require_once $helpers;
        if (function_exists('dkpi_ensure_tables')) {
            try {
                dkpi_ensure_tables($pdo);
            } catch (Throwable $e) {
                /* ignore */
            }
        }
        try {
            if ($driverId > 0 && function_exists('dkpi_get_entry')) {
                $entry = dkpi_get_entry($pdo, $driverId, $weekStart, 'delivery');
                $workLog = is_array($entry['work_log'] ?? null) ? $entry['work_log'] : [];
                if ($workLog === [] && !empty($entry['work_log_json'])) {
                    $decoded = json_decode((string) $entry['work_log_json'], true);
                    $workLog = is_array($decoded) ? $decoded : [];
                }
                $workLogCount = count($workLog);
                $appendVehicleTasks($workLog);
                if ($workLogCount > 0 && function_exists('dkpi_infer_vehicle_care_progress')) {
                    $progress = dkpi_infer_vehicle_care_progress($workLog);
                    $maintPct = (float) ($progress['maintenance_pct'] ?? 0);
                    $inspectPct = (float) ($progress['inspection_pct'] ?? 0);
                    $vehiclePct = round(($maintPct + $inspectPct) / 2, 2);
                    $vehicleHow = 'Scheduled maintenance ' . rtrim(rtrim(number_format($maintPct, 1), '0'), '.')
                        . '% and daily inspection ' . rtrim(rtrim(number_format($inspectPct, 1), '0'), '.')
                        . '% from ' . $workLogCount . ' service/maintenance log(s)'
                        . ' - vehicle care ' . rtrim(rtrim(number_format($vehiclePct, 1), '0'), '.') . '%.';
                } elseif (!empty($entry['vehicle_care_pct'])) {
                    $vehiclePct = (float) $entry['vehicle_care_pct'];
                    $vehicleHow = 'Vehicle care from recorded KPI entry: '
                        . rtrim(rtrim(number_format($vehiclePct, 1), '0'), '.') . '%.';
                }
            } elseif ($driverId <= 0 && function_exists('dkpi_list_entries')) {
                $entries = dkpi_list_entries($pdo, $weekStart, null, 'delivery');
                $sum = 0.0;
                $n = 0;
                foreach ($entries as $entry) {
                    $workLog = is_array($entry['work_log'] ?? null) ? $entry['work_log'] : [];
                    if ($workLog === [] && !empty($entry['work_log_json'])) {
                        $decoded = json_decode((string) $entry['work_log_json'], true);
                        $workLog = is_array($decoded) ? $decoded : [];
                    }
                    $workLogCount += count($workLog);
                    $appendVehicleTasks($workLog);
                    if ($workLog !== [] && function_exists('dkpi_infer_vehicle_care_progress')) {
                        $progress = dkpi_infer_vehicle_care_progress($workLog);
                        $sum += ((float) ($progress['maintenance_pct'] ?? 0) + (float) ($progress['inspection_pct'] ?? 0)) / 2;
                        $n++;
                    } elseif (isset($entry['vehicle_care_pct'])) {
                        $sum += (float) $entry['vehicle_care_pct'];
                        $n++;
                    }
                }
                if ($n > 0) {
                    $vehiclePct = round($sum / $n, 2);
                    $vehicleHow = 'Fleet average vehicle care from ' . $n . ' driver recording(s) this week: '
                        . rtrim(rtrim(number_format($vehiclePct, 1), '0'), '.') . '%.';
                }
            }
        } catch (Throwable $e) {
            /* keep defaults */
        }
    }

    if ($completed <= 0) {
        $docsPct = 0.0;
        $docsHow = 'No completed deliveries this week, so documentation scored 0%.';
    } else {
        $signedPct = round(($signed / $completed) * 100, 2);
        $avgRating = $rated > 0 ? round($ratingSum / $rated, 2) : 0.0;
        $reviewPct = $rated > 0 ? round(($avgRating / 5) * 100, 2) : $signedPct;
        $docsPct = round(($signedPct * 0.70) + ($reviewPct * 0.30), 2);
        $docsHow = $signed . ' of ' . $completed . ' deliveries signed/returned'
            . ' (' . rtrim(rtrim(number_format($signedPct, 1), '0'), '.') . '%)';
        if ($rated > 0) {
            $docsHow .= '; customer reviews avg ' . number_format($avgRating, 1) . '/5 from ' . $rated . ' rating(s)';
        } else {
            $docsHow .= '; no customer ratings yet this week (signature rate used for review portion)';
        }
        $docsHow .= ' - documentation ' . rtrim(rtrim(number_format($docsPct, 1), '0'), '.') . '%.';
    }

    $computed = deliveries_performance_weighted_score($onTimePct, $vehiclePct, $docsPct);
    $achievements = $computed['achievements'];
    $weighted = (float) $computed['weighted_score'];

    $calculation = [
        $onTimeHow . ' Weight 40%. Achievement vs 95% target: '
            . rtrim(rtrim(number_format((float) $achievements['on_time'], 1), '0'), '.') . '%.',
        $vehicleHow . ' Weight 30%. Achievement vs 100% target: '
            . rtrim(rtrim(number_format((float) $achievements['vehicle_care'], 1), '0'), '.') . '%.',
        $docsHow . ' Weight 30%. Achievement vs 100% target: '
            . rtrim(rtrim(number_format((float) $achievements['documentation'], 1), '0'), '.') . '%.',
        'Overall score = (on-time x 40%) + (vehicle care x 30%) + (documentation x 30%) = '
            . number_format($weighted, 1) . '%.',
    ];

    $suggestions = [];
    if ($completed > 0 && $onTimePct < 95) {
        $suggestions[] = 'Deliver on or before the agreed time. Aim for 95% on time.';
    }
    if ($completed <= 0) {
        $suggestions[] = 'Finish and sign deliveries this week so your score can be counted.';
    }
    if ($late > 0) {
        $suggestions[] = $late . ' delivery/deliveries were late. Start trips earlier next time.';
    }
    if ($unsigned > 0) {
        $suggestions[] = 'Get the client to sign every delivery and return papers the same day.';
    }
    if ($vehiclePct < 100) {
        $suggestions[] = 'Record vehicle checks and maintenance in Driver KPI under Vehicle Care.';
    }
    if ($rated === 0 && $completed > 0) {
        $suggestions[] = 'Ask the customer for a quick rating or short comment after delivery.';
    } elseif ($rated > 0 && ($ratingSum / max(1, $rated)) < 4) {
        $suggestions[] = 'Check low ratings and fix what went wrong for the next trip.';
    }
    if ($suggestions === [] && $weighted >= 90) {
        $suggestions[] = 'Great week. Keep delivering on time, signing papers, and checking the vehicle.';
    } elseif ($suggestions === []) {
        $suggestions[] = 'Keep delivering, getting signatures, and logging vehicle care.';
    }

    $isInitApi = isset($_SERVER['SCRIPT_NAME']) && stripos((string) $_SERVER['SCRIPT_NAME'], 'init.php') !== false;
    $allowAi = empty($query['skip_ai']) && !$isInitApi;
    try {
        if ($allowAi) {
            $aiRoot = dirname(__DIR__, 2) . '/includes/ai_helpers.php';
            if (is_file($aiRoot)) {
                require_once $aiRoot;
            }
            $settings = function_exists('ai_fetch_settings_row') ? ai_fetch_settings_row() : null;
            $aiOn = $settings && (int) ($settings['is_enabled'] ?? 0) === 1;
            $companyId = (int) ($_SESSION['company_id'] ?? 0);
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            if ($aiOn && $companyId > 0 && $userId > 0 && function_exists('ai_handle_ask') && $completed > 0) {
                $prompt = 'You coach delivery drivers on KPI performance. '
                    . 'Driver: ' . $driver['driver_name'] . '. '
                    . 'Week ' . $weekStart . ' to ' . $weekEnd . '. '
                    . 'On-time ' . $onTimePct . '% (target 95%, weight 40%). '
                    . 'Vehicle care ' . $vehiclePct . '% (target 100%, weight 30%). '
                    . 'Documentation ' . $docsPct . '% (target 100%, weight 30%). '
                    . 'Overall ' . $weighted . '%. '
                    . 'Completed ' . $completed . ', signed ' . $signed . ', late ' . $late . ', ratings ' . $rated . '. '
                    . 'Reply with exactly one short actionable tip (max 25 words). No markdown.';
                $aiRes = ai_handle_ask($userId, $companyId, $prompt, 'driver_kpi');
                $tip = trim((string) ($aiRes['answer'] ?? ''));
                if ($tip !== '') {
                    array_unshift($suggestions, $tip);
                    $suggestions = array_values(array_unique($suggestions));
                    $suggestions = array_slice($suggestions, 0, 5);
                }
            }
        }
    } catch (Throwable $e) {
        /* keep rule-based suggestions */
    }

    $metrics = [
        [
            'key' => 'on_time',
            'label' => 'On-time Delivery',
            'description' => 'Deliveries completed on or before the agreed date/time',
            'actual' => $onTimePct,
            'target' => 95.0,
            'weight' => 40.0,
            'achievement' => (float) $achievements['on_time'],
            'tasksTitle' => 'Deliveries that built this score',
            'tasksEmpty' => 'No completed deliveries contributed to on-time this week.',
            'tasks' => $onTimeTasks,
        ],
        [
            'key' => 'vehicle_care',
            'label' => 'Vehicle Care',
            'description' => 'Completion of scheduled vehicle maintenance and daily inspections',
            'actual' => $vehiclePct,
            'target' => 100.0,
            'weight' => 30.0,
            'achievement' => (float) $achievements['vehicle_care'],
            'tasksTitle' => 'Maintenance and inspection tasks logged',
            'tasksEmpty' => 'No vehicle care / maintenance tasks logged this week.',
            'tasks' => $vehicleTasks,
        ],
        [
            'key' => 'documentation',
            'label' => 'Documentation',
            'description' => 'Delivery documents completed accurately and returned on time',
            'actual' => $docsPct,
            'target' => 100.0,
            'weight' => 30.0,
            'achievement' => (float) $achievements['documentation'],
            'tasksTitle' => 'Documents and reviews that built this score',
            'tasksEmpty' => 'No signed documents or reviews contributed this week.',
            'tasks' => $documentationTasks,
        ],
    ];

    return [
        'score' => $weighted,
        'on_time_pct' => $onTimePct,
        'vehicle_care_pct' => $vehiclePct,
        'documentation_pct' => $docsPct,
        'achievements' => $achievements,
        'driver_id' => $driverId,
        'driver_name' => (string) $driver['driver_name'],
        'scope' => (string) $driver['scope'],
        'week_start' => $weekStart,
        'week_end' => $weekEnd,
        'counts' => [
            'created' => $createdCount,
            'completed' => $completed,
            'on_time' => $onTime,
            'late' => $late,
            'signed' => $signed,
            'unsigned' => $unsigned,
            'rated' => $rated,
            'work_log' => $workLogCount,
        ],
        'calculation' => $calculation,
        'suggestions' => $suggestions,
        'metrics' => $metrics,
        'items' => $items,
        'drivers' => [],
    ];
}

/**
 * Ranked driver performance board for the current week.
 * Includes all Driver-department users (0% when no weekly activity).
 *
 * @return list<array<string,mixed>>
 */
function deliveries_list_driver_performance_board(PDO $pdo, array $query = []): array
{
    $week = deliveries_performance_week_bounds(isset($query['week_start']) ? (string) $query['week_start'] : null);
    $weekStart = $week['week_start'];
    $weekEnd = $week['week_end'];

    $byId = [];

    // 1) All accounts in the Driver department
    try {
        $stmt = $pdo->query("
            SELECT id AS driver_id, full_name AS driver_name
            FROM users
            WHERE department = 'Driver'
            ORDER BY full_name ASC
            LIMIT 200
        ");
        foreach (($stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : []) as $row) {
            $id = (int) ($row['driver_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $byId[$id] = [
                'driver_id' => $id,
                'driver_name' => trim((string) ($row['driver_name'] ?? 'Driver')),
            ];
        }
    } catch (Throwable $e) {
        /* fall through */
    }

    // 2) Anyone who completed a delivery this week (covers non-department assignments)
    try {
        $sql = "
            SELECT DISTINCT
                COALESCE(NULLIF(t.driver_id, 0), NULLIF(o.requested_driver_id, 0)) AS driver_id,
                COALESCE(u.full_name, u2.full_name, 'Driver') AS driver_name
            FROM delivery_orders o
            LEFT JOIN delivery_trips t ON o.trip_id = t.id
            LEFT JOIN users u ON t.driver_id = u.id
            LEFT JOIN users u2 ON o.requested_driver_id = u2.id
            WHERE o.status IN ('delivered', 'completed')
              AND o.completion_time IS NOT NULL
              AND o.completion_time BETWEEN ? AND ?
              AND COALESCE(NULLIF(t.driver_id, 0), NULLIF(o.requested_driver_id, 0)) IS NOT NULL
            LIMIT 200
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$weekStart . ' 00:00:00', $weekEnd . ' 23:59:59']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) ($row['driver_id'] ?? 0);
            if ($id <= 0 || isset($byId[$id])) {
                continue;
            }
            $byId[$id] = [
                'driver_id' => $id,
                'driver_name' => trim((string) ($row['driver_name'] ?? 'Driver')),
            ];
        }
    } catch (Throwable $e) {
        /* keep department list */
    }

    // 3) Drivers with KPI entries this week
    try {
        $helpers = dirname(__DIR__, 2) . '/driver-kpi/includes/driver_kpi_helpers.php';
        if (is_file($helpers)) {
            require_once $helpers;
            if (function_exists('dkpi_list_entries')) {
                $entries = dkpi_list_entries($pdo, $weekStart, null, 'delivery');
                foreach ($entries as $entry) {
                    $id = (int) ($entry['user_id'] ?? 0);
                    if ($id <= 0 || isset($byId[$id])) {
                        continue;
                    }
                    $byId[$id] = [
                        'driver_id' => $id,
                        'driver_name' => trim((string) ($entry['full_name'] ?? $entry['driver_name'] ?? 'Driver')),
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        /* keep existing list */
    }

    $driverRows = array_values($byId);
    usort($driverRows, static function (array $a, array $b): int {
        return strcasecmp((string) ($a['driver_name'] ?? ''), (string) ($b['driver_name'] ?? ''));
    });

    $board = [];
    foreach ($driverRows as $row) {
        $id = (int) ($row['driver_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $name = trim((string) ($row['driver_name'] ?? 'Driver'));
        $perf = deliveries_compute_driver_performance($pdo, [
            'force_driver_id' => $id,
            'skip_ai' => 1,
            'week_start' => $weekStart,
        ]);
        $board[] = [
            'id' => $id,
            'name' => $name !== '' ? $name : (string) ($perf['driver_name'] ?? 'Driver'),
            'score' => (float) ($perf['score'] ?? 0),
            'on_time_pct' => (float) ($perf['on_time_pct'] ?? 0),
            'vehicle_care_pct' => (float) ($perf['vehicle_care_pct'] ?? 0),
            'documentation_pct' => (float) ($perf['documentation_pct'] ?? 0),
            'completed' => (int) ($perf['counts']['completed'] ?? 0),
            'signed' => (int) ($perf['counts']['signed'] ?? 0),
            'metrics' => is_array($perf['metrics'] ?? null) ? $perf['metrics'] : [],
            'calculation' => is_array($perf['calculation'] ?? null) ? $perf['calculation'] : [],
            'suggestions' => is_array($perf['suggestions'] ?? null) ? $perf['suggestions'] : [],
            'items' => is_array($perf['items'] ?? null) ? $perf['items'] : [],
        ];
    }

    usort($board, static function (array $a, array $b): int {
        $scoreCmp = ($b['score'] <=> $a['score']);
        if ($scoreCmp !== 0) {
            return $scoreCmp;
        }
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    return $board;
}
