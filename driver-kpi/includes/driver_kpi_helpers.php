<?php
/**
 * Driver KPI mini-module - weekly recording + Performance sync.
 *
 * Catalog (Ultimate General Trading KPI Manual):
 *   On-time Delivery  target 95%  weight 40%
 *   Vehicle Care      target 100% weight 30%
 *   Documentation     target 100% weight 30%
 */

function dkpi_services(): array
{
    return [
        'delivery' => [
            'key' => 'delivery',
            'label' => 'Delivery',
            'description' => 'Record weekly delivery performance: on-time, vehicle care, and documentation',
            'record_title' => 'Delivery recordings',
            'lede' => "Enter this week's delivery scores so Performance can track how deliveries are running.",
        ],
        'ride' => [
            'key' => 'ride',
            'label' => 'Ride service',
            'description' => 'Record ride services completed: on-time, vehicle care, and documentation',
            'record_title' => 'Ride service recordings',
            'lede' => 'Enter the ride services you completed this week so your performance can be tracked.',
        ],
    ];
}

function dkpi_normalize_service(?string $service): string
{
    $key = strtolower(trim((string) $service));
    return isset(dkpi_services()[$key]) ? $key : 'delivery';
}

function dkpi_service_source_module(string $service): string
{
    return 'driver_kpi_' . dkpi_normalize_service($service);
}

function dkpi_metrics(?string $service = null): array
{
    $service = dkpi_normalize_service($service);
    $onTimeLabel = $service === 'ride' ? 'On-time Ride' : 'On-time Delivery';
    $onTimeDesc = $service === 'ride'
        ? 'Rides completed on or before the agreed date/time'
        : 'Deliveries completed on or before the agreed date/time';
    $docsDesc = $service === 'ride'
        ? 'Ride documents completed accurately and returned on time'
        : 'Delivery documents completed accurately and returned on time';

    return [
        'on_time' => [
            'key' => 'on_time',
            'label' => $onTimeLabel,
            'description' => $onTimeDesc,
            'target' => 95.0,
            'weight' => 40.0,
            'column' => 'on_time_pct',
        ],
        'vehicle_care' => [
            'key' => 'vehicle_care',
            'label' => 'Vehicle Care',
            'description' => 'Completion of scheduled vehicle maintenance and daily inspections',
            'target' => 100.0,
            'weight' => 30.0,
            'column' => 'vehicle_care_pct',
        ],
        'documentation' => [
            'key' => 'documentation',
            'label' => 'Documentation',
            'description' => $docsDesc,
            'target' => 100.0,
            'weight' => 30.0,
            'column' => 'documentation_pct',
        ],
    ];
}

