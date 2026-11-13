<?php

declare(strict_types=1);

require_once __DIR__ . '/web-services-lib.php';

function webSalesPdo(): ?PDO
{
    $salesFunctions = dirname(__DIR__, 2) . '/modules/sales/functions.php';
    if (!function_exists('sales_pdo') && is_file($salesFunctions)) {
        require_once $salesFunctions;
    }
    if (function_exists('sales_pdo')) {
        $pdo = sales_pdo();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }
    $pdo = $GLOBALS['pdo'] ?? null;

    return $pdo instanceof PDO ? $pdo : null;
}

function webSalesEnsureSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS website_sales_customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            added_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_website_sales_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $done = true;
}

function webSalesPageUrl(): string
{
    return function_exists('company_url') ? company_url('website/web-sales') : '/ultimate/website/web-sales';
}

function webSalesApiUrl(): string
{
    return function_exists('app_url') ? app_url('/api/website_sales.php') : '/api/website_sales.php';
}

function webSalesPhoneKey(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    return strlen($digits) >= 9 ? substr($digits, -9) : '';
}

function webSalesCustomerName(array $c): string
{
    $name = trim((string) ($c['company_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($c['contact_person'] ?? ''));
    }

    return $name !== '' ? $name : ('Customer #' . (int) ($c['id'] ?? 0));
}

/**
 * Active customers that are not yet tagged as web sales customers.
 *
 * @return list<array<string,mixed>>
 */
function webSalesAvailableCustomers(PDO $pdo): array
{
    webSalesEnsureSchema($pdo);
    $rows = $pdo->query("
        SELECT c.id, c.customer_code, c.company_name, c.contact_person, c.email, c.phone
        FROM customers c
        LEFT JOIN website_sales_customers w ON w.customer_id = c.id
        WHERE w.id IS NULL AND COALESCE(c.status, 'active') = 'active'
        ORDER BY COALESCE(NULLIF(c.company_name, ''), c.contact_person)
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $quoteEmails = [];
    $quotePhones = [];
    $quotePdo = $GLOBALS['pdo'] ?? $pdo;
    try {
        $q = $quotePdo->query("SELECT customer_email, customer_phone FROM website_quote_requests WHERE COALESCE(status, '') <> 'deleted'");
        foreach ($q ? $q->fetchAll(PDO::FETCH_ASSOC) : [] as $r) {
            $email = strtolower(trim((string) ($r['customer_email'] ?? '')));
            if ($email !== '') {
                $quoteEmails[$email] = true;
            }
            $phone = webSalesPhoneKey((string) ($r['customer_phone'] ?? ''));
            if ($phone !== '') {
                $quotePhones[$phone] = true;
            }
        }
    } catch (Throwable $e) {
    }

    foreach ($rows as &$row) {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        $phone = webSalesPhoneKey((string) ($row['phone'] ?? ''));
        $row['suggested'] = ($email !== '' && isset($quoteEmails[$email])) || ($phone !== '' && isset($quotePhones[$phone]));
        $row['name'] = webSalesCustomerName($row);
    }
    unset($row);
    usort($rows, static fn ($a, $b) => ($b['suggested'] <=> $a['suggested']) ?: strcasecmp($a['name'], $b['name']));

    return $rows;
}

/**
 * Tagged web sales customers with their quote and invoice totals.
 *
 * @return list<array<string,mixed>>
 */
function webSalesCustomers(PDO $pdo, string $from = '', string $to = ''): array
{
    webSalesEnsureSchema($pdo);
    $rows = $pdo->query('
        SELECT c.id, c.customer_code, c.company_name, c.contact_person, c.email, c.phone, w.created_at AS tagged_at
        FROM website_sales_customers w
        JOIN customers c ON c.id = w.customer_id
        ORDER BY w.created_at DESC, w.id DESC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return [];
    }

    $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $range = static function (string $col) use ($from, $to): array {
        $sql = '';
        $params = [];
        if ($from !== '') {
            $sql .= " AND $col >= ?";
            $params[] = $from;
        }
        if ($to !== '') {
            $sql .= " AND $col <= ?";
            $params[] = $to;
        }

        return [$sql, $params];
    };
    $quotes = [];
    $invoices = [];
    try {
        [$rangeSql, $rangeParams] = $range('COALESCE(order_date, DATE(created_at))');
        $st = $pdo->prepare("SELECT customer_id, COUNT(*) n, COALESCE(SUM(total_amount), 0) total FROM sales_orders WHERE customer_id IN ($in)$rangeSql GROUP BY customer_id");
        $st->execute(array_merge($ids, $rangeParams));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $quotes[(int) $r['customer_id']] = $r;
        }
    } catch (Throwable $e) {
    }
    try {
        [$rangeSql, $rangeParams] = $range('invoice_date');
        $st = $pdo->prepare("SELECT customer_id, COUNT(*) n, COALESCE(SUM(total_amount), 0) total, COALESCE(SUM(amount_paid), 0) paid
            FROM invoices WHERE customer_id IN ($in) AND COALESCE(status, '') <> 'cancelled'$rangeSql GROUP BY customer_id");
        $st->execute(array_merge($ids, $rangeParams));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $invoices[(int) $r['customer_id']] = $r;
        }
    } catch (Throwable $e) {
    }

    foreach ($rows as &$row) {
        $id = (int) $row['id'];
        $row['name'] = webSalesCustomerName($row);
        $row['quotes'] = (int) ($quotes[$id]['n'] ?? 0);
        $row['invoices'] = (int) ($invoices[$id]['n'] ?? 0);
        $row['invoiced'] = (float) ($invoices[$id]['total'] ?? 0);
        $row['paid'] = (float) ($invoices[$id]['paid'] ?? 0);
    }
    unset($row);

    return $rows;
}

/**
 * Quotations and invoices of one customer, newest first.
 *
 * @return array{quotes:list<array<string,mixed>>,invoices:list<array<string,mixed>>}
 */
function webSalesCustomerDocs(PDO $pdo, int $customerId, string $from = '', string $to = ''): array
{
    $docUrl = static function (string $page, int $id): string {
        if (function_exists('sales_module_url')) {
            return sales_module_url($page, ['id' => $id, 'module' => 'sales']);
        }

        return (function_exists('app_url') ? app_url('/modules/sales/' . $page) : '/modules/sales/' . $page) . '?module=sales&id=' . $id;
    };
    $range = static function (string $col) use ($from, $to): array {
        $sql = '';
        $params = [];
        if ($from !== '') {
            $sql .= " AND $col >= ?";
            $params[] = $from;
        }
        if ($to !== '') {
            $sql .= " AND $col <= ?";
            $params[] = $to;
        }

        return [$sql, $params];
    };

    $quotes = [];
    try {
        [$sql, $params] = $range('COALESCE(order_date, DATE(created_at))');
        $st = $pdo->prepare("SELECT id, COALESCE(NULLIF(formatted_number, ''), NULLIF(order_number, ''), CONCAT('#', id)) AS number,
                COALESCE(order_date, DATE(created_at)) AS doc_date, total_amount, status
            FROM sales_orders WHERE customer_id = ?$sql ORDER BY doc_date DESC, id DESC LIMIT 100");
        $st->execute(array_merge([$customerId], $params));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $quotes[] = [
                'number' => (string) $r['number'],
                'date' => (string) $r['doc_date'],
                'total' => (float) $r['total_amount'],
                'status' => (string) $r['status'],
                'url' => $docUrl('orders/view.php', (int) $r['id']),
            ];
        }
    } catch (Throwable $e) {
    }

    $invoices = [];
    try {
        [$sql, $params] = $range('invoice_date');
        $st = $pdo->prepare("SELECT id, invoice_number, invoice_date, total_amount, amount_paid, status
            FROM invoices WHERE customer_id = ?$sql ORDER BY invoice_date DESC, id DESC LIMIT 100");
        $st->execute(array_merge([$customerId], $params));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $invoices[] = [
                'number' => (string) $r['invoice_number'],
                'date' => (string) $r['invoice_date'],
                'total' => (float) $r['total_amount'],
                'paid' => (float) $r['amount_paid'],
                'status' => (string) $r['status'],
                'url' => $docUrl('invoices/view.php', (int) $r['id']),
            ];
        }
    } catch (Throwable $e) {
    }

    return ['quotes' => $quotes, 'invoices' => $invoices];
}

/**
 * @param list<int> $ids
 */
function webSalesAddCustomers(PDO $pdo, array $ids, int $userId): int
{
    webSalesEnsureSchema($pdo);
    $st = $pdo->prepare('INSERT IGNORE INTO website_sales_customers (customer_id, added_by) SELECT id, ? FROM customers WHERE id = ?');
    $added = 0;
    foreach ($ids as $id) {
        $st->execute([$userId ?: null, $id]);
        $added += $st->rowCount();
    }

    return $added;
}

function webSalesRemoveCustomer(PDO $pdo, int $id): bool
{
    webSalesEnsureSchema($pdo);
    $st = $pdo->prepare('DELETE FROM website_sales_customers WHERE customer_id = ?');
    $st->execute([$id]);

    return $st->rowCount() > 0;
}

/**
 * @return array{0:string,1:string,2:string} from, to (Y-m-d or ''), preset key
 */
function webSalesRange(): array
{
    $valid = static function ($v): string {
        $v = is_string($v) ? trim($v) : '';
        $d = DateTime::createFromFormat('Y-m-d', $v);

        return $d && $d->format('Y-m-d') === $v ? $v : '';
    };
    $preset = is_string($_GET['range'] ?? null) ? $_GET['range'] : '';
    $today = date('Y-m-d');
    switch ($preset) {
        case 'today':
            return [$today, $today, $preset];
        case '7':
        case '30':
            return [date('Y-m-d', strtotime('-' . ((int) $preset - 1) . ' days')), $today, $preset];
        case 'month':
            return [date('Y-m-01'), $today, $preset];
        case 'year':
            return [date('Y-01-01'), $today, $preset];
    }
    $from = $valid($_GET['from'] ?? '');
    $to = $valid($_GET['to'] ?? '');
    if ($from !== '' && $to !== '' && $from > $to) {
        [$from, $to] = [$to, $from];
    }

    return [$from, $to, ($from !== '' || $to !== '') ? 'custom' : 'all'];
}

function webSalesRangeLabel(string $from, string $to): string
{
    $fmt = static fn (string $d): string => date('d M Y', strtotime($d));
    if ($from === '' && $to === '') {
        return 'All time';
    }
    if ($from === '') {
        return 'Up to ' . $fmt($to);
    }
    if ($to === '') {
        return 'From ' . $fmt($from);
    }

    return $from === $to ? $fmt($from) : $fmt($from) . ' - ' . $fmt($to);
}

function webSalesPanel(): string
{
    $pdo = webSalesPdo();
    if (!$pdo) {
        return '<section class="uq-page"><div class="uq-empty">The customer database is not available.</div></section>' . webQuoteRequestsCss();
    }
    [$from, $to, $preset] = webSalesRange();
    try {
        $customers = webSalesCustomers($pdo, $from, $to);
        $available = webSalesAvailableCustomers($pdo);
    } catch (Throwable $e) {
        error_log('web sales panel: ' . $e->getMessage());

        return '<section class="uq-page"><div class="uq-empty">Web sales customers could not be loaded.</div></section>' . webQuoteRequestsCss();
    }

    $h = 'webQuoteH';
    $invoiced = array_sum(array_column($customers, 'invoiced'));
    $paid = array_sum(array_column($customers, 'paid'));
    $suggestedCount = count(array_filter($available, static fn ($c) => $c['suggested']));

    $kpi = static function (string $color, string $icon, string $label, string $value, string $helper, bool $money = false) use ($h): string {
        return '<div class="uq-kpi uq-kpi--' . $color . '"><span class="uq-kpi-icon uq-kpi-icon--' . $color . '">' . webQuoteIcon($icon) . '</span>'
            . '<div class="uq-kpi-body"><div class="uq-kpi-label">' . $h($label) . '</div>'
            . '<div class="uq-kpi-value' . ($money ? ' uq-kpi-value--money' : '') . '">' . $h($value) . '</div>'
            . '<div class="uq-kpi-helper">' . $h($helper) . '</div></div></div>';
    };

    $html = '<section class="uq-page ws-page" data-api="' . $h(webSalesApiUrl()) . '" data-csrf="' . $h(webQuoteCsrf()) . '">'
        . '<div class="uq-top"><div class="uq-title"><div class="uq-title-row"><h1>Web sales</h1></div></div></div>'
        . '<div class="uq-toolbar">';
    if ($customers !== []) {
        $html .= '<div class="uq-toolbar-search"><label class="uq-search">' . webQuoteIcon('search')
            . '<input type="search" id="ws-search" placeholder="Search customer, email or phone" aria-label="Search web sales customers" autocomplete="off"></label></div>';
    }
    $presets = ['all' => 'All time', 'today' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days', 'month' => 'This month', 'year' => 'This year', 'custom' => 'Custom range'];
    $html .= '<div class="uq-toolbar-actions"><form class="ws-range" method="get" action="' . $h(webSalesPageUrl()) . '" aria-label="Date range">'
        . '<select name="range" id="ws-range-preset" aria-label="Date range">';
    foreach ($presets as $key => $label) {
        $key = (string) $key;
        $html .= '<option value="' . $h($key) . '"' . ($key === $preset ? ' selected' : '') . '>' . $h($label) . '</option>';
    }
    $html .= '</select>'
        . '<label><span>From</span><input type="date" name="from" value="' . $h($from) . '" max="' . date('Y-m-d') . '"></label>'
        . '<label><span>To</span><input type="date" name="to" value="' . $h($to) . '" max="' . date('Y-m-d') . '"></label>'
        . '<button type="submit">Apply</button></form>'
        . '<button type="button" class="uq-tbtn uq-tbtn--primary" id="ws-add-btn">'
        . webQuoteIcon('plus') . '<span>Add customers</span></button></div></div>'
        . '<div class="uq-kpis">'
        . $kpi('indigo', 'user', 'Web customers', (string) count($customers), 'Tagged as web sales')
        . $kpi('amber', 'doc', 'Quotes', (string) array_sum(array_column($customers, 'quotes')), webSalesRangeLabel($from, $to))
        . $kpi('violet', 'list', 'Invoiced', webQuoteMoney($invoiced), array_sum(array_column($customers, 'invoices')) . ' invoices', true)
        . $kpi('teal', 'box', 'Paid', webQuoteMoney($paid), 'Balance ' . webQuoteMoney($invoiced - $paid), true)
        . '</div>';

    if ($customers === []) {
        $html .= '<div class="uq-empty">No web sales customers yet. Use <strong>Add customers</strong> to pick customers from the system.</div>';
    } else {
        $html .= '<div class="ws-table-wrap"><table class="ws-table"><thead><tr>'
            . '<th>Customer</th><th>Contact</th><th class="ws-num">Quotes</th><th class="ws-num">Invoices</th>'
            . '<th class="ws-num">Invoiced</th><th class="ws-num">Paid</th><th>Added</th><th></th></tr></thead><tbody>';
        foreach ($customers as $c) {
            $search = strtolower(implode(' ', [$c['name'], $c['contact_person'], $c['email'], $c['phone'], $c['customer_code']]));
            $added = ($ts = strtotime((string) $c['tagged_at'])) ? date('d M Y', $ts) : '';
            $html .= '<tr class="ws-row" data-id="' . (int) $c['id'] . '" data-search="' . $h($search) . '" title="Show quotations and invoices">'
                . '<td><div class="ws-name-cell"><span class="ws-chev">' . webQuoteIcon('chevron') . '</span><div><div class="ws-name">' . $h($c['name']) . '</div><div class="ws-muted">' . $h((string) $c['customer_code']) . '</div></div></div></td>'
                . '<td><div>' . $h((string) $c['email']) . '</div><div class="ws-muted">' . $h((string) $c['phone']) . '</div></td>'
                . '<td class="ws-num">' . (int) $c['quotes'] . '</td>'
                . '<td class="ws-num">' . (int) $c['invoices'] . '</td>'
                . '<td class="ws-num">' . $h(number_format($c['invoiced'], 2)) . '</td>'
                . '<td class="ws-num">' . $h(number_format($c['paid'], 2)) . '</td>'
                . '<td>' . $h($added) . '</td>'
                . '<td><button type="button" class="uq-delete ws-remove" data-id="' . (int) $c['id'] . '" data-name="' . $h($c['name']) . '" title="Remove from web sales" aria-label="Remove ' . $h($c['name']) . ' from web sales">'
                . webQuoteIcon('x') . '</button></td></tr>'
                . '<tr class="ws-detail" data-for="' . (int) $c['id'] . '" hidden><td colspan="8"><div class="ws-docs"></div></td></tr>';
        }
        $html .= '</tbody></table></div>';
    }

    $html .= '<div class="uq-modal" id="ws-modal" hidden><div class="uq-modal-box ws-modal-box" role="dialog" aria-modal="true" aria-labelledby="ws-modal-title">'
        . '<div class="ws-modal-head"><div><h2 id="ws-modal-title">Add web sales customers</h2>'
        . '<p>Select customers from the system who buy through the website.'
        . ($suggestedCount > 0 ? ' Customers marked <span class="ws-badge">Website quote</span> have sent a quote request from the website.' : '')
        . '</p></div><button type="button" class="uq-filters-close" data-ws-close aria-label="Close">' . webQuoteIcon('x') . '</button></div>'
        . '<div class="ws-modal-search"><label class="uq-search">' . webQuoteIcon('search')
        . '<input type="search" id="ws-pick-search" placeholder="Search customers" aria-label="Search customers" autocomplete="off"></label></div>'
        . '<div class="ws-pick-list" id="ws-pick-list">';
    if ($available === []) {
        $html .= '<div class="ws-muted ws-pad">Every active customer is already in web sales.</div>';
    }
    foreach ($available as $c) {
        $search = strtolower(implode(' ', [$c['name'], $c['contact_person'], $c['email'], $c['phone'], $c['customer_code']]));
        $detail = implode(' | ', array_filter([(string) $c['email'], (string) $c['phone']]));
        if ($detail === '') {
            $detail = (string) $c['customer_code'];
        }
        $html .= '<div class="ws-pick-item" data-search="' . $h($search) . '">'
            . '<label class="ws-pick"><input type="checkbox" value="' . (int) $c['id'] . '">'
            . '<span class="ws-pick-main"><span class="ws-name">' . $h($c['name']) . '</span>'
            . ($c['suggested'] ? ' <span class="ws-badge">Website quote</span>' : '')
            . '<span class="ws-muted">' . $h($detail) . '</span></span></label>'
            . '<div class="ws-docs ws-pick-docs" hidden></div></div>';
    }
    $html .= '</div><div class="uq-filters-footer ws-modal-foot"><span class="ws-muted" id="ws-pick-count">0 selected</span>'
        . '<button type="button" class="uq-tbtn uq-tbtn--ghost" data-ws-close>Cancel</button>'
        . '<button type="button" class="uq-tbtn uq-tbtn--primary" id="ws-pick-save" disabled>Save to web customers</button></div>'
        . '</div></div></section>';

    return $html . webQuoteRequestsCss() . webSalesCss() . webSalesScript();
}

function webSalesCss(): string
{
    return <<<'CSS'
<style>
.ws-page .uq-top{margin-bottom:14px}
.ws-table-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:auto;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.ws-table{width:100%;border-collapse:collapse;font-size:.875rem;color:#334155}
.ws-table th{background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#64748b;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.03em;padding:.7rem 1rem;text-align:left;white-space:nowrap}
.ws-table td{border-bottom:1px solid #eef2f7;padding:.7rem 1rem;vertical-align:middle}
.ws-table tr:last-child td{border-bottom:0}
.ws-table tr[hidden]{display:none}
.ws-num{text-align:right!important;white-space:nowrap}
.ws-name{font-weight:500;color:#0f172a}
.ws-page h1{font-weight:600}
.ws-page .uq-kpi-value{font-weight:500}
.ws-page .uq-tbtn{font-weight:500}
#ws-add-btn{border-radius:9999px!important;padding:.45rem 1.1rem;font-weight:500}
#ws-add-btn svg{width:15px;height:15px;flex-shrink:0;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.ws-page .uq-toolbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem}
.ws-page .uq-toolbar-search{flex:0 1 26rem}
.ws-page .uq-toolbar-actions{margin-left:auto}
.ws-range{display:flex;align-items:center;flex-wrap:wrap;gap:.5rem;margin:0}
.ws-range label{display:flex;align-items:center;gap:.35rem;margin:0;font-size:.8rem;font-weight:400;color:#64748b}
.ws-range select,.ws-range input{height:2.25rem;padding:0 .6rem;border:1px solid #e2e8f0;border-radius:8px;background:#fff;font:inherit;font-size:.8125rem;color:#0f172a}
.ws-range select{padding-right:1.75rem}
.ws-range select:focus,.ws-range input:focus{outline:0;border-color:#a5b4fc;box-shadow:0 0 0 3px rgba(99,102,241,.12)}
.ws-range button{height:2.25rem;padding:0 1rem;border:1px solid #4f46e5;border-radius:9999px!important;background:#fff;color:#4f46e5;font:inherit;font-size:.8125rem;font-weight:500;cursor:pointer}
.ws-range button:hover{background:#eef2ff}
.ws-pick-item[hidden],.ws-docs[hidden],.ws-detail[hidden]{display:none}
.ws-row{cursor:pointer}
.ws-row:hover td{background:#f8fafc}
.ws-row.is-open td{background:#f5f7ff;border-bottom-color:transparent}
.ws-name-cell{display:flex;align-items:center;gap:.5rem}
.ws-chev{display:inline-flex;color:#94a3b8;transition:transform .15s}
.ws-chev svg{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2}
.ws-row.is-open .ws-chev{transform:rotate(90deg);color:#4f46e5}
.ws-detail > td{background:#f5f7ff;padding:0 1rem 1rem!important}
.ws-docs{font-size:.8rem}
.ws-pick-docs{margin:0 1.1rem .75rem 2.75rem;padding:.6rem .75rem;border:1px solid #e8edf3;border-radius:10px;background:#f8fafc}
.ws-docs-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.ws-pick-docs .ws-docs-grid{grid-template-columns:1fr}
.ws-docs-col{background:#fff;border:1px solid #e8edf3;border-radius:10px;overflow:hidden}
.ws-docs-title{padding:.5rem .75rem;font-weight:500;color:#334155;border-bottom:1px solid #eef2f7}
.ws-docs-title span{display:inline-block;min-width:1.4rem;padding:0 .35rem;margin-left:.25rem;border-radius:9999px;background:#eef2ff;color:#4f46e5;font-size:.7rem;text-align:center}
.ws-docs-table{width:100%;border-collapse:collapse}
.ws-docs-table th{padding:.4rem .75rem;font-size:.68rem;font-weight:500;color:#94a3b8;text-transform:uppercase;letter-spacing:.03em;text-align:left;background:#fafbfc}
.ws-docs-table td{padding:.4rem .75rem;border-top:1px solid #f1f5f9;color:#334155}
.ws-docs-table a{color:#4f46e5;text-decoration:none}
.ws-docs-table a:hover{text-decoration:underline}
.ws-status{display:inline-block;padding:.05rem .45rem;border-radius:9999px;background:#f1f5f9;color:#475569;font-size:.7rem;text-transform:capitalize}
.ws-docs-empty{padding:.6rem .75rem}
.ws-docs-error{color:#b91c1c}
@media (max-width: 900px){.ws-docs-grid{grid-template-columns:1fr}}
.ws-crumb-row{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding-right:1.25rem}
.ws-crumb-row > nav{flex:1 1 auto;min-width:0}
.ws-table th{font-weight:500}
.ws-table td,.ws-pick,.ws-pick-main{font-weight:400}
.ws-modal-head h2{font-weight:600}
.ws-muted{color:#94a3b8;font-size:.78rem}
.ws-pad{padding:1rem}
.ws-badge{display:inline-block;margin-left:.35rem;padding:.05rem .45rem;border-radius:9999px;background:#ecfdf5;color:#047857;font-size:.68rem;font-weight:600;vertical-align:middle}
.uq-modal-box.ws-modal-box{width:min(560px,calc(100vw - 24px));max-height:calc(100vh - 48px);display:flex;flex-direction:column;padding:0;overflow:hidden;border-top:0;border-radius:14px}
.ws-modal-head{display:flex;justify-content:space-between;gap:.75rem;padding:1rem 1.1rem .75rem;border-bottom:1px solid #f1f5f9}
.ws-modal-head h2{margin:0;font-size:1.05rem;font-weight:700;color:#0f172a}
.ws-modal-head p{margin:.25rem 0 0;font-size:.8rem;color:#64748b}
.ws-modal-search{padding:.75rem 1.1rem}
.ws-pick-list{flex:1 1 auto;overflow:auto;border-top:1px solid #f1f5f9;min-height:180px}
.ws-pick{display:flex;align-items:flex-start;gap:.7rem;padding:.6rem 1.1rem;border-bottom:1px solid #f5f7fa;cursor:pointer;margin:0}
.ws-pick:hover{background:#f8fafc}
.ws-pick[hidden]{display:none}
.ws-pick input{width:16px;height:16px;margin-top:.15rem;accent-color:#4f46e5;flex-shrink:0}
.ws-pick-main{display:flex;flex-wrap:wrap;align-items:center;gap:0 .2rem;min-width:0}
.ws-pick-main .ws-muted{flex-basis:100%}
.ws-modal-foot{align-items:center;margin-top:0}
.ws-modal-foot #ws-pick-count{margin-right:auto}
.ws-modal-foot .uq-tbtn--primary:disabled{opacity:.5;cursor:not-allowed}
</style>
CSS;
}

function webSalesScript(): string
{
    return <<<'JS'
<script>
(function () {
    var page = document.querySelector(".ws-page");
    if (!page) return;
    var api = page.getAttribute("data-api");
    var csrf = page.getAttribute("data-csrf");
    var modal = document.getElementById("ws-modal");
    var saveBtn = document.getElementById("ws-pick-save");
    var countEl = document.getElementById("ws-pick-count");

    var rangeForm = page.querySelector(".ws-range");
    if (rangeForm) {
        var preset = rangeForm.querySelector("select[name=range]");
        preset.addEventListener("change", function () {
            if (preset.value !== "custom") rangeForm.submit();
        });
        rangeForm.querySelectorAll("input[type=date]").forEach(function (input) {
            input.addEventListener("change", function () { preset.value = "custom"; });
        });
    }

    var crumb = document.querySelector("nav.erp-auto-breadcrumb");
    var addBtn = document.getElementById("ws-add-btn");
    if (crumb && addBtn) {
        var row = document.createElement("div");
        row.className = "ws-crumb-row";
        crumb.parentNode.insertBefore(row, crumb);
        row.appendChild(crumb);
        row.appendChild(addBtn);
    }

    function filter(input, selector) {
        if (!input) return;
        input.addEventListener("input", function () {
            var q = input.value.trim().toLowerCase();
            document.querySelectorAll(selector).forEach(function (el) {
                el.hidden = q !== "" && el.getAttribute("data-search").indexOf(q) === -1;
            });
        });
    }
    filter(document.getElementById("ws-search"), ".ws-table tbody tr.ws-row");
    filter(document.getElementById("ws-pick-search"), ".ws-pick-item");
    var tableSearch = document.getElementById("ws-search");
    if (tableSearch) {
        tableSearch.addEventListener("input", function () {
            document.querySelectorAll(".ws-detail").forEach(function (d) {
                var row = document.querySelector('.ws-row[data-id="' + d.getAttribute("data-for") + '"]');
                if (row && row.hidden) { d.hidden = true; row.classList.remove("is-open"); }
            });
        });
    }

    function esc(s) {
        return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
    }
    function money(n) {
        return Number(n || 0).toLocaleString("en", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function shortDate(d) {
        var t = new Date(String(d || "").replace(" ", "T"));
        return isNaN(t) ? esc(d) : t.toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" });
    }
    function docList(title, rows, withPaid) {
        var html = '<div class="ws-docs-col"><div class="ws-docs-title">' + title + ' <span>' + rows.length + '</span></div>';
        if (!rows.length) return html + '<div class="ws-muted ws-docs-empty">None</div></div>';
        html += '<table class="ws-docs-table"><thead><tr><th>Number</th><th>Date</th><th class="ws-num">Amount</th>'
            + (withPaid ? '<th class="ws-num">Paid</th>' : '') + '<th>Status</th></tr></thead><tbody>';
        rows.forEach(function (r) {
            html += '<tr><td><a href="' + esc(r.url) + '" target="_blank" rel="noopener">' + esc(r.number) + '</a></td>'
                + '<td>' + shortDate(r.date) + '</td><td class="ws-num">' + money(r.total) + '</td>'
                + (withPaid ? '<td class="ws-num">' + money(r.paid) + '</td>' : '')
                + '<td><span class="ws-status">' + esc(r.status || "-") + '</span></td></tr>';
        });
        return html + '</tbody></table></div>';
    }
    function loadDocs(box, customerId, withRange) {
        box.hidden = false;
        if (box.getAttribute("data-loaded") === (withRange ? "range" : "all")) return;
        box.innerHTML = '<div class="ws-muted ws-docs-empty">Loading quotations and invoices...</div>';
        var qs = "action=docs&customer_id=" + encodeURIComponent(customerId);
        if (withRange) {
            var cur = new URLSearchParams(window.location.search);
            ["range", "from", "to"].forEach(function (k) { if (cur.get(k)) qs += "&" + k + "=" + encodeURIComponent(cur.get(k)); });
        }
        fetch(api + (api.indexOf("?") === -1 ? "?" : "&") + qs, { credentials: "same-origin", headers: { "Accept": "application/json" } })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (!json || !json.success) throw new Error((json && json.message) || "Could not load documents.");
                box.innerHTML = '<div class="ws-docs-grid">' + docList("Quotations", json.quotes || [], false)
                    + docList("Invoices", json.invoices || [], true) + '</div>';
                box.setAttribute("data-loaded", withRange ? "range" : "all");
            })
            .catch(function (err) {
                box.innerHTML = '<div class="ws-docs-empty ws-docs-error">' + esc(err.message) + '</div>';
            });
    }

    document.querySelectorAll(".ws-row").forEach(function (row) {
        row.addEventListener("click", function (e) {
            if (e.target.closest("button, a")) return;
            var detail = document.querySelector('.ws-detail[data-for="' + row.getAttribute("data-id") + '"]');
            if (!detail) return;
            var open = detail.hidden;
            detail.hidden = !open;
            row.classList.toggle("is-open", open);
            if (open) loadDocs(detail.querySelector(".ws-docs"), row.getAttribute("data-id"), true);
        });
    });

    function post(data) {
        var body = new FormData();
        Object.keys(data).forEach(function (k) {
            [].concat(data[k]).forEach(function (v) { body.append(Array.isArray(data[k]) ? k + "[]" : k, v); });
        });
        body.append("csrf_token", csrf);
        return fetch(api, { method: "POST", credentials: "same-origin", headers: { "Accept": "application/json" }, body: body })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (!json || !json.success) throw new Error((json && json.message) || "The change could not be saved.");
                return json;
            });
    }

    function selected() {
        return Array.prototype.map.call(modal.querySelectorAll(".ws-pick input:checked"), function (i) { return i.value; });
    }
    modal.addEventListener("change", function (e) {
        var box = e.target.closest(".ws-pick-item");
        if (box && e.target.type === "checkbox") {
            var docs = box.querySelector(".ws-pick-docs");
            if (e.target.checked) loadDocs(docs, e.target.value, false);
            else docs.hidden = true;
        }
        var n = selected().length;
        countEl.textContent = n + " selected";
        saveBtn.disabled = n === 0;
    });
    document.getElementById("ws-add-btn").addEventListener("click", function () {
        modal.hidden = false;
        var s = document.getElementById("ws-pick-search");
        if (s) s.focus();
    });
    modal.querySelectorAll("[data-ws-close]").forEach(function (b) {
        b.addEventListener("click", function () { modal.hidden = true; });
    });
    modal.addEventListener("click", function (e) { if (e.target === modal) modal.hidden = true; });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") modal.hidden = true; });

    saveBtn.addEventListener("click", function () {
        saveBtn.disabled = true;
        saveBtn.textContent = "Saving...";
        post({ action: "add", customer_ids: selected() })
            .then(function () { window.location.reload(); })
            .catch(function (err) {
                alert(err.message);
                saveBtn.disabled = false;
                saveBtn.textContent = "Save to web customers";
            });
    });

    document.querySelectorAll(".ws-remove").forEach(function (btn) {
        btn.addEventListener("click", function () {
            if (!confirm("Remove " + btn.getAttribute("data-name") + " from web sales? The customer stays in the system.")) return;
            btn.disabled = true;
            post({ action: "remove", customer_id: btn.getAttribute("data-id") })
                .then(function () { window.location.reload(); })
                .catch(function (err) { alert(err.message); btn.disabled = false; });
        });
    });
})();
</script>
JS;
}
