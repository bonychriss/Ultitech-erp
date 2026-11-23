<?php
/**
 * Global "Home / Module / Page" breadcrumb rendered under the shared employee header.
 *
 * Page opt-outs / overrides (set before including the header):
 *   $hideErpBreadcrumbs = true;
 *   $erpBreadcrumbs = [['label' => 'Stock', 'href' => '...'], ['label' => 'Current page']];
 */

if (!function_exists('erp_breadcrumb_items')) {
    /**
     * @return list<array{label:string, href:?string}>
     */
    function erp_breadcrumb_items(?string $currentTitle = null): array
    {
        $homeHref = function_exists('company_url') ? company_url('select-module') : null;
        $home = ['label' => 'Home', 'href' => $homeHref];

        if (!empty($GLOBALS['erpBreadcrumbs']) && is_array($GLOBALS['erpBreadcrumbs'])) {
            return array_merge([$home], array_values($GLOBALS['erpBreadcrumbs']));
        }

        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = '/' . trim(rawurldecode($path), '/');
        $base = defined('APP_BASE_PATH') ? '/' . trim((string) APP_BASE_PATH, '/') : '';
        if ($base !== '/' && $base !== '' && stripos($path . '/', $base . '/') === 0) {
            $path = substr($path, strlen($base));
        }
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($s) => $s !== ''));

        $slug = strtolower(trim((string) ($_SESSION['company_slug'] ?? '')));
        if ($slug !== '' && isset($segments[0]) && strtolower($segments[0]) === $slug) {
            array_shift($segments);
        }
        if (!$segments) {
            return [];
        }

        $last = (string) end($segments);
        if (preg_match('#^(select-module|login|logout|index)(\.php)?$#i', $last) && count($segments) === 1) {
            return [];
        }

        $hidden = ['modules', 'includes', 'desks', 'pages', 'partials', 'employee', 'public', 'views'];
        $labels = [
            'stock' => 'Stock', 'stocks' => 'Stock', 'sales' => 'Sales', 'expenses' => 'Expenses',
            'balances' => 'Balances', 'accounting' => 'Accounting', 'purchases' => 'Purchases',
            'products' => 'Products', 'suppliers' => 'Suppliers', 'shipments' => 'Shipments',
            'reports' => 'Reports', 'transfers' => 'Transfers', 'brands' => 'Brands',
            'categories' => 'Categories', 'admin' => 'Admin', 'payroll' => 'Payroll',
            'deliveries' => 'Deliveries', 'dispatch' => 'Dispatch', 'finance' => 'Finance',
            'crm' => 'CRM', 'orders' => 'Orders', 'invoices' => 'Invoices', 'customers' => 'Customers',
            'payments' => 'Payments', 'revenue' => 'Revenue', 'attendance' => 'Attendance',
            'petty-cash' => 'Petty Cash', 'sales-reports' => 'Sales Reports', 'vendor-bills' => 'Vendor Bills',
            'todo' => 'To-do', 'tasks' => 'Tasks', 'weekly_tasks' => 'Weekly Tasks', 'email' => 'Email',
            'backup' => 'Backup', 'analytics' => 'Analytics', 'personalization' => 'Personalization',
            'outstanding-invoices' => 'Outstanding Invoices', 'customer_statement' => 'Customer Statement',
            'cover-page' => 'Cover Page',
        ];
        $title = static function (string $seg) use ($labels): string {
            $key = strtolower(preg_replace('/\.php$/i', '', $seg));
            if (isset($labels[$key])) {
                return $labels[$key];
            }
            return ucwords(trim(str_replace(['-', '_'], ' ', $key)));
        };

        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $linkBase = static function (string $rel): string {
            return function_exists('company_url') ? company_url($rel) : ('/' . ltrim($rel, '/'));
        };

        $crumbs = [$home];
        $n = count($segments);
        for ($i = 0; $i < $n - 1; $i++) {
            $seg = $segments[$i];
            if (in_array(strtolower($seg), $hidden, true)) {
                continue;
            }
            $rel = implode('/', array_slice($segments, 0, $i + 1));
            $href = null;
            foreach (['index.php', 'dashboard.php'] as $entry) {
                if (is_file($root . '/' . $rel . '/' . $entry)) {
                    $href = $linkBase($rel . '/' . $entry);
                    break;
                }
            }
            $crumbs[] = ['label' => $title($seg), 'href' => $href];
        }

        $isIndex = (bool) preg_match('#^(index|dashboard)(\.php)?$#i', $last);
        $current = trim((string) $currentTitle);
        if ($current !== '') {
            $current = trim((string) preg_split('/\s+[|\-\x{2013}\x{2014}]\s+/u', $current)[0]);
        }
        if ($current === '') {
            $current = $isIndex && $n > 1 ? $title($segments[$n - 2]) : $title($last);
        }
        if (count($crumbs) > 1 && strcasecmp((string) $crumbs[count($crumbs) - 1]['label'], $current) === 0) {
            array_pop($crumbs);
        }
        $crumbs[] = ['label' => $current, 'href' => null];

        return $crumbs;
    }

    /**
     * Called from the shared headers with their local scope values.
     *
     * @param list<mixed> $titleCandidates
     */
    function erp_header_breadcrumbs(bool $hidden, string $headerSlotsHtml, array $titleCandidates): void
    {
        if ($hidden || isset($_GET['print']) || stripos($headerSlotsHtml, 'breadcrumb') !== false) {
            return;
        }
        $currentTitle = '';
        foreach ($titleCandidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $currentTitle = html_entity_decode(strip_tags($candidate), ENT_QUOTES, 'UTF-8');
                break;
            }
        }
        erp_render_breadcrumbs($currentTitle);
    }

    function erp_render_breadcrumbs(?string $currentTitle = null): void
    {
        $items = erp_breadcrumb_items($currentTitle);
        if (count($items) < 2) {
            return;
        }
        $count = count($items);
        ?>
<nav id="erp-auto-breadcrumb" class="erp-auto-breadcrumb" aria-label="Breadcrumb">
<?php foreach ($items as $idx => $item):
    $label = htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8');
    $href = $item['href'] ?? null;
    if ($idx > 0): ?><span class="erp-auto-breadcrumb-sep" aria-hidden="true">/</span><?php endif;
    if ($idx === $count - 1): ?><span class="erp-auto-breadcrumb-current" aria-current="page"><?= $label ?></span><?php
    elseif ($href): ?><a class="erp-auto-breadcrumb-link erp-nav-back-ignore" href="<?= htmlspecialchars((string) $href, ENT_QUOTES, 'UTF-8') ?>"><?= $label ?></a><?php
    else: ?><span class="erp-auto-breadcrumb-text"><?= $label ?></span><?php
    endif;
endforeach; ?>
</nav>
<style>
.erp-auto-breadcrumb { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; padding: 10px 1.25rem 0; font-size: 12.5px; line-height: 1.4; min-width: 0; }
.erp-auto-breadcrumb-link { color: #94a3b8; text-decoration: none; font-weight: 500; }
.erp-auto-breadcrumb-link:hover { color: #7c3aed; }
.erp-auto-breadcrumb-text { color: #94a3b8; font-weight: 500; }
.erp-auto-breadcrumb-sep { color: #cbd5e1; user-select: none; }
.erp-auto-breadcrumb-current { color: #475569; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 60vw; }
html[data-theme="dark"] .erp-auto-breadcrumb-link, html[data-theme="dark"] .erp-auto-breadcrumb-text, html[data-theme="dark"] .erp-auto-breadcrumb-sep { color: #8696a0; }
html[data-theme="dark"] .erp-auto-breadcrumb-link:hover { color: #06cf9c; }
html[data-theme="dark"] .erp-auto-breadcrumb-current { color: #e9edef; }
@media (max-width: 767.98px) { .erp-auto-breadcrumb { padding: 8px 0.75rem 0; font-size: 12px; } }
@media print { .erp-auto-breadcrumb { display: none !important; } }
</style>
<script>
(function () {
    var own = document.getElementById('erp-auto-breadcrumb');
    if (!own) return;
    var sel = 'nav[aria-label="Breadcrumb"], .vv-breadcrumb, .ov-breadcrumb, .sr-breadcrumb, ol.breadcrumb';
    function check() {
        var list = document.querySelectorAll(sel);
        for (var i = 0; i < list.length; i++) {
            var el = list[i];
            if (el === own || own.contains(el)) continue;
            if (el.offsetParent !== null || el.getClientRects().length) { own.style.display = 'none'; return true; }
        }
        return false;
    }
    function start() {
        if (check() || !window.MutationObserver) return;
        var mo = new MutationObserver(function () { if (check()) mo.disconnect(); });
        mo.observe(document.body, { childList: true, subtree: true });
        setTimeout(function () { mo.disconnect(); }, 8000);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
</script>
        <?php
    }
}
