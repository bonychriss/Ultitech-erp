<?php

declare(strict_types=1);

/**
 * Notifications centre React UI helpers.
 */

function notificationsUiWebBasePath(): string
{
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/notifications-ui'), '/');
    }

    return '/notifications-ui';
}

function notificationsUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

    return notificationsUiWebBasePath() . '/' . $relativePath;
}

/**
 * @return array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function notificationsUiLoadReactAssets(): ?array
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
        'assetBase' => notificationsUiPublicUrl('frontend/dist/assets/'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

/**
 * @return 'today'|'yesterday'|'earlier'
 */
function notificationsUiPeriodOf($createdAt): string
{
    $ts = is_numeric($createdAt) ? (int) $createdAt : strtotime((string) $createdAt);
    if (!$ts) {
        return 'earlier';
    }
    $startToday = strtotime('today');
    $startYesterday = strtotime('yesterday');
    if ($ts >= $startToday) {
        return 'today';
    }
    if ($ts >= $startYesterday) {
        return 'yesterday';
    }

    return 'earlier';
}

function notificationsUiRelativeTime($createdAt): string
{
    if (function_exists('nc_relative_time')) {
        return (string) nc_relative_time($createdAt);
    }
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
        return ((int) floor($diff / 60)) . 'm ago';
    }
    if ($ts >= $startToday) {
        return ((int) floor($diff / 3600)) . 'h ago';
    }
    if ($ts >= $startYesterday) {
        return date('g:i A', $ts);
    }

    return date('M j', $ts);
}

/**
 * Infer ERP module key from notification row (link + copy + source).
 */
function notificationsUiDetectModule(array $n): string
{
    $src = strtolower(trim((string) ($n['src'] ?? $n['source'] ?? '')));
    $link = strtolower(trim((string) ($n['link_url'] ?? $n['link'] ?? '')));
    $title = strtolower(trim((string) ($n['title'] ?? '')));
    $message = strtolower(trim((string) ($n['message'] ?? '')));
    $blob = $title . ' ' . $message . ' ' . $link;

    if ($src === 'core' || (int) ($n['voucher_id'] ?? 0) > 0) {
        return 'voucher';
    }

    if (preg_match('/module=payroll|\/payroll|modules\/payroll|payslip|run-payroll/', $blob)
        || preg_match('/\b(payroll|payslip|salary)\b/', $blob)) {
        return 'payroll';
    }
    if (preg_match('/module=sales|\/sales\/|modules\/sales|customer_statement/', $blob)
        || preg_match('/\b(invoice|quotation|sales order|customer)\b/', $blob)) {
        return 'sales';
    }
    if (preg_match('/store-management|purchase.?order|\bpo\b|\/stock\/|module=stocks?|modules\/stock|shipment|warehouse|replenish/', $blob)
        || preg_match('/\b(purchase order|stock|shipment|warehouse|procurement)\b/', $blob)) {
        return 'stock';
    }
    if (preg_match('/\/deliveries|module=deliveries|delivery.?note/', $blob)
        || preg_match('/\b(delivery|delivered|driver assigned)\b/', $blob)) {
        return 'deliveries';
    }
    if (preg_match('/driver-kpi|module=driver_kpi|ride.?recording/', $blob)
        || preg_match('/\b(driver kpi|ride)\b/', $blob)) {
        return 'driver_kpi';
    }
    if (preg_match('/\/attendance|module=attendance/', $blob)
        || preg_match('/\b(attendance|clock.?in|clock.?out|overtime)\b/', $blob)) {
        return 'attendance';
    }
    if (preg_match('/\/letter|module=letter|write-letter|manage-letters/', $blob)
        || preg_match('/\b(letter|stamp|inbox)\b/', $blob)) {
        return 'letter';
    }
    if (preg_match('/\/cashbook|petty.?cash|module=cashbook|\/accounting|module=accounting|\/revenue|module=revenue/', $blob)
        || preg_match('/\b(cash book|petty cash|accounting|revenue|ledger)\b/', $blob)) {
        return 'finance';
    }
    if (preg_match('/\/suggest|module=suggest/', $blob)
        || preg_match('/\b(suggest|ai assistant|smarter)\b/', $blob)) {
        return 'suggest';
    }
    if (preg_match('/manage-users|company-settings|email-settings|whatsapp|admin\//', $blob)
        || preg_match('/\b(user management|company settings|admin)\b/', $blob)) {
        return 'admin';
    }
    if (preg_match('/weekly_tasks|module=tasks|\btodo\b/', $blob)
        || preg_match('/\b(task|todo|reminder)\b/', $blob)) {
        return 'tasks';
    }

    return $src === 'system' ? 'system' : 'general';
}

