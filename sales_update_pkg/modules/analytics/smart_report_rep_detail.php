<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/smart_report_sales_helpers.php';
extract(analytics_bootstrap());

$filters = smart_report_sales_parse_filters();
$userId = (int) ($_GET['user_id'] ?? 0);
if ($userId > 0 && !analytics_user_in_company($pdo, $userId)) {
    http_response_code(403);
    analytics_page_start('Access denied', '', 'smart_report', false, false);
    echo '<p class="text-muted mb-0">This sales employee is not part of your company.</p>';
    analytics_page_end();
    exit;
}
$repName = trim((string) ($_GET['rep_name'] ?? ''));
if ($repName === '') {
    $repName = $userId > 0 ? 'Sales employee' : 'Unassigned';
}

$quotations = smart_report_rep_quotations($pdo, $filters, $userId);
$invoices = smart_report_rep_invoices($pdo, $filters, $userId);
$quoteTotal = array_sum(array_map(static fn(array $row): float => (float) ($row['total_amount'] ?? 0), $quotations));
$invoiceTotal = array_sum(array_map(static fn(array $row): float => (float) ($row['total_amount'] ?? 0), $invoices));

$repSnapshot = smart_report_rep_performance_snapshot($pdo, $filters, $userId, $repName, $quotations, $invoices);
$repInsights = smart_report_rep_build_insights($repSnapshot);

$saApiBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$repAiInsightsApi = $saApiBase . '/api/rep_ai_insights.php';
$jsBase = $saApiBase . '/js/smart_report_rep_detail.jsx';

$periodLabel = date('M j, Y', strtotime($filters['start_date']))
    . ' &ndash; '
    . date('M j, Y', strtotime($filters['end_date']));
$backUrl = smart_report_sales_back_url($filters);
$headerSubtitle = '<span class="sa-header-meta">'
    . '<a href="' . htmlspecialchars($backUrl) . '" class="sa-header-back">'
    . '<i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Sales Analytics</a>'
    . '<span class="sa-header-dot" aria-hidden="true">&middot;</span>'
    . '<span class="sa-header-period">' . $periodLabel . '</span>'
    . '</span>';

$repDetailData = [
    'repName' => $repName,
    'userId' => $userId,
    'startDate' => $filters['start_date'],
    'endDate' => $filters['end_date'],
    'backUrl' => $backUrl,
    'quoteTotal' => $quoteTotal,
    'invoiceTotal' => $invoiceTotal,
    'quotations' => array_map(static function (array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'order_number' => (string) ($row['order_number'] ?? ''),
            'quote_date' => (string) ($row['quote_date'] ?? ''),
            'total_amount' => (float) ($row['total_amount'] ?? 0),
            'customer_name' => (string) ($row['customer_name'] ?? 'Walk-in'),
            'status' => (string) ($row['status'] ?? ''),
        ];
    }, $quotations),
    'invoices' => array_map(static function (array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'invoice_number' => (string) ($row['invoice_number'] ?? ''),
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'total_amount' => (float) ($row['total_amount'] ?? 0),
            'customer_name' => (string) ($row['customer_name'] ?? 'Walk-in'),
            'status' => (string) ($row['status'] ?? ''),
        ];
    }, $invoices),
    'initialInsights' => [
        'achievements' => $repInsights['achievements'] ?? [],
        'suggestions' => $repInsights['suggestions'] ?? [],
        'source' => $repInsights['source'] ?? 'rules',
    ],
    'apis' => [
        'aiInsights' => $repAiInsightsApi,
    ],
    'links' => [
        'orderViewBase' => '../sales/orders/view.php?module=sales&id=',
        'invoiceViewBase' => '../sales/view.php?module=sales&id=',
    ],
];

analytics_page_start(
    $repName . ' - Quotations & Invoices',
    $headerSubtitle,
    'smart_report',
    false,
    false
);
?>

<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    body.da-page {
        background-color: #f8fafc !important;
        font-family: 'Outfit', sans-serif;
        color: #1e293b;
    }
    body.da-page .da-shell {
        max-width: 1400px;
    }
    body.da-page .employee-header.employee-header--page-context {
        padding-bottom: 8px !important;
    }
    .sa-header-meta {
        display: inline-flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        font-size: 13px;
        line-height: 1.4;
    }
    .sa-header-back {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #475569;
        text-decoration: none;
        font-weight: 600;
        white-space: nowrap;
    }
    .sa-header-back:hover {
        color: #2563eb;
        text-decoration: none;
    }
    .sa-header-dot {
        color: #cbd5e1;
        font-weight: 700;
    }
    .sa-header-period {
        color: #94a3b8;
        white-space: nowrap;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .animate-fade-in {
        animation: fadeIn 0.4s ease-out forwards;
    }
</style>

<div id="sa-rep-detail-root" class="py-2"></div>

<script>
window.REP_DETAIL_DATA = <?= json_encode($repDetailData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
<script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
<script src="https://unpkg.com/@babel/standalone@7.23.9/babel.min.js"></script>
<script type="text/babel" src="<?= htmlspecialchars($jsBase) ?>"></script>

<?php
analytics_page_end();