function dkpi_week_bounds(?string $weekStart = null): array
{
    if (function_exists('wm_get_week_bounds')) {
        return wm_get_week_bounds($weekStart);
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

function dkpi_shift_week(string $weekStart, int $weeks): array
{
    if (function_exists('wm_shift_week')) {
        return wm_shift_week($weekStart, $weeks);
    }
    $d = new DateTime($weekStart . ' 12:00:00', new DateTimeZone('Africa/Dar_es_Salaam'));
    $d->modify(($weeks * 7) . ' days');
    return dkpi_week_bounds($d->format('Y-m-d'));
}

function dkpi_clamp_pct($value): float
{
    $n = is_numeric($value) ? (float) $value : 0.0;
    if ($n < 0) {
        return 0.0;
    }
    if ($n > 100) {
        return 100.0;
    }
    return round($n, 2);
}

/** Achievement vs target, capped at 100%. */
function dkpi_metric_achievement(float $actual, float $target): float
{
    if ($target <= 0) {
        return 0.0;
    }
    return round(min(100.0, ($actual / $target) * 100.0), 2);
}

function dkpi_compute_weighted_score(float $onTime, float $vehicleCare, float $documentation): array
{
    $metrics = dkpi_metrics();
    $parts = [
        'on_time' => dkpi_metric_achievement($onTime, $metrics['on_time']['target']),
        'vehicle_care' => dkpi_metric_achievement($vehicleCare, $metrics['vehicle_care']['target']),
        'documentation' => dkpi_metric_achievement($documentation, $metrics['documentation']['target']),
    ];
    $score = 0.0;
    foreach ($metrics as $key => $m) {
        $score += ($parts[$key] * ($m['weight'] / 100.0));
    }
    return [
        'achievements' => $parts,
        'weighted_score' => round($score, 2),
    ];
}

function dkpi_ensure_tables(PDO $pdo): bool
{
    if (!function_exists('tableExists')) {
        return false;
    }

    if (!tableExists('driver_kpi_entries', $pdo)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `driver_kpi_entries` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `company_id` int(11) DEFAULT NULL,
          `recorded_by` int(11) NOT NULL,
          `service_type` varchar(32) NOT NULL DEFAULT 'delivery',
          `week_start` date NOT NULL,
          `week_end` date NOT NULL,
          `on_time_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
          `vehicle_care_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
          `documentation_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
          `weighted_score` decimal(5,2) NOT NULL DEFAULT 0.00,
          `notes` text DEFAULT NULL,
          `work_log_json` longtext DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_dkpi_user_week_service` (`user_id`,`week_start`,`service_type`),
          KEY `idx_dkpi_week` (`week_start`),
          KEY `idx_dkpi_company` (`company_id`),
          KEY `idx_dkpi_service` (`service_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } else {
        if (function_exists('columnExists') && !columnExists('driver_kpi_entries', 'service_type', $pdo)) {
            try {
                $pdo->exec("ALTER TABLE `driver_kpi_entries` ADD COLUMN `service_type` varchar(32) NOT NULL DEFAULT 'delivery' AFTER `recorded_by`");
            } catch (Throwable $e) {
                /* may already exist */
            }
        }
        try {
            $pdo->exec('ALTER TABLE `driver_kpi_entries` DROP INDEX `uq_dkpi_user_week`');
        } catch (Throwable $e) {
            /* old index may not exist */
        }
        try {
            $pdo->exec('ALTER TABLE `driver_kpi_entries` ADD UNIQUE KEY `uq_dkpi_user_week_service` (`user_id`,`week_start`,`service_type`)');
        } catch (Throwable $e) {
            /* unique may already exist */
        }
        if (function_exists('columnExists') && !columnExists('driver_kpi_entries', 'work_log_json', $pdo)) {
            try {
                $pdo->exec('ALTER TABLE `driver_kpi_entries` ADD COLUMN `work_log_json` longtext DEFAULT NULL AFTER `notes`');
            } catch (Throwable $e) {
                /* may already exist */
            }
        }
    }

    if (!tableExists('performance_module_scores', $pdo)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `performance_module_scores` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `company_id` int(11) DEFAULT NULL,
          `week_start` date NOT NULL,
          `week_end` date NOT NULL,
          `source_module` varchar(64) NOT NULL,
          `score_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
          `weight` decimal(5,2) NOT NULL DEFAULT 100.00,
          `payload_json` longtext DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_pms_user_week_source` (`user_id`,`week_start`,`source_module`),
          KEY `idx_pms_week` (`week_start`),
          KEY `idx_pms_source` (`source_module`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    return tableExists('driver_kpi_entries', $pdo) && tableExists('performance_module_scores', $pdo);
}

function dkpi_text_has_maintenance(string $text): bool
{
    return (bool) preg_match(
        '/\b(oil|service|servicing|maintenance|repair|tyre|tire|brake|filter|scheduled|garage|workshop|battery|fluid|lubricat|align|sparks?|coolant)\b/i',
        $text
    );
}

function dkpi_text_has_inspection(string $text): bool
{
    return (bool) preg_match(
        '/\b(inspect|inspection|check|checked|checking|daily|lights?|wiper|mirror|horn|pressure|walk[\s-]?around|pre[\s-]?trip|safety|tyre|tire)\b/i',
        $text
    );
}

/**
 * Auto-calculate scheduled maintenance % and daily inspection % from work log text (no manual entry).
 *
 * @return array{maintenance_pct:float,inspection_pct:float,maintenance_days:int,inspection_days:int,how:list<string>}
 */
function dkpi_infer_vehicle_care_progress(array $workLog): array
{
    $maintEntries = 0;
    $maintDetailed = 0;
    $inspectDays = [];
    $maintDays = [];

    foreach ($workLog as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = dkpi_normalize_work_log_type($item['type'] ?? 'vehicle_care');
        $task = trim((string) ($item['task_description'] ?? ''));
        $text = trim((string) ($item['text'] ?? ''));
        $blob = trim($task . ' ' . $text . ' ' . (string) ($item['label'] ?? ''));
        $at = trim((string) ($item['at'] ?? ''));
        if ($at === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $at)) {
            $at = 'undated-' . (string) ($item['id'] ?? uniqid('', true));
        }

        $isMaint = $type === 'maintenance' || dkpi_text_has_maintenance($blob);
        $isInspect = $type === 'inspection' || dkpi_text_has_inspection($blob);

        // Generic vehicle_care notes without clear keywords still count as light inspection evidence
        if ($type === 'vehicle_care' && $blob !== '' && !$isMaint && !$isInspect) {
            $isInspect = true;
        }

        if ($isMaint) {
            $maintEntries++;
            $maintDays[$at] = true;
            if (strlen($text) >= 25 || strlen($task) >= 20) {
                $maintDetailed++;
            }
        }
        if ($isInspect) {
            $inspectDays[$at] = true;
        }
    }

    $maintDayCount = count($maintDays);
    $inspectDayCount = count($inspectDays);

    if ($maintEntries <= 0) {
        $maintPct = 0.0;
        $maintHow = 'No scheduled maintenance activity found in the work log.';
    } elseif ($maintEntries === 1 && $maintDetailed === 0) {
        $maintPct = 55.0;
        $maintHow = '1 maintenance note logged - scheduled maintenance progress set to 55%.';
    } elseif ($maintEntries === 1) {
        $maintPct = 80.0;
        $maintHow = '1 clear maintenance activity logged - scheduled maintenance progress set to 80%.';
    } elseif ($maintDayCount >= 2 || $maintEntries >= 2) {
        $maintPct = 100.0;
        $maintHow = 'Multiple maintenance activities logged - scheduled maintenance progress set to 100%.';
    } else {
        $maintPct = 90.0;
        $maintHow = 'Strong maintenance evidence logged - scheduled maintenance progress set to 90%.';
    }

    // Daily inspection: share of week days covered (7-day week)
    $inspectPct = $inspectDayCount <= 0
        ? 0.0
        : min(100.0, round(($inspectDayCount / 7) * 100, 1));
    if ($inspectDayCount <= 0) {
        $inspectHow = 'No daily inspection activity found in the work log.';
    } else {
        $inspectHow = $inspectDayCount . ' day(s) with inspection evidence this week - daily inspection progress set to '
            . rtrim(rtrim(number_format($inspectPct, 1), '0'), '.') . '%.';
    }

    return [
        'maintenance_pct' => $maintPct,
        'inspection_pct' => $inspectPct,
        'maintenance_days' => $maintDayCount,
        'inspection_days' => $inspectDayCount,
        'how' => [$maintHow, $inspectHow],
    ];
}

function dkpi_work_log_counts(array $workLog): array
{
    $counts = [
        'ride_service' => 0,
        'maintenance' => 0,
        'inspection' => 0,
        'vehicle_care' => 0,
        'total' => 0,
        'detailed' => 0,
        'maintenance_pct' => null,
        'inspection_pct' => null,
    ];
    foreach ($workLog as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = dkpi_normalize_work_log_type($item['type'] ?? 'vehicle_care');
        if (!isset($counts[$type])) {
            $counts[$type] = 0;
        }
        $counts[$type]++;
        $counts['total']++;
        $text = trim((string) ($item['text'] ?? ''));
        if (strlen($text) >= 20) {
            $counts['detailed']++;
        }
        if ($type === 'maintenance') {
            $counts['maintenance'] = (int) $counts['maintenance'];
        }
        if ($type === 'inspection') {
            $counts['inspection'] = (int) $counts['inspection'];
        }
    }

    $progress = dkpi_infer_vehicle_care_progress($workLog);
    $counts['maintenance_pct'] = $progress['maintenance_pct'];
    $counts['inspection_pct'] = $progress['inspection_pct'];
    if ($progress['maintenance_pct'] > 0) {
        $counts['maintenance'] = max((int) $counts['maintenance'], max(1, (int) $progress['maintenance_days']));
    }
    if ($progress['inspection_pct'] > 0) {
        $counts['inspection'] = max((int) $counts['inspection'], max(1, (int) $progress['inspection_days']));
    }
    $counts['progress_how'] = $progress['how'];

    return $counts;
}

/**
 * System/AI performance scoring from the week's work log (no manual %).
 *
 * @return array{
 *   on_time_pct:float,
 *   vehicle_care_pct:float,
 *   documentation_pct:float,
 *   weighted_score:float,
 *   counts:array,
 *   calculation:list<string>,
 *   suggestions:list<string>,
 *   congratulations:string,
 *   source:string
 * }
 */
function dkpi_analyze_from_work_log(array $workLog, string $service = 'ride'): array
{
    $service = dkpi_normalize_service($service);
    $counts = dkpi_work_log_counts($workLog);
    $metrics = dkpi_metrics($service);

    $rides = (int) $counts['ride_service'];
    $maint = (int) $counts['maintenance'];
    $inspect = (int) $counts['inspection'];
    $detailed = (int) $counts['detailed'];
    $total = (int) $counts['total'];

    // On-time: evidence from completed ride/delivery logs this week
    if ($rides <= 0) {
        $onTime = 0.0;
        $onTimeHow = 'No ride/service logs this week, so on-time scored 0%.';
    } elseif ($rides === 1) {
        $onTime = 68.0;
        $onTimeHow = '1 ride/service logged - scored 68% toward the 95% on-time target.';
    } elseif ($rides === 2) {
        $onTime = 80.0;
        $onTimeHow = '2 ride/service logs - scored 80% toward the 95% on-time target.';
    } elseif ($rides === 3) {
        $onTime = 88.0;
        $onTimeHow = '3 ride/service logs - scored 88% toward the 95% on-time target.';
    } elseif ($rides === 4) {
        $onTime = 94.0;
        $onTimeHow = '4 ride/service logs - scored 94% toward the 95% on-time target.';
    } else {
        $onTime = 98.0;
        $onTimeHow = $rides . ' ride/service logs - scored 98% toward the 95% on-time target.';
    }

    // Vehicle care: prefer logged maintenance/inspection progress %
    $maintPct = $counts['maintenance_pct'];
    $inspectPct = $counts['inspection_pct'];
    if ($maintPct !== null || $inspectPct !== null) {
        $m = $maintPct !== null ? (float) $maintPct : 0.0;
        $i = $inspectPct !== null ? (float) $inspectPct : 0.0;
        $vehicle = round(($m + $i) / 2, 2);
        $vehicleHow = 'Scheduled maintenance ' . rtrim(rtrim(number_format($m, 1), '0'), '.')
            . '% and daily inspection ' . rtrim(rtrim(number_format($i, 1), '0'), '.')
            . '% - vehicle care scored ' . rtrim(rtrim(number_format($vehicle, 1), '0'), '.') . '% (average).';
    } else {
        $vehicle = min(100.0, ($maint * 40.0) + ($inspect * 45.0));
        if ($maint === 0 && $inspect === 0) {
            $vehicleHow = 'No maintenance or inspection progress logged - vehicle care scored 0%.';
        } else {
            $vehicleHow = $maint . ' maintenance and ' . $inspect . ' inspection log(s) - vehicle care scored '
                . rtrim(rtrim(number_format($vehicle, 1), '0'), '.') . '%.';
        }
    }

    // Documentation: detail quality of logs + inspection progress
    if ($total <= 0) {
        $docs = 0.0;
        $docsHow = 'No work logged - documentation scored 0%.';
    } else {
        $detailRatio = $detailed / max(1, $total);
        $inspectBoost = $inspectPct !== null ? min(30.0, ((float) $inspectPct) * 0.3) : min(30.0, $inspect * 15.0);
        $docs = min(100.0, round(($detailRatio * 70.0) + $inspectBoost + min(20.0, $total * 5.0), 2));
        $docsHow = $detailed . ' of ' . $total . ' logs have clear detail; inspection progress counted for paperwork evidence - documentation scored '
            . rtrim(rtrim(number_format($docs, 1), '0'), '.') . '%.';
    }

    $computed = dkpi_compute_weighted_score($onTime, $vehicle, $docs);
    $weighted = (float) $computed['weighted_score'];

    $calculation = [];
    if (!empty($counts['progress_how']) && is_array($counts['progress_how'])) {
        foreach ($counts['progress_how'] as $line) {
            $calculation[] = (string) $line;
        }
    }
    $calculation[] = $onTimeHow . ' Weight ' . rtrim(rtrim(number_format($metrics['on_time']['weight'], 1), '0'), '.') . '%.';
    $calculation[] = $vehicleHow . ' Weight ' . rtrim(rtrim(number_format($metrics['vehicle_care']['weight'], 1), '0'), '.') . '%.';
    $calculation[] = $docsHow . ' Weight ' . rtrim(rtrim(number_format($metrics['documentation']['weight'], 1), '0'), '.') . '%.';
    $calculation[] = 'Overall score = (on-time achievement x 40%) + (vehicle care achievement x 30%) + (documentation achievement x 30%) = '
        . number_format($weighted, 1) . '%.';

    $suggestions = [];
    if ($rides < 3) {
        $suggestions[] = $service === 'ride'
            ? 'Log every completed ride this week so on-time performance is fully counted.'
            : 'Log every completed delivery this week so on-time performance is fully counted.';
    }
    if ($maint === 0 && ($maintPct === null || (float) $maintPct <= 0)) {
        $suggestions[] = 'Raise Scheduled Maintenance Progress by completing planned vehicle service.';
    }
    if ($inspect === 0 && ($inspectPct === null || (float) $inspectPct < 100)) {
        $suggestions[] = 'Increase Daily Inspection Progress toward 100% with consistent daily checks.';
    }
    if ($total > 0 && $detailed < $total) {
        $suggestions[] = 'Write a short clear description of work performed so documentation scores higher.';
    }
    if ($suggestions === [] && $weighted >= 90) {
        $suggestions[] = 'Keep the same rhythm next week - your vehicle care coverage looks strong.';
    } elseif ($suggestions === []) {
        $suggestions[] = 'Keep logging vehicle care progress and work performed each week.';
    }

    if ($total === 0) {
        $headline = 'Get started';
        $message = 'No activity logged yet for this week. Add work in the Work log to unlock your performance score.';
        $tone = 'empty';
    } elseif ($weighted >= 95) {
        $headline = 'Congratulations';
        $message = 'Outstanding week! You are meeting or exceeding the KPI targets.';
        $tone = 'congrats';
    } elseif ($weighted >= 85) {
        $headline = 'Congratulations';
        $message = 'Great work this week - you are close to target. A few more complete logs will push you over the line.';
        $tone = 'congrats';
    } elseif ($weighted >= 70) {
        $headline = 'Keep going';
        $message = 'Solid progress so far. Focus on the suggestions below to lift your score.';
        $tone = 'encourage';
    } else {
        $headline = 'Room to improve';
        $message = 'Thanks for logging what you can. Follow the suggestions below to improve next week.';
        $tone = 'encourage';
    }

    // Optional AI narrative (short) when Ultimate Intelligence is enabled
    $source = 'system';
    $aiRoot = dirname(__DIR__, 2) . '/includes/ai_helpers.php';
    if (is_file($aiRoot)) {
        require_once $aiRoot;
    }
    try {
        $settings = function_exists('ai_fetch_settings_row') ? ai_fetch_settings_row() : null;
        $aiOn = $settings && (int) ($settings['is_enabled'] ?? 0) === 1;
        $companyId = (int) ($_SESSION['company_id'] ?? 0);
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($aiOn && $companyId > 0 && $userId > 0 && function_exists('ai_handle_ask') && $total > 0) {
            $snippets = [];
            foreach (array_slice($workLog, 0, 8) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $snippets[] = trim(
                    (string) ($item['task_description'] ?? '') . ' | ' . (string) ($item['text'] ?? '')
                );
            }
            $prompt = 'You score driver vehicle-care progress from work notes. '
                . 'Current system estimate: scheduled maintenance '
                . number_format((float) ($maintPct ?? 0), 1) . '%, daily inspection '
                . number_format((float) ($inspectPct ?? 0), 1) . '%. '
                . 'Notes: ' . implode(' ;; ', $snippets) . '. '
                . 'Reply with exactly 3 short lines: '
                . '1) MAINT: number 0-100 '
                . '2) INSPECT: number 0-100 '
                . '3) TIP: one improvement tip. No markdown.';
            $aiRes = ai_handle_ask($userId, $companyId, $prompt, 'driver_kpi');
            $answer = trim((string) ($aiRes['answer'] ?? ''));
            if ($answer !== '') {
                $source = 'ai';
                if (preg_match('/MAINT\s*:\s*(\d+(?:\.\d+)?)/i', $answer, $mm)) {
                    $maintPct = dkpi_clamp_pct($mm[1]);
                }
                if (preg_match('/INSPECT\s*:\s*(\d+(?:\.\d+)?)/i', $answer, $im)) {
                    $inspectPct = dkpi_clamp_pct($im[1]);
                }
                $m = (float) $maintPct;
                $i = (float) $inspectPct;
                $vehicle = round(($m + $i) / 2, 2);
                $counts['maintenance_pct'] = $m;
                $counts['inspection_pct'] = $i;
                $counts['progress_how'] = [
                    'AI scored scheduled maintenance progress at ' . rtrim(rtrim(number_format($m, 1), '0'), '.') . '%.',
                    'AI scored daily inspection progress at ' . rtrim(rtrim(number_format($i, 1), '0'), '.') . '%.',
                ];
                $computed = dkpi_compute_weighted_score($onTime, $vehicle, $docs);
                $weighted = (float) $computed['weighted_score'];
                $vehicleHow = 'Scheduled maintenance ' . rtrim(rtrim(number_format($m, 1), '0'), '.')
                    . '% and daily inspection ' . rtrim(rtrim(number_format($i, 1), '0'), '.')
                    . '% - vehicle care scored ' . rtrim(rtrim(number_format($vehicle, 1), '0'), '.') . '% (average).';
                $calculation = [];
                foreach ($counts['progress_how'] as $line) {
                    $calculation[] = (string) $line;
                }
                $calculation[] = $onTimeHow . ' Weight ' . rtrim(rtrim(number_format($metrics['on_time']['weight'], 1), '0'), '.') . '%.';
                $calculation[] = $vehicleHow . ' Weight ' . rtrim(rtrim(number_format($metrics['vehicle_care']['weight'], 1), '0'), '.') . '%.';
                $calculation[] = $docsHow . ' Weight ' . rtrim(rtrim(number_format($metrics['documentation']['weight'], 1), '0'), '.') . '%.';
                $calculation[] = 'Overall score = (on-time achievement x 40%) + (vehicle care achievement x 30%) + (documentation achievement x 30%) = '
                    . number_format($weighted, 1) . '%.';
                if (preg_match('/TIP\s*:\s*(.+)$/im', $answer, $tm)) {
                    array_unshift($suggestions, trim($tm[1]));
                    $suggestions = array_values(array_unique($suggestions));
                    $suggestions = array_slice($suggestions, 0, 4);
                }
            }
        }
    } catch (Throwable $e) {
        // Keep system narrative
    }

    return [
        'on_time_pct' => $onTime,
        'vehicle_care_pct' => $vehicle,
        'documentation_pct' => $docs,
        'maintenance_pct' => (float) ($maintPct ?? 0),
        'inspection_pct' => (float) ($inspectPct ?? 0),
        'weighted_score' => $weighted,
        'counts' => $counts,
        'calculation' => $calculation,
        'suggestions' => $suggestions,
        'headline' => $headline,
        'message' => $message,
        'tone' => $tone,
        'congratulations' => $message,
        'source' => $source,
    ];
}

function dkpi_company_id(): ?int
{
    if (function_exists('getCurrentCompanyId')) {
        $id = (int) getCurrentCompanyId();
        return $id > 0 ? $id : null;
    }
    $sid = (int) ($_SESSION['company_id'] ?? 0);
    return $sid > 0 ? $sid : null;
}

function dkpi_sync_to_performance(PDO $pdo, array $entry): void
{
    if (!dkpi_ensure_tables($pdo)) {
        return;
    }
    $service = dkpi_normalize_service($entry['service_type'] ?? 'delivery');
    $payload = [
        'service_type' => $service,
        'on_time_pct' => (float) ($entry['on_time_pct'] ?? 0),
        'vehicle_care_pct' => (float) ($entry['vehicle_care_pct'] ?? 0),
        'documentation_pct' => (float) ($entry['documentation_pct'] ?? 0),
        'work_log' => $entry['work_log'] ?? dkpi_normalize_work_log($entry['work_log_json'] ?? []),
        'metrics' => dkpi_metrics($service),
        'entry_id' => (int) ($entry['id'] ?? 0),
    ];
    $st = $pdo->prepare(
        'INSERT INTO performance_module_scores
            (user_id, company_id, week_start, week_end, source_module, score_pct, weight, payload_json)
         VALUES (?, ?, ?, ?, ?, ?, 100, ?)
         ON DUPLICATE KEY UPDATE
            company_id = VALUES(company_id),
            week_end = VALUES(week_end),
            score_pct = VALUES(score_pct),
            payload_json = VALUES(payload_json),
            updated_at = CURRENT_TIMESTAMP'
    );
    $st->execute([
        (int) $entry['user_id'],
        $entry['company_id'] ?? null,
        $entry['week_start'],
        $entry['week_end'],
        dkpi_service_source_module($service),
        (float) $entry['weighted_score'],
        json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
}

function dkpi_work_log_types(): array
{
    return [
        'vehicle_care' => 'Vehicle Care',
        'ride_service' => 'Ride service',
        'maintenance' => 'Maintenance',
        'inspection' => 'Inspection',
    ];
}

function dkpi_normalize_work_log_type(?string $type): string
{
    $key = strtolower(trim((string) $type));
    $aliases = [
        'vehicle_care' => 'vehicle_care',
        'vehicle' => 'vehicle_care',
        'care' => 'vehicle_care',
        'ride' => 'ride_service',
        'service' => 'ride_service',
        'ride_service' => 'ride_service',
        'maintenance' => 'maintenance',
        'inspect' => 'inspection',
        'inspection' => 'inspection',
    ];
    return $aliases[$key] ?? 'vehicle_care';
}

/**
 * @param mixed $raw
 * @return list<array{id:string,type:string,label:string,text:string,at:string,task_description?:string,maintenance_pct?:float,inspection_pct?:float}>
 */
function dkpi_normalize_work_log($raw): array
{
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($raw)) {
        return [];
    }
    $types = dkpi_work_log_types();
    $out = [];
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $text = trim((string) ($item['text'] ?? $item['description'] ?? $item['work_performed'] ?? ''));
        if ($text === '') {
            continue;
        }
        $type = dkpi_normalize_work_log_type($item['type'] ?? 'vehicle_care');
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '') {
            $id = bin2hex(random_bytes(8));
        }
        $at = trim((string) ($item['at'] ?? ''));
        if ($at === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $at)) {
            $at = (new DateTime('now', new DateTimeZone('Africa/Dar_es_Salaam')))->format('Y-m-d');
        }
        $row = [
            'id' => $id,
            'type' => $type,
            'label' => $types[$type] ?? 'Vehicle Care',
            'text' => mb_substr($text, 0, 500),
            'at' => $at,
        ];
        $task = trim((string) ($item['task_description'] ?? ''));
        if ($task !== '') {
            $row['task_description'] = mb_substr($task, 0, 300);
        }
        if (array_key_exists('maintenance_pct', $item) || array_key_exists('scheduled_maintenance_pct', $item)) {
            $row['maintenance_pct'] = dkpi_clamp_pct($item['maintenance_pct'] ?? $item['scheduled_maintenance_pct'] ?? 0);
        }
        if (array_key_exists('inspection_pct', $item) || array_key_exists('daily_inspection_pct', $item)) {
            $row['inspection_pct'] = dkpi_clamp_pct($item['inspection_pct'] ?? $item['daily_inspection_pct'] ?? 0);
        }
        foreach (['voucher', 'letter', 'document'] as $attachKey) {
            $att = dkpi_normalize_attachment($item[$attachKey] ?? null);
            if ($att !== null) {
                $row[$attachKey] = $att;
            }
        }
        $vouchersRaw = $item['vouchers'] ?? null;
        if (!is_array($vouchersRaw) && isset($row['voucher'])) {
            $vouchersRaw = [$row['voucher']];
        }
        if (is_array($vouchersRaw)) {
            $vouchers = [];
            foreach ($vouchersRaw as $one) {
                $att = dkpi_normalize_attachment($one);
                if ($att === null) {
                    continue;
                }
                $dup = false;
                foreach ($vouchers as $existing) {
                    if (
                        ($att['id'] !== '' && $att['id'] === ($existing['id'] ?? ''))
                        || ($att['path'] !== '' && $att['path'] === ($existing['path'] ?? ''))
                    ) {
                        $dup = true;
                        break;
                    }
                }
                if (!$dup) {
                    $vouchers[] = $att;
                }
                if (count($vouchers) >= 20) {
                    break;
                }
            }
            if ($vouchers !== []) {
                $row['vouchers'] = $vouchers;
                $row['voucher'] = $vouchers[0];
            }
        }
        $out[] = $row;
        if (count($out) >= 100) {
            break;
        }
    }
    return $out;
}

/**
 * @param mixed $raw
 * @return array{source:string,path?:string,name:string,size?:int,mime?:string,id?:string,url?:string}|null
 */
function dkpi_normalize_attachment($raw): ?array
{
    if (!is_array($raw)) {
        return null;
    }
    $source = strtolower(trim((string) ($raw['source'] ?? '')));
    if ($source === '' && !empty($raw['id']) && empty($raw['path'])) {
        $source = 'system';
    }
    if ($source === '' && !empty($raw['path'])) {
        $source = 'upload';
    }
    if ($source === 'system') {
        $id = trim((string) ($raw['id'] ?? ''));
        if ($id === '') {
            return null;
        }
        $name = trim((string) ($raw['name'] ?? $raw['label'] ?? $id));
        if ($name === '') {
            $name = $id;
        }
        $url = trim((string) ($raw['url'] ?? ''));
        if ($url !== '' && (strpos($url, 'javascript:') === 0 || strpos($url, '..') !== false)) {
            $url = '';
        }
        return [
            'source' => 'system',
            'id' => mb_substr($id, 0, 64),
            'name' => mb_substr($name, 0, 180),
            'url' => mb_substr($url, 0, 400),
            'path' => '',
            'size' => 0,
            'mime' => '',
        ];
    }

    $path = trim((string) ($raw['path'] ?? ''));
    if ($path === '' || strpos($path, '..') !== false) {
        return null;
    }
    if (!preg_match('#^assets/uploads/driver-kpi/#', $path)) {
        return null;
    }
    $name = trim((string) ($raw['name'] ?? basename($path)));
    if ($name === '') {
        $name = basename($path);
    }
    return [
        'source' => 'upload',
        'path' => $path,
        'name' => mb_substr($name, 0, 180),
        'size' => max(0, (int) ($raw['size'] ?? 0)),
        'mime' => mb_substr(trim((string) ($raw['mime'] ?? 'application/octet-stream')), 0, 120),
        'url' => '',
        'id' => '',
    ];
}

function dkpi_public_base_path(): string
{
    $base = '';
    if (defined('APP_BASE_PATH')) {
        $base = rtrim((string) APP_BASE_PATH, '/');
    }
    return $base === '/' ? '' : $base;
}

/**
 * @return list<array{id:int,label:string,voucher_no:string,url:string}>
 */
function dkpi_list_selectable_vouchers(PDO $pdo, int $viewerId, bool $isAdmin): array
{
    if (!function_exists('tableExists') || !tableExists('payment_vouchers', $pdo)) {
        return [];
    }
    $sql = 'SELECT id, voucher_no, payee_name, total_amount, currency, status
            FROM payment_vouchers WHERE 1=1';
    $params = [];
    if (function_exists('companyScopeSql')) {
        try {
            [$frag, $cparams] = companyScopeSql('payment_vouchers', '');
            if (is_string($frag) && $frag !== '') {
                $sql .= $frag;
                $params = array_merge($params, is_array($cparams) ? $cparams : []);
            }
        } catch (Throwable $e) {
            // ignore scope helper failures
        }
    }
    if (!$isAdmin) {
        $sql .= ' AND created_by = ?';
        $params[] = $viewerId;
    }
    $sql .= ' ORDER BY id DESC LIMIT 100';
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    $base = dkpi_public_base_path();
    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $voucherNo = trim((string) ($row['voucher_no'] ?? ('#' . $id)));
        $payee = trim((string) ($row['payee_name'] ?? ''));
        $currency = trim((string) ($row['currency'] ?? ''));
        $amount = isset($row['total_amount']) ? number_format((float) $row['total_amount'], 2) : '';
        $label = $voucherNo;
        if ($payee !== '') {
            $label .= ' - ' . $payee;
        }
        if ($amount !== '') {
            $label .= ' (' . trim($currency . ' ' . $amount) . ')';
        }
        $out[] = [
            'id' => $id,
            'voucher_no' => $voucherNo,
            'label' => $label,
            'url' => $base . '/view-voucher.php?id=' . $id,
        ];
    }
    return $out;
}

/**
 * @return list<array{id:string,label:string,title:string,status:string,url:string}>
 */
function dkpi_list_selectable_letters(PDO $pdo, int $viewerId, bool $isAdmin): array
{
    $letterApproval = dirname(__DIR__, 2) . '/modules/letter/includes/letter-approval.php';
    if (is_file($letterApproval)) {
        require_once $letterApproval;
    }
    if (function_exists('letterApprovalEnsureTable')) {
        try {
            letterApprovalEnsureTable($pdo);
        } catch (Throwable $e) {
            return [];
        }
    }
    if (!function_exists('tableExists') || !tableExists('letter_documents', $pdo)) {
        return [];
    }
    $companyId = (int) ($_SESSION['company_id'] ?? 0);
    $companySlug = strtolower(trim((string) ($_SESSION['company_slug'] ?? '')));
    try {
        if ($isAdmin && $companyId > 0) {
            $st = $pdo->prepare(
                'SELECT id, title, status, author_name, updated_at
                 FROM letter_documents
                 WHERE company_id = ?
                 ORDER BY updated_at DESC
                 LIMIT 100'
            );
            $st->execute([$companyId]);
        } elseif ($isAdmin && $companySlug !== '') {
            $st = $pdo->prepare(
                'SELECT id, title, status, author_name, updated_at
                 FROM letter_documents
                 WHERE company_slug = ?
                 ORDER BY updated_at DESC
                 LIMIT 100'
            );
            $st->execute([$companySlug]);
        } else {
            $st = $pdo->prepare(
                'SELECT id, title, status, author_name, updated_at
                 FROM letter_documents
                 WHERE author_id = ?
                 ORDER BY updated_at DESC
                 LIMIT 100'
            );
            $st->execute([$viewerId]);
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    $base = dkpi_public_base_path();
    $out = [];
    foreach ($rows as $row) {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $title = trim((string) ($row['title'] ?? 'Untitled letter'));
        if ($title === '') {
            $title = 'Untitled letter';
        }
        $status = trim((string) ($row['status'] ?? ''));
        $author = trim((string) ($row['author_name'] ?? ''));
        $label = $title;
        if ($status !== '') {
            $label .= ' [' . $status . ']';
        }
        if ($author !== '') {
            $label .= ' - ' . $author;
        }
        $out[] = [
            'id' => $id,
            'title' => $title,
            'status' => $status,
            'label' => $label,
            'url' => $base . '/letter.php?desk=compose&id=' . rawurlencode($id),
        ];
    }
    return $out;
}

function dkpi_uploads_root(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'driver-kpi';
}

/**
 * Store one uploaded file for driver KPI attachments.
 *
 * @return array{path:string,name:string,size:int,mime:string}
 */
function dkpi_store_upload(array $file, int $userId, string $kind): array
{
    $kind = in_array($kind, ['voucher', 'letter', 'file', 'document'], true) ? $kind : 'file';
    if ($kind === 'file') {
        $kind = 'document';
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed for ' . $kind . '.');
    }
    $size = (int) ($file['size'] ?? 0);
    $max = 10 * 1024 * 1024;
    if ($size <= 0 || $size > $max) {
        throw new RuntimeException(ucfirst($kind) . ' must be between 1 byte and 10MB.');
    }
    $orig = (string) ($file['name'] ?? 'file');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx'];
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException(ucfirst($kind) . ' must be PDF, image, or Word document.');
    }
    $root = dkpi_uploads_root();
    $userDir = $root . DIRECTORY_SEPARATOR . max(1, $userId);
    if (!is_dir($userDir) && !@mkdir($userDir, 0775, true) && !is_dir($userDir)) {
        throw new RuntimeException('Could not create upload folder.');
    }
    $safeBase = preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($orig, PATHINFO_FILENAME));
    if ($safeBase === '' || $safeBase === null) {
        $safeBase = $kind;
    }
    $unique = $kind . '_' . $safeBase . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $destAbs = $userDir . DIRECTORY_SEPARATOR . $unique;
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp) || !@move_uploaded_file($tmp, $destAbs)) {
        throw new RuntimeException('Could not save ' . $kind . ' file.');
    }
    $mime = (string) ($file['type'] ?? 'application/octet-stream');
    if ($mime === '') {
        $mime = 'application/octet-stream';
    }
    return [
        'path' => 'assets/uploads/driver-kpi/' . max(1, $userId) . '/' . $unique,
        'name' => mb_substr($orig, 0, 180),
        'size' => $size,
        'mime' => mb_substr($mime, 0, 120),
    ];
}

function dkpi_hydrate_entry(?array $row): ?array
{
    if ($row === null) {
        return null;
    }
    $row['work_log'] = dkpi_normalize_work_log($row['work_log_json'] ?? []);
    return $row;
}

function dkpi_save_entry(
    PDO $pdo,
    int $userId,
    string $weekStart,
    float $onTime,
    float $vehicleCare,
    float $documentation,
    int $recordedBy,
    ?string $notes = null,
    string $serviceType = 'delivery',
    $workLog = null
): array {
    dkpi_ensure_tables($pdo);
    $serviceType = dkpi_normalize_service($serviceType);
    $bounds = dkpi_week_bounds($weekStart);
    $onTime = dkpi_clamp_pct($onTime);
    $vehicleCare = dkpi_clamp_pct($vehicleCare);
    $documentation = dkpi_clamp_pct($documentation);
    $computed = dkpi_compute_weighted_score($onTime, $vehicleCare, $documentation);
    $companyId = dkpi_company_id();
    $notes = $notes !== null ? trim($notes) : null;
    if ($notes === '') {
        $notes = null;
    }
    $workItems = dkpi_normalize_work_log($workLog);
    $workJson = $workItems === [] ? null : json_encode($workItems, JSON_UNESCAPED_UNICODE);

    $st = $pdo->prepare(
        'INSERT INTO driver_kpi_entries
            (user_id, company_id, recorded_by, service_type, week_start, week_end,
             on_time_pct, vehicle_care_pct, documentation_pct, weighted_score, notes, work_log_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            company_id = VALUES(company_id),
            recorded_by = VALUES(recorded_by),
            week_end = VALUES(week_end),
            on_time_pct = VALUES(on_time_pct),
            vehicle_care_pct = VALUES(vehicle_care_pct),
            documentation_pct = VALUES(documentation_pct),
            weighted_score = VALUES(weighted_score),
            notes = VALUES(notes),
            work_log_json = VALUES(work_log_json),
            updated_at = CURRENT_TIMESTAMP'
    );
    $st->execute([
        $userId,
        $companyId,
        $recordedBy,
        $serviceType,
        $bounds['week_start'],
        $bounds['week_end'],
        $onTime,
        $vehicleCare,
        $documentation,
        $computed['weighted_score'],
        $notes,
        $workJson,
    ]);

    $entry = dkpi_get_entry($pdo, $userId, $bounds['week_start'], $serviceType);
    if ($entry) {
        dkpi_sync_to_performance($pdo, $entry);
    }
    return $entry ?: [];
}

function dkpi_get_entry(PDO $pdo, int $userId, string $weekStart, string $serviceType = 'delivery'): ?array
{
    if (!dkpi_ensure_tables($pdo)) {
        return null;
    }
    $serviceType = dkpi_normalize_service($serviceType);
    $st = $pdo->prepare('SELECT * FROM driver_kpi_entries WHERE user_id = ? AND week_start = ? AND service_type = ? LIMIT 1');
    $st->execute([$userId, $weekStart, $serviceType]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return dkpi_hydrate_entry($row ?: null);
}

function dkpi_list_entries(PDO $pdo, string $weekStart, ?int $filterUserId = null, ?string $serviceType = null): array
{
    if (!dkpi_ensure_tables($pdo)) {
        return [];
    }
    $sql = 'SELECT e.*, u.full_name, u.department, u.role
            FROM driver_kpi_entries e
            INNER JOIN users u ON u.id = e.user_id
            WHERE e.week_start = ?';
    $params = [$weekStart];
    if ($serviceType !== null && $serviceType !== '') {
        $sql .= ' AND e.service_type = ?';
        $params[] = dkpi_normalize_service($serviceType);
    }
    if ($filterUserId !== null && $filterUserId > 0) {
        $sql .= ' AND e.user_id = ?';
        $params[] = $filterUserId;
    }
    $sql .= ' ORDER BY e.service_type ASC, e.weighted_score DESC, u.full_name ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(static function (array $row): array {
        return dkpi_hydrate_entry($row) ?? $row;
    }, $rows);
}

function dkpi_is_driver_department(?string $department): bool
{
    $dept = strtolower(trim((string) $department));
    return $dept === 'driver' || $dept === 'drivers';
}

function dkpi_user_department(PDO $pdo, int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    $cols = 'department';
    if (function_exists('ensureUsersExtraRolesColumn')) {
        ensureUsersExtraRolesColumn($pdo);
    }
    if (function_exists('columnExists') && columnExists('users', 'extra_roles', $pdo)) {
        $cols .= ', extra_roles';
    }
    $st = $pdo->prepare("SELECT {$cols} FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return trim((string) ($row['department'] ?? ''));
}

function dkpi_user_is_driver(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    if ($userId === (int) ($_SESSION['user_id'] ?? 0) && function_exists('userHasAccessRole')) {
        return userHasAccessRole('Driver');
    }
    $cols = 'department';
    if (function_exists('ensureUsersExtraRolesColumn')) {
        ensureUsersExtraRolesColumn($pdo);
    }
    if (function_exists('columnExists') && columnExists('users', 'extra_roles', $pdo)) {
        $cols .= ', extra_roles';
    }
    $st = $pdo->prepare("SELECT {$cols} FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return false;
    }
    if (function_exists('userAccessRolesFromParts')) {
        foreach (userAccessRolesFromParts((string) ($row['department'] ?? ''), $row['extra_roles'] ?? null) as $role) {
            if (dkpi_is_driver_department($role)) {
                return true;
            }
        }
        return false;
    }
    return dkpi_is_driver_department((string) ($row['department'] ?? ''));
}

function dkpi_list_employees(PDO $pdo): array
{
    $hasExtra = false;
    if (function_exists('ensureUsersExtraRolesColumn')) {
        ensureUsersExtraRolesColumn($pdo);
    }
    if (function_exists('columnExists') && columnExists('users', 'extra_roles', $pdo)) {
        $hasExtra = true;
    }
    $select = $hasExtra
        ? 'id, full_name, department, role, extra_roles'
        : 'id, full_name, department, role';
    $st = $pdo->query(
        "SELECT {$select}
         FROM users
         WHERE is_active = 1
           AND LOWER(TRIM(COALESCE(role,''))) NOT IN ('admin','administrator','superadmin','super_admin','company_admin')
         ORDER BY full_name ASC"
    );
    $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $out = [];
    foreach ($rows as $row) {
        $roles = function_exists('userAccessRolesFromParts')
            ? userAccessRolesFromParts((string) ($row['department'] ?? ''), $row['extra_roles'] ?? null)
            : [trim((string) ($row['department'] ?? ''))];
        $isDriver = false;
        foreach ($roles as $role) {
            if (dkpi_is_driver_department($role)) {
                $isDriver = true;
                break;
            }
        }
        if ($isDriver) {
            unset($row['extra_roles']);
            $out[] = $row;
        }
    }
    return $out;
}

function dkpi_module_scores_for_week(PDO $pdo, string $weekStart, ?string $source = null): array
{
    if (!function_exists('tableExists') || !tableExists('performance_module_scores', $pdo)) {
        return [];
    }
    if ($source !== null && $source !== '') {
        $st = $pdo->prepare(
            'SELECT * FROM performance_module_scores WHERE week_start = ? AND source_module = ?'
        );
        $st->execute([$weekStart, $source]);
    } else {
        $st = $pdo->prepare(
            "SELECT * FROM performance_module_scores
             WHERE week_start = ?
               AND (source_module = 'driver_kpi' OR source_module LIKE 'driver_kpi_%')"
        );
        $st->execute([$weekStart]);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byUser = [];
    foreach ($rows as $row) {
        $uid = (int) $row['user_id'];
        if (!isset($byUser[$uid])) {
            $byUser[$uid] = [
                'user_id' => $uid,
                'scores' => [],
                'score_pct' => 0.0,
                'payload_json' => $row['payload_json'] ?? null,
                'source_module' => 'driver_kpi',
            ];
        }
        $byUser[$uid]['scores'][] = $row;
    }
    foreach ($byUser as &$agg) {
        $sum = 0.0;
        $n = 0;
        $combined = [
            'on_time_pct' => 0.0,
            'vehicle_care_pct' => 0.0,
            'documentation_pct' => 0.0,
            'services' => [],
        ];
        foreach ($agg['scores'] as $scoreRow) {
            $sum += (float) ($scoreRow['score_pct'] ?? 0);
            $n++;
            $detail = dkpi_entry_from_score_row($scoreRow);
            $svc = (string) ($detail['service_type'] ?? 'delivery');
            $combined['services'][$svc] = $detail;
            $combined['on_time_pct'] += (float) ($detail['on_time_pct'] ?? 0);
            $combined['vehicle_care_pct'] += (float) ($detail['vehicle_care_pct'] ?? 0);
            $combined['documentation_pct'] += (float) ($detail['documentation_pct'] ?? 0);
        }
        if ($n > 0) {
            $agg['score_pct'] = round($sum / $n, 2);
            $combined['on_time_pct'] = round($combined['on_time_pct'] / $n, 2);
            $combined['vehicle_care_pct'] = round($combined['vehicle_care_pct'] / $n, 2);
            $combined['documentation_pct'] = round($combined['documentation_pct'] / $n, 2);
            $combined['score_pct'] = $agg['score_pct'];
            $agg['payload_json'] = json_encode($combined, JSON_UNESCAPED_UNICODE);
        }
    }
    unset($agg);
    return $byUser;
}

function dkpi_entry_from_score_row(array $scoreRow): ?array
{
    $payload = [];
    if (!empty($scoreRow['payload_json'])) {
        $decoded = json_decode((string) $scoreRow['payload_json'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    $source = (string) ($scoreRow['source_module'] ?? 'driver_kpi');
    $service = (string) ($payload['service_type'] ?? '');
    if ($service === '' && str_starts_with($source, 'driver_kpi_')) {
        $service = substr($source, strlen('driver_kpi_'));
    }
    if ($service === '') {
        $service = 'delivery';
    }
    return [
        'score_pct' => (float) ($scoreRow['score_pct'] ?? $payload['score_pct'] ?? 0),
        'service_type' => dkpi_normalize_service($service),
        'on_time_pct' => (float) ($payload['on_time_pct'] ?? 0),
        'vehicle_care_pct' => (float) ($payload['vehicle_care_pct'] ?? 0),
        'documentation_pct' => (float) ($payload['documentation_pct'] ?? 0),
        'source_module' => $source,
        'services' => is_array($payload['services'] ?? null) ? $payload['services'] : null,
    ];
}