/**
 * @param array<string,mixed> $n
 * @return array{icon:string,tone:string,module:string}
 */
function notificationsUiVisual(array $n): array
{
    $module = notificationsUiDetectModule($n);
    $type = strtolower(trim((string) ($n['type'] ?? 'info')));
    $title = strtolower(trim((string) ($n['title'] ?? '')));
    $blob = $title . ' ' . strtolower((string) ($n['message'] ?? ''));

    $map = [
        'voucher' => ['icon' => 'voucher', 'tone' => 'amber'],
        'payroll' => ['icon' => 'payroll', 'tone' => 'blue'],
        'sales' => ['icon' => 'sales', 'tone' => 'teal'],
        'stock' => ['icon' => 'stock', 'tone' => 'sky'],
        'deliveries' => ['icon' => 'truck', 'tone' => 'blue'],
        'driver_kpi' => ['icon' => 'kpi', 'tone' => 'teal'],
        'attendance' => ['icon' => 'clock', 'tone' => 'green'],
        'letter' => ['icon' => 'mail', 'tone' => 'sky'],
        'finance' => ['icon' => 'money', 'tone' => 'green'],
        'suggest' => ['icon' => 'spark', 'tone' => 'amber'],
        'admin' => ['icon' => 'shield', 'tone' => 'slate'],
        'tasks' => ['icon' => 'tasks', 'tone' => 'blue'],
        'system' => ['icon' => 'system', 'tone' => 'slate'],
        'general' => ['icon' => 'bell', 'tone' => 'slate'],
    ];

    $visual = $map[$module] ?? $map['general'];

    // Action / status overrides within a module
    if ($module === 'voucher') {
        if (preg_match('/\b(reject|denied|cancelled)\b/', $blob) || $type === 'danger') {
            $visual = ['icon' => 'alert', 'tone' => 'rose'];
        } elseif (preg_match('/\b(paid|posted|approved|complete)\b/', $blob) || $type === 'success') {
            $visual = ['icon' => 'check', 'tone' => 'green'];
        } elseif (preg_match('/\b(sign|check|approve|waiting)\b/', $blob)) {
            $visual = ['icon' => 'voucher', 'tone' => 'amber'];
        }
    } elseif ($module === 'payroll') {
        if (preg_match('/\b(approved|marked paid|payslip available)\b/', $blob) || $type === 'success') {
            $visual = ['icon' => 'check', 'tone' => 'green'];
        } elseif (preg_match('/\bemailed\b/', $blob)) {
            $visual = ['icon' => 'mail', 'tone' => 'sky'];
        }
    } elseif ($module === 'deliveries') {
        if (preg_match('/\b(reject|fail|unaccepted)\b/', $blob) || $type === 'danger') {
            $visual = ['icon' => 'alert', 'tone' => 'rose'];
        } elseif (preg_match('/\b(complete|delivered)\b/', $blob) || $type === 'success') {
            $visual = ['icon' => 'check', 'tone' => 'green'];
        }
    } elseif ($module === 'stock') {
        if (preg_match('/\b(receive|received|verified)\b/', $blob)) {
            $visual = ['icon' => 'package', 'tone' => 'green'];
        } elseif (preg_match('/\b(remind|verify|pending)\b/', $blob)) {
            $visual = ['icon' => 'stock', 'tone' => 'amber'];
        }
    } elseif ($type === 'danger' || preg_match('/\b(warn|error|fail|danger)\b/', $blob)) {
        $visual = ['icon' => 'alert', 'tone' => 'rose'];
    } elseif ($type === 'success') {
        $visual = ['icon' => 'check', 'tone' => 'green'];
    }

    $visual['module'] = $module;

    return $visual;
}

/**
 * Human label for notification module badge.
 */
