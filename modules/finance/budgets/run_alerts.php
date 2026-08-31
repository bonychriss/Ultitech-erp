<?php
/**
 * Budget alerts runner.
 *
 * - Threshold: actual >= configured alert_threshold_percent of budget (existing behaviour).
 * - Pacing: spend % runs ahead of linear time-based pace (non-progressive / burn too fast).
 *
 * Recommended cron (example):
 *   php c:\xampp\htdocs\staff\modules\finance\budgets\run_alerts.php
 */
require_once __DIR__ . '/lib.php';
requireFinanceOrAdmin();

$periodType = $_GET['period_type'] ?? 'monthly';
if (!in_array($periodType, ['monthly', 'quarterly', 'yearly'], true)) $periodType = 'monthly';
$periodKey = $_GET['period'] ?? ($periodType === 'monthly' ? date('Y-m') : ($periodType === 'yearly' ? date('Y') : (date('Y') . '-Q' . (int)ceil(((int)date('n')) / 3))));
[$periodStart, $periodEnd] = budget_parse_period($periodType, (string)$periodKey);

$pacingMarginPp = isset($_GET['pacing_margin']) ? (float) $_GET['pacing_margin'] : 15.0;
if ($pacingMarginPp < 5.0) {
    $pacingMarginPp = 5.0;
}
if ($pacingMarginPp > 60.0) {
    $pacingMarginPp = 60.0;
}

$sent = 0;
$skipped = 0;
$errors = 0;

$sql = "
    SELECT bi.*, b.name AS budget_name, b.currency, b.period_type, b.created_by AS budget_created_by
    FROM budget_items bi
    JOIN budgets b ON bi.budget_id = b.id
    WHERE b.is_active = 1 AND bi.is_active = 1 AND bi.alert_email IS NOT NULL AND bi.alert_email <> ''
";
$items = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$chkSt = $pdo->prepare('SELECT id FROM budget_alerts WHERE budget_item_id = ? AND period_start = ? AND period_end = ? AND sent_to = ? AND alert_kind = ? LIMIT 1');
$insSt = $pdo->prepare('INSERT INTO budget_alerts (budget_item_id, period_start, period_end, spent_percent, sent_to, alert_kind) VALUES (?,?,?,?,?,?)');

