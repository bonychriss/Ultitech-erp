<?php
/**
 * Notification rows (AI Notification Center reference layout).
 * Expects: $ncItems (list of rows from getNotificationCentreFeedPaged or merged header shape)
 */
if (!isset($ncItems) || !is_array($ncItems)) {
    $ncItems = [];
}

if (!function_exists('nc_is_voucher_action_notification')) {
    /**
     * True when the notification asks the user to sign / approve / check / pay / post a voucher.
     */
    function nc_is_voucher_action_notification(array $n): bool
    {
        $blob = strtolower(trim((string) ($n['title'] ?? '') . ' ' . (string) ($n['message'] ?? '')));
        if ($blob === '') {
            return false;
        }
        if (preg_match('/\b(sign payment voucher|sign as applicant|sign as department|sign as checked|approve as department|check payment voucher|voucher requires checking|final approval needed|mark voucher as paid|mark as paid|post payment voucher|post voucher)\b/', $blob)) {
            return true;
        }
        if (preg_match('/\b(please open voucher|waiting for you to sign|ready for your signature|needs your (signature|approval)|sign as)\b/', $blob)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('nc_voucher_action_rank')) {
    /**
     * Lower = higher in the list. Sign/check/approve before paid/post before other items.
     */
    function nc_voucher_action_rank(array $n): int
    {
        $blob = strtolower(trim((string) ($n['title'] ?? '') . ' ' . (string) ($n['message'] ?? '')));
        if (preg_match('/\bsign as applicant|sign payment voucher\b/', $blob)) {
            return 0;
        }
        if (preg_match('/\bsign as department|approve as department\b/', $blob)) {
            return 1;
        }
        if (preg_match('/\bsign as checked|check payment|requires checking\b/', $blob)) {
            return 2;
        }
        if (preg_match('/\bfinal approval\b/', $blob)) {
            return 3;
        }
        if (preg_match('/\bmark.*paid\b/', $blob)) {
            return 4;
        }
        if (preg_match('/\bpost (payment )?voucher\b/', $blob)) {
            return 5;
        }
        if (nc_is_voucher_action_notification($n)) {
            return 6;
        }

        return 100;
    }
}

if (!function_exists('nc_sort_action_notifications_first')) {
    /**
     * Keep all notifications, but put voucher action items (sign/approve/…) on top.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    function nc_sort_action_notifications_first(array $items): array
    {
        if (count($items) < 2) {
            return $items;
        }

        $indexed = [];
        foreach ($items as $i => $n) {
            $indexed[] = ['i' => $i, 'n' => $n];
        }

        usort($indexed, static function ($a, $b) {
            $na = $a['n'];
            $nb = $b['n'];
            $ra = nc_voucher_action_rank($na);
            $rb = nc_voucher_action_rank($nb);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            $ua = (int) ($na['is_read'] ?? 0) === 0 ? 0 : 1;
            $ub = (int) ($nb['is_read'] ?? 0) === 0 ? 0 : 1;
            if ($ua !== $ub) {
                return $ua <=> $ub;
            }

            $ta = strtotime((string) ($na['created_at'] ?? '')) ?: 0;
            $tb = strtotime((string) ($nb['created_at'] ?? '')) ?: 0;
            if ($ta !== $tb) {
                return $tb <=> $ta; // newest first within same bucket
            }

            return $a['i'] <=> $b['i'];
        });

        $out = [];
        foreach ($indexed as $row) {
            $out[] = $row['n'];
        }

        return $out;
    }
}

$ncItems = nc_sort_action_notifications_first($ncItems);

if (!function_exists('nc_format_notification_message')) {
    function nc_format_notification_message(string $message): string
    {
        $esc = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        // Bold numbers, percents, and compact time-like tokens for emphasis
        $esc = preg_replace('/\b(\d+(?:\.\d+)?%|\d{1,3}(?:,\d{3})+(?:\+)?|\d+\+)\b/', '<span class="nc-msg-highlight">$1</span>', $esc);
        $esc = preg_replace('/\b(\d{1,2}:\d{2}\s*(?:AM|PM)?(?:\s*UTC)?)\b/i', '<span class="nc-msg-highlight">$1</span>', $esc);
        $esc = preg_replace('/\bfor\s+([A-Za-z0-9][A-Za-z0-9\s.&\-]*?)\s*\(/i', 'for <span class="nc-msg-highlight">$1</span> (', $esc);
        $esc = preg_replace('/\(([^)]+)\)/', '(<span class="nc-msg-highlight">$1</span>)', $esc);

        return nl2br($esc, false);
    }
}

if (!function_exists('nc_relative_time')) {
    function nc_relative_time($createdAt): string
    {
        $ts = is_numeric($createdAt) ? (int) $createdAt : strtotime((string) $createdAt);
        if (!$ts) {
            return '';
        }
        $startToday = strtotime('today');
        $startYesterday = strtotime('yesterday');
        $diff = max(0, time() - $ts);
        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            $m = (int) floor($diff / 60);

            return $m . 'm ago';
        }
        if ($ts >= $startToday) {
            $h = (int) floor($diff / 3600);

            return $h . 'h ago';
        }
        if ($ts >= $startYesterday) {
            return date('g:i A', $ts);
        }
        if ($diff < 604800) {
            $d = (int) floor($diff / 86400);

            return $d . 'd ago';
        }

        return date('M j', $ts);
    }
}

if (!function_exists('nc_card_period')) {
    /** @return 'today'|'yesterday'|'week'|'earlier' */
    function nc_card_period($createdAt): string
    {
        $ts = is_numeric($createdAt) ? (int) $createdAt : strtotime((string) $createdAt);
        if (!$ts) {
            return 'earlier';
        }
        $startToday = strtotime('today');
        $startYesterday = strtotime('yesterday');
        $startWeek = strtotime('monday this week');
        if ($startWeek === false || $startWeek > $startToday) {
            $startWeek = strtotime('-6 days', $startToday);
        }
        if ($ts >= $startToday) {
            return 'today';
        }
        if ($ts >= $startYesterday) {
            return 'yesterday';
        }
        if ($ts >= $startWeek) {
            return 'week';
        }

        return 'earlier';
    }
}

if (!function_exists('nc_voucher_rejection_reason')) {
    function nc_voucher_rejection_reason(array $n): string
    {
        $msg = trim((string) ($n['message'] ?? ''));
        if (preg_match('/\bReason:\s*(.+)$/is', $msg, $m)) {
            $fromMsg = trim((string) ($m[1] ?? ''));
            if ($fromMsg !== '' && !preg_match('/^quick rejected/i', $fromMsg)) {
                return $fromMsg;
            }
        }

        $vid = 0;
        if (function_exists('nc_guess_voucher_id_from_notification')) {
            $vid = (int) nc_guess_voucher_id_from_notification($n);
        } else {
            $vid = (int) ($n['voucher_id'] ?? 0);
        }
        if ($vid <= 0) {
            return '';
        }

        global $pdo;
        if (!($pdo instanceof PDO)) {
            return '';
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT comments FROM approval_logs
                 WHERE voucher_id = ? AND LOWER(action) = 'rejected'
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$vid]);
            $comments = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($comments === '' || preg_match('/^quick rejected/i', $comments)) {
                return '';
            }

            return $comments;
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('nc_card_coach')) {
    /**
     * Google-style next-step tip shown after opening a notification.
     * @return array{title:string,body:string,action:string}
     */
    function nc_card_coach(array $n): array
    {
        $title = strtolower(trim((string) ($n['title'] ?? '')));
        $blob = $title . ' ' . strtolower((string) ($n['message'] ?? ''));

        if (str_contains($title, 'voucher rejected') || preg_match('/\bvoucher\s+rejected\b/', $blob)) {
            $reason = nc_voucher_rejection_reason($n);
            $body = $reason !== ''
                ? ('Rejection reason: ' . $reason . "\n\nOpen the voucher, fix what was flagged, then resubmit it.")
                : 'Open the voucher to review why it was rejected, fix the issues, then resubmit it.';

            return [
                'title' => 'Voucher was rejected',
                'body' => $body,
                'action' => 'Got it',
            ];
        }

        if (str_contains($title, 'voucher approved') || preg_match('/\bvoucher\s+approved\b/', $blob)) {
            return [
                'title' => 'Voucher approved',
                'body' => 'This voucher is approved. Continue with payment or posting when ready.',
                'action' => 'Got it',
            ];
        }

        if (str_contains($title, 'new voucher submitted') || preg_match('/\bnew voucher submitted\b/', $blob)) {
            return [
                'title' => 'Review this voucher',
                'body' => 'Open the voucher, check the details, then approve or reject it.',
                'action' => 'Got it',
            ];
        }

        if (str_contains($title, 'sign payment voucher') || preg_match('/\bsign as applicant\b/', $blob)) {
            return [
                'title' => 'Your signature is needed',
                'body' => 'Open the voucher and sign as Applicant to move it to the next approver.',
                'action' => 'Got it',
            ];
        }
        if (str_contains($title, 'approve as department manager') || preg_match('/\bdepartment manager\b/', $blob) && preg_match('/\bsign|approve\b/', $blob)) {
            return [
                'title' => 'Department Manager approval',
                'body' => 'Open the voucher and sign as Department Manager when it is your turn.',
                'action' => 'Got it',
            ];
        }
        if (str_contains($title, 'check payment voucher') || preg_match('/\bchecked by\b/', $blob) || preg_match('/\brequires checking\b/', $blob)) {
            return [
                'title' => 'Check this voucher',
                'body' => 'Open the voucher, review the details, then sign as Checked By.',
                'action' => 'Got it',
            ];
        }
        if (str_contains($title, 'final approval') || preg_match('/\bfinal approval\b/', $blob)) {
            return [
                'title' => 'Final approval needed',
                'body' => 'Open the voucher and give final approval so Finance can mark it paid.',
                'action' => 'Got it',
            ];
        }
        if (str_contains($title, 'mark voucher as paid') || preg_match('/\bmark.*paid\b/', $blob)) {
            return [
                'title' => 'Mark as paid',
                'body' => 'When payment is complete, open the voucher and mark it as paid.',
                'action' => 'Got it',
            ];
        }
        if (str_contains($title, 'post payment voucher') || preg_match('/\bpost.*voucher\b/', $blob)) {
            return [
                'title' => 'Post this voucher',
                'body' => 'Open the voucher and post it to finalize bookkeeping.',
                'action' => 'Got it',
            ];
        }

        if (str_contains($title, 'draft payroll') || preg_match('/\bdraft payroll\b/', $blob)) {
            return [
                'title' => 'Review this draft payroll',
                'body' => 'Open the run, check employee payslips, then use Approve run when everything looks correct.',
                'action' => 'Got it',
            ];
        }
        if (str_contains($title, 'payroll approved') || preg_match('/\bpayroll approved\b/', $blob)) {
            return [
                'title' => 'Ready for payout',
                'body' => 'This payroll is approved. When payment is complete, click Mark as paid to post it to accounting.',
                'action' => 'Got it',
            ];
        }
        if (str_contains($title, 'marked paid') || preg_match('/\bmarked paid\b/', $blob)) {
            return [
                'title' => 'Payroll is complete',
                'body' => 'This run is marked paid. You can export the report or send payslips to employees if needed.',
                'action' => 'Got it',
            ];
        }
        if (str_contains($title, 'payslip available') || preg_match('/\bpayslip available\b/', $blob)) {
            return [
                'title' => 'Your payslip is ready',
                'body' => 'Open My Payslips to view, download, or print your payslip for this period.',
                'action' => 'Got it',
            ];
        }
        if (str_contains($title, 'payslip emailed') || preg_match('/\bpayslip emailed\b/', $blob)) {
            return [
                'title' => 'Check your email',
                'body' => 'Your payslip was sent by email. You can also open it anytime from My Payslips.',
                'action' => 'Got it',
            ];
        }

        $heading = trim((string) ($n['title'] ?? 'Notification'));
        $detail = trim((string) ($n['message'] ?? 'Follow the steps on this page to continue.'));

        return [
            'title' => $heading !== '' ? $heading : 'Next step',
            'body' => $detail !== '' ? $detail : 'Review the details on this page, then continue with the recommended action.',
            'action' => 'Got it',
        ];
    }
}

if (!function_exists('nc_card_visual')) {
    /**
     * @return array{icon:string,tone:string}
     */
    function nc_card_visual(array $n): array
    {
        $title = strtolower(trim((string) ($n['title'] ?? '')));
        $blob = $title . ' ' . strtolower((string) ($n['message'] ?? '')) . ' ' . strtolower((string) ($n['type'] ?? ''));

        if (str_contains($title, 'draft payroll') || preg_match('/\bdraft payroll\b/', $blob)) {
            return ['icon' => 'fas fa-file-invoice-dollar', 'tone' => 'blue'];
        }
        if (str_contains($title, 'payroll approved') || preg_match('/\bpayroll approved\b/', $blob)) {
            return ['icon' => 'fas fa-check-circle', 'tone' => 'green'];
        }
        if (str_contains($title, 'marked paid') || preg_match('/\bmarked paid\b/', $blob)) {
            return ['icon' => 'fas fa-money-check-alt', 'tone' => 'teal'];
        }
        if (str_contains($title, 'payslip available') || preg_match('/\bpayslip available\b/', $blob)) {
            return ['icon' => 'fas fa-wallet', 'tone' => 'blue'];
        }
        if (str_contains($title, 'payslip emailed') || preg_match('/\bpayslip emailed\b|\bemailed\b/', $blob)) {
            return ['icon' => 'fas fa-envelope', 'tone' => 'sky'];
        }
        if (preg_match('/\b(ai|smarter|model|assistant|brain)\b/', $blob)) {
            return ['icon' => 'fas fa-lightbulb', 'tone' => 'amber'];
        }
        if (preg_match('/\b(analys|analytics|report|chart|data|kpi)\b/', $blob)) {
            return ['icon' => 'fas fa-chart-line', 'tone' => 'blue'];
        }
        if (preg_match('/\b(maint|system|update|wrench|fix)\b/', $blob)) {
            return ['icon' => 'fas fa-wrench', 'tone' => 'slate'];
        }
        if (preg_match('/\b(payroll|salary|payslip|payment|paid)\b/', $blob)) {
            return ['icon' => 'fas fa-wallet', 'tone' => 'blue'];
        }
        if (preg_match('/\b(mail|email)\b/', $blob)) {
            return ['icon' => 'fas fa-envelope', 'tone' => 'sky'];
        }
        if (preg_match('/\b(warn|danger|error|fail)\b/', $blob) || ($n['type'] ?? '') === 'danger') {
            return ['icon' => 'fas fa-exclamation', 'tone' => 'rose'];
        }
        if (preg_match('/\b(success|approved|complete)\b/', $blob) || ($n['type'] ?? '') === 'success') {
            return ['icon' => 'fas fa-check', 'tone' => 'green'];
        }

        return ['icon' => 'far fa-bell', 'tone' => 'slate'];
    }
}

if (!function_exists('nc_card_fa_icon')) {
    function nc_card_fa_icon(array $n): string
    {
        return nc_card_visual($n)['icon'];
    }
}

if (empty($ncItems)): ?>
    <div class="nc-empty">
        <div class="nc-empty-icon" aria-hidden="true"><i class="far fa-bell"></i></div>
        <p class="mb-0 fw-semibold">You&rsquo;re all caught up</p>
        <p class="mb-0 small">No notifications to show right now.</p>
    </div>
<?php
    return;
endif;

foreach ($ncItems as $n):
    $src = strtolower(trim((string) ($n['src'] ?? $n['source'] ?? '')));
    if ($src === '') {
        $src = (trim((string) ($n['link_url'] ?? $n['link'] ?? '')) !== '') ? 'system' : 'core';
    }
    $compositeId = ($src === 'system' ? 's' : 'c') . (int) ($n['id'] ?? 0);
    $isUnread = ((int) ($n['is_read'] ?? 0) === 0);
    $href = function_exists('nc_notification_href') ? nc_notification_href($n) : '';
    $createdRaw = $n['created_at'] ?? '';
    $period = nc_card_period($createdRaw);
    $timeLabel = nc_relative_time($createdRaw);
    $visual = nc_card_visual($n);
    $iconFa = $visual['icon'];
    $tone = $visual['tone'];
    $coach = nc_card_coach($n);
    $classes = 'nc-card nc-card--tone-' . $tone . ($isUnread ? ' is-unread' : '');
    if (nc_is_voucher_action_notification($n)) {
        $classes .= ' nc-card--pv-action';
    }
    $voucherIdAttr = 0;
    if (function_exists('nc_guess_voucher_id_from_notification')) {
        $voucherIdAttr = (int) nc_guess_voucher_id_from_notification($n);
    } else {
        $voucherIdAttr = (int) ($n['voucher_id'] ?? 0);
    }
    $voucherNoAttr = '';
    $msgBlob = (string) ($n['title'] ?? '') . ' ' . (string) ($n['message'] ?? '');
    if (preg_match('/\b(PV\/[A-Z0-9][A-Z0-9\/\-]+)\b/i', $msgBlob, $vm)) {
        $voucherNoAttr = strtoupper(trim((string) ($vm[1] ?? '')));
    }
    $cardDataAttrs = 'data-notif-id="' . htmlspecialchars($compositeId, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-nc-period="' . htmlspecialchars($period, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-nc-coach-title="' . htmlspecialchars($coach['title'], ENT_QUOTES, 'UTF-8') . '"'
        . ' data-nc-coach-body="' . htmlspecialchars($coach['body'], ENT_QUOTES, 'UTF-8') . '"'
        . ' data-nc-coach-action="' . htmlspecialchars($coach['action'], ENT_QUOTES, 'UTF-8') . '"'
        . ($voucherIdAttr > 0 ? ' data-voucher-id="' . (int) $voucherIdAttr . '"' : '')
        . ($voucherNoAttr !== '' ? ' data-voucher-no="' . htmlspecialchars($voucherNoAttr, ENT_QUOTES, 'UTF-8') . '"' : '')
        . (nc_is_voucher_action_notification($n) ? ' data-pv-action="1"' : '');
    if ($href !== ''): ?>
    <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
        class="<?= htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') ?>"
        <?= $cardDataAttrs ?>
        onclick="if (typeof headerNotifItemClick === 'function') { headerNotifItemClick(event, this); }">
    <?php else: ?>
    <article class="<?= htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') ?>"
        <?= $cardDataAttrs ?>>
    <?php endif; ?>
        <span class="nc-card-icon nc-card-icon--<?= htmlspecialchars($tone, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true">
            <i class="<?= htmlspecialchars($iconFa, ENT_QUOTES, 'UTF-8') ?>"></i>
        </span>
        <div class="nc-card-body">
            <div class="nc-card-title-row">
                <h3 class="nc-card-title"><?= htmlspecialchars((string) ($n['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                <div class="nc-card-meta-inline">
                    <?php if ($timeLabel !== ''): ?>
                        <time class="nc-card-time"><?= htmlspecialchars($timeLabel, ENT_QUOTES, 'UTF-8') ?></time>
                    <?php endif; ?>
                    <?php if ($isUnread): ?>
                        <span class="nc-card-unread-dot" aria-label="Unread"></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (trim((string) ($n['message'] ?? '')) !== ''): ?>
                <p class="nc-card-message"><?= nc_format_notification_message((string) $n['message']) ?></p>
            <?php endif; ?>
        </div>
    <?php if ($href !== ''): ?></a><?php else: ?></article><?php endif; ?>
<?php endforeach; ?>