function notificationsUiModuleLabel(string $module): string
{
    $map = [
        'voucher' => 'Payment voucher',
        'payroll' => 'Payroll',
        'sales' => 'Sales',
        'stock' => 'Stock / Purchases',
        'deliveries' => 'Deliveries',
        'driver_kpi' => 'Driver KPI',
        'attendance' => 'Attendance',
        'letter' => 'Letter',
        'finance' => 'Finance',
        'suggest' => 'Suggestions',
        'admin' => 'Admin',
        'tasks' => 'Tasks',
        'system' => 'System',
        'general' => 'Notification',
    ];

    return $map[$module] ?? 'Notification';
}

/**
 * Rewrite legacy voucher-action copy into the clearer centre format.
 *
 * @param array<string,mixed> $n
 * @return array{title:string,message:string}
 */
function notificationsUiFormatVoucherCopy(array $n): array
{
    $title = trim((string) ($n['title'] ?? ''));
    $message = trim((string) ($n['message'] ?? ''));
    // Normalize broken encoding (en-dash / replacement char) to plain ASCII hyphen.
    $title = preg_replace('/[\x{2013}\x{2014}\x{FFFD}]+/u', '-', $title) ?? $title;
    $title = preg_replace('/\s*-\s*/', ' - ', $title) ?? $title;
    $title = preg_replace('/\s+/', ' ', trim($title)) ?? $title;
    $message = preg_replace('/[\x{2013}\x{2014}\x{FFFD}]+/u', '-', $message) ?? $message;
    $blob = strtolower($title . ' ' . $message);

    $voucherNo = '';
    if (preg_match('/\b(PV\/[A-Z0-9][A-Z0-9\/\-]+)\b/i', $title . ' ' . $message, $m)) {
        $voucherNo = strtoupper(trim((string) $m[1]));
    }

    $role = '';
    if (preg_match('/\bas\s+(Applicant|Department Manager|Checked By)\b/i', $message, $m)) {
        $role = trim((string) $m[1]);
    } elseif (preg_match('/\b(Applicant|Department Manager|Checked By)\b/i', $title . ' ' . $message, $m)) {
        $role = trim((string) $m[1]);
    }

    $creator = '';
    $actorName = '';
    $vid = (int) ($n['voucher_id'] ?? 0);
    if ($vid <= 0 && function_exists('nc_guess_voucher_id_from_notification')) {
        $vid = (int) nc_guess_voucher_id_from_notification($n);
    }
    if ($vid > 0) {
        global $pdo;
        if ($pdo instanceof PDO) {
            try {
                $st = $pdo->prepare(
                    'SELECT voucher_no, applicant, department_manager, checked_by, prepared_by, created_by,
                            approved_by, general_manager
                     FROM payment_vouchers WHERE id = ? LIMIT 1'
                );
                $st->execute([$vid]);
                $v = $st->fetch(PDO::FETCH_ASSOC);
                if (is_array($v)) {
                    if ($voucherNo === '' && trim((string) ($v['voucher_no'] ?? '')) !== '') {
                        $voucherNo = trim((string) $v['voucher_no']);
                    }
                    if (function_exists('paymentVoucherNotificationCreatorName')) {
                        $creator = paymentVoucherNotificationCreatorName($v);
                    }
                    if (function_exists('paymentVoucherStatusActorName')) {
                        $statusHint = '';
                        if (preg_match('/\bapproved\b/', $blob)) {
                            $statusHint = 'approved';
                        } elseif (preg_match('/\brejected\b/', $blob)) {
                            $statusHint = 'rejected';
                        } elseif (preg_match('/\bpaid\b/', $blob)) {
                            $statusHint = 'paid';
                        } elseif (preg_match('/\bposted\b/', $blob)) {
                            $statusHint = 'posted';
                        }
                        $actorName = paymentVoucherStatusActorName($v, $statusHint);
                    }
                }
            } catch (Throwable $e) {
                /* ignore */
            }
        }
    }
    if ($creator === '') {
        $creator = 'a colleague';
    }
    if ($voucherNo === '') {
        $voucherNo = 'this voucher';
    }

    // Status updates (approved / rejected / paid / posted)
    if (preg_match('/\bvoucher\s+approved\b/', $blob) || preg_match('/\bhas been approved\b/', $blob)) {
        $msg = $actorName !== ''
            ? sprintf('Your voucher **%s** has been approved by **%s**.', $voucherNo, $actorName)
            : sprintf('Your voucher **%s** has been approved.', $voucherNo);

        return ['title' => 'Voucher APPROVED', 'message' => $msg];
    }
    if (preg_match('/\bvoucher\s+rejected\b/', $blob) || preg_match('/\bhas been rejected\b/', $blob)) {
        $msg = $actorName !== ''
            ? sprintf('Your voucher **%s** has been rejected by **%s**.', $voucherNo, $actorName)
            : sprintf('Your voucher **%s** has been rejected.', $voucherNo);

        return ['title' => 'Voucher REJECTED', 'message' => $msg];
    }
    if (preg_match('/\bvoucher\s+paid\b/', $blob) || preg_match('/\bhas been paid\b/', $blob) || preg_match('/\bmarked paid\b/', $blob)) {
        $msg = $actorName !== ''
            ? sprintf('Your voucher **%s** has been paid by **%s**.', $voucherNo, $actorName)
            : sprintf('Your voucher **%s** has been paid.', $voucherNo);

        return ['title' => 'Voucher PAID', 'message' => $msg];
    }
    if (preg_match('/\bvoucher\s+posted\b/', $blob) || preg_match('/\bhas been posted\b/', $blob)) {
        $msg = $actorName !== ''
            ? sprintf('Your voucher **%s** has been posted (finalized) by **%s**.', $voucherNo, $actorName)
            : sprintf('Your voucher **%s** has been posted (finalized).', $voucherNo);

        return ['title' => 'Voucher POSTED', 'message' => $msg];
    }

    // Already in new action format - keep as-is.
    if (preg_match('/^Payment Voucher\s*[-]/i', $title)) {
        return ['title' => $title, 'message' => $message];
    }

    if (preg_match('/\b(sign payment voucher|signature required|sign as applicant)\b/', $blob)
        || ($role !== '' && strcasecmp($role, 'Applicant') === 0 && preg_match('/\b(sign|open voucher|listed as)\b/', $blob))) {
        return [
            'title' => 'Payment Voucher - Signature Required',
            'message' => sprintf(
                'Payment voucher **%s** created by **%s** requires your signature as **Applicant**.',
                $voucherNo,
                $creator
            ),
        ];
    }
    if (preg_match('/\b(approve as department|department manager)\b/', $blob)
        || strcasecmp($role, 'Department Manager') === 0) {
        return [
            'title' => 'Payment Voucher - Approval Required',
            'message' => sprintf(
                'Payment voucher **%s** created by **%s** requires your approval as **Department Manager**.',
                $voucherNo,
                $creator
            ),
        ];
    }
    if (preg_match('/\b(check payment voucher|checked by|requires checking)\b/', $blob)
        || strcasecmp($role, 'Checked By') === 0) {
        return [
            'title' => 'Payment Voucher - Check Required',
            'message' => sprintf(
                'Payment voucher **%s** created by **%s** requires your review as **Checked By**.',
                $voucherNo,
                $creator
            ),
        ];
    }
    if (preg_match('/\bfinal approval\b/', $blob)) {
        return [
            'title' => 'Payment Voucher - Final Approval',
            'message' => sprintf(
                'Payment voucher **%s** created by **%s** is ready for **final approval**.',
                $voucherNo,
                $creator
            ),
        ];
    }
    if (preg_match('/\bmark.*paid\b/', $blob)) {
        return [
            'title' => 'Payment Voucher - Mark as Paid',
            'message' => sprintf(
                'Payment voucher **%s** created by **%s** is approved. Mark it as **paid** when payment is complete.',
                $voucherNo,
                $creator
            ),
        ];
    }
    if (preg_match('/\bpost (payment )?voucher\b/', $blob)) {
        return [
            'title' => 'Payment Voucher - Post Required',
            'message' => sprintf(
                'Payment voucher **%s** created by **%s** is paid. **Post** it to finalize bookkeeping.',
                $voucherNo,
                $creator
            ),
        ];
    }

    return ['title' => $title, 'message' => $message];
}