foreach ($items as $it) {
    $iid = (int) $it['id'];
    $budgeted = (float) ($it['budgeted_amount'] ?? 0);
    if ($budgeted <= 0) {
        $skipped++;
        continue;
    }

    $to = trim((string)($it['alert_email'] ?? ''));
    if ($to === '') {
        $skipped++;
        continue;
    }

    $actual = budget_compute_item_actual($iid, $periodStart, $periodEnd);
    $spentPct = budget_compute_variance_percent($budgeted, $actual);
    $threshold = (float) ($it['alert_threshold_percent'] ?? 90);
    $currency = (string)($it['currency'] ?? 'TZS');
    $budgetIdForLink = (int)($it['budget_id'] ?? 0);
    $link = $budgetIdForLink > 0 ? budget_link_open($budgetIdForLink, $periodType, $periodKey) : null;
    $lineName = (string)($it['item_name'] ?? 'Budget item');
    $emailUserId = budget_find_user_id_by_email($to);

    // --- Threshold alert (at % of budget) ---
    if ($spentPct >= $threshold) {
        $chkSt->execute([$iid, $periodStart, $periodEnd, $to, 'threshold']);
        if ($chkSt->fetchColumn()) {
            $skipped++;
        } else {
            $inAppTitle = 'Budget threshold: ' . $lineName;
            $inAppMsg = sprintf(
                '%s is at %s%% of budget (threshold %s%%). %s %s actual of %s budgeted for %s to %s.',
                $lineName,
                number_format($spentPct, 1),
                number_format($threshold, 1),
                $currency,
                number_format($actual, 2),
                number_format($budgeted, 2),
                $periodStart,
                $periodEnd
            );

            $notifyUids = [];
            if ($emailUserId) {
                $notifyUids[$emailUserId] = true;
            }
            $cb = (int)($it['budget_created_by'] ?? 0);
            if ($cb > 0) {
                $notifyUids[$cb] = true;
            }
            $inAppOk = false;
            foreach (array_keys($notifyUids) as $uid) {
                budget_notify_system($uid, $inAppTitle, $inAppMsg, $link, 'warning');
                $inAppOk = true;
            }
            if (!$inAppOk) {
                $inAppOk = budget_notify_admin_fallback($inAppTitle, $inAppMsg, $link);
            }

            $subject = 'Budget Alert: ' . ($it['item_name'] ?? 'Budget item') . ' at ' . number_format($spentPct, 1) . '%';
            $body = '
        <div style="font-family:Arial,sans-serif;max-width:680px;margin:0 auto;">
            <h2 style="margin:0 0 8px;color:#111827;">Budget threshold alert</h2>
            <p style="margin:0 0 10px;color:#374151;">
                The budget item <strong>' . h($it['item_name'] ?? '') . '</strong> has reached <strong>' . number_format($spentPct, 1) . '%</strong> for the period
                <strong>' . h($periodStart) . '</strong> to <strong>' . h($periodEnd) . '</strong>.
            </p>
            <table cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;border:1px solid #e5e7eb;">
                <tr style="background:#f9fafb;">
                    <td style="border-bottom:1px solid #e5e7eb;color:#6b7280;">Budgeted</td>
                    <td style="border-bottom:1px solid #e5e7eb;text-align:right;"><strong>' . h($currency) . ' ' . number_format($budgeted, 2) . '</strong></td>
                </tr>
                <tr>
                    <td style="border-bottom:1px solid #e5e7eb;color:#6b7280;">Actual spent</td>
                    <td style="border-bottom:1px solid #e5e7eb;text-align:right;"><strong>' . h($currency) . ' ' . number_format($actual, 2) . '</strong></td>
                </tr>
                <tr>
                    <td style="color:#6b7280;">Threshold</td>
                    <td style="text-align:right;"><strong>' . number_format($threshold, 1) . '%</strong></td>
                </tr>
            </table>
            <p style="margin:12px 0 0;color:#6b7280;font-size:12px;">
                This is an automated alert from the ERP Budget module.
            </p>
        </div>
    ';

            $emailOk = false;
            try {
                $emailOk = (bool) sendEmail($to, $subject, $body, true);
            } catch (Throwable $e) {
                // email failed; in-app may still have succeeded
            }
            if ($emailOk || $inAppOk) {
                try {
                    $insSt->execute([$iid, $periodStart, $periodEnd, $spentPct, $to, 'threshold']);
                    $sent++;
                } catch (Throwable $e) {
                    $errors++;
                }
            } else {
                $errors++;
            }
        }
    }

    // --- Pacing alert (spend ahead of calendar / non-linear burn) ---
    if (budget_pacing_ahead_of_schedule($budgeted, $actual, $periodStart, $periodEnd, $pacingMarginPp)) {
        $elapsedFrac = budget_period_elapsed_fraction($periodStart, $periodEnd);
        $expectedLinearPct = $elapsedFrac * 100.0;

        $chkSt->execute([$iid, $periodStart, $periodEnd, $to, 'pacing']);
        if ($chkSt->fetchColumn()) {
            $skipped++;
        } else {
            $paceTitle = 'Budget pacing: ' . $lineName;
            $paceMsg = sprintf(
                'Spending is ahead of schedule: %s is at %s%% of budget but only about %s%% through the period (%s to %s). At a steady pace you would expect roughly %s%% used by now (margin %s%%). %s %s actual of %s budgeted.',
                $lineName,
                number_format($spentPct, 1),
                number_format($elapsedFrac * 100.0, 1),
                $periodStart,
                $periodEnd,
                number_format($expectedLinearPct, 1),
                number_format($pacingMarginPp, 0),
                $currency,
                number_format($actual, 2),
                number_format($budgeted, 2)
            );

            $notifyUidsP = [];
            if ($emailUserId) {
                $notifyUidsP[$emailUserId] = true;
            }
            $cbp = (int)($it['budget_created_by'] ?? 0);
            if ($cbp > 0) {
                $notifyUidsP[$cbp] = true;
            }
            $inAppOkP = false;
            foreach (array_keys($notifyUidsP) as $uid) {
                budget_notify_system($uid, $paceTitle, $paceMsg, $link, 'danger');
                $inAppOkP = true;
            }
            if (!$inAppOkP) {
                $inAppOkP = budget_notify_admin_fallback($paceTitle, $paceMsg, $link);
            }

            $subjectP = 'Budget pacing: ' . $lineName . ' ahead of schedule';
            $bodyP = '
        <div style="font-family:Arial,sans-serif;max-width:680px;margin:0 auto;">
            <h2 style="margin:0 0 8px;color:#111827;">Budget pacing alert</h2>
            <p style="margin:0 0 10px;color:#374151;">
                <strong>' . h($lineName) . '</strong> has used <strong>' . number_format($spentPct, 1) . '%</strong> of the budget while the period is only about
                <strong>' . number_format($elapsedFrac * 100.0, 1) . '%</strong> complete (<strong>' . h($periodStart) . '</strong> to <strong>' . h($periodEnd) . '</strong>).
                A linear pace would be near <strong>' . number_format($expectedLinearPct, 1) . '%</strong> by now.
            </p>
            <p style="margin:0;color:#374151;font-size:14px;">Actual: <strong>' . h($currency) . ' ' . number_format($actual, 2) . '</strong> of <strong>' . h($currency) . ' ' . number_format($budgeted, 2) . '</strong> budgeted.</p>
            <p style="margin:12px 0 0;color:#6b7280;font-size:12px;">Automated message from the ERP Budget module.</p>
        </div>
    ';

            $emailOkP = false;
            try {
                $emailOkP = (bool) sendEmail($to, $subjectP, $bodyP, true);
            } catch (Throwable $e) {
                // ignore
            }
            if ($emailOkP || $inAppOkP) {
                try {
                    $insSt->execute([$iid, $periodStart, $periodEnd, $spentPct, $to, 'pacing']);
                    $sent++;
                } catch (Throwable $e) {
                    $errors++;
                }
            } else {
                $errors++;
            }
        }
    }
}

header('Content-Type: application/json');
echo json_encode([
    'period' => ['type' => $periodType, 'key' => $periodKey, 'start' => $periodStart, 'end' => $periodEnd],
    'pacing_margin_pp' => $pacingMarginPp,
    'sent' => $sent,
    'skipped' => $skipped,
    'errors' => $errors,
]);
