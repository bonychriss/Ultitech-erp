<?php
/**
 * Notification rows (AI Notification Center reference layout).
 * Expects: $ncItems (list of rows from getNotificationCentreFeedPaged or merged header shape)
 */
if (!isset($ncItems) || !is_array($ncItems)) {
    $ncItems = [];
}

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
        $diff = max(0, time() - $ts);
        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            $m = (int) floor($diff / 60);

            return $m . 'm ago';
        }
        if ($diff < 86400) {
            $h = (int) floor($diff / 3600);

            return $h . 'h ago';
        }
        if ($diff < 172800) {
            return 'Yesterday';
        }
        if ($diff < 604800) {
            $d = (int) floor($diff / 86400);

            return $d . 'd ago';
        }

        return date('M j', $ts);
    }
}

if (!function_exists('nc_card_period')) {
    /** @return 'today'|'week'|'earlier' */
    function nc_card_period($createdAt): string
    {
        $ts = is_numeric($createdAt) ? (int) $createdAt : strtotime((string) $createdAt);
        if (!$ts) {
            return 'earlier';
        }
        $startToday = strtotime('today');
        $startWeek = strtotime('monday this week');
        if ($startWeek === false || $startWeek > $startToday) {
            $startWeek = strtotime('-6 days', $startToday);
        }
        if ($ts >= $startToday) {
            return 'today';
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
    if ($href !== ''): ?>
    <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
        class="<?= htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') ?>"
        data-notif-id="<?= htmlspecialchars($compositeId, ENT_QUOTES, 'UTF-8') ?>"
        data-nc-period="<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>"
        data-nc-coach-title="<?= htmlspecialchars($coach['title'], ENT_QUOTES, 'UTF-8') ?>"
        data-nc-coach-body="<?= htmlspecialchars($coach['body'], ENT_QUOTES, 'UTF-8') ?>"
        data-nc-coach-action="<?= htmlspecialchars($coach['action'], ENT_QUOTES, 'UTF-8') ?>"
        onclick="if (typeof headerNotifItemClick === 'function') { headerNotifItemClick(event, this); }">
    <?php else: ?>
    <article class="<?= htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') ?>"
        data-notif-id="<?= htmlspecialchars($compositeId, ENT_QUOTES, 'UTF-8') ?>"
        data-nc-period="<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>"
        data-nc-coach-title="<?= htmlspecialchars($coach['title'], ENT_QUOTES, 'UTF-8') ?>"
        data-nc-coach-body="<?= htmlspecialchars($coach['body'], ENT_QUOTES, 'UTF-8') ?>"
        data-nc-coach-action="<?= htmlspecialchars($coach['action'], ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
        <span class="nc-card-icon nc-card-icon--<?= htmlspecialchars($tone, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true">
            <i class="<?= htmlspecialchars($iconFa, ENT_QUOTES, 'UTF-8') ?>"></i>
        </span>
        <div class="nc-card-body">
            <div class="nc-card-title-row">
                <h3 class="nc-card-title"><?= htmlspecialchars((string) ($n['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                <div class="nc-card-meta-inline">
                    <?php if ($isUnread): ?>
                        <span class="nc-card-unread-label">Unread</span>
                    <?php endif; ?>
                    <?php if ($timeLabel !== ''): ?>
                        <time class="nc-card-time"><?= htmlspecialchars($timeLabel, ENT_QUOTES, 'UTF-8') ?></time>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (trim((string) ($n['message'] ?? '')) !== ''): ?>
                <p class="nc-card-message"><?= nc_format_notification_message((string) $n['message']) ?></p>
            <?php endif; ?>
        </div>
    <?php if ($href !== ''): ?></a><?php else: ?></article><?php endif; ?>
<?php endforeach; ?>