/**
 * @param array<string,mixed> $n
 * @return array<string,mixed>
 */
function notificationsUiNormalizeItem(array $n): array
{
    $src = strtolower(trim((string) ($n['src'] ?? $n['source'] ?? '')));
    if ($src === '') {
        $src = (trim((string) ($n['link_url'] ?? $n['link'] ?? '')) !== '') ? 'system' : 'core';
    }
    $id = (int) ($n['id'] ?? 0);
    $compositeId = ($src === 'system' ? 's' : 'c') . $id;
    $created = $n['created_at'] ?? '';
    $visual = notificationsUiVisual($n);
    $module = (string) ($visual['module'] ?? 'general');
    $href = '';
    if (function_exists('nc_notification_href')) {
        $href = (string) nc_notification_href($n);
    } elseif (function_exists('resolveStoredNotificationLink')) {
        $href = (string) (resolveStoredNotificationLink($n['link'] ?? $n['link_url'] ?? null) ?? '');
    }

    $title = (string) ($n['title'] ?? '');
    $message = (string) ($n['message'] ?? '');
    if ($module === 'voucher') {
        $formatted = notificationsUiFormatVoucherCopy($n);
        $title = $formatted['title'];
        $message = $formatted['message'];
    }

    return [
        'id' => $compositeId,
        'rawId' => $id,
        'source' => $src,
        'title' => $title,
        'message' => $message,
        'href' => $href,
        'isUnread' => empty($n['is_read']) || (int) $n['is_read'] === 0,
        'createdAt' => (string) $created,
        'timeLabel' => notificationsUiRelativeTime($created),
        'period' => notificationsUiPeriodOf($created),
        'tone' => $visual['tone'],
        'icon' => $visual['icon'],
        'module' => $module,
        'moduleLabel' => notificationsUiModuleLabel($module),
        'type' => (string) ($n['type'] ?? 'info'),
    ];
}

