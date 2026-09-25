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
 * @param array<string,mixed> $n
 * @return array{icon:string,tone:string}
 */
function notificationsUiVisual(array $n): array
{
    if (function_exists('nc_card_visual')) {
        $v = nc_card_visual($n);
        $tone = (string) ($v['tone'] ?? 'slate');
        $fa = (string) ($v['icon'] ?? 'far fa-bell');
        $icon = 'bell';
        if (str_contains($fa, 'check') || str_contains($fa, 'approve')) {
            $icon = 'check';
        } elseif (str_contains($fa, 'file') || str_contains($fa, 'document')) {
            $icon = 'file';
        } elseif (str_contains($fa, 'money') || str_contains($fa, 'dollar') || str_contains($fa, 'receipt')) {
            $icon = 'money';
        } elseif (str_contains($fa, 'exchange') || str_contains($fa, 'right-left') || str_contains($fa, 'arrows')) {
            $icon = 'swap';
        } elseif (str_contains($fa, 'exclamation') || str_contains($fa, 'warn')) {
            $icon = 'alert';
        } elseif (str_contains($fa, 'envelope') || str_contains($fa, 'mail')) {
            $icon = 'mail';
        } elseif (str_contains($fa, 'box') || str_contains($fa, 'truck') || str_contains($fa, 'warehouse')) {
            $icon = 'package';
        }

        return ['icon' => $icon, 'tone' => $tone];
    }

    return ['icon' => 'bell', 'tone' => 'slate'];
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
