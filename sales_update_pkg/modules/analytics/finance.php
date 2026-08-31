<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/smart_report_finance_helpers.php';
extract(analytics_bootstrap());

$fiFilters = smart_report_finance_parse_filters();
$financeDetail = smart_report_finance_reports($pdo, $fiFilters);

$fiDataVerification = ['accurate' => false, 'issue_count' => 0, 'issues' => []];
$fiVerifyAnalysis = ['summary' => '', 'details' => []];
$fiVerifyServiceOk = true;
$fiShowFinanceData = false;

try {
    $fiDataVerification = smart_report_finance_verify_displayed_data($pdo, $fiFilters, $financeDetail);
    $fiVerifyAnalysis = smart_report_finance_ai_verify_analysis($pdo, $fiDataVerification);
    $fiShowFinanceData = !empty($fiDataVerification['accurate']);
} catch (Throwable $e) {
    error_log('finance.php verification error: ' . $e->getMessage());
    $fiVerifyServiceOk = false;
    $fiShowFinanceData = false;
}

$fiDateLabel = date('M j, Y', strtotime($fiFilters['start_date']))
    . ' &ndash; '
    . date('M j, Y', strtotime($fiFilters['end_date']));
$fiHeaderSubtitle = '<span class="sa-header-meta">'
    . '<a href="smart_report.php?module=analytics" class="sa-header-back">'
    . '<i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Smart Report</a>'
    . '<span class="sa-header-dot" aria-hidden="true">&middot;</span>'
    . '<span class="sa-header-period">' . $fiDateLabel . '</span>'
    . '</span>';

analytics_page_start(
    'Financial Reports',
    $fiHeaderSubtitle,
    'finance',
    false,
    false
);
?>

<?php include __DIR__ . '/includes/smart_report_page_styles.php'; ?>

<div class="sa-page">
    <?php include __DIR__ . '/includes/smart_report_finance_filters.php'; ?>
    <?= smart_report_render_finance_checker_result($fiDataVerification, $fiVerifyAnalysis, $fiVerifyServiceOk) ?>
    <?php if ($fiShowFinanceData): ?>
    <div class="sa-intelligence">
        <?= smart_report_render_finance_reports_html($financeDetail, $fiFilters) ?>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var dateForm = document.getElementById('saDateFilters');
    if (dateForm) {
        var startInput = document.getElementById('sa_start_date');
        var endInput = document.getElementById('sa_end_date');

        function submitDateFilter() {
            if (!startInput || !endInput) return;
            if (!startInput.value || !endInput.value) return;
            if (startInput.value > endInput.value) {
                var tmp = startInput.value;
                startInput.value = endInput.value;
                endInput.value = tmp;
            }
            dateForm.submit();
        }

        [startInput, endInput].forEach(function (input) {
            if (!input) return;
            input.addEventListener('change', submitDateFilter);
        });
    }

    var dateMenu = document.getElementById('saDateMenu');
    var dateMenuToggle = document.getElementById('saDateMenuToggle');
    var dateMenuPanel = document.getElementById('saDateMenuPanel');
    if (dateMenu && dateMenuToggle && dateMenuPanel) {
        function closeDateMenu() {
            dateMenu.classList.remove('is-open');
            dateMenuToggle.setAttribute('aria-expanded', 'false');
            dateMenuPanel.hidden = true;
        }

        function openDateMenu() {
            dateMenu.classList.add('is-open');
            dateMenuToggle.setAttribute('aria-expanded', 'true');
            dateMenuPanel.hidden = false;
        }

        dateMenuToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            if (dateMenuPanel.hidden) {
                openDateMenu();
            } else {
                closeDateMenu();
            }
        });

        document.addEventListener('click', function (event) {
            if (!dateMenu.contains(event.target)) {
                closeDateMenu();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeDateMenu();
            }
        });
    }

    document.querySelectorAll('.sa-matrix-card[data-matrix]').forEach(function (card) {
        var childRows = card.querySelectorAll('.sa-row-child');
        var toggle = card.querySelector('.sa-tree-toggle');
        var viewAllBtn = card.querySelector('.sa-view-all-btn');
        var previewRows = parseInt(card.getAttribute('data-preview-rows') || '0', 10);
        var viewAllLabel = card.getAttribute('data-view-all-label') || 'rows';
        var parentOpen = false;

        function syncChildRows() {
            var showAll = card.classList.contains('is-preview-expanded');
            card.classList.toggle('is-tree-collapsed', !parentOpen);
            childRows.forEach(function (row, idx) {
                if (!parentOpen) {
                    row.style.display = 'none';
                    return;
                }
                if (previewRows > 0 && idx >= previewRows && !showAll) {
                    row.style.display = 'none';
                } else {
                    row.style.display = '';
                }
            });
            if (toggle) {
                toggle.classList.toggle('bi-chevron-down', parentOpen);
                toggle.classList.toggle('bi-chevron-right', !parentOpen);
                toggle.setAttribute('aria-expanded', parentOpen ? 'true' : 'false');
            }
        }

        syncChildRows();

        if (toggle) {
            toggle.addEventListener('click', function () {
                parentOpen = !parentOpen;
                if (!parentOpen) {
                    card.classList.remove('is-preview-expanded');
                }
                syncChildRows();
            });
        }

        if (viewAllBtn) {
            viewAllBtn.addEventListener('click', function () {
                var expanded = card.classList.toggle('is-preview-expanded');
                viewAllBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                var total = childRows.length;
                viewAllBtn.innerHTML = expanded
                    ? 'Show top ' + previewRows + ' <i class="bi bi-chevron-down" aria-hidden="true"></i>'
                    : 'View all ' + total.toLocaleString() + ' ' + viewAllLabel + ' <i class="bi bi-chevron-down" aria-hidden="true"></i>';
                parentOpen = true;
                syncChildRows();
            });
        }
    });
});
</script>

<?php
analytics_page_end();