/**
 * @return array{
 *   sections: array{today:list,yesterday:list,earlier:list},
 *   countUnread:int,
 *   markAllApi:string,
 *   markReadApi:string,
 *   settingsUrl:string,
 *   listUrl:string,
 *   prefsApi:string,
 *   page:string,
 *   preferences:array<string,mixed>
 * }
 */
function notificationsUiBuildPayload(string $page = 'list'): array
{
    $page = strtolower(trim($page)) === 'settings' ? 'settings' : 'list';

    // Ensure card helpers (visual / href / relative time) are available.
    $cardsPartial = dirname(__DIR__) . '/includes/partials/notifications_centre_cards.php';
    if (is_file($cardsPartial)) {
        $ncItems = [];
        ob_start();
        require $cardsPartial;
        ob_end_clean();
    }

    if (function_exists('reconcileStalePaymentVoucherActionNotificationsForUser')) {
        try {
            reconcileStalePaymentVoucherActionNotificationsForUser();
        } catch (Throwable $e) {
        }
    }

    $prefs = notificationsUiGetPreferences();
    $enabledModules = is_array($prefs['modules'] ?? null) ? $prefs['modules'] : [];

    $sections = [
        'today' => [],
        'yesterday' => [],
        'earlier' => [],
    ];
    $countUnread = 0;

    if ($page === 'list') {
        $allItems = function_exists('getNotificationCentreFeedPaged')
            ? getNotificationCentreFeedPaged(120, 0)
            : [];
        if (function_exists('nc_sort_action_notifications_first')) {
            $allItems = nc_sort_action_notifications_first($allItems);
        }

        foreach ($allItems as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = notificationsUiNormalizeItem($row);
            $mod = (string) ($item['module'] ?? 'general');
            if (isset($enabledModules[$mod]) && !$enabledModules[$mod]) {
                continue;
            }
            $period = $item['period'];
            if ($period === 'today') {
                $sections['today'][] = $item;
            } elseif ($period === 'yesterday') {
                $sections['yesterday'][] = $item;
            } else {
                $sections['earlier'][] = $item;
            }
            if (!empty($item['isUnread'])) {
                $countUnread++;
            }
        }
    }

    $markAllApi = function_exists('app_url')
        ? app_url('/api/notifications.php')
        : '/api/notifications.php';
    $markReadApi = function_exists('app_url')
        ? app_url('/api/get_notifications.php')
        : '/api/get_notifications.php';
    $prefsApi = $markAllApi;
    $listUrl = function_exists('company_url')
        ? company_url('notifications.php')
        : (function_exists('app_url') ? app_url('/notifications.php') : '/notifications.php');
    $settingsUrl = function_exists('company_url')
        ? company_url('notifications/settings.php')
        : (function_exists('app_url') ? app_url('/notifications/settings.php') : '/notifications/settings.php');

    return [
        'page' => $page,
        'sections' => $sections,
        'countUnread' => $countUnread,
        'markAllApi' => $markAllApi,
        'markReadApi' => $markReadApi,
        'prefsApi' => $prefsApi,
        'listUrl' => $listUrl,
        'settingsUrl' => $settingsUrl,
        'preferences' => $prefs,
        'moduleOptions' => notificationsUiModuleOptions(),
    ];
}

