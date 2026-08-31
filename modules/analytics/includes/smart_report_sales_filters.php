<?php
/**
 * Date range filter for Sales Analytics (smart_report_sales.php).
 * Expects: $saFilters (from smart_report_sales_parse_filters).
 */
$saFilters = $saFilters ?? smart_report_sales_parse_filters();
$saFilterBase = 'smart_report_sales.php';
$resetUrl = $saFilterBase . '?module=analytics';
$today = date('Y-m-d');
$yearStart = date('Y-01-01');
$monthStart = date('Y-m-01');
$lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
$lastMonthEnd = date('Y-m-t', strtotime('last day of last month'));
$last30Start = date('Y-m-d', strtotime('-29 days'));
$presets = [
    ['label' => 'YTD', 'start' => $yearStart, 'end' => $today],
    ['label' => 'This month', 'start' => $monthStart, 'end' => $today],
    ['label' => 'Last month', 'start' => $lastMonthStart, 'end' => $lastMonthEnd],
    ['label' => 'Last 30 days', 'start' => $last30Start, 'end' => $today],
];
$activePresetLabel = '';
foreach ($presets as $preset) {
    if ($saFilters['start_date'] === $preset['start'] && $saFilters['end_date'] === $preset['end']) {
        $activePresetLabel = $preset['label'];
        break;
    }
}
$menuLabel = $activePresetLabel !== '' ? $activePresetLabel : 'Quick ranges';
?>
<form method="get" class="sa-date-filters" id="saDateFilters" action="<?= htmlspecialchars($saFilterBase) ?>">
    <input type="hidden" name="module" value="analytics">

    <div class="sa-date-filter-group">
        <label for="sa_start_date">From</label>
        <input type="date" id="sa_start_date" name="start_date" value="<?= htmlspecialchars($saFilters['start_date']) ?>" required>
    </div>

    <div class="sa-date-filter-group">
        <label for="sa_end_date">To</label>
        <input type="date" id="sa_end_date" name="end_date" value="<?= htmlspecialchars($saFilters['end_date']) ?>" required>
    </div>

    <div class="sa-date-menu" id="saDateMenu">
        <button type="button"
                class="sa-date-menu-toggle"
                id="saDateMenuToggle"
                aria-expanded="false"
                aria-haspopup="true"
                aria-controls="saDateMenuPanel">
            <span><?= htmlspecialchars($menuLabel) ?></span>
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
        </button>
        <div class="sa-date-menu-panel" id="saDateMenuPanel" role="menu" hidden>
            <?php foreach ($presets as $preset): ?>
                <?php
                $presetUrl = $saFilterBase . '?' . http_build_query([
                    'module' => 'analytics',
                    'start_date' => $preset['start'],
                    'end_date' => $preset['end'],
                ]);
                $isActive = $saFilters['start_date'] === $preset['start'] && $saFilters['end_date'] === $preset['end'];
                ?>
                <a href="<?= htmlspecialchars($presetUrl) ?>"
                   role="menuitem"
                   class="sa-date-menu-item<?= $isActive ? ' is-active' : '' ?>">
                    <?= htmlspecialchars($preset['label']) ?>
                </a>
            <?php endforeach; ?>
            <div class="sa-date-menu-divider" role="separator"></div>
            <a href="<?= htmlspecialchars($resetUrl) ?>"
               role="menuitem"
               class="sa-date-menu-item sa-date-menu-item--reset">
                Reset
            </a>
        </div>
    </div>
</form>
