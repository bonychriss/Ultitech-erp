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
function webSalesCustomers(PDO $pdo): array
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
    $quotes = [];
    $invoices = [];
    try {
        $st = $pdo->prepare("SELECT customer_id, COUNT(*) n, COALESCE(SUM(total_amount), 0) total FROM sales_orders WHERE customer_id IN ($in) GROUP BY customer_id");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $quotes[(int) $r['customer_id']] = $r;
        }
    } catch (Throwable $e) {
    }
    try {
        $st = $pdo->prepare("SELECT customer_id, COUNT(*) n, COALESCE(SUM(total_amount), 0) total, COALESCE(SUM(amount_paid), 0) paid
            FROM invoices WHERE customer_id IN ($in) AND COALESCE(status, '') <> 'cancelled' GROUP BY customer_id");
        $st->execute($ids);
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

function webSalesPanel(): string
{
    $pdo = webSalesPdo();
    if (!$pdo) {
        return '<section class="uq-page"><div class="uq-empty">The customer database is not available.</div></section>' . webQuoteRequestsCss();
    }
    try {
        $customers = webSalesCustomers($pdo);
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
        . '<div class="uq-top"><div class="uq-title"><div class="uq-title-row"><h1>Web sales</h1></div>'
        . '<p class="ws-sub">Customers in the system who buy through the website.</p></div></div>'
        . '<div class="uq-toolbar">';
    if ($customers !== []) {
        $html .= '<div class="uq-toolbar-search"><label class="uq-search">' . webQuoteIcon('search')
            . '<input type="search" id="ws-search" placeholder="Search customer, email or phone" aria-label="Search web sales customers" autocomplete="off"></label></div>';
    }
    $html .= '<div class="uq-toolbar-actions"><button type="button" class="uq-tbtn uq-tbtn--primary" id="ws-add-btn">'
        . webQuoteIcon('plus') . '<span>Add customers</span></button></div></div>'
        . '<div class="uq-kpis">'
        . $kpi('indigo', 'user', 'Web customers', (string) count($customers), 'Tagged as web sales')
        . $kpi('amber', 'doc', 'Quotes', (string) array_sum(array_column($customers, 'quotes')), 'For these customers')
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
            $html .= '<tr data-search="' . $h($search) . '">'
                . '<td><div class="ws-name">' . $h($c['name']) . '</div><div class="ws-muted">' . $h((string) $c['customer_code']) . '</div></td>'
                . '<td><div>' . $h((string) $c['email']) . '</div><div class="ws-muted">' . $h((string) $c['phone']) . '</div></td>'
                . '<td class="ws-num">' . (int) $c['quotes'] . '</td>'
                . '<td class="ws-num">' . (int) $c['invoices'] . '</td>'
                . '<td class="ws-num">' . $h(number_format($c['invoiced'], 2)) . '</td>'
                . '<td class="ws-num">' . $h(number_format($c['paid'], 2)) . '</td>'
                . '<td>' . $h($added) . '</td>'
                . '<td><button type="button" class="uq-delete ws-remove" data-id="' . (int) $c['id'] . '" data-name="' . $h($c['name']) . '" title="Remove from web sales" aria-label="Remove ' . $h($c['name']) . ' from web sales">'
                . webQuoteIcon('x') . '</button></td></tr>';
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
        $html .= '<label class="ws-pick" data-search="' . $h($search) . '"><input type="checkbox" value="' . (int) $c['id'] . '">'
            . '<span class="ws-pick-main"><span class="ws-name">' . $h($c['name']) . '</span>'
            . ($c['suggested'] ? ' <span class="ws-badge">Website quote</span>' : '')
            . '<span class="ws-muted">' . $h($detail) . '</span></span></label>';
    }
    $html .= '</div><div class="uq-filters-footer ws-modal-foot"><span class="ws-muted" id="ws-pick-count">0 selected</span>'
        . '<button type="button" class="uq-tbtn uq-tbtn--ghost" data-ws-close>Cancel</button>'
        . '<button type="button" class="uq-tbtn uq-tbtn--primary" id="ws-pick-save" disabled>Add selected</button></div>'
        . '</div></div></section>';

    return $html . webQuoteRequestsCss() . webSalesCss() . webSalesScript();
}

function webSalesCss(): string
{
    return <<<'CSS'
<style>
.ws-sub{margin:.25rem 0 0;color:#64748b;font-size:.9rem}
.ws-page .uq-top{margin-bottom:14px}
.ws-table-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:auto;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.ws-table{width:100%;border-collapse:collapse;font-size:.875rem;color:#334155}
.ws-table th{background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#64748b;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.03em;padding:.7rem 1rem;text-align:left;white-space:nowrap}
.ws-table td{border-bottom:1px solid #eef2f7;padding:.7rem 1rem;vertical-align:middle}
.ws-table tr:last-child td{border-bottom:0}
.ws-table tr[hidden]{display:none}
.ws-num{text-align:right!important;white-space:nowrap}
.ws-name{font-weight:600;color:#0f172a}
.ws-muted{color:#94a3b8;font-size:.78rem}
.ws-pad{padding:1rem}
.ws-badge{display:inline-block;margin-left:.35rem;padding:.05rem .45rem;border-radius:9999px;background:#ecfdf5;color:#047857;font-size:.68rem;font-weight:700;vertical-align:middle}
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

    function filter(input, selector) {
        if (!input) return;
        input.addEventListener("input", function () {
            var q = input.value.trim().toLowerCase();
            document.querySelectorAll(selector).forEach(function (el) {
                el.hidden = q !== "" && el.getAttribute("data-search").indexOf(q) === -1;
            });
        });
    }
    filter(document.getElementById("ws-search"), ".ws-table tbody tr");
    filter(document.getElementById("ws-pick-search"), ".ws-pick");

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
    modal.addEventListener("change", function () {
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
        saveBtn.textContent = "Adding...";
        post({ action: "add", customer_ids: selected() })
            .then(function () { window.location.reload(); })
            .catch(function (err) {
                alert(err.message);
                saveBtn.disabled = false;
                saveBtn.textContent = "Add selected";
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