/**
 * @return list<array{id:string,label:string,color:string}>
 */
function notificationsUiModuleOptions(): array
{
    return [
        ['id' => 'voucher', 'label' => 'Payment voucher', 'color' => '#0f766e', 'description' => 'Signatures, approvals, and voucher status updates.'],
        ['id' => 'payroll', 'label' => 'Payroll', 'color' => '#1d4ed8', 'description' => 'Payslip releases and payroll processing alerts.'],
        ['id' => 'sales', 'label' => 'Sales', 'color' => '#15803d', 'description' => 'Orders, invoices, and sales follow-up reminders.'],
        ['id' => 'stock', 'label' => 'Stock / Purchases', 'color' => '#1e3a8a', 'description' => 'PO verification, receiving, and purchase alerts.'],
        ['id' => 'deliveries', 'label' => 'Deliveries', 'color' => '#0369a1', 'description' => 'Delivery started, completed, and shipment updates.'],
        ['id' => 'driver_kpi', 'label' => 'Driver KPI', 'color' => '#0e7490', 'description' => 'Driver performance and ride recording alerts.'],
        ['id' => 'attendance', 'label' => 'Attendance', 'color' => '#c2410c', 'description' => 'Clock-in reminders and attendance exceptions.'],
        ['id' => 'letter', 'label' => 'Letter', 'color' => '#E6B800', 'description' => 'Letter drafts, reviews, and approval requests.'],
        ['id' => 'finance', 'label' => 'Finance', 'color' => '#0d9488', 'description' => 'Payments, cashbook, and finance workflow alerts.'],
        ['id' => 'suggest', 'label' => 'Suggestions', 'color' => '#ca8a04', 'description' => 'New suggestions and feedback responses.'],
        ['id' => 'admin', 'label' => 'Admin', 'color' => '#4b5563', 'description' => 'Admin actions and company management notices.'],
        ['id' => 'tasks', 'label' => 'Tasks', 'color' => '#e11d48', 'description' => 'Assigned tasks, deadlines, and completions.'],
        ['id' => 'system', 'label' => 'System', 'color' => '#4b5563', 'description' => 'System maintenance and account notices.'],
        ['id' => 'general', 'label' => 'General', 'color' => '#64748b', 'description' => 'Other notifications that are not module-specific.'],
    ];
}

/**
 * @return array{modules:array<string,bool>,emailAlerts:bool}
 */
function notificationsUiDefaultPreferences(): array
{
    $modules = [];
    foreach (notificationsUiModuleOptions() as $opt) {
        $modules[(string) $opt['id']] = true;
    }

    return [
        'modules' => $modules,
        'emailAlerts' => false,
    ];
}

function notificationsUiEnsurePreferencesTable(): bool
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return false;
    }
    static $done = false;
    if ($done) {
        return true;
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_notification_preferences (
                user_id INT NOT NULL,
                prefs_json TEXT NOT NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $done = true;

        return true;
    } catch (Throwable $e) {
        error_log('notificationsUiEnsurePreferencesTable: ' . $e->getMessage());

        return false;
    }
}

/**
 * @return array{modules:array<string,bool>,emailAlerts:bool}
 */
