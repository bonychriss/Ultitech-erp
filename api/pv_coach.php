<?php
/**
 * Pending payment-voucher coach payload for the logged-in user.
 * GET ? { ok, count, fingerprint, title, body, action, href, secondary, badge, tasks[] }
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once dirname(__DIR__) . '/includes/functions.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth', 'count' => 0, 'tasks' => []]);
    exit;
}

global $pdo;
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db', 'count' => 0, 'tasks' => []]);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$userName = function_exists('resolveVoucherSessionDisplayName')
    ? resolveVoucherSessionDisplayName($pdo)
    : trim((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));

// Drop highlight/unread on action notifs whose voucher no longer needs this user.
if (function_exists('reconcileStalePaymentVoucherActionNotificationsForUser')) {
    try {
        reconcileStalePaymentVoucherActionNotificationsForUser($userId);
    } catch (Throwable $e) {
        error_log('api/pv_coach.php reconcile: ' . $e->getMessage());
    }
}

$tasks = [];
try {
    if (function_exists('getPendingPaymentVoucherTasks')) {
        $tasks = getPendingPaymentVoucherTasks($pdo, $userId, $userName, 8);
    }
} catch (Throwable $e) {
    error_log('api/pv_coach.php: ' . $e->getMessage());
    $tasks = [];
}

if (!is_array($tasks)) {
    $tasks = [];
}

$count = count($tasks);

// Fallback: if turn detection returns nothing, still tip from unread action notifications.
if ($count === 0) {
    try {
        if (function_exists('ensureNotificationsSchema')) {
            ensureNotificationsSchema();
        }
        $st = $pdo->prepare(
            "SELECT id, title, message, voucher_id
             FROM notifications
             WHERE is_read = 0
               AND user_id = ?
               AND voucher_id IS NOT NULL AND voucher_id > 0
               AND (
                    LOWER(title) LIKE '%sign%'
                 OR LOWER(title) LIKE '%approve%'
                 OR LOWER(title) LIKE '%check%'
                 OR LOWER(title) LIKE '%paid%'
                 OR LOWER(title) LIKE '%post%'
                 OR LOWER(message) LIKE '%sign%'
                 OR LOWER(message) LIKE '%waiting%'
               )
             ORDER BY id DESC
             LIMIT 5"
        );
        $st->execute([$userId]);
        $notifs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($notifs as $n) {
            $vid = (int) ($n['voucher_id'] ?? 0);
            if ($vid <= 0) {
                continue;
            }
            $viewUrl = function_exists('company_url')
                ? company_url('employee/view-voucher.php?id=' . $vid . '&module=voucher')
                : (function_exists('app_url') ? app_url('/employee/view-voucher.php?id=' . $vid . '&module=voucher') : '/employee/view-voucher.php?id=' . $vid);
            $vno = '';
            try {
                $vs = $pdo->prepare('SELECT voucher_no FROM payment_vouchers WHERE id = ? LIMIT 1');
                $vs->execute([$vid]);
                $vno = (string) ($vs->fetchColumn() ?: '');
            } catch (Throwable $e2) {
            }
            $tasks[] = [
                'id' => $vid,
                'voucher_no' => $vno,
                'payee_name' => '',
                'total_amount' => 0,
                'currency' => 'TZS',
                'status' => '',
                'date_created' => '',
                'prepared_by' => '',
                'required_action' => (string) ($n['title'] ?? 'Action needed'),
                'action_key' => 'notify',
                'action_label' => trim((string) ($n['title'] ?? 'Open payment voucher')),
                'view_url' => $viewUrl,
            ];
        }
        $count = count($tasks);
    } catch (Throwable $e) {
        error_log('api/pv_coach.php notif fallback: ' . $e->getMessage());
    }
}

$preferredKeys = ['sign_applicant', 'sign_dept_manager', 'sign_checked_by', 'final_approve', 'mark_paid', 'post', 'notify'];
usort($tasks, static function ($a, $b) use ($preferredKeys) {
    $ai = array_search((string) ($a['action_key'] ?? ''), $preferredKeys, true);
    $bi = array_search((string) ($b['action_key'] ?? ''), $preferredKeys, true);
    $ai = $ai === false ? 99 : $ai;
    $bi = $bi === false ? 99 : $bi;
    if ($ai === $bi) {
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    }
    return $ai <=> $bi;
});

$first = $count > 0 ? $tasks[0] : null;
$actionLabel = $first ? trim((string) ($first['action_label'] ?? '')) : '';
$voucherNo = $first ? trim((string) ($first['voucher_no'] ?? '')) : '';
$viewUrl = $first ? (string) ($first['view_url'] ?? '') : '';
$tasksUrl = function_exists('company_url')
    ? (company_url('employee/pending-voucher-tasks.php') . '?module=voucher')
    : (function_exists('app_url')
        ? (app_url('/employee/pending-voucher-tasks.php') . '?module=voucher')
        : '/employee/pending-voucher-tasks.php?module=voucher');

$fpParts = [];
$actionKeys = [];
foreach ($tasks as $t) {
    $fpParts[] = ((int) ($t['id'] ?? 0)) . ':' . (string) ($t['action_key'] ?? '');
    $ak = (string) ($t['action_key'] ?? '');
    if ($ak !== '') {
        $actionKeys[$ak] = true;
    }
}
$actionKeys = array_keys($actionKeys);

/**
 * Friendly coach body based on the actions this user actually has.
 *
 * @param list<string> $keys
 */
