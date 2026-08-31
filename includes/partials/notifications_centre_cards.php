<?php
/**
 * Notification cards (reference layout).
 * Expects: $ncItems (list of rows from getNotificationCentreFeedPaged or merged header shape)
 */
if (!isset($ncItems) || !is_array($ncItems)) {
    $ncItems = [];
}

if (!function_exists('nc_format_notification_message')) {
    function nc_format_notification_message(string $message): string
    {
        $esc = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $esc = preg_replace('/\bfor\s+([A-Za-z0-9][A-Za-z0-9\s.&\-]*?)\s*\(/i', 'for <span class="nc-msg-highlight">$1</span> (', $esc);
        $esc = preg_replace('/\(([^)]+)\)/', '<span class="nc-msg-highlight">($1)</span>', $esc);
        $esc = preg_replace('/\b([A-Z]{2,}(?:\/[A-Z0-9]+)+)\b/', '<span class="nc-msg-highlight">$1</span>', $esc);

        return nl2br($esc, false);
    }
}

if (!function_exists('nc_card_icon_tone')) {
    /**
     * Stable random icon color per notification (reference: purple, green, amber, rose).
     */
    function nc_card_icon_tone(array $n): string
    {
        $src = strtolower((string) ($n['src'] ?? $n['source'] ?? 'core'));
        $key = ($src === 'system' ? 's' : 'c') . (int) ($n['id'] ?? 0)
            . '|' . (string) ($n['title'] ?? '')
            . '|' . (string) ($n['created_at'] ?? '');
        $tones = ['purple', 'green', 'amber', 'rose'];
        $hash = crc32($key);

        return $tones[abs($hash) % count($tones)];
    }
}

if (empty($ncItems)): ?>
    <div class="nc-empty">
        <div class="nc-empty-icon" aria-hidden="true"><i class="far fa-bell"></i></div>
        <p class="mb-0 fw-semibold text-slate-700">You&rsquo;re all caught up</p>
        <p class="mb-0 small text-muted">No notifications to show right now.</p>
    </div>
<?php
    return;
endif;

foreach ($ncItems as $ncIndex => $n):
    $src = strtolower((string) ($n['src'] ?? $n['source'] ?? 'core'));
    $compositeId = ($src === 'system' ? 's' : 'c') . (int) ($n['id'] ?? 0);
    $isUnread = empty($n['is_read']) || (int) $n['is_read'] === 0;
    $typeRaw = strtolower((string) ($n['type'] ?? 'info'));
    $typeClass = in_array($typeRaw, ['info', 'success', 'warning', 'danger'], true) ? $typeRaw : 'info';
    $iconTone = nc_card_icon_tone($n);
    $iconClass = 'nc-card-icon nc-card-icon--' . $iconTone . ($isUnread ? '' : ' nc-card-icon--read');
    $href = function_exists('nc_notification_href') ? nc_notification_href($n) : '';
    $tagLabel = $src === 'system' ? 'ACCOUNT' : 'GENERAL';
    $tagClass = $src === 'system' ? 'nc-card-tag nc-card-tag--account' : 'nc-card-tag';
    $created = !empty($n['created_at']) ? strtotime($n['created_at']) : false;
    $timeLabel = $created ? date('M j', $created) . ' &bull; ' . date('g:i A', $created) : '';
    $classes = 'nc-card nc-card--' . $typeClass . ($isUnread ? ' is-unread' : '');
    if ($href !== ''): ?>
    <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
        class="<?= htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') ?>"
        data-notif-id="<?= htmlspecialchars($compositeId, ENT_QUOTES, 'UTF-8') ?>"
        onclick="if (typeof headerNotifItemClick === 'function') { headerNotifItemClick(event, this); }">
    <?php else: ?>
    <article class="<?= htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') ?>"
        data-notif-id="<?= htmlspecialchars($compositeId, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
        <span class="nc-card-accent" aria-hidden="true"></span>
        <span class="<?= htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"><i class="fas fa-file-alt"></i></span>
        <div class="nc-card-body">
            <?php if ($isUnread): ?>
                <span class="nc-card-unread-dot" aria-hidden="true"></span>
            <?php endif; ?>
            <h3 class="nc-card-title"><?= htmlspecialchars((string) ($n['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
            <?php if (trim((string) ($n['message'] ?? '')) !== ''): ?>
                <p class="nc-card-message"><?= nc_format_notification_message((string) $n['message']) ?></p>
            <?php endif; ?>
            <div class="nc-card-meta">
                <?php if ($timeLabel !== ''): ?>
                    <span class="nc-card-meta-date"><i class="far fa-calendar" aria-hidden="true"></i><?= $timeLabel ?></span>
                <?php endif; ?>
                <span class="<?= htmlspecialchars($tagClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tagLabel, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
        <div class="nc-card-aside">
            <span class="nc-card-chevron" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>
        </div>
    <?php if ($href !== ''): ?></a><?php else: ?></article><?php endif; ?>
<?php endforeach; ?>