function notificationsUiGetPreferences(?int $userId = null): array
{
    $defaults = notificationsUiDefaultPreferences();
    $uid = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return $defaults;
    }
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return $defaults;
    }
    notificationsUiEnsurePreferencesTable();
    try {
        $st = $pdo->prepare('SELECT prefs_json FROM user_notification_preferences WHERE user_id = ? LIMIT 1');
        $st->execute([$uid]);
        $raw = $st->fetchColumn();
        if (!is_string($raw) || $raw === '') {
            return $defaults;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        $modules = $defaults['modules'];
        if (isset($decoded['modules']) && is_array($decoded['modules'])) {
            foreach ($modules as $key => $_) {
                if (array_key_exists($key, $decoded['modules'])) {
                    $modules[$key] = (bool) $decoded['modules'][$key];
                }
            }
        }

        return [
            'modules' => $modules,
            'emailAlerts' => !empty($decoded['emailAlerts']),
        ];
    } catch (Throwable $e) {
        return $defaults;
    }
}

/**
 * @param array<string,mixed> $prefs
 * @return array{ok:bool,preferences?:array{modules:array<string,bool>,emailAlerts:bool},error?:string}
 */
function notificationsUiSavePreferencesResult(array $prefs, ?int $userId = null): array
{
    $uid = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return ['ok' => false, 'error' => 'Not signed in'];
    }
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return ['ok' => false, 'error' => 'Database unavailable'];
    }
    if (!notificationsUiEnsurePreferencesTable()) {
        return ['ok' => false, 'error' => 'Could not prepare preferences table'];
    }
    $normalized = notificationsUiDefaultPreferences();
    if (isset($prefs['modules']) && is_array($prefs['modules'])) {
        foreach ($normalized['modules'] as $key => $_) {
            if (array_key_exists($key, $prefs['modules'])) {
                $raw = $prefs['modules'][$key];
                if (is_bool($raw)) {
                    $normalized['modules'][$key] = $raw;
                } else {
                    $normalized['modules'][$key] = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
                }
            }
        }
    }
    $normalized['emailAlerts'] = !empty($prefs['emailAlerts']);
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'error' => 'Could not encode preferences'];
    }
    try {
        $st = $pdo->prepare(
            'INSERT INTO user_notification_preferences (user_id, prefs_json, updated_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE prefs_json = VALUES(prefs_json), updated_at = NOW()'
        );
        $st->execute([$uid, $json]);

        return ['ok' => true, 'preferences' => $normalized];
    } catch (Throwable $e) {
        error_log('notificationsUiSavePreferences: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'Database save failed'];
    }
}

/**
 * @param array<string,mixed> $prefs
 * @return array{modules:array<string,bool>,emailAlerts:bool}|null
 */
function notificationsUiSavePreferences(array $prefs, ?int $userId = null): ?array
{
    $result = notificationsUiSavePreferencesResult($prefs, $userId);

    return !empty($result['ok']) ? ($result['preferences'] ?? null) : null;
}

/**
 * Whether a user still wants in-app notifications for a module.
 */
function notificationsUiModuleAllowed(?int $userId, string $module): bool
{
    $uid = (int) ($userId ?? 0);
    $module = trim($module);
    if ($module === '') {
        $module = 'general';
    }
    if ($uid <= 0) {
        return true;
    }
    $prefs = notificationsUiGetPreferences($uid);
    $modules = is_array($prefs['modules'] ?? null) ? $prefs['modules'] : [];
    if (!array_key_exists($module, $modules)) {
        return true;
    }

    return (bool) $modules[$module];
}

/**
 * Whether a user opted into email copies of notifications.
 */
function notificationsUiEmailAlertsEnabled(?int $userId): bool
{
    $uid = (int) ($userId ?? 0);
    if ($uid <= 0) {
        return false;
    }
    $prefs = notificationsUiGetPreferences($uid);

    return !empty($prefs['emailAlerts']);
}

/**
 * Drop rows for modules the current user disabled.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function notificationsUiFilterRowsByPreferences(array $rows, ?int $userId = null): array
{
    $uid = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0 || $rows === []) {
        return $rows;
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $module = notificationsUiDetectModule($row);
        if (!notificationsUiModuleAllowed($uid, $module)) {
            continue;
        }
        $out[] = $row;
    }

    return $out;
}