$buildCoachBody = static function (array $keys, int $count, string $voucherNo, string $actionLabel): string {
    $hasSign = (bool) array_intersect($keys, ['sign_applicant', 'sign_dept_manager', 'sign_checked_by', 'notify']);
    $hasFinal = in_array('final_approve', $keys, true);
    $hasPaid = in_array('mark_paid', $keys, true);
    $hasPost = in_array('post', $keys, true);

    $primaryKey = $keys[0] ?? '';
    $vLabel = $voucherNo !== '' ? ('voucher ' . $voucherNo) : 'this voucher';
    $vLabelCap = $voucherNo !== '' ? ('Voucher ' . $voucherNo) : 'A payment voucher';

    if ($count === 1) {
        switch ($primaryKey) {
            case 'sign_applicant':
                return '1 voucher remaining — ' . $vLabelCap . ' still needs your signature as Applicant.';
            case 'sign_dept_manager':
                return '1 voucher remaining — ' . $vLabelCap . ' still needs your Department Manager approval.';
            case 'sign_checked_by':
                return '1 voucher remaining — ' . $vLabelCap . ' still needs you to check and sign.';
            case 'final_approve':
                return '1 voucher remaining — ' . $vLabelCap . ' is ready for your final approval.';
            case 'mark_paid':
                return '1 voucher remaining — ' . $vLabelCap . ' is approved and ready to mark as paid.';
            case 'post':
                return '1 voucher remaining — ' . $vLabelCap . ' is paid and ready for you to post.';
            default:
                if ($actionLabel !== '') {
                    return '1 voucher remaining — ' . $vLabelCap . ' still needs: ' . $actionLabel . '.';
                }
                return '1 voucher remaining — open ' . $vLabel . ' to finish.';
        }
    }

    // Multiple tasks — lead with remaining count so progress is clear after each sign.
    $parts = [];
    if ($hasSign) {
        $parts[] = 'sign';
    }
    if ($hasFinal) {
        $parts[] = 'give final approval';
    }
    if ($hasPaid) {
        $parts[] = 'mark as paid';
    }
    if ($hasPost) {
        $parts[] = 'post';
    }
    if ($parts === []) {
        $parts[] = 'review';
    }

    if (count($parts) === 1) {
        $work = $parts[0];
    } elseif (count($parts) === 2) {
        $work = $parts[0] . ' or ' . $parts[1];
    } else {
        $last = array_pop($parts);
        $work = implode(', ', $parts) . ', or ' . $last;
    }

    $start = $voucherNo !== '' ? ('Start with ' . $vLabel) : 'Start with the next one';
    if ($actionLabel !== '') {
        $start .= ' (' . $actionLabel . ')';
    }

    return $count . ' vouchers remaining for you to ' . $work . '. ' . $start . '.';
};

