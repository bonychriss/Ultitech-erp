<?php
// modules/analytics/smart_report_sales.php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/smart_report_sales_helpers.php';
extract(analytics_bootstrap());

$saFilters = smart_report_sales_parse_filters();
$salesDetail = smart_report_sales_drilldown($pdo, $saFilters);
$salesDetail['revenue_matrices'] = smart_report_sales_revenue_matrices($pdo, $saFilters);
$salesDetail['ranking_matrices'] = smart_report_ranking_matrices($pdo, $saFilters);
$salesDetail['pipeline_matrices'] = smart_report_pipeline_matrices($pdo, $saFilters);

$saDataVerification = ['accurate' => false, 'issue_count' => 0, 'issues' => []];
$saVerifyAnalysis = ['summary' => 'Verification could not be completed.', 'details' => []];
$saVerifyServiceOk = true;
$saShowSalesData = false;

try {
    $saDataVerification = smart_report_sales_verify_displayed_data($pdo, $saFilters, $salesDetail);
    $saVerifyAnalysis = smart_report_sales_ai_verify_analysis($pdo, $saDataVerification);
    $saShowSalesData = !empty($saDataVerification['accurate']);
} catch (Throwable $e) {
    error_log('smart_report_sales verify: ' . $e->getMessage());
    $saVerifyServiceOk = false;
    $saShowSalesData = false;
}

$saApiBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$customerInvoicesApi = $saApiBase . '/api/customer_invoices.php';

$saDateLabel = date('M j, Y', strtotime($saFilters['start_date']))
    . ' &ndash; '
    . date('M j, Y', strtotime($saFilters['end_date']));
$saHeaderSubtitle = '<span class="sa-header-meta">'
    . '<a href="smart_report.php?module=analytics" class="sa-header-back">'
    . '<i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Smart Report</a>'
    . '<span class="sa-header-dot" aria-hidden="true">&middot;</span>'
    . '<span class="sa-header-period">' . $saDateLabel . '</span>'
    . '</span>';

analytics_page_start(
    'Sales Analytics',
    $saHeaderSubtitle,
    'smart_report',
    false,
    false
);
?>

<?php include __DIR__ . '/includes/smart_report_page_styles.php'; ?>

<div class="sa-page">
    <?php include __DIR__ . '/includes/smart_report_sales_filters.php'; ?>
    <?= smart_report_render_data_checker_result($saDataVerification, $saVerifyAnalysis, $saVerifyServiceOk) ?>
    <?php if ($saShowSalesData): ?>
    <div class="sa-intelligence"
         data-start-date="<?= htmlspecialchars($saFilters['start_date']) ?>"
         data-end-date="<?= htmlspecialchars($saFilters['end_date']) ?>"
         data-customer-invoices-api="<?= htmlspecialchars($customerInvoicesApi) ?>">
        <?= smart_report_render_sales_drilldown_html($salesDetail) ?>
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
                    card.querySelectorAll('.sa-row-drill-panel').forEach(function (panel) {
                        panel.remove();
                    });
                    card.querySelectorAll('.sa-row-drillable.sa-row-selected').forEach(function (row) {
                        row.classList.remove('sa-row-selected');
                    });
                    if (viewAllBtn) {
                        viewAllBtn.setAttribute('aria-expanded', 'false');
                    }
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

    var intelligenceRoot = document.querySelector('.sa-intelligence');
    if (intelligenceRoot) {
        var invoicesApi = intelligenceRoot.getAttribute('data-customer-invoices-api') || 'api/customer_invoices.php';
        var periodStart = intelligenceRoot.getAttribute('data-start-date') || '';
        var periodEnd = intelligenceRoot.getAttribute('data-end-date') || '';


        function matrixColspan(table) {
            var head = table ? table.querySelector('thead tr') : null;
            return head ? head.children.length : 1;
        }

        function closeDrillPanels(table) {
            if (!table) return;
            table.querySelectorAll('.sa-row-drill-panel').forEach(function (panel) {
                panel.remove();
            });
            table.querySelectorAll('.sa-row-drillable.sa-row-selected').forEach(function (row) {
                row.classList.remove('sa-row-selected');
                row.setAttribute('aria-expanded', 'false');
            });
        }

        function openCustomerDrill(row) {
            var table = row.closest('table');
            if (!table) return;

            var existingPanel = row.nextElementSibling;
            if (existingPanel && existingPanel.classList.contains('sa-row-drill-panel')) {
                existingPanel.remove();
                row.classList.remove('sa-row-selected');
                row.setAttribute('aria-expanded', 'false');
                return;
            }

            closeDrillPanels(table);
            row.classList.add('sa-row-selected');

            var panelRow = document.createElement('tr');
            panelRow.className = 'sa-row-drill-panel';
            var panelCell = document.createElement('td');
            panelCell.colSpan = matrixColspan(table);
            panelCell.innerHTML = '<div class="sa-drill-loading text-center"><div class="spinner-border spinner-border-sm text-primary" role="status"></div> Loading invoices...</div>';
            panelRow.appendChild(panelCell);
            row.parentNode.insertBefore(panelRow, row.nextSibling);

            var params = new URLSearchParams({
                module: 'analytics',
                customer_id: row.getAttribute('data-customer-id') || '0',
                customer_label: row.getAttribute('data-customer-label') || '',
                start_date: periodStart,
                end_date: periodEnd
            });

            fetch(invoicesApi + '?' + params.toString(), { credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (data && data.success) {
                        panelCell.innerHTML = data.html || '<p class="sa-drill-empty text-muted mb-0">No invoices found.</p>';
                    } else {
                        panelCell.innerHTML = '<p class="text-danger mb-0 sa-drill-empty">' + ((data && data.error) || 'Failed to load invoices.') + '</p>';
                    }
                })
                .catch(function () {
                    panelCell.innerHTML = '<p class="text-danger mb-0 sa-drill-empty">Failed to load invoices.</p>';
                });
        }

        function bindDrillRow(row, openFn) {
            row.setAttribute('role', 'button');
            row.setAttribute('tabindex', '0');
            row.setAttribute('aria-expanded', 'false');

            row.addEventListener('click', function (event) {
                if (event.target.closest('input, button, a')) return;
                openFn(row);
                var panelOpen = row.nextElementSibling && row.nextElementSibling.classList.contains('sa-row-drill-panel');
                row.setAttribute('aria-expanded', panelOpen ? 'true' : 'false');
            });

            row.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                if (event.target.closest('input, button, a')) return;
                event.preventDefault();
                openFn(row);
                var panelOpen = row.nextElementSibling && row.nextElementSibling.classList.contains('sa-row-drill-panel');
                row.setAttribute('aria-expanded', panelOpen ? 'true' : 'false');
            });
        }

        document.querySelectorAll('#sa-customer-rank .sa-row-drillable').forEach(function (row) {
            bindDrillRow(row, openCustomerDrill);
        });

        document.querySelectorAll('#sa-rep-performance .sa-row-rep-link').forEach(function (row) {
            row.setAttribute('role', 'link');
            row.setAttribute('tabindex', '0');

            row.addEventListener('click', function (event) {
                if (event.target.closest('button, a')) {
                    return;
                }
                var href = row.getAttribute('data-href');
                if (href) {
                    window.location.href = href;
                }
            });

            row.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                if (event.target.closest('button, a')) {
                    return;
                }
                event.preventDefault();
                var href = row.getAttribute('data-href');
                if (href) {
                    window.location.href = href;
                }
            });
        });
    }
});
</script>

<?php
analytics_page_end();
