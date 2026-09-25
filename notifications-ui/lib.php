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

    return [
        'id' => $compositeId,
        'rawId' => $id,
        'source' => $src,
        'title' => (string) ($n['title'] ?? ''),
        'message' => (string) ($n['message'] ?? ''),
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
 *   settingsUrl:string
 * }
 */
function notificationsUiBuildPayload(): array
{
    // Ensure card helpers (visual / href / relative time) are available.
    $cardsPartial = dirname(__DIR__) . '/includes/partials/notifications_centre_cards.php';
    if (is_file($cardsPartial)) {
        // Define helpers only  avoid rendering by pre-setting empty list and capturing output.
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

    $allItems = function_exists('getNotificationCentreFeedPaged')
        ? getNotificationCentreFeedPaged(120, 0)
        : [];
    if (function_exists('nc_sort_action_notifications_first')) {
        $allItems = nc_sort_action_notifications_first($allItems);
    }

    $sections = [
        'today' => [],
        'yesterday' => [],
        'earlier' => [],
    ];
    $countUnread = 0;
    foreach ($allItems as $row) {
        if (!is_array($row)) {
            continue;
        }
        $item = notificationsUiNormalizeItem($row);
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

    $markAllApi = function_exists('app_url')
        ? app_url('/includes/notifications_api.php')
        : '/includes/notifications_api.php';
    $markReadApi = function_exists('app_url')
        ? app_url('/api/get_notifications.php')
        : '/api/get_notifications.php';
    $settingsUrl = function_exists('company_url')
        ? company_url('employee/account.php')
        : (function_exists('app_url') ? app_url('/employee/account.php') : '/employee/account.php');

    return [
        'sections' => $sections,
        'countUnread' => $countUnread,
        'markAllApi' => $markAllApi,
        'markReadApi' => $markReadApi,
        'settingsUrl' => $settingsUrl,
    ];
}