$titleForKey = static function (string $key, string $actionLabel, int $count, array $allKeys = []): string {
    if ($count > 1) {
        $unique = array_values(array_unique($allKeys));
        // Only use a specific title when every pending task is the same kind of work.
        if (count($unique) === 1 && isset([
            'sign_applicant' => 1,
            'sign_dept_manager' => 1,
            'sign_checked_by' => 1,
            'final_approve' => 1,
            'mark_paid' => 1,
            'post' => 1,
        ][$unique[0]])) {
            $map = [
                'sign_applicant' => 'Signatures needed',
                'sign_dept_manager' => 'Approvals needed',
                'sign_checked_by' => 'Vouchers to check',
                'final_approve' => 'Final approvals needed',
                'mark_paid' => 'Vouchers to mark paid',
                'post' => 'Vouchers to post',
            ];
            return $map[$unique[0]];
        }
        return $count . ' payment vouchers need you';
    }
    if ($actionLabel !== '') {
        return $actionLabel;
    }
    return 'Payment voucher needs you';
};

if ($count >= 1) {
    $primaryKey = (string) ($first['action_key'] ?? '');
    $uniqueKeys = array_values(array_unique($actionKeys));

    $signFamily = ['sign_applicant', 'sign_dept_manager', 'sign_checked_by', 'notify'];
    $onlySigning = $uniqueKeys !== [] && empty(array_diff($uniqueKeys, $signFamily));
    $onlyPaid = $uniqueKeys === ['mark_paid'];
    $onlyPost = $uniqueKeys === ['post'];
    $onlyFinal = $uniqueKeys === ['final_approve'];

    // Body should only describe the work reflected in the title.
    $bodyKeys = $actionKeys;
    if ($onlySigning) {
        $bodyKeys = array_values(array_intersect($actionKeys, $signFamily));
        $title = 'Signatures needed';
    } elseif ($onlyFinal) {
        $bodyKeys = ['final_approve'];
        $title = 'Final approvals needed';
    } elseif ($onlyPaid) {
        $bodyKeys = ['mark_paid'];
        $title = 'Vouchers to mark paid';
    } elseif ($onlyPost) {
        $bodyKeys = ['post'];
        $title = 'Vouchers to post';
    } else {
        $title = $titleForKey($primaryKey, $actionLabel, $count, $actionKeys);
    }

    $body = $buildCoachBody($bodyKeys, $count, $voucherNo, $actionLabel);
    // Single voucher ? open it. Multiple ? open Notifications and highlight cards.
    $actionText = $count === 1 ? 'Open voucher' : 'View';
    $href = ($count === 1 && $viewUrl !== '') ? $viewUrl : '';
    $openNotifications = $count > 1;
} else {
    $title = '';
    $body = '';
    $actionText = '';
    $href = $tasksUrl;
    $openNotifications = false;
}

$voucherIds = [];
$voucherNos = [];
foreach ($tasks as $t) {
    $vid = (int) ($t['id'] ?? 0);
    if ($vid > 0) {
        $voucherIds[] = $vid;
    }
    $vno = trim((string) ($t['voucher_no'] ?? ''));
    if ($vno !== '') {
        $voucherNos[] = strtoupper($vno);
    }
}

echo json_encode([
    'ok' => true,
    'count' => $count,
    'fingerprint' => implode('|', $fpParts),
    'userId' => $userId,
    'userName' => $userName,
    'title' => $title,
    'body' => $body,
    'action' => $actionText,
    'href' => $href,
    'openNotifications' => $openNotifications,
    'voucherIds' => array_values(array_unique($voucherIds)),
    'voucherNos' => array_values(array_unique($voucherNos)),
    'secondary' => 'Got it',
    'badge' => $count > 99 ? '99+' : (string) $count,
    'tasks' => array_map(static function ($t) {
        return [
            'id' => (int) ($t['id'] ?? 0),
            'voucher_no' => (string) ($t['voucher_no'] ?? ''),
            'action_key' => (string) ($t['action_key'] ?? ''),
            'action_label' => (string) ($t['action_label'] ?? ''),
            'view_url' => (string) ($t['view_url'] ?? ''),
        ];
    }, $tasks),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
