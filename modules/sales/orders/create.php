<?php
require_once '../../../includes/config.php';
require_once '../../../includes/functions.php';
// require_once '../../../includes/auth.php';
// checkAuthentication('sales');
require_once '../functions.php';

// Temporary auth bypass
if (session_status() == PHP_SESSION_NONE)
    session_start();
if (!isset($_SESSION['user_id']))
    $_SESSION['user_id'] = 1;

global $pdo, $control_pdo;
$salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
$controlConn = null;
if (isset($GLOBALS['control_pdo']) && $GLOBALS['control_pdo'] instanceof PDO) {
    $controlConn = $GLOBALS['control_pdo'];
} elseif ($control_pdo instanceof PDO) {
    $controlConn = $control_pdo;
}

// VIEW MODE: List of Quotations
if (!isset($_GET['mode']) || $_GET['mode'] !== 'new') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ids'])) {
        $rawIds = $_POST['delete_ids'];
        if (!is_array($rawIds)) {
            $rawIds = explode(',', (string) $rawIds);
        }
        $deleteIds = array_filter(array_unique(array_map('intval', $rawIds)));
        foreach ($deleteIds as $delId) {
            try {
                $statusStmt = $salesDb->prepare("SELECT status FROM sales_orders WHERE id = ?");
                $statusStmt->execute([$delId]);
                $status = $statusStmt->fetchColumn();
                if ($status !== 'quotation') {
                    continue;
                }

                $invStmt = $salesDb->prepare("SELECT COUNT(*) FROM invoices WHERE order_id = ?");
                $invStmt->execute([$delId]);
                if ((int) $invStmt->fetchColumn() > 0) {
                    continue;
                }

                $salesDb->prepare("DELETE FROM sales_order_items WHERE order_id = ?")->execute([$delId]);
                $salesDb->prepare("DELETE FROM sales_orders WHERE id = ?")->execute([$delId]);
            } catch (Exception $e) {
                // silent fail to keep UI clean
            }
        }
        header('Location: ' . sales_module_url('orders/create.php', ['module' => 'sales', 'msg' => 'deleted']));
        exit;
    }

    if (function_exists('salesQuotationsListUsesReactShell') && salesQuotationsListUsesReactShell()) {
        require_once __DIR__ . '/includes/orders-lib.php';
        salesQuotationsListRenderReactShell();
    }

    $productsHasItemType = false;
    try {
        $prodCols = $salesDb->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
        $productsHasItemType = is_array($prodCols) && in_array('item_type', $prodCols, true);
    } catch (Throwable $e) {
        $productsHasItemType = false;
    }

    $vehicleLineSelect = $productsHasItemType
        ? '(SELECT COUNT(*) FROM sales_order_items soi INNER JOIN products p ON p.id = soi.product_id WHERE soi.order_id = so.id AND LOWER(TRIM(COALESCE(p.item_type, \'\'))) IN (\'vehicle\', \'truck\')) AS _rm_vehicle_lines'
        : '0 AS _rm_vehicle_lines';

    $listSql = "
        SELECT so.*, c.company_name, u.full_name AS salesperson, $vehicleLineSelect
        FROM sales_orders so
        LEFT JOIN customers c ON so.customer_id = c.id
        LEFT JOIN users u ON so.created_by = u.id";
    $listParams = [];
    $scope = function_exists('salesCompanyScopeSql') ? salesCompanyScopeSql('sales_orders', 'so') : ['', []];
    if ($scope[0] !== '') {
        $listSql .= ' WHERE 1=1' . $scope[0];
        $listParams = $scope[1];
    }
    $listSql .= ' ORDER BY so.created_at DESC';
    try {
        $stmt = $salesDb->prepare($listSql);
        $stmt->execute($listParams);
        $quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('sales orders create.php list: ' . $e->getMessage());
        $quotations = [];
        if (isset($_GET['debug']) && $_GET['debug'] === '1') {
            $dbName = '';
            try {
                $dbName = (string) $salesDb->query('SELECT DATABASE()')->fetchColumn();
            } catch (Throwable $ignored) {
            }
            $ctrlDb = '';
            if ($controlConn instanceof PDO) {
                try {
                    $ctrlDb = (string) $controlConn->query('SELECT DATABASE()')->fetchColumn();
                } catch (Exception $ignored) {
                }
            }
            $salesDbHint = isset($GLOBALS['sales_database_name']) ? (string) $GLOBALS['sales_database_name'] : '';
            die(
                'Quotations query failed: ' . htmlspecialchars($e->getMessage())
                . ' (sales_pdo database: ' . htmlspecialchars($dbName) . ')'
                . ($salesDbHint !== '' && $salesDbHint !== $dbName ? ' — resolved: ' . htmlspecialchars($salesDbHint) : '')
                . ($ctrlDb !== '' ? ' — control_pdo database: ' . htmlspecialchars($ctrlDb) : '')
                . (defined('SALES_DB_NAME') ? ' — SALES_DB_NAME: ' . htmlspecialchars((string) SALES_DB_NAME) : '')
            );
        }
    }
    // Fix mojibake often stored in DB: UTF-8 em dash mangled into â + € + ” (U+00E2 U+20AC U+201D).
    $mojibakeDash = "\xC3\xA2\xE2\x82\xAC\xE2\x80\x9D";
    $realEmDash = "\xE2\x80\x94";
    foreach ($quotations as &$qRow) {
        foreach (['company_name', 'salesperson'] as $textCol) {
            if (isset($qRow[$textCol]) && is_string($qRow[$textCol]) && $qRow[$textCol] !== '') {
                $qRow[$textCol] = str_replace([$mojibakeDash, $realEmDash], '-', $qRow[$textCol]);
            }
        }
        $vehicleLines = (int) ($qRow['_rm_vehicle_lines'] ?? 0);
        unset($qRow['_rm_vehicle_lines']);
        $ot = isset($qRow['order_type']) ? trim((string) $qRow['order_type']) : '';
        $storedTruck = (strtolower($ot) === 'truck');
        // Show Truck if saved as truck OR line items include a vehicle/truck product (fixes legacy rows).
        $qRow['order_type'] = ($storedTruck || $vehicleLines > 0) ? 'truck' : 'spare';
    }
    unset($qRow);

    $quotationsForJs = function_exists('sales_quotations_for_js')
        ? sales_quotations_for_js($quotations)
        : $quotations;

    $rmDefaultCurrency = 'TZS';
    try {
        if (function_exists('currentCompanyId')) {
            $cidRm = (int) currentCompanyId();
            if ($cidRm > 0) {
                $stRm = $salesDb->prepare('SELECT default_currency FROM sales_settings WHERE company_id = ? LIMIT 1');
                $stRm->execute([$cidRm]);
                $rowRm = $stRm->fetch(PDO::FETCH_ASSOC);
                if (!empty($rowRm['default_currency'])) {
                    $rmDefaultCurrency = (string) $rowRm['default_currency'];
                }
            }
        }
        if ($rmDefaultCurrency === 'TZS') {
            $rowRm = $salesDb->query('SELECT default_currency FROM sales_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (!empty($rowRm['default_currency'])) {
                $rmDefaultCurrency = (string) $rowRm['default_currency'];
            }
        }
    } catch (Throwable $e) {
        // keep TZS
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Quotations | Sales</title>
        <script>
            // Safe set for in-browser Tailwind CDN: avoid ReferenceError if `tailwind` isn't loaded yet
            window.tailwind = window.tailwind || {};
            window.tailwind.config = { corePlugins: { preflight: false } };
        </script>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link href="<?= app_url('assets/css/style.css') ?>" rel="stylesheet">
        <link href="<?= app_url('assets/css/sales-mobile.css') ?>" rel="stylesheet">

        <script src="https://cdnjs.cloudflare.com/ajax/libs/react/18.2.0/umd/react.production.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.2.0/umd/react-dom.production.min.js"></script>
        
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            /* Match shell + flex column so no white strip at gap or viewport edge */
            html:has(body.page-sales-quotations),
            body.page-sales-quotations {
                background-color: #f8f9fc !important;
            }
            body.page-sales-quotations {
                font-family: 'Outfit', system-ui, -apple-system, sans-serif;
                color: #374151;
                min-height: 100vh;
            }
            body.page-sales-quotations .layout-main-wrapper {
                background-color: #f8f9fc;
                width: 100%;
                max-width: 100%;
            }
            body.page-sales-quotations .layout-main-wrapper > .flex-grow-1 {
                flex: 1 1 0%;
                min-width: 0;
                max-width: none;
                width: 100%;
                background-color: #f8f9fc;
            }
            body.page-sales-quotations header.employee-header {
                background: #f8f9fc !important;
                box-shadow: none !important;
                border-bottom: 1px solid rgba(15, 23, 42, 0.06);
            }
            body.page-sales-quotations .employee-header .header-content {
                background: transparent;
            }
            body.page-sales-quotations #native-sidebar {
                background: var(--sidebar-bg) !important;
                border-right: 1px solid var(--sidebar-border) !important;
                color: var(--sidebar-text) !important;
            }
            .main-content {
                padding: 0;
                max-width: 100%;
                margin: 0 auto;
                min-height: calc(100vh - 64px);
            }
            /* Inset past sidebar seam (global sidebar forces main-content padding-left: 0 !important) */
            html body .main-content.sales-quotations-shell {
                padding-left: 1rem !important;
                padding-right: 1rem !important;
                box-sizing: border-box !important;
                flex: 1 1 auto;
                width: 100% !important;
                max-width: none !important;
                min-width: 0;
                background-color: #f8f9fc;
            }
            body.page-sales-quotations #react-root {
                width: 100%;
                min-width: 0;
            }
            @media (min-width: 993px) {
                html body .main-content.sales-quotations-shell {
                    padding-left: 1.75rem !important;
                    padding-right: 1.5rem !important;
                }
            }
            .q-btn-primary {
                background-color: #2563EB;
                color: #fff;
                border: 1px solid #2563EB;
            }
            .q-btn-primary:hover {
                background-color: #1D4ED8;
                border-color: #1D4ED8;
            }
            .q-checkbox {
                appearance: none;
                width: 1rem;
                height: 1rem;
                border: 1px solid #D1D5DB;
                border-radius: 0.125rem;
                background: #fff;
                cursor: pointer;
                position: relative;
            }
            .q-checkbox:checked {
                background-color: #2563EB;
                border-color: #2563EB;
            }
            .q-checkbox:checked::after {
                content: '\2713';
                color: #fff;
                position: absolute;
                font-size: 11px;
                font-weight: bold;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(5px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .animate-fade-in { animation: fadeIn 0.2s ease-out forwards; }
            ::-webkit-scrollbar { width: 6px; height: 6px; }
            ::-webkit-scrollbar-track { background: #f1f5f9; }
            ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
            .no-scrollbar::-webkit-scrollbar { display: none; }
            .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
            /* Bootstrap (sidebar) can flatten button radius */
            button.quotations-create-primary-btn,
            a.quotations-create-primary-btn {
                border-radius: 9999px !important;
            }
        </style>
    </head>

    <body class="page-sales-quotations">
        <?php include '../../../includes/header_employee.php'; ?>

        <div class="main-content sales-quotations-shell" id="react-root">
            <p class="p-6 text-gray-500 text-base">Loading quotations…</p>
        </div>

        <script>
            window.APP_DATA = {
                quotations: <?= sales_json_script($quotationsForJs) ?>,
                currentUserId: <?= (int) ($_SESSION['user_id'] ?? 0) ?>,
                isRoadmaster: <?= sales_json_script(function_exists('isRoadmaster') ? isRoadmaster() : false) ?>,
                quotationsShellLayout: <?= sales_json_script(function_exists('quotationsListUsesRoadmasterShell') && quotationsListUsesRoadmasterShell()) ?>,
                defaultCurrency: <?= sales_json_script($rmDefaultCurrency) ?>
            };
        </script>

        <!-- Global error handler outside Babel to catch loading or parsing errors -->
        <script>
            window.addEventListener('error', function (ev) {
                try {
                    var msg = ev && ev.message ? ev.message : String(ev);
                    var el = document.getElementById('react-root');
                    if (el && el.innerHTML.indexOf('Loading quotations') !== -1) {
                        el.innerHTML = '<div class="p-6 text-red-600 text-base border border-red-200 bg-red-50 rounded m-4"><strong>Error:</strong> ' + msg + '</div>';
                    }
                } catch (e) { }
            });
            setTimeout(function() {
                var el = document.getElementById('react-root');
                if (el && el.innerHTML.indexOf('Loading quotations') !== -1) {
                    el.innerHTML = '<div class="p-6 text-orange-600 text-base border border-orange-200 bg-orange-50 rounded m-4"><strong>Warning:</strong> The application is taking too long to load. Please check your internet connection or try refreshing the page.</div>';
                }
            }, 10000);
        </script>

        <script>const { useState, useMemo, useEffect, Component } = React;
class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, error: null };
  }
  static getDerivedStateFromError(error) {
    return { hasError: true, error };
  }
  componentDidCatch(error, errorInfo) {
    console && console.error && console.error("React ErrorBoundary caught an error", error, errorInfo);
  }
  render() {
    if (this.state.hasError) {
      return /* @__PURE__ */ React.createElement("div", { className: "p-6 text-red-600 bg-red-50 border border-red-200 rounded m-4" }, /* @__PURE__ */ React.createElement("h2", { className: "text-lg font-bold mb-2" }, "Something went wrong"), /* @__PURE__ */ React.createElement("p", { className: "text-sm font-mono whitespace-pre-wrap" }, this.state.error && this.state.error.toString()));
    }
    return this.props.children;
  }
}
const VIEW_MODES = ["list", "cards", "board"];
const PAGE_SIZE = 25;
const PAGINATION_WINDOW = 5;
function paginationPageNumbers(currentPage, totalPages, windowSize = PAGINATION_WINDOW) {
  if (totalPages <= 1) {
    return totalPages >= 1 ? [1] : [];
  }
  if (totalPages <= windowSize) {
    return Array.from({ length: totalPages }, (_, i) => i + 1);
  }
  const block = Math.floor((currentPage - 1) / windowSize);
  const start = block * windowSize + 1;
  const end = Math.min(start + windowSize - 1, totalPages);
  return Array.from({ length: end - start + 1 }, (_, i) => start + i);
}
const BOARD_COLS = [
  { key: "draft", label: "Draft" },
  { key: "quotation", label: "Quotation" },
  { key: "confirmed", label: "Sales order" },
  { key: "processing", label: "Processing" },
  { key: "shipped", label: "Shipped" },
  { key: "delivered", label: "Delivered" },
  { key: "paid", label: "Paid" },
  { key: "cancelled", label: "Cancelled" },
  { key: "other", label: "Other" }
];
function boardColumnForStatus(status) {
  const s = (status || "").toLowerCase().trim();
  if (["draft"].includes(s)) return "draft";
  if (["quotation"].includes(s)) return "quotation";
  if (["confirmed", "invoiced"].includes(s)) return "confirmed";
  if (["processing", "pending"].includes(s)) return "processing";
  if (["shipped"].includes(s)) return "shipped";
  if (["delivered"].includes(s)) return "delivered";
  if (["paid", "completed"].includes(s)) return "paid";
  if (["cancelled", "canceled"].includes(s)) return "cancelled";
  return "other";
}
function statusPill(status) {
  const st = (status || "").toLowerCase().trim();
  const labels = {
    draft: "Draft",
    quotation: "Quotation",
    confirmed: "Sales Order",
    invoiced: "Sales Order",
    processing: "Processing",
    pending: "Pending",
    shipped: "Shipped",
    delivered: "Delivered",
    paid: "Paid",
    completed: "Completed",
    cancelled: "Cancelled"
  };
  const label = labels[st] || (status || "-");
  let cls = "bg-gray-500 text-white";
  if (st === "confirmed" || st === "invoiced" || st === "paid" || st === "completed" || st === "delivered") cls = "bg-[#28A745] text-white";
  else if (st === "quotation" || st === "sent") cls = "bg-[#17A2B8] text-white";
  else if (st === "draft" || st === "pending" || st === "processing") cls = "bg-[#FFC107] text-gray-900";
  else if (st === "cancelled" || st === "canceled") cls = "bg-[#DC3545] text-white";
  else if (st === "shipped") cls = "bg-[#6f42c1] text-white";
  return /* @__PURE__ */ React.createElement("span", { className: "inline-block px-2.5 py-0.5 text-sm font-semibold rounded-full " + cls }, label);
}
function initials(name) {
  if (!name || !String(name).trim()) return "?";
  return String(name).split(/\s+/).map((w) => w[0]).join("").toUpperCase().slice(0, 2);
}
const SALESPERSON_AVATAR_STYLES = [
  { fill: "bg-sky-100 text-sky-800", glow: "shadow-[0_0_16px_rgba(56,189,248,0.45)] ring-1 ring-sky-300/80" },
  { fill: "bg-violet-100 text-violet-800", glow: "shadow-[0_0_16px_rgba(167,139,250,0.45)] ring-1 ring-violet-300/80" },
  { fill: "bg-emerald-100 text-emerald-800", glow: "shadow-[0_0_16px_rgba(52,211,153,0.45)] ring-1 ring-emerald-300/80" },
  { fill: "bg-amber-100 text-amber-900", glow: "shadow-[0_0_16px_rgba(251,191,36,0.5)] ring-1 ring-amber-300/80" },
  { fill: "bg-rose-100 text-rose-800", glow: "shadow-[0_0_16px_rgba(251,113,133,0.45)] ring-1 ring-rose-300/80" },
  { fill: "bg-cyan-100 text-cyan-800", glow: "shadow-[0_0_16px_rgba(34,211,238,0.45)] ring-1 ring-cyan-300/80" },
  { fill: "bg-indigo-100 text-indigo-800", glow: "shadow-[0_0_16px_rgba(129,140,248,0.45)] ring-1 ring-indigo-300/80" },
  { fill: "bg-teal-100 text-teal-800", glow: "shadow-[0_0_16px_rgba(45,212,191,0.45)] ring-1 ring-teal-300/80" },
  { fill: "bg-orange-100 text-orange-800", glow: "shadow-[0_0_16px_rgba(251,146,60,0.45)] ring-1 ring-orange-300/80" },
  { fill: "bg-pink-100 text-pink-800", glow: "shadow-[0_0_16px_rgba(244,114,182,0.45)] ring-1 ring-pink-300/80" },
  { fill: "bg-lime-100 text-lime-800", glow: "shadow-[0_0_16px_rgba(163,230,53,0.45)] ring-1 ring-lime-300/80" },
  { fill: "bg-fuchsia-100 text-fuchsia-800", glow: "shadow-[0_0_16px_rgba(232,121,249,0.45)] ring-1 ring-fuchsia-300/80" }
];
function salespersonAvatarClasses(name) {
  const s = name && String(name).trim() || "";
  if (!s) {
    return "bg-gray-100 text-gray-600 shadow-[0_0_12px_rgba(156,163,175,0.35)] ring-1 ring-gray-200/90";
  }
  let h = 0;
  for (let i = 0; i < s.length; i++) {
    h = (h << 5) - h + s.charCodeAt(i) | 0;
  }
  const st = SALESPERSON_AVATAR_STYLES[Math.abs(h) % SALESPERSON_AVATAR_STYLES.length];
  return st.fill + " " + st.glow;
}
const getTypeBadge = (type) => {
  const t = (type || "spare").toLowerCase();
  if (t === "truck") {
    return /* @__PURE__ */ React.createElement("span", { className: "inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-bold bg-[#714b67]/10 text-[#714b67] border border-[#714b67]/20" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-truck text-[10px]" }), " TRUCK");
  }
  return /* @__PURE__ */ React.createElement("span", { className: "inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-xs font-bold bg-[#008784]/10 text-[#008784] border border-[#008784]/20" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-wrench text-[10px]" }), " SPARE");
};
function QuotationsListApp() {
  const { quotations: initialQuotations, currentUserId, isRoadmaster, quotationsShellLayout, defaultCurrency: appCurrency } = window.APP_DATA;
  const useRmShell = quotationsShellLayout === true;
  const defaultCurrency = appCurrency || "TZS";
  const [quotations] = useState(initialQuotations || []);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [myQuotationsOnly, setMyQuotationsOnly] = useState(false);
  const [selectedIds, setSelectedIds] = useState(/* @__PURE__ */ new Set());
  const [openMenuId, setOpenMenuId] = useState(null);
  const [page, setPage] = useState(1);
  const [viewMode, setViewMode] = useState(() => {
    try {
      const v = localStorage.getItem("sales_quotations_view_mode");
      if (VIEW_MODES.includes(v)) return v;
    } catch (e) {
    }
    return "list";
  });
  useEffect(() => {
    try {
      localStorage.setItem("sales_quotations_view_mode", viewMode);
    } catch (e) {
    }
  }, [viewMode]);
  useEffect(() => {
    setPage(1);
  }, [search, statusFilter, myQuotationsOnly, viewMode]);
  useEffect(() => {
    if (openMenuId == null) return;
    const close = (ev) => {
      if (ev.target && typeof ev.target.closest === "function" && ev.target.closest("[data-quotation-actions]")) return;
      setOpenMenuId(null);
    };
    document.addEventListener("click", close);
    return () => document.removeEventListener("click", close);
  }, [openMenuId]);
  const pageSize = useRmShell ? 10 : PAGE_SIZE;
  const formatCurrency = (amount) => new Intl.NumberFormat("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount || 0);
  const formatDateTime = (dateStr) => {
    if (!dateStr) return "-";
    const d = new Date(dateStr);
    if (Number.isNaN(d.getTime())) return "-";
    const pad = (n) => String(n).padStart(2, "0");
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
  };
  const filteredQuotations = useMemo(() => {
    return quotations.filter((q) => {
      const mineOk = !myQuotationsOnly || Number(q.created_by) === Number(currentUserId);
      const matchesSearch = (q.order_number || "").toLowerCase().includes(search.toLowerCase()) || (q.company_name || "").toLowerCase().includes(search.toLowerCase()) || (q.salesperson || "").toLowerCase().includes(search.toLowerCase());
      const matchesStatus = !statusFilter || (q.status || "").toLowerCase() === statusFilter;
      return mineOk && matchesSearch && matchesStatus;
    });
  }, [quotations, search, statusFilter, myQuotationsOnly, currentUserId]);
  const quotationStats = useMemo(() => {
    const y = (/* @__PURE__ */ new Date()).getFullYear();
    let totalVal = 0;
    let pending = 0;
    let convertedYtd = 0;
    quotations.forEach((q) => {
      totalVal += parseFloat(q.total_amount) || 0;
      const st = (q.status || "").toLowerCase();
      if (["quotation", "draft", "sent"].includes(st)) pending++;
      if (["confirmed", "invoiced", "processing", "shipped", "delivered", "paid", "completed"].includes(st)) {
        const d = q.created_at ? new Date(q.created_at) : null;
        if (d && !Number.isNaN(d.getTime()) && d.getFullYear() === y) convertedYtd++;
      }
    });
    return { total: quotations.length, totalVal, pending, convertedYtd };
  }, [quotations]);
  const pageCount = Math.max(1, Math.ceil(filteredQuotations.length / pageSize));
  const safePage = Math.min(page, pageCount);
  const visiblePageNumbers = useMemo(
    () => paginationPageNumbers(safePage, pageCount, PAGINATION_WINDOW),
    [safePage, pageCount]
  );
  const pagedQuotations = useMemo(() => {
    const start = (safePage - 1) * pageSize;
    return filteredQuotations.slice(start, start + pageSize);
  }, [filteredQuotations, safePage, pageSize]);
  const byBoard = useMemo(() => {
    const m = { draft: [], quotation: [], confirmed: [], processing: [], shipped: [], delivered: [], paid: [], cancelled: [], other: [] };
    filteredQuotations.forEach((q) => {
      m[boardColumnForStatus(q.status)].push(q);
    });
    return m;
  }, [filteredQuotations]);
  const handleNewClick = () => {
    Swal.fire({
      title: "Select Quotation Type",
      text: "What kind of quotation would you like to create?",
      icon: "question",
      showDenyButton: true,
      showCancelButton: true,
      confirmButtonText: '<i class="fas fa-truck me-2"></i> Truck Quote',
      denyButtonText: '<i class="fas fa-cogs me-2"></i> Spare Part Quote',
      confirmButtonColor: "#714b67",
      denyButtonColor: "#008784",
      cancelButtonColor: "#94a3b8"
    }).then((result) => {
      if (result.isConfirmed) {
        window.location.href = "create.php?mode=new&type=truck";
      } else if (result.isDenied) {
        window.location.href = "create.php?mode=new&type=spare";
      }
    });
  };
  const handleSelectAll = (e) => {
    if (e.target.checked) {
      setSelectedIds(new Set(pagedQuotations.map((q) => q.id)));
    } else {
      setSelectedIds(/* @__PURE__ */ new Set());
    }
  };
  const toggleSelection = (id, e) => {
    e.stopPropagation();
    const newSet = new Set(selectedIds);
    if (newSet.has(id)) newSet.delete(id);
    else newSet.add(id);
    setSelectedIds(newSet);
  };
  const selectedList = useMemo(
    () => filteredQuotations.filter((q) => selectedIds.has(q.id)),
    [filteredQuotations, selectedIds]
  );
  const canDelete = selectedList.length > 0 && selectedList.every((q) => (q.status || "").toLowerCase() === "quotation");
  const canInvoice = selectedList.length === 1;
  const handleDelete = (e) => {
    if (!confirm("Delete selected quotations? (Only quotation status, no invoice)")) e.preventDefault();
  };
  const viewBtn = (mode, icon, title) => /* @__PURE__ */ React.createElement(
    "button",
    {
      type: "button",
      title,
      "aria-pressed": viewMode === mode,
      onClick: () => setViewMode(mode),
      className: "px-2.5 py-1.5 rounded text-sm transition-colors " + (viewMode === mode ? "bg-sky-100 text-sky-800 border border-sky-200 shadow-sm" : "text-gray-500 hover:text-gray-800 hover:bg-gray-100 border border-transparent")
    },
    /* @__PURE__ */ React.createElement("i", { className: "fas " + icon })
  );
  const rangeStart = filteredQuotations.length === 0 ? 0 : (safePage - 1) * pageSize + 1;
  const rangeEnd = Math.min(safePage * pageSize, filteredQuotations.length);
  const RowCells = ({ q }) => /* @__PURE__ */ React.createElement(React.Fragment, null, /* @__PURE__ */ React.createElement("td", { className: "px-3 py-2.5 align-middle", onClick: (e) => e.stopPropagation() }, /* @__PURE__ */ React.createElement(
    "input",
    {
      type: "checkbox",
      className: "q-checkbox",
      checked: selectedIds.has(q.id),
      onChange: (e) => toggleSelection(q.id, e)
    }
  )), /* @__PURE__ */ React.createElement("td", { className: "px-3 py-2.5 text-base font-semibold text-gray-900 whitespace-nowrap" }, /* @__PURE__ */ React.createElement("a", { href: "view.php?id=" + q.id, className: "hover:text-[#2563EB] hover:underline", onClick: (e) => e.stopPropagation() }, q.order_number)), /* @__PURE__ */ React.createElement("td", { className: "px-3 py-2.5 text-base text-gray-600 whitespace-nowrap" }, formatDateTime(q.created_at)), /* @__PURE__ */ React.createElement("td", { className: "px-3 py-2.5 truncate max-w-[200px] " + (useRmShell ? "text-base font-bold uppercase tracking-tight text-gray-900" : "text-base text-gray-900 font-medium"), title: q.company_name || "" }, q.company_name || "-"), /* @__PURE__ */ React.createElement("td", { className: "px-3 py-2.5 whitespace-nowrap" }, /* @__PURE__ */ React.createElement("div", { className: "flex items-center gap-2" }, /* @__PURE__ */ React.createElement("span", { className: "inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold " + salespersonAvatarClasses(q.salesperson) }, initials(q.salesperson)), /* @__PURE__ */ React.createElement("span", { className: "text-base text-gray-700 truncate max-w-[140px]" }, q.salesperson || "-"))), /* @__PURE__ */ React.createElement("td", { className: "px-3 py-2.5 text-base font-semibold text-gray-900 text-right whitespace-nowrap" }, formatCurrency(q.total_amount)), /* @__PURE__ */ React.createElement("td", { className: "px-3 py-2.5 text-center whitespace-nowrap" }, statusPill(q.status)), useRmShell ? /* @__PURE__ */ React.createElement("td", { className: "px-3 py-2.5 text-right whitespace-nowrap w-14", onClick: (e) => e.stopPropagation() }, /* @__PURE__ */ React.createElement("div", { className: "relative flex justify-end", "data-quotation-actions": "1" }, /* @__PURE__ */ React.createElement(
    "button",
    {
      type: "button",
      className: "p-2 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-800",
      title: "Actions",
      "aria-label": "Actions",
      onClick: (e) => {
        e.stopPropagation();
        setOpenMenuId((id) => id === q.id ? null : q.id);
      }
    },
    /* @__PURE__ */ React.createElement("i", { className: "fas fa-ellipsis-vertical" })
  ), openMenuId === q.id && /* @__PURE__ */ React.createElement("div", { className: "absolute right-0 top-full mt-1 w-44 bg-white border border-gray-200 rounded-lg shadow-lg z-50 py-1 text-sm text-left", onClick: (e) => e.stopPropagation() }, /* @__PURE__ */ React.createElement("a", { href: "view.php?id=" + q.id, className: "block px-3 py-2 hover:bg-gray-50 text-gray-700 no-underline" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-eye w-5 text-gray-400" }), " View"), /* @__PURE__ */ React.createElement("a", { href: "print.php?id=" + q.id, target: "_blank", rel: "noopener noreferrer", className: "block px-3 py-2 hover:bg-gray-50 text-gray-700 no-underline" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-print w-5 text-gray-400" }), " Print")))) : /* @__PURE__ */ React.createElement("td", { className: "px-3 py-2.5 text-right whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity" }, /* @__PURE__ */ React.createElement("a", { href: "view.php?id=" + q.id, className: "text-gray-400 hover:text-gray-700 me-2", title: "View" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-eye" })), /* @__PURE__ */ React.createElement("a", { href: "print.php?id=" + q.id, target: "_blank", rel: "noopener noreferrer", className: "text-gray-400 hover:text-gray-700", title: "Print", onClick: (e) => e.stopPropagation() }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-print" }))));
  const theadRowClass = useRmShell ? "border-b border-gray-200 bg-slate-50 text-xs font-semibold text-gray-600 uppercase tracking-wider" : "border-b-2 border-gray-200 bg-white text-sm font-bold text-gray-500 uppercase tracking-wide";
  const renderQuotationsBody = () => {
    if (filteredQuotations.length === 0) {
      return /* @__PURE__ */ React.createElement("div", { className: "text-center py-20 " + (useRmShell ? "px-4" : "bg-white border border-gray-100 m-4 rounded-lg") }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-file-invoice text-4xl text-gray-300 mb-3" }), /* @__PURE__ */ React.createElement("p", { className: "text-gray-600 font-medium text-lg" }, "No quotations found"), /* @__PURE__ */ React.createElement("p", { className: "text-gray-400 text-base mt-1" }, "Adjust search or filters"));
    }
    if (viewMode === "list") {
      return /* @__PURE__ */ React.createElement("div", { className: "overflow-x-auto " + (useRmShell ? "bg-white" : "bg-white border-t border-gray-200") }, /* @__PURE__ */ React.createElement("table", { className: "w-full text-left border-collapse " + (useRmShell ? "min-w-[1000px]" : "min-w-[920px]") }, /* @__PURE__ */ React.createElement("thead", null, /* @__PURE__ */ React.createElement("tr", { className: theadRowClass }, /* @__PURE__ */ React.createElement("th", { className: "w-10 px-3 py-3" }, /* @__PURE__ */ React.createElement("input", { type: "checkbox", className: "q-checkbox", onChange: handleSelectAll, checked: pagedQuotations.length > 0 && pagedQuotations.every((q) => selectedIds.has(q.id)) })), /* @__PURE__ */ React.createElement("th", { className: "px-3 py-3" }, "Number"), /* @__PURE__ */ React.createElement("th", { className: "px-3 py-3 whitespace-nowrap" }, "Creation date"), /* @__PURE__ */ React.createElement("th", { className: "px-3 py-3" }, "Customer"), /* @__PURE__ */ React.createElement("th", { className: "px-3 py-3" }, "Salesperson"), /* @__PURE__ */ React.createElement("th", { className: "px-3 py-3 text-right" }, "Total"), /* @__PURE__ */ React.createElement("th", { className: "px-3 py-3 text-center" }, "Status"), /* @__PURE__ */ React.createElement("th", { className: "w-14 px-3 py-3 text-right whitespace-nowrap" }, useRmShell ? "Actions" : /* @__PURE__ */ React.createElement("i", { className: "fas fa-sliders-h text-gray-400", title: "Actions" })))), /* @__PURE__ */ React.createElement("tbody", { className: "divide-y divide-gray-100 bg-white" }, pagedQuotations.map((q) => /* @__PURE__ */ React.createElement("tr", { key: q.id, className: "hover:bg-gray-50 group cursor-pointer", onClick: () => {
        window.location.href = "view.php?id=" + q.id;
      } }, /* @__PURE__ */ React.createElement(RowCells, { q }))))));
    }
    if (viewMode === "cards") {
      return /* @__PURE__ */ React.createElement("div", { className: "p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3 bg-white" }, pagedQuotations.map((q) => /* @__PURE__ */ React.createElement("div", { key: q.id, className: "bg-white border border-gray-200 rounded-lg p-4 shadow-sm hover:shadow-md transition-shadow" }, /* @__PURE__ */ React.createElement("div", { className: "flex justify-between items-start gap-2 mb-2" }, /* @__PURE__ */ React.createElement("div", { className: "min-w-0" }, /* @__PURE__ */ React.createElement("a", { href: "view.php?id=" + q.id, className: "font-bold text-gray-900 text-base hover:text-[#2563EB] block truncate" }, q.order_number)), /* @__PURE__ */ React.createElement("div", { className: "flex flex-col items-end gap-1.5" }, statusPill(q.status), (isRoadmaster || useRmShell) && getTypeBadge(q.order_type))), /* @__PURE__ */ React.createElement("p", { className: "text-base font-medium text-gray-800 truncate" }, q.company_name || "-"), /* @__PURE__ */ React.createElement("p", { className: "text-sm text-gray-500 mt-1" }, formatDateTime(q.created_at)), /* @__PURE__ */ React.createElement("div", { className: "flex items-center gap-2 mt-2" }, /* @__PURE__ */ React.createElement("span", { className: "h-7 w-7 shrink-0 rounded-full text-xs font-bold flex items-center justify-center " + salespersonAvatarClasses(q.salesperson) }, initials(q.salesperson)), /* @__PURE__ */ React.createElement("span", { className: "text-sm text-gray-600 truncate" }, q.salesperson || "-")), /* @__PURE__ */ React.createElement("p", { className: "text-base font-semibold text-gray-900 mt-3" }, formatCurrency(q.total_amount)), /* @__PURE__ */ React.createElement("div", { className: "flex gap-2 mt-3" }, /* @__PURE__ */ React.createElement("a", { href: "view.php?id=" + q.id, className: "flex-1 text-center py-2 text-sm font-semibold rounded border border-gray-200 hover:bg-gray-50" }, "View"), /* @__PURE__ */ React.createElement("a", { href: "print.php?id=" + q.id, target: "_blank", rel: "noopener noreferrer", className: "flex-1 text-center py-2 text-sm font-semibold rounded bg-gray-800 text-white hover:bg-gray-900" }, "Print")))));
    }
    return /* @__PURE__ */ React.createElement("div", { className: "p-3 overflow-x-auto no-scrollbar bg-white" }, /* @__PURE__ */ React.createElement("div", { className: "flex gap-3 min-w-max pb-2" }, BOARD_COLS.map((col) => {
      const items = byBoard[col.key] || [];
      return /* @__PURE__ */ React.createElement("div", { key: col.key, className: "w-72 shrink-0 bg-white rounded-lg border border-gray-200 flex flex-col max-h-[72vh]" }, /* @__PURE__ */ React.createElement("div", { className: "px-3 py-2.5 border-b border-gray-200 bg-white/95 rounded-t-lg flex justify-between items-center" }, /* @__PURE__ */ React.createElement("span", { className: "text-sm font-bold text-gray-600 uppercase tracking-wide" }, col.label), /* @__PURE__ */ React.createElement("span", { className: "text-xs font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full" }, items.length)), /* @__PURE__ */ React.createElement("div", { className: "p-2 space-y-2 overflow-y-auto flex-1" }, items.length === 0 ? /* @__PURE__ */ React.createElement("p", { className: "text-sm text-gray-400 text-center py-6" }, "-") : items.map((q) => /* @__PURE__ */ React.createElement("div", { key: q.id, className: "bg-white border border-gray-200 rounded-md p-3 shadow-sm" }, /* @__PURE__ */ React.createElement("a", { href: "view.php?id=" + q.id, className: "font-semibold text-base text-gray-900 hover:text-[#2563EB] block truncate" }, q.order_number), /* @__PURE__ */ React.createElement("p", { className: "text-sm text-gray-600 truncate mt-1" }, q.company_name), /* @__PURE__ */ React.createElement("p", { className: "text-sm text-gray-500 mt-1" }, formatCurrency(q.total_amount)), /* @__PURE__ */ React.createElement("div", { className: "mt-2" }, statusPill(q.status))))));
    })));
  };
  const rmToolbar = /* @__PURE__ */ React.createElement("div", { className: "flex flex-wrap items-center gap-3 p-4 border-b border-gray-50 bg-white" }, /* @__PURE__ */ React.createElement("div", { className: "relative flex-1 min-w-[200px] max-w-md" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm" }), /* @__PURE__ */ React.createElement(
    "input",
    {
      type: "text",
      value: search,
      onChange: (e) => setSearch(e.target.value),
      placeholder: "Search number, customer, salesperson...",
      className: "w-full pl-9 pr-4 py-2.5 text-sm bg-white border border-gray-200 rounded-full focus:outline-none focus:ring-2 focus:ring-[#2563EB]/20 focus:border-[#2563EB] shadow-sm"
    }
  )), /* @__PURE__ */ React.createElement(
    "select",
    {
      value: myQuotationsOnly ? "mine" : "all",
      onChange: (e) => setMyQuotationsOnly(e.target.value === "mine"),
      className: "text-sm border border-gray-200 rounded-full px-4 py-2.5 bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#2563EB]/20 min-w-[150px] shadow-sm"
    },
    /* @__PURE__ */ React.createElement("option", { value: "all" }, "All quotations"),
    /* @__PURE__ */ React.createElement("option", { value: "mine" }, "My quotations")
  ), /* @__PURE__ */ React.createElement(
    "select",
    {
      value: statusFilter,
      onChange: (e) => setStatusFilter(e.target.value),
      className: "text-sm border border-gray-200 rounded-full px-4 py-2.5 bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#2563EB]/20 min-w-[150px] shadow-sm"
    },
    /* @__PURE__ */ React.createElement("option", { value: "" }, "All statuses"),
    /* @__PURE__ */ React.createElement("option", { value: "quotation" }, "Quotation"),
    /* @__PURE__ */ React.createElement("option", { value: "draft" }, "Draft"),
    /* @__PURE__ */ React.createElement("option", { value: "confirmed" }, "Confirmed"),
    /* @__PURE__ */ React.createElement("option", { value: "processing" }, "Processing"),
    /* @__PURE__ */ React.createElement("option", { value: "shipped" }, "Shipped"),
    /* @__PURE__ */ React.createElement("option", { value: "delivered" }, "Delivered"),
    /* @__PURE__ */ React.createElement("option", { value: "paid" }, "Paid"),
    /* @__PURE__ */ React.createElement("option", { value: "completed" }, "Completed"),
    /* @__PURE__ */ React.createElement("option", { value: "cancelled" }, "Cancelled")
  ), /* @__PURE__ */ React.createElement("button", { type: "button", className: "inline-flex items-center gap-2 px-4 py-2.5 rounded-full border border-gray-200 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 shadow-sm transition-colors" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-sliders-h text-gray-500" }), " Filters"), /* @__PURE__ */ React.createElement("button", { type: "button", className: "p-2.5 rounded-lg border border-gray-200 bg-white text-gray-600 hover:bg-gray-50", title: "Date range" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-calendar-alt" })), /* @__PURE__ */ React.createElement("div", { className: "flex-1 min-w-[8px]" }), /* @__PURE__ */ React.createElement("div", { className: "flex items-center gap-0.5 bg-white rounded-full border border-gray-200 p-0.5 shadow-sm", role: "group", "aria-label": "View" }, viewBtn("list", "fa-list", "List"), viewBtn("cards", "fa-th-large", "Cards"), viewBtn("board", "fa-columns", "Board")), /* @__PURE__ */ React.createElement("div", { className: "flex items-center gap-2 text-sm text-gray-600" }, /* @__PURE__ */ React.createElement(
    "button",
    {
      type: "button",
      disabled: safePage <= 1,
      onClick: () => setPage((p) => Math.max(1, p - 1)),
      className: "p-2 rounded-lg border border-gray-200 bg-white disabled:opacity-40 hover:bg-gray-50"
    },
    /* @__PURE__ */ React.createElement("i", { className: "fas fa-chevron-left text-xs" })
  ), /* @__PURE__ */ React.createElement("span", { className: "tabular-nums whitespace-nowrap px-1" }, rangeStart, "-", rangeEnd, " of ", filteredQuotations.length), /* @__PURE__ */ React.createElement(
    "button",
    {
      type: "button",
      disabled: safePage >= pageCount,
      onClick: () => setPage((p) => Math.min(pageCount, p + 1)),
      className: "p-2 rounded-lg border border-gray-200 bg-white disabled:opacity-40 hover:bg-gray-50"
    },
    /* @__PURE__ */ React.createElement("i", { className: "fas fa-chevron-right text-xs" })
  )), selectedIds.size > 0 && /* @__PURE__ */ React.createElement("div", { className: "flex items-center gap-2 flex-wrap w-full justify-end border-t border-gray-100 pt-3 mt-1" }, /* @__PURE__ */ React.createElement("span", { className: "text-sm text-gray-500" }, selectedIds.size, " selected"), canInvoice && /* @__PURE__ */ React.createElement(
    "a",
    {
      href: "../invoices/create.php?order_id=" + Array.from(selectedIds)[0],
      className: "text-sm font-semibold px-2.5 py-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-800"
    },
    /* @__PURE__ */ React.createElement("i", { className: "fas fa-file-invoice-dollar text-[#2563EB] me-1" }),
    "Invoice"
  ), canDelete && /* @__PURE__ */ React.createElement("form", { method: "POST", onSubmit: handleDelete, className: "inline m-0" }, /* @__PURE__ */ React.createElement("input", { type: "hidden", name: "delete_ids", value: Array.from(selectedIds).join(",") }), /* @__PURE__ */ React.createElement("button", { type: "submit", className: "text-sm font-semibold px-2.5 py-1.5 rounded-lg border border-red-200 bg-white text-red-700 hover:bg-red-50" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-trash-alt me-1" }), "Delete"))));
  const rmFooter = filteredQuotations.length > 0 && viewMode === "list" && /* @__PURE__ */ React.createElement("div", { className: "flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-t border-gray-100 bg-white text-sm text-gray-600" }, /* @__PURE__ */ React.createElement("span", null, "Showing ", /* @__PURE__ */ React.createElement("span", { className: "font-semibold text-gray-800" }, rangeStart), " to ", /* @__PURE__ */ React.createElement("span", { className: "font-semibold text-gray-800" }, rangeEnd), " of", " ", /* @__PURE__ */ React.createElement("span", { className: "font-semibold text-gray-800" }, filteredQuotations.length), " quotations"), /* @__PURE__ */ React.createElement("div", { className: "flex items-center gap-1" }, /* @__PURE__ */ React.createElement(
    "button",
    {
      type: "button",
      disabled: safePage <= 1,
      onClick: () => setPage((p) => Math.max(1, p - 1)),
      className: "px-3 py-1.5 rounded-lg border border-gray-200 bg-white text-sm font-medium disabled:opacity-40 hover:bg-gray-50"
    },
    "Previous"
  ), visiblePageNumbers.map((pn) => /* @__PURE__ */ React.createElement(
    "button",
    {
      key: pn,
      type: "button",
      onClick: () => setPage(pn),
      className: "min-w-[2.25rem] px-2 py-1.5 rounded-lg text-sm font-semibold border " + (pn === safePage ? "bg-[#2563EB] text-white border-[#2563EB]" : "bg-white text-gray-700 border-gray-200 hover:bg-gray-50")
    },
    pn
  )), /* @__PURE__ */ React.createElement(
    "button",
    {
      type: "button",
      disabled: safePage >= pageCount,
      onClick: () => setPage((p) => Math.min(pageCount, p + 1)),
      className: "px-3 py-1.5 rounded-lg border border-gray-200 bg-white text-sm font-medium disabled:opacity-40 hover:bg-gray-50"
    },
    "Next"
  )));
  if (isRoadmaster || useRmShell) {
    const fmtBig = (n) => new Intl.NumberFormat("en-US", { maximumFractionDigits: 0 }).format(n);
    return /* @__PURE__ */ React.createElement("div", { className: "w-full max-w-full ml-0 animate-fade-in bg-transparent min-h-[calc(100vh-4rem)] pb-10" }, /* @__PURE__ */ React.createElement("div", { className: "w-full max-w-full ml-0 px-4 md:px-6 pt-6 pb-6" }, /* @__PURE__ */ React.createElement("div", { className: "flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3 mb-2" }, /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("div", { className: "flex items-center gap-2" }, /* @__PURE__ */ React.createElement("h1", { className: "text-3xl font-bold text-gray-900 tracking-tight" }, "Quotations"), /* @__PURE__ */ React.createElement("a", { href: "../settings/index.php", className: "text-gray-400 hover:text-[#2563EB] p-1 rounded-md hover:bg-gray-100/80", title: "Sales settings" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-cog text-lg" }))), /* @__PURE__ */ React.createElement("p", { className: "text-gray-500 mt-1 text-base max-w-xl leading-snug" }, "Create, manage and track all your quotations.")), isRoadmaster ? /* @__PURE__ */ React.createElement(
      "button",
      {
        type: "button",
        onClick: handleNewClick,
        className: "quotations-create-primary-btn inline-flex items-center justify-center gap-2 !rounded-full bg-[#7C3AED] hover:bg-[#6D28D9] text-white px-8 py-3 text-base font-semibold shadow-sm hover:shadow-md transition-colors border-0 cursor-pointer whitespace-nowrap"
      },
      /* @__PURE__ */ React.createElement("i", { className: "fas fa-plus" }),
      " Create quotation"
    ) : /* @__PURE__ */ React.createElement(
      "a",
      {
        href: "create.php?mode=new",
        className: "quotations-create-primary-btn inline-flex items-center justify-center gap-2 !rounded-full bg-[#7C3AED] hover:bg-[#6D28D9] text-white px-8 py-3 text-base font-semibold shadow-sm hover:shadow-md transition-colors border-0 cursor-pointer whitespace-nowrap no-underline"
      },
      /* @__PURE__ */ React.createElement("i", { className: "fas fa-plus" }),
      " Create quotation"
    )), /* @__PURE__ */ React.createElement("div", { className: "grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 mb-5" }, /* @__PURE__ */ React.createElement("div", { className: "bg-white rounded-lg border border-gray-200 px-3.5 py-3 shadow-sm flex items-center gap-3" }, /* @__PURE__ */ React.createElement("div", { className: "h-10 w-10 shrink-0 rounded-lg bg-violet-100 flex items-center justify-center text-violet-600" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-file-lines text-base" })), /* @__PURE__ */ React.createElement("div", { className: "min-w-0 flex-1" }, /* @__PURE__ */ React.createElement("p", { className: "text-sm font-medium text-gray-500 leading-snug" }, "Total Quotations"), /* @__PURE__ */ React.createElement("p", { className: "text-2xl font-bold text-gray-900 mt-1 leading-tight tabular-nums" }, fmtBig(quotationStats.total)), /* @__PURE__ */ React.createElement("p", { className: "text-xs text-gray-400 mt-1 leading-snug" }, "All time"))), /* @__PURE__ */ React.createElement("div", { className: "bg-white rounded-lg border border-gray-200 px-3.5 py-3 shadow-sm flex items-center gap-3" }, /* @__PURE__ */ React.createElement("div", { className: "h-10 w-10 shrink-0 rounded-lg bg-emerald-100 flex items-center justify-center text-emerald-600" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-wallet text-base" })), /* @__PURE__ */ React.createElement("div", { className: "min-w-0 flex-1" }, /* @__PURE__ */ React.createElement("p", { className: "text-sm font-medium text-gray-500 leading-snug" }, "Total Value"), /* @__PURE__ */ React.createElement("p", { className: "text-2xl font-bold text-gray-900 mt-1 leading-tight truncate", title: defaultCurrency + " " + formatCurrency(quotationStats.totalVal) }, defaultCurrency, " ", formatCurrency(quotationStats.totalVal)), /* @__PURE__ */ React.createElement("p", { className: "text-xs text-gray-400 mt-1 leading-snug" }, "All time"))), /* @__PURE__ */ React.createElement("div", { className: "bg-white rounded-lg border border-gray-200 px-3.5 py-3 shadow-sm flex items-center gap-3" }, /* @__PURE__ */ React.createElement("div", { className: "h-10 w-10 shrink-0 rounded-lg bg-amber-100 flex items-center justify-center text-amber-600" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-clock text-base" })), /* @__PURE__ */ React.createElement("div", { className: "min-w-0 flex-1" }, /* @__PURE__ */ React.createElement("p", { className: "text-sm font-medium text-gray-500 leading-snug" }, "Pending Quotations"), /* @__PURE__ */ React.createElement("p", { className: "text-2xl font-bold text-gray-900 mt-1 leading-tight tabular-nums" }, fmtBig(quotationStats.pending)), /* @__PURE__ */ React.createElement("p", { className: "text-xs text-gray-400 mt-1 leading-snug" }, "Awaiting response"))), /* @__PURE__ */ React.createElement("div", { className: "bg-white rounded-lg border border-gray-200 px-3.5 py-3 shadow-sm flex items-center gap-3" }, /* @__PURE__ */ React.createElement("div", { className: "h-10 w-10 shrink-0 rounded-lg bg-green-100 flex items-center justify-center text-green-600" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-circle-check text-base" })), /* @__PURE__ */ React.createElement("div", { className: "min-w-0 flex-1" }, /* @__PURE__ */ React.createElement("p", { className: "text-sm font-medium text-gray-500 leading-snug" }, "Converted to Orders"), /* @__PURE__ */ React.createElement("p", { className: "text-2xl font-bold text-gray-900 mt-1 leading-tight tabular-nums" }, fmtBig(quotationStats.convertedYtd)), /* @__PURE__ */ React.createElement("p", { className: "text-xs text-gray-400 mt-1 leading-snug" }, "This year")))), /* @__PURE__ */ React.createElement("div", { className: "bg-white rounded-xl shadow-sm overflow-hidden" }, rmToolbar, renderQuotationsBody(), rmFooter)));
  }
  return /* @__PURE__ */ React.createElement("div", { className: "max-w-full mx-auto animate-fade-in" }, /* @__PURE__ */ React.createElement("div", { className: "bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm" }, /* @__PURE__ */ React.createElement("div", { className: "px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100" }, /* @__PURE__ */ React.createElement("a", { href: "create.php?mode=new", className: "quotations-create-primary-btn inline-flex items-center justify-center gap-2 !rounded-full bg-[#7C3AED] hover:bg-[#6D28D9] text-white px-6 py-2.5 text-base font-semibold shadow-sm hover:shadow-md transition-colors border-0 cursor-pointer no-underline" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-plus text-sm" }), " Create quotation"), /* @__PURE__ */ React.createElement("div", { className: "flex items-center gap-2 min-w-0" }, /* @__PURE__ */ React.createElement("h1", { className: "text-xl font-bold text-gray-900 truncate" }, "Quotations"), /* @__PURE__ */ React.createElement("a", { href: "../settings/index.php", className: "text-gray-400 hover:text-[#2563EB]", title: "Sales settings" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-cog text-base" }))), /* @__PURE__ */ React.createElement("div", { className: "flex-1" }), /* @__PURE__ */ React.createElement("div", { className: "flex items-center gap-2 text-base text-gray-600" }, /* @__PURE__ */ React.createElement(
    "button",
    {
      type: "button",
      disabled: safePage <= 1,
      onClick: () => setPage((p) => Math.max(1, p - 1)),
      className: "p-1.5 rounded-full border border-gray-200 bg-white disabled:opacity-40 hover:bg-gray-50 transition-colors"
    },
    /* @__PURE__ */ React.createElement("i", { className: "fas fa-chevron-left text-sm" })
  ), /* @__PURE__ */ React.createElement("span", { className: "tabular-nums whitespace-nowrap" }, rangeStart, "-", rangeEnd, " / ", filteredQuotations.length), /* @__PURE__ */ React.createElement(
    "button",
    {
      type: "button",
      disabled: safePage >= pageCount,
      onClick: () => setPage((p) => Math.min(pageCount, p + 1)),
      className: "p-1 rounded border border-gray-200 bg-white disabled:opacity-40 hover:bg-gray-50"
    },
    /* @__PURE__ */ React.createElement("i", { className: "fas fa-chevron-right text-sm" })
  )), /* @__PURE__ */ React.createElement("div", { className: "flex items-center gap-0.5 bg-gray-100 rounded-lg border border-gray-200 p-0.5", role: "group", "aria-label": "View" }, viewBtn("list", "fa-list", "List"), viewBtn("cards", "fa-th-large", "Cards"), viewBtn("board", "fa-columns", "Board"))), /* @__PURE__ */ React.createElement("div", { className: "px-4 py-2 flex flex-wrap items-center gap-3 bg-gray-50/80" }, /* @__PURE__ */ React.createElement("div", { className: "relative flex-1 min-w-[200px] max-w-xl" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-filter absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm" }), /* @__PURE__ */ React.createElement(
    "input",
    {
      type: "text",
      value: search,
      onChange: (e) => setSearch(e.target.value),
      placeholder: "Search number, customer, salesperson...",
      className: "w-full pl-9 pr-3 py-2 text-base bg-white border border-gray-200 rounded-md focus:outline-none focus:ring-2 focus:ring-[#2563EB]/20 focus:border-[#2563EB]"
    }
  )), myQuotationsOnly && /* @__PURE__ */ React.createElement(
    "button",
    {
      type: "button",
      onClick: () => setMyQuotationsOnly(false),
      className: "inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md bg-[#2563EB]/10 text-[#1D4ED8] text-sm font-semibold border border-[#2563EB]/30"
    },
    "My quotations",
    /* @__PURE__ */ React.createElement("i", { className: "fas fa-times text-[10px]" })
  ), !myQuotationsOnly && /* @__PURE__ */ React.createElement(
    "button",
    {
      type: "button",
      onClick: () => setMyQuotationsOnly(true),
      className: "text-sm font-medium text-gray-600 hover:text-[#2563EB] border border-dashed border-gray-300 rounded-md px-2.5 py-1.5 hover:border-[#2563EB]"
    },
    "+ My quotations"
  ), /* @__PURE__ */ React.createElement(
    "select",
    {
      value: statusFilter,
      onChange: (e) => setStatusFilter(e.target.value),
      className: "text-base border border-gray-200 rounded-md px-2.5 py-2 bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#2563EB]/20"
    },
    /* @__PURE__ */ React.createElement("option", { value: "" }, "All statuses"),
    /* @__PURE__ */ React.createElement("option", { value: "quotation" }, "Quotation"),
    /* @__PURE__ */ React.createElement("option", { value: "draft" }, "Draft"),
    /* @__PURE__ */ React.createElement("option", { value: "confirmed" }, "Confirmed"),
    /* @__PURE__ */ React.createElement("option", { value: "processing" }, "Processing"),
    /* @__PURE__ */ React.createElement("option", { value: "shipped" }, "Shipped"),
    /* @__PURE__ */ React.createElement("option", { value: "delivered" }, "Delivered"),
    /* @__PURE__ */ React.createElement("option", { value: "paid" }, "Paid"),
    /* @__PURE__ */ React.createElement("option", { value: "completed" }, "Completed"),
    /* @__PURE__ */ React.createElement("option", { value: "cancelled" }, "Cancelled")
  ), selectedIds.size > 0 && /* @__PURE__ */ React.createElement("div", { className: "flex items-center gap-2 flex-wrap ms-auto" }, /* @__PURE__ */ React.createElement("span", { className: "text-sm text-gray-500" }, selectedIds.size, " selected"), canInvoice && /* @__PURE__ */ React.createElement(
    "a",
    {
      href: "../invoices/create.php?order_id=" + Array.from(selectedIds)[0],
      className: "text-sm font-semibold px-2.5 py-1.5 rounded border border-gray-200 bg-white hover:bg-gray-50 text-gray-800"
    },
    /* @__PURE__ */ React.createElement("i", { className: "fas fa-file-invoice-dollar text-[#2563EB] me-1" }),
    "Invoice"
  ), canDelete && /* @__PURE__ */ React.createElement("form", { method: "POST", onSubmit: handleDelete, className: "inline m-0" }, /* @__PURE__ */ React.createElement("input", { type: "hidden", name: "delete_ids", value: Array.from(selectedIds).join(",") }), /* @__PURE__ */ React.createElement("button", { type: "submit", className: "text-sm font-semibold px-2.5 py-1.5 rounded border border-red-200 bg-white text-red-700 hover:bg-red-50" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-trash-alt me-1" }), "Delete"))))), /* @__PURE__ */ React.createElement("div", { className: "bg-transparent min-h-[50vh] pb-8" }, renderQuotationsBody()));
}
(function() {
  const rootEl = document.getElementById("react-root");
  window.addEventListener("error", function(ev) {
    try {
      const msg = ev && ev.message ? ev.message : String(ev);
      if (rootEl) {
        rootEl.innerHTML = '<p class="p-6 text-red-600 text-base">Unable to load quotations: ' + msg + "</p>";
      }
    } catch (e) {
    }
  });
  window.addEventListener("unhandledrejection", function(ev) {
    try {
      const reason = ev && ev.reason ? ev.reason.message || String(ev.reason) : String(ev);
      if (rootEl) {
        rootEl.innerHTML = '<p class="p-6 text-red-600 text-base">Unable to load quotations: ' + reason + "</p>";
      }
    } catch (e) {
    }
  });
  if (!rootEl) {
    return;
  }
  if (typeof React === "undefined" || typeof ReactDOM === "undefined") {
    rootEl.innerHTML = '<p class="p-6 text-red-600 text-base">Unable to load quotations: React failed to load. Check your network connection and refresh.</p>';
    return;
  }
  try {
    const root = ReactDOM.createRoot(rootEl);
    root.render(
      /* @__PURE__ */ React.createElement(ErrorBoundary, null, /* @__PURE__ */ React.createElement(QuotationsListApp, null))
    );
  } catch (err) {
    console && console.error && console.error(err);
    rootEl.innerHTML = '<p class="p-6 text-red-600 text-base">Unable to load quotations: ' + (err && err.message ? err.message : String(err)) + "</p>";
  }
})();
</script>
    </div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
    </body>

    </html>
    <?php
    $GLOBALS['_erp_layout_closed'] = true;
    exit; // Stop here, do not show the create form
}

// === EXISTING CREATE_ORDER LOGIC BELOW (Runs when mode=new or POST) ===

// Get available products from Stock Module
try {
    $prodCols = [];
    try {
        $prodCols = $salesDb->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    } catch (Throwable $e) {
        $prodCols = [];
    }
    // Sales needs a single image field. Prefer `main_image`, fall back to `image`.
    $imgSelect = 'NULL AS main_image';
    if (in_array('main_image', $prodCols, true) && in_array('image', $prodCols, true)) {
        $imgSelect = 'COALESCE(p.main_image, p.image) AS main_image';
    } elseif (in_array('main_image', $prodCols, true)) {
        $imgSelect = 'p.main_image AS main_image';
    } elseif (in_array('image', $prodCols, true)) {
        $imgSelect = 'p.image AS main_image';
    }

    $products = $salesDb->query("
        SELECT p.id, p.product_code, p.name, p.description, p.unit_price as selling_price, p.item_type, $imgSelect,
               (
                   COALESCE((SELECT SUM(quantity) FROM stock WHERE product_id = p.id), 0) -
                   COALESCE((
                       SELECT SUM(soi.quantity)
                       FROM sales_order_items soi
                       JOIN sales_orders so ON soi.order_id = so.id
                       WHERE soi.product_id = p.id
                       AND so.status IN ('confirmed', 'invoiced', 'paid')
                       AND so.status NOT IN ('shipped', 'delivered', 'cancelled')
                       AND (so.shipped_at IS NULL OR so.shipped_at = '0000-00-00 00:00:00')
                   ), 0)
               ) as stock_quantity
        FROM products p
        ORDER BY p.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (function_exists('sales_product_image_url')) {
        foreach ($products as $pIdx => $pRow) {
            $pId = (int) ($pRow['id'] ?? 0);
            if ($pId < 1) {
                $products[$pIdx]['image_url'] = '';
                continue;
            }
            $line = array(
                'product_id' => $pId,
                'main_image' => (string) ($pRow['main_image'] ?? ''),
            );
            if (function_exists('sales_order_item_image_name')) {
                $line['main_image'] = sales_order_item_image_name($line, $salesDb);
            }
            $products[$pIdx]['image_url'] = sales_product_image_url($pId, (string) ($line['main_image'] ?? ''), 'thumbnail');
        }
        unset($pIdx, $pRow, $pId, $line);
    }

} catch (PDOException $e) {
    // Fallback or error logging
    $products = [];
    $error_msg = "Error loading products: " . $e->getMessage();
}

// Get customers
$customers = $salesDb->query("
    SELECT id, customer_code, company_name, contact_person, 
           current_balance, credit_limit, phone, email
    FROM customers 
    WHERE status = 'active'
    ORDER BY company_name
")->fetchAll();

$users = [];
try {
    $userCols = $salesDb->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    $hasNameCol = in_array('name', $userCols, true);
    $hasActiveCol = in_array('is_active', $userCols, true);
    $displayExpr = $hasNameCol
        ? "TRIM(COALESCE(NULLIF(TRIM(full_name), ''), NULLIF(TRIM(name), ''), username, ''))"
        : "TRIM(COALESCE(NULLIF(TRIM(full_name), ''), username, ''))";
    $usersSql = 'SELECT id, username, ' . $displayExpr . ' AS full_name FROM users';
    if ($hasActiveCol) {
        $usersSql .= ' WHERE is_active = 1';
    }
    $usersSql .= " HAVING full_name <> '' ORDER BY full_name ASC";
    $users = $salesDb->query($usersSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $users = [];
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentUserName = trim((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
if ($currentUserId > 0 && $currentUserName !== '') {
    $hasCurrentUser = false;
    foreach ($users as $existingUser) {
        if ((int) ($existingUser['id'] ?? 0) === $currentUserId) {
            $hasCurrentUser = true;
            break;
        }
    }
    if (!$hasCurrentUser) {
        array_unshift($users, [
            'id' => $currentUserId,
            'username' => (string) ($_SESSION['username'] ?? ''),
            'full_name' => $currentUserName,
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        ensureCustomerColumnsExist();

        $salesDb->beginTransaction();

        // 1. Create Sales Order
        $order_number = 'SO-' . date('Y') . '-' . str_pad(getNextOrderNumber(), 5, '0', STR_PAD_LEFT);

        $orderType = function_exists('salesNormalizeOrderType')
            ? salesNormalizeOrderType((string) ($_POST['order_type'] ?? 'spare'))
            : 'spare';
        if (function_exists('isRoadmaster') && isRoadmaster() && !empty($_POST['items']) && is_array($_POST['items'])) {
            try {
                $itCols = $salesDb->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
                if (is_array($itCols) && in_array('item_type', $itCols, true)) {
                    $itStmt = $salesDb->prepare('SELECT LOWER(TRIM(COALESCE(item_type, \'\'))) FROM products WHERE id = ? LIMIT 1');
                    foreach ($_POST['items'] as $item) {
                        $pid = (int) ($item['product_id'] ?? 0);
                        if ($pid <= 0) {
                            continue;
                        }
                        $itStmt->execute([$pid]);
                        $it = (string) $itStmt->fetchColumn();
                        if ($it === 'vehicle' || $it === 'truck') {
                            $orderType = 'truck';
                            break;
                        }
                    }
                }
            } catch (Throwable $e) {
                // keep $orderType from POST
            }
        }

        $soCols = [];
        try {
            $soCols = $salesDb->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        } catch (Throwable $e) {
            $soCols = [];
        }
        $hasLeadTime = in_array('lead_time', $soCols, true);
        $hasOrderType = in_array('order_type', $soCols, true);
        $hasCompanyId = in_array('company_id', $soCols, true);
        $hasCurrency = in_array('currency', $soCols, true);
        $hasExchangeRate = in_array('exchange_rate', $soCols, true);
        $hasDisplayCurrencies = in_array('display_currencies', $soCols, true);
        $hasCurrencyRates = in_array('currency_rates', $soCols, true);

        $quoteCurrencyOptions = sales_invoice_currency_options();
        $displayCurrencies = [];
        if (!empty($_POST['display_currencies'])) {
            $rawCurrencies = $_POST['display_currencies'];
            $decodedCurrencies = is_string($rawCurrencies) ? json_decode($rawCurrencies, true) : $rawCurrencies;
            if (is_array($decodedCurrencies)) {
                foreach ($decodedCurrencies as $currencyCode) {
                    $currencyCode = strtoupper(trim((string) $currencyCode));
                    if (isset($quoteCurrencyOptions[$currencyCode]) && !in_array($currencyCode, $displayCurrencies, true)) {
                        $displayCurrencies[] = $currencyCode;
                    }
                }
            }
        }

        $selectedCurrency = strtoupper(trim((string) ($_POST['currency'] ?? '')));
        if ($selectedCurrency === '' || !isset($quoteCurrencyOptions[$selectedCurrency])) {
            $selectedCurrency = $displayCurrencies[0] ?? 'TZS';
        }
        if (!in_array($selectedCurrency, $displayCurrencies, true)) {
            array_unshift($displayCurrencies, $selectedCurrency);
        }
        if ($displayCurrencies === []) {
            $displayCurrencies = [$selectedCurrency];
        }
        $displayCurrencies = sales_order_display_currencies_ordered($displayCurrencies, $selectedCurrency);

        $currencyRates = ['TZS' => 1.0];
        if (!empty($_POST['currency_rates'])) {
            $rawRates = $_POST['currency_rates'];
            $decodedRates = is_string($rawRates) ? json_decode($rawRates, true) : $rawRates;
            if (is_array($decodedRates)) {
                foreach ($decodedRates as $rateCode => $rateValue) {
                    $rateCode = strtoupper(trim((string) $rateCode));
                    if (!isset($quoteCurrencyOptions[$rateCode])) {
                        continue;
                    }
                    $currencyRates[$rateCode] = max(0.0, (float) $rateValue);
                }
            }
        }
        $currencyRates['TZS'] = 1.0;
        $postedExchangeRate = (float) ($currencyRates[$selectedCurrency] ?? ($_POST['exchange_rate'] ?? 1));
        if ($selectedCurrency === 'TZS') {
            $postedExchangeRate = 1.0;
        } elseif ($postedExchangeRate <= 0) {
            $postedExchangeRate = 1.0;
        }
        $currencyRates[$selectedCurrency] = $postedExchangeRate;
        if (in_array('USD', $displayCurrencies, true) && (float) ($currencyRates['USD'] ?? 0) <= 1.01 && function_exists('sales_invoice_bot_exchange_rates')) {
            $botUsd = sales_invoice_bot_exchange_rates(['USD']);
            if ((float) ($botUsd['USD'] ?? 0) > 1.01) {
                $currencyRates['USD'] = (float) $botUsd['USD'];
            }
        }

        $orderFields = [
            'order_number',
            'customer_id',
            'quote_date',
            'valid_until',
        ];
        $orderValues = [
            $order_number,
            !empty($_POST['customer_id']) ? $_POST['customer_id'] : null,
            $_POST['quote_date'],
            $_POST['valid_until'],
        ];
        if ($hasLeadTime) {
            $orderFields[] = 'lead_time';
            $orderValues[] = ($_POST['lead_time'] ?? '') !== '' ? $_POST['lead_time'] : null;
        }
        $orderFields = array_merge($orderFields, [
            'subtotal',
            'discount_amount',
            'tax_amount',
            'shipping_charges',
            'total_amount',
            'status',
        ]);
        $orderValues = array_merge($orderValues, [
            $_POST['subtotal'],
            $_POST['discount_amount'],
            $_POST['tax_amount'],
            $_POST['shipping_charges'],
            $_POST['total_amount'],
            $_POST['status'] ?? 'draft',
        ]);
        if ($hasCurrency) {
            $orderFields[] = 'currency';
            $orderValues[] = $selectedCurrency;
        }
        if ($hasExchangeRate) {
            $orderFields[] = 'exchange_rate';
            $orderValues[] = $postedExchangeRate;
        }
        if ($hasDisplayCurrencies) {
            $orderFields[] = 'display_currencies';
            $orderValues[] = json_encode(array_values($displayCurrencies), JSON_UNESCAPED_UNICODE);
        }
        if ($hasCurrencyRates) {
            $orderFields[] = 'currency_rates';
            $orderValues[] = json_encode($currencyRates, JSON_UNESCAPED_UNICODE);
        }
        if ($hasOrderType) {
            $orderFields[] = 'order_type';
            $orderValues[] = $orderType;
        }
        $orderFields[] = 'created_by';
        $orderValues[] = !empty($_POST['created_by']) ? $_POST['created_by'] : $_SESSION['user_id'];
        if ($hasCompanyId) {
            $orderFields[] = 'company_id';
            $orderValues[] = currentCompanyId();
        }

        $order_sql = 'INSERT INTO sales_orders (' . implode(', ', $orderFields) . ') VALUES (' . implode(', ', array_fill(0, count($orderValues), '?')) . ')';
        $stmt = $salesDb->prepare($order_sql);
        $stmt->execute($orderValues);


        $order_id = $salesDb->lastInsertId();

        // 2. Add Order Items
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $soiCols = [];
            try {
                $soiCols = $salesDb->query('SHOW COLUMNS FROM sales_order_items')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            } catch (Throwable $e) {
                $soiCols = [];
            }
            $hasItemDesc = in_array('description', $soiCols, true);
            $hasItemCompanyId = in_array('company_id', $soiCols, true);
            $itemFields = ['order_id', 'product_id', 'quantity', 'unit_price', 'discount_percentage', 'line_total'];
            if ($hasItemDesc) {
                $itemFields[] = 'description';
            }
            if ($hasItemCompanyId) {
                array_splice($itemFields, 1, 0, ['company_id']);
            }
            $item_sql = 'INSERT INTO sales_order_items (' . implode(', ', $itemFields) . ') VALUES (' . implode(', ', array_fill(0, count($itemFields), '?')) . ')';
            $stmtItem = $salesDb->prepare($item_sql);

            foreach ($_POST['items'] as $item) {
                if (!empty($item['product_id']) && $item['quantity'] > 0) {
                    $line_total = $item['quantity'] * $item['unit_price'];

                    $itemValues = [$order_id];
                    if ($hasItemCompanyId) {
                        $itemValues[] = currentCompanyId();
                    }
                    $itemValues = array_merge($itemValues, [
                        $item['product_id'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['discount'] ?? 0,
                        $line_total,
                    ]);
                    if ($hasItemDesc) {
                        $itemValues[] = $item['description'] ?? '';
                    }
                    $stmtItem->execute($itemValues);

                    // 3. Reserve Stock (if confirmed order)
                    if (($_POST['status'] ?? '') === 'confirmed') {
                        $reserve_sql = "INSERT INTO stock_reservations (
                            order_id, product_id, quantity, expires_at
                        ) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 3 DAY))";

                        $stmt = $salesDb->prepare($reserve_sql);
                        $stmt->execute([$order_id, $item['product_id'], $item['quantity']]);
                    }
                }
            }
        }

        $salesDb->commit();

        $_SESSION['success'] = "Sales order created successfully! Order #: " . $order_number;
        header('Location: ' . sales_module_url('orders/view.php', ['id' => $order_id, 'module' => 'sales']));
        exit();

    } catch (Exception $e) {
        $salesDb->rollBack();
        $_SESSION['error'] = "Error creating order: " . $e->getMessage();
    }
}

$catalogueUrl = sales_catalogue_url('quote');
$customerCatalogueUrl = sales_customer_catalogue_url('quote', sales_module_url('orders/create.php', ['mode' => 'new']));
$predefinedType = strtolower(trim((string) ($_GET['type'] ?? 'spare')));
if (!in_array($predefinedType, ['truck', 'spare'], true)) {
    $predefinedType = 'spare';
}
if (!salesSupportsTruckInvoices() && $predefinedType === 'truck') {
    $redirectParams = $_GET;
    unset($redirectParams['type']);
    $redirectQuery = http_build_query($redirectParams);
    header('Location: create.php' . ($redirectQuery !== '' ? '?' . $redirectQuery : ''));
    exit;
}
$companyTaxMode = trim((string) getCompanySetting('tax_calculation_mode', 'exclusive'));
if (!in_array($companyTaxMode, ['exclusive', 'inclusive'], true)) {
    $companyTaxMode = 'exclusive';
}

$quoteDefaultCurrency = 'TZS';
try {
    if (function_exists('currentCompanyId')) {
        $cidQuote = (int) currentCompanyId();
        if ($cidQuote > 0) {
            $stQuote = $salesDb->prepare('SELECT default_currency FROM sales_settings WHERE company_id = ? LIMIT 1');
            $stQuote->execute([$cidQuote]);
            $rowQuote = $stQuote->fetch(PDO::FETCH_ASSOC);
            if (!empty($rowQuote['default_currency'])) {
                $quoteDefaultCurrency = strtoupper(trim((string) $rowQuote['default_currency']));
            }
        }
    }
    if ($quoteDefaultCurrency === 'TZS') {
        $rowQuote = $salesDb->query('SELECT default_currency FROM sales_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!empty($rowQuote['default_currency'])) {
            $quoteDefaultCurrency = strtoupper(trim((string) $rowQuote['default_currency']));
        }
    }
} catch (Throwable $e) {
    $quoteDefaultCurrency = 'TZS';
}

$quoteCurrencyOptions = sales_invoice_currency_options();
if (!isset($quoteCurrencyOptions[$quoteDefaultCurrency])) {
    $quoteDefaultCurrency = 'TZS';
}

$quoteInitialExchangeRates = ['TZS' => '1.0000'];
$quoteSeedRateCurrencies = [strtoupper($quoteDefaultCurrency)];
if (function_exists('isRoadmaster') && isRoadmaster()) {
    foreach (['TZS', 'USD'] as $seedCode) {
        if (!in_array($seedCode, $quoteSeedRateCurrencies, true)) {
            $quoteSeedRateCurrencies[] = $seedCode;
        }
    }
}
if (function_exists('sales_invoice_bot_exchange_rates')) {
    $quoteInitialExchangeRates = sales_invoice_bot_exchange_rates($quoteSeedRateCurrencies);
}

$quoteInitialExchangeRate = 1.0;
if ($quoteDefaultCurrency !== 'TZS' && !empty($quoteInitialExchangeRates[$quoteDefaultCurrency])) {
    $quoteInitialExchangeRate = (float) $quoteInitialExchangeRates[$quoteDefaultCurrency];
} elseif (function_exists('bot_get_exchange_rate')) {
    $botRateInfo = bot_get_exchange_rate($quoteDefaultCurrency);
    if (is_array($botRateInfo) && (float) ($botRateInfo['rate'] ?? 0) > 0) {
        $quoteInitialExchangeRate = (float) $botRateInfo['rate'];
    }
}

$quoteExchangeRateApiUrl = function_exists('sales_module_url')
    ? sales_module_url('payments/exchange_rate.php')
    : '../payments/exchange_rate.php';

$quotePageTitle = function_exists('salesQuoteCreatePageTitle')
    ? salesQuoteCreatePageTitle($predefinedType)
    : 'Create Quotation';

if (function_exists('salesQuoteCreateUsesReactShell') && salesQuoteCreateUsesReactShell()) {
    require_once __DIR__ . '/../invoices/includes/invoices-lib.php';
    salesDocumentCreateRenderReactShell($quotePageTitle);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Quotation</title>
    
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <script>
        window.tailwind = window.tailwind || {};
        window.tailwind.config = { corePlugins: { preflight: false } };
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="/stock/assets/css/style.css" rel="stylesheet">
    <link href="/assets/css/sales-mobile.css" rel="stylesheet">

    <!-- React & Babel -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/react/18.2.0/umd/react.production.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.2.0/umd/react-dom.production.min.js"></script>
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html:has(body.page-sales-order-create),
        body.page-sales-order-create {
            background-color: #f8fafc !important;
        }

        body.page-sales-order-create {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: #334155;
            font-size: 16px;
            min-height: 100vh;
        }

        body.page-sales-order-create header.employee-header {
            background: #f8fafc !important;
            box-shadow: none !important;
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
        }

        body.page-sales-order-create .employee-header .header-content {
            background: transparent;
        }

        body.page-sales-order-create #native-sidebar {
            background: var(--sidebar-bg) !important;
            border-right: 1px solid var(--sidebar-border) !important;
            color: var(--sidebar-text) !important;
        }

        body.page-sales-order-create .layout-main-wrapper {
            background-color: #f8fafc;
            width: 100%;
            max-width: 100%;
        }

        body.page-sales-order-create .layout-main-wrapper > .flex-grow-1 {
            flex: 1 1 0%;
            min-width: 0;
            max-width: none;
            width: 100%;
            background-color: #f8fafc;
        }

        .main-content {
            padding: 0;
            max-width: 100%;
            margin: 0 auto;
            min-height: calc(100vh - 64px);
        }

        body.page-sales-order-create .main-content {
            flex: 1 1 auto;
            width: 100% !important;
            max-width: none !important;
            min-width: 0;
            background-color: #f8fafc;
        }

        body.page-sales-order-create #react-root {
            width: 100%;
            min-width: 0;
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in {
            animation: fadeIn 0.4s ease-out forwards;
        }

        /* Custom scrollbar for product list */
        .custom-scroll::-webkit-scrollbar {
            width: 6px;
        }
        .custom-scroll::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        .custom-scroll::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        .custom-scroll::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>

<body class="page-sales-order-create">
    <?php include '../../../includes/header_employee.php'; ?>

    <div class="main-content" id="react-root">
        <p class="p-6 text-gray-500 text-base">Loading order form…</p>
    </div>

    <script>
        window.APP_DATA = {
            products: <?= sales_json_script($products) ?>,
            customers: <?= sales_json_script($customers) ?>,
            users: <?= sales_json_script($users) ?>,
            currentUserId: <?= sales_json_script($_SESSION['user_id']) ?>,
            currentUserName: <?= sales_json_script((string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System Admin')) ?>,
            error: <?= sales_json_script($_SESSION['error'] ?? ($error_msg ?? null)) ?>,
            catalogueUrl: <?= sales_json_script($catalogueUrl) ?>,
            customerCatalogueUrl: <?= sales_json_script($customerCatalogueUrl) ?>,
            stockUploadsBase: <?= sales_json_script(app_url('/stock/uploads/products')) ?>,
            isRoadmaster: <?= sales_json_script(function_exists('isRoadmaster') ? isRoadmaster() : false) ?>,
            predefinedType: <?= sales_json_script($predefinedType) ?>,
            taxMode: <?= sales_json_script($companyTaxMode) ?>,
            defaultCurrency: <?= sales_json_script($quoteDefaultCurrency) ?>,
            currencyOptions: <?= sales_json_script($quoteCurrencyOptions) ?>,
            initialExchangeRate: <?= sales_json_script($quoteInitialExchangeRate) ?>,
            initialExchangeRates: <?= sales_json_script($quoteInitialExchangeRates) ?>,
            exchangeRateApiUrl: <?= sales_json_script($quoteExchangeRateApiUrl) ?>
        };

        <?php unset($_SESSION['error']); ?>
    </script>

    <!-- Global error handler outside Babel to catch loading or parsing errors -->
    <script>
        window.addEventListener('error', function (ev) {
            try {
                var msg = ev && ev.message ? ev.message : String(ev);
                var el = document.getElementById('react-root');
                if (el && el.innerHTML.indexOf('Loading') !== -1) {
                    el.innerHTML = '<div class="p-6 text-red-600 text-base border border-red-200 bg-red-50 rounded m-4"><strong>Error:</strong> ' + msg + '</div>';
                }
            } catch (e) { }
        });
        setTimeout(function() {
            var el = document.getElementById('react-root');
            if (el && el.innerHTML.indexOf('Loading') !== -1) {
                el.innerHTML = '<div class="p-6 text-orange-600 text-base border border-orange-200 bg-orange-50 rounded m-4"><strong>Warning:</strong> The application is taking too long to load. Please check your internet connection or try refreshing the page.</div>';
            }
        }, 10000);
    </script>

    <script>const { useState, useEffect, useMemo, Component } = React;
class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, error: null };
  }
  static getDerivedStateFromError(error) {
    return { hasError: true, error };
  }
  componentDidCatch(error, errorInfo) {
    console && console.error && console.error("React ErrorBoundary caught an error", error, errorInfo);
  }
  render() {
    if (this.state.hasError) {
      return /* @__PURE__ */ React.createElement("div", { className: "p-6 text-red-600 bg-red-50 border border-red-200 rounded m-4" }, /* @__PURE__ */ React.createElement("h2", { className: "text-lg font-bold mb-2" }, "Something went wrong"), /* @__PURE__ */ React.createElement("p", { className: "text-sm font-mono whitespace-pre-wrap" }, this.state.error && this.state.error.toString()));
    }
    return this.props.children;
  }
}
function CreateOrderApp() {
  const { products, customers, users = [], currentUserId, currentUserName, error, catalogueUrl, customerCatalogueUrl, stockUploadsBase, isRoadmaster, predefinedType, taxMode, defaultCurrency, currencyOptions = {}, initialExchangeRate, initialExchangeRates = { TZS: "1.0000" }, exchangeRateApiUrl } = window.APP_DATA;
  const flagBase = "https://flagcdn.com/w40/";
  const [items, setItems] = useState([
    { id: Date.now(), product_id: "", quantity: 1, unit_price: 0, discount: 0, tax_percent: 18, line_total: 0, description: "", stock_quantity: 0, max_stock: 0, image: "", searchQuery: "", showDropdown: false }
  ]);
  const [formData, setFormData] = useState({
    customer_id: "",
    quote_date: (/* @__PURE__ */ new Date()).toISOString().split("T")[0],
    valid_until: new Date(Date.now() + 7 * 24 * 60 * 60 * 1e3).toISOString().split("T")[0],
    lead_time: "",
    discount_amount: 0,
    tax_percentage: 0,
    shipping_charges: 0,
    status: "quotation",
    order_type: predefinedType || "spare",
    created_by: currentUserId || "",
    currencies: (() => {
      const code = String(defaultCurrency || "TZS").toUpperCase();
      if (isRoadmaster && (predefinedType || "spare") === "truck") {
        return code === "TZS" ? ["TZS", "USD"] : [code, "TZS"].filter((v, i, a) => a.indexOf(v) === i);
      }
      return [code];
    })(),
    primaryCurrency: String(defaultCurrency || "TZS").toUpperCase(),
    exchange_rates: (() => {
      const seeded = { ...initialExchangeRates };
      const code = String(defaultCurrency || "TZS").toUpperCase();
      if (code !== "TZS" && initialExchangeRate && !seeded[code]) {
        seeded[code] = String(initialExchangeRate);
      }
      return seeded;
    })()
  });
  const [currencyMenuOpen, setCurrencyMenuOpen] = useState(false);
  const [rateLoadingCodes, setRateLoadingCodes] = useState([]);
  const [rateHint, setRateHint] = useState(() => {
    const usdDefault = initialExchangeRates?.USD;
    if (isRoadmaster && usdDefault) {
      return "Default BOT USD rate: " + parseFloat(usdDefault).toFixed(4) + " TZS per 1 USD. Fetching latest rate…";
    }
    return "Select one or more currencies. BOT rates load automatically for non-TZS codes.";
  });
  const rateFetchTokenRef = React.useRef(0);
  const defaultLineTax = () => parseFloat(formData.tax_percentage) > 0 ? parseFloat(formData.tax_percentage) : 18;
  useEffect(() => {
    const pickedFromStorage = localStorage.getItem("selected_customer_id");
    const pickedFromQuery = new URLSearchParams(window.location.search).get("customer_id");
    const picked = pickedFromQuery || pickedFromStorage;
    if (picked) {
      setFormData((prev) => ({ ...prev, customer_id: String(picked) }));
      localStorage.removeItem("selected_customer_id");
      try {
        const url = new URL(window.location.href);
        if (url.searchParams.has("customer_id")) {
          url.searchParams.delete("customer_id");
          window.history.replaceState({}, "", url.toString());
        }
      } catch (e) {
      }
    }
  }, []);
  useEffect(() => {
    const key = "sales_catalogue_guide_quote_seen";
    if (!localStorage.getItem(key)) {
      Swal.fire({
        title: "New Feature: Catalogue",
        html: '<div style="font-size:14px; color:#374151; margin-top:6px;">You can select products quickly from the Catalogue.</div>',
        icon: "info",
        showCancelButton: true,
        confirmButtonText: "Open Catalogue",
        cancelButtonText: "Later"
      }).then((result) => {
        if (result.isConfirmed) {
          window.location.href = catalogueUrl;
        }
      });
      localStorage.setItem(key, "1");
    }
    try {
      const raw = localStorage.getItem("sales_catalogue_items");
      let restored = false;
      if (raw) {
        const catItems = JSON.parse(raw);
        if (Array.isArray(catItems) && catItems.length > 0) {
          const newItems = catItems.map((ci) => {
            const prod = products.find((p) => p.id == ci.product_id);
            if (!prod) return null;
            const qty = parseFloat(ci.quantity) || 1;
            const price = parseFloat(prod.selling_price) || 0;
            return {
              id: Date.now() + Math.random(),
              product_id: prod.id,
              quantity: qty,
              unit_price: price,
              discount: 0,
              tax_percent: 18,
              line_total: qty * price,
              description: prod.description || "",
              stock_quantity: prod.stock_quantity || 0,
              max_stock: prod.stock_quantity || 0,
              image: prod.image_url || "",
              searchQuery: prod.name,
              showDropdown: false
            };
          }).filter(Boolean);
          if (newItems.length > 0) {
            setItems(newItems);
            restored = true;
          }
        }
        localStorage.removeItem("sales_catalogue_items");
      }
      if (!restored) {
        const idsParam = new URLSearchParams(window.location.search).get("catalogue_product_ids");
        if (idsParam) {
          const ids = idsParam.split(",").map((s) => Number(s.trim())).filter((id) => id > 0);
          const newItems = ids.map((pid) => {
            const prod = products.find((p) => p.id == pid);
            if (!prod) return null;
            const price = parseFloat(prod.selling_price) || 0;
            return {
              id: Date.now() + Math.random(),
              product_id: prod.id,
              quantity: 1,
              unit_price: price,
              discount: 0,
              tax_percent: 18,
              line_total: price,
              description: prod.description || "",
              stock_quantity: prod.stock_quantity || 0,
              max_stock: prod.stock_quantity || 0,
              image: prod.image_url || "",
              searchQuery: prod.name,
              showDropdown: false
            };
          }).filter(Boolean);
          if (newItems.length > 0) {
            setItems(newItems);
          }
          try {
            const url = new URL(window.location.href);
            url.searchParams.delete("catalogue_product_ids");
            window.history.replaceState({}, "", url.toString());
          } catch (e2) {
          }
        }
      }
    } catch (e) {
    }
  }, [catalogueUrl, products]);
  const closeAllDropdowns = () => {
    setItems((prev) => prev.map((it) => ({ ...it, showDropdown: false, focusIndex: -1 })));
  };
  useEffect(() => {
    const onDocClick = (e) => {
      if (e.target.closest(".product-search-cell") || e.target.closest(".product-search-dropdown")) {
        return;
      }
      setItems((prev) => {
        if (!prev.some((it) => it.showDropdown)) {
          return prev;
        }
        return prev.map((it) => ({ ...it, showDropdown: false, focusIndex: -1 }));
      });
    };
    document.addEventListener("click", onDocClick, true);
    return () => document.removeEventListener("click", onDocClick, true);
  }, []);
  const formatRateHint = (data, code) => {
    if (!data || !data.ok) {
      return data && data.error ? data.error : "Could not load BOT rate. Enter manually.";
    }
    const src = data.via_ai ? "BOT (AI)" : data.source || "BOT";
    const asOf = data.as_of ? " as of " + data.as_of : "";
    return src + " mean rate: " + parseFloat(data.rate).toFixed(4) + " TZS per 1 " + code + " (" + src + asOf + "). You may adjust before saving.";
  };
  const applyDefaultBotRate = React.useCallback((currencyCode) => {
    const fallback = initialExchangeRates?.[currencyCode];
    if (!fallback) {
      return false;
    }
    setFormData((prev) => ({
      ...prev,
      exchange_rates: {
        ...prev.exchange_rates,
        [currencyCode]: fallback
      }
    }));
    setRateHint("Using default BOT rate: " + parseFloat(fallback).toFixed(4) + " TZS per 1 " + currencyCode + ".");
    return true;
  }, [initialExchangeRates]);
  const fetchBotExchangeRate = React.useCallback((code) => {
    const currencyCode = String(code || "TZS").toUpperCase();
    if (currencyCode === "TZS") {
      setFormData((prev) => ({
        ...prev,
        exchange_rates: {
          ...prev.exchange_rates,
          TZS: "1.0000"
        }
      }));
      return Promise.resolve();
    }
    const token = ++rateFetchTokenRef.current;
    setRateLoadingCodes((prev) => [...new Set([...prev, currencyCode])]);
    return fetch(exchangeRateApiUrl + "?currency=" + encodeURIComponent(currencyCode), { credentials: "same-origin" }).then((res) => res.json()).then((data) => {
      if (token !== rateFetchTokenRef.current) {
        return;
      }
      if (data && data.ok && data.rate) {
        setFormData((prev) => ({
          ...prev,
          exchange_rates: {
            ...prev.exchange_rates,
            [currencyCode]: parseFloat(data.rate).toFixed(4)
          }
        }));
        setRateHint(formatRateHint(data, currencyCode));
      } else if (!applyDefaultBotRate(currencyCode)) {
        setRateHint(formatRateHint(data, currencyCode));
      }
    }).catch(() => {
      if (token === rateFetchTokenRef.current && !applyDefaultBotRate(currencyCode)) {
        setRateHint("Could not fetch BOT rate for " + currencyCode + ". Enter manually.");
      }
    }).finally(() => {
      if (token === rateFetchTokenRef.current) {
        setRateLoadingCodes((prev) => prev.filter((c) => c !== currencyCode));
      }
    });
  }, [exchangeRateApiUrl, applyDefaultBotRate]);
  const toggleCurrency = (code) => {
    const currencyCode = String(code || "TZS").toUpperCase();
    setFormData((prev) => {
      const selected = [...(prev.currencies || [])];
      const idx = selected.indexOf(currencyCode);
      if (idx >= 0) {
        if (selected.length <= 1) {
          return prev;
        }
        selected.splice(idx, 1);
        let primaryCurrency = prev.primaryCurrency;
        if (primaryCurrency === currencyCode) {
          primaryCurrency = selected[0];
        }
        return { ...prev, currencies: selected, primaryCurrency };
      }
      selected.push(currencyCode);
      if (currencyCode !== "TZS" && !prev.exchange_rates?.[currencyCode]) {
        setTimeout(() => fetchBotExchangeRate(currencyCode), 0);
      }
      return { ...prev, currencies: selected };
    });
  };
  const setPrimaryCurrency = (code) => {
    const currencyCode = String(code || "TZS").toUpperCase();
    setFormData((prev) => {
      const selected = [...(prev.currencies || [])];
      if (!selected.includes(currencyCode)) {
        selected.push(currencyCode);
      }
      return { ...prev, currencies: selected, primaryCurrency: currencyCode };
    });
    if (currencyCode !== "TZS" && !formData.exchange_rates?.[currencyCode]) {
      fetchBotExchangeRate(currencyCode);
    }
  };
  const updateExchangeRate = (code, value) => {
    const currencyCode = String(code || "TZS").toUpperCase();
    setFormData((prev) => ({
      ...prev,
      exchange_rates: {
        ...prev.exchange_rates,
        [currencyCode]: value
      }
    }));
  };
  useEffect(() => {
    const codes = (formData.currencies || []).filter((code) => code !== "TZS");
    codes.forEach((code) => {
      fetchBotExchangeRate(code);
    });
  }, []);
  useEffect(() => {
    const onDocClick = (e) => {
      if (!e.target.closest(".inv-currency-picker")) {
        setCurrencyMenuOpen(false);
      }
    };
    document.addEventListener("click", onDocClick, true);
    return () => document.removeEventListener("click", onDocClick, true);
  }, []);
  const handleItemChange = (index, field, value) => {
    const newItems = [...items];
    const item = { ...newItems[index] };
    if (field === "product_id") {
      const prod = products.find((p) => p.id == value);
      if (prod) {
        item.product_id = prod.id;
        item.searchQuery = prod.name;
        item.unit_price = parseFloat(prod.selling_price) || 0;
        item.description = prod.description || "";
        item.stock_quantity = prod.stock_quantity || 0;
        item.max_stock = prod.stock_quantity || 0;
        item.image = prod.image_url || "";
        if (isRoadmaster && prod.item_type === "vehicle" && formData.order_type !== "truck") {
          setFormData((prev) => {
            const code = String(defaultCurrency || "TZS").toUpperCase();
            const currencies = prev.currencies && prev.currencies.length > 1 ? prev.currencies : code === "TZS" ? ["TZS", "USD"] : [code, "TZS"].filter((v, i, a) => a.indexOf(v) === i);
            return { ...prev, order_type: "truck", currencies, primaryCurrency: prev.primaryCurrency || code };
          });
          if (window.Swal) {
            Swal.fire({
              toast: true,
              position: "top-end",
              icon: "info",
              title: "Vehicle detected. Switching to Truck Quotation.",
              showConfirmButton: false,
              timer: 3e3
            });
          }
        }
      } else {
        item.product_id = "";
        item.searchQuery = "";
        item.unit_price = 0;
        item.description = "";
        item.stock_quantity = 0;
        item.max_stock = 0;
        item.image = "";
      }
    } else {
      item[field] = value;
      if (field === "searchQuery") {
        item.showDropdown = true;
        item.focusIndex = 0;
        const el = document.getElementById(`item-search-${index}`);
        if (el) {
          const rect = el.getBoundingClientRect();
          item.dropdownPos = { left: rect.left + window.scrollX, top: rect.top + window.scrollY };
        }
      }
      if (field === "showDropdown" && value === false) {
        item.focusIndex = -1;
      }
    }
    item.line_total = recalcLineTotal(item);
    newItems[index] = item;
    setItems(newItems);
  };
  const selectProduct = (index, product) => {
    if (!product) {
      return;
    }
    setItems((prev) => {
      const newItems = [...prev];
      const item = { ...newItems[index] };
      item.product_id = product.id;
      item.searchQuery = product.name || "";
      item.unit_price = parseFloat(product.selling_price) || 0;
      item.description = product.description || "";
      item.stock_quantity = product.stock_quantity || 0;
      item.max_stock = product.stock_quantity || 0;
      item.image = product.image_url || "";
      item.showDropdown = false;
      item.focusIndex = -1;
      if (item.tax_percent === void 0 || item.tax_percent === null) {
        item.tax_percent = defaultLineTax();
      }
      item.line_total = recalcLineTotal(item);
      newItems[index] = item;
      return newItems;
    });
    if (isRoadmaster && product.item_type === "vehicle" && formData.order_type !== "truck") {
      setFormData((prev) => {
        const code = String(defaultCurrency || "TZS").toUpperCase();
        const currencies = prev.currencies && prev.currencies.length > 1 ? prev.currencies : code === "TZS" ? ["TZS", "USD"] : [code, "TZS"].filter((v, i, a) => a.indexOf(v) === i);
        return { ...prev, order_type: "truck", currencies, primaryCurrency: prev.primaryCurrency || code };
      });
      if (window.Swal) {
        Swal.fire({
          toast: true,
          position: "top-end",
          icon: "info",
          title: "Vehicle detected. Switching to Truck Quotation.",
          showConfirmButton: false,
          timer: 3e3
        });
      }
    }
  };
  const handleInputKey = (index, e, matchingProducts) => {
    if (!matchingProducts) matchingProducts = [];
    if (e.key === "ArrowDown") {
      if (!items[index].showDropdown) {
        openDropdown(index);
        return;
      }
      moveFocus(index, 1, matchingProducts.length || 1);
    } else if (e.key === "ArrowUp") {
      moveFocus(index, -1, matchingProducts.length || 1);
    } else if (e.key === "Enter") {
      if (items[index].showDropdown) {
        e.preventDefault();
        const fi = items[index].focusIndex >= 0 ? items[index].focusIndex : 0;
        const prod = matchingProducts[fi];
        if (prod) selectProduct(index, prod);
      }
    } else if (e.key === "Escape") {
      closeDropdown(index);
    }
  };
  const openDropdown = (index) => {
    const el = document.getElementById(`item-search-${index}`);
    setItems((prev) => {
      const newItems = prev.map((it, i) => ({
        ...it,
        showDropdown: i === index,
        focusIndex: -1
      }));
      if (!el) {
        newItems[index] = { ...newItems[index], showDropdown: true, focusIndex: -1 };
        return newItems;
      }
      const rect = el.getBoundingClientRect();
      const pos = { left: rect.left + window.scrollX, top: rect.top + window.scrollY };
      newItems[index] = { ...newItems[index], showDropdown: true, dropdownPos: pos, focusIndex: -1 };
      return newItems;
    });
    if (el) {
      setTimeout(() => {
        try {
          el.focus();
        } catch (e) {
        }
      }, 50);
    }
  };
  const moveFocus = (index, delta, max) => {
    const newItems = [...items];
    const cur = newItems[index].focusIndex || 0;
    let next = cur + delta;
    if (next < 0) next = max - 1;
    if (next >= max) next = 0;
    newItems[index] = { ...newItems[index], focusIndex: next };
    setItems(newItems);
    setTimeout(() => {
      const el = document.getElementById(`prod-${index}-${next}`);
      if (el && typeof el.scrollIntoView === "function") el.scrollIntoView({ block: "nearest" });
    }, 10);
  };
  const closeDropdown = (index) => {
    const newItems = [...items];
    newItems[index] = { ...newItems[index], showDropdown: false, focusIndex: -1 };
    setItems(newItems);
  };
  const recalcLineTotal = (item) => {
    const qty = parseFloat(item.quantity) || 0;
    const price = parseFloat(item.unit_price) || 0;
    const disc = parseFloat(item.discount) || 0;
    let total = qty * price;
    total = total - total * (disc / 100);
    return total;
  };
  const emptyLineItem = () => ({
    id: Date.now() + Math.random(),
    product_id: "",
    quantity: 1,
    unit_price: 0,
    discount: 0,
    tax_percent: defaultLineTax(),
    line_total: 0,
    description: "",
    stock_quantity: 0,
    max_stock: 0,
    image: "",
    searchQuery: "",
    showDropdown: false
  });
  const addItem = () => {
    closeAllDropdowns();
    setItems((prev) => [...prev.map((it) => ({ ...it, showDropdown: false })), emptyLineItem()]);
  };
  const removeItem = (index) => {
    closeAllDropdowns();
    if (items.length > 1) {
      setItems(items.filter((_, i) => i !== index));
    } else {
      setItems([emptyLineItem()]);
    }
  };
  const clearAllItems = () => {
    if (confirm("Are you sure you want to clear all line items?")) {
      closeAllDropdowns();
      setItems([emptyLineItem()]);
    }
  };
  const totals = useMemo(() => {
    const grossSubtotal = items.reduce((sum, item) => sum + (parseFloat(item.line_total) || 0), 0);
    const discountAmt = parseFloat(formData.discount_amount) || 0;
    const afterDisc = Math.max(0, grossSubtotal - discountAmt);
    const shipping = parseFloat(formData.shipping_charges) || 0;
    if (taxMode === "inclusive") {
      const subtotal2 = items.reduce((sum, item) => {
        const gross = parseFloat(item.line_total) || 0;
        const tp = parseFloat(item.tax_percent);
        const pct = Number.isFinite(tp) ? tp : parseFloat(formData.tax_percentage) || 18;
        if (pct <= 0) return sum + gross;
        return sum + gross / (1 + pct / 100);
      }, 0);
      const afterDiscSubtotal = Math.max(0, subtotal2 - discountAmt);
      let taxAmt2 = Math.max(0, grossSubtotal - subtotal2);
      if (subtotal2 > 0 && afterDiscSubtotal < subtotal2) {
        taxAmt2 *= afterDiscSubtotal / subtotal2;
      }
      const grandTotal2 = afterDiscSubtotal + taxAmt2 + shipping;
      return { subtotal: subtotal2, taxAmt: taxAmt2, grandTotal: grandTotal2 };
    }
    const subtotal = grossSubtotal;
    const taxAmt = items.reduce((sum, item) => {
      const base = parseFloat(item.line_total) || 0;
      const tp = parseFloat(item.tax_percent);
      const pct = Number.isFinite(tp) ? tp : parseFloat(formData.tax_percentage) || 18;
      return sum + base * (pct / 100);
    }, 0);
    const grandTotal = afterDisc + taxAmt + shipping;
    return { subtotal, taxAmt, grandTotal };
  }, [items, formData.discount_amount, formData.tax_percentage, formData.shipping_charges, taxMode]);
  const submitOrder = (e, status) => {
    e.preventDefault();
    setFormData((prev) => ({ ...prev, status }));
    setTimeout(() => {
      const formElement = e.target.closest("form");
      if (formElement) {
        formElement.submit();
      }
    }, 100);
  };
  const formatCurrency = (val) => new Intl.NumberFormat("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val);
  const displayCurrencies = (() => {
    const raw = formData.currencies && formData.currencies.length ? formData.currencies : [formData.primaryCurrency || "TZS"];
    const primary = formData.primaryCurrency || raw[0] || "TZS";
    const ordered = raw.includes(primary) ? [primary] : [primary];
    raw.forEach((code) => {
      if (code !== primary && !ordered.includes(code)) {
        ordered.push(code);
      }
    });
    return ordered;
  })();
  const primaryCurrency = formData.primaryCurrency || displayCurrencies[0] || "TZS";
  const convertAmount = (amount, fromCode, toCode) => {
    const from = String(fromCode || "TZS").toUpperCase();
    const to = String(toCode || "TZS").toUpperCase();
    if (from === to) {
      return amount;
    }
    const rates = formData.exchange_rates || { TZS: "1.0000" };
    const toTzs = (value, code) => {
      if (code === "TZS") return value;
      const rate = parseFloat(rates[code]) || 0;
      return rate > 0 ? value * rate : value;
    };
    const fromTzs = (tzsValue, code) => {
      if (code === "TZS") return tzsValue;
      const rate = parseFloat(rates[code]) || 0;
      return rate > 0 ? tzsValue / rate : tzsValue;
    };
    return fromTzs(toTzs(amount, from), to);
  };
  const moneyLabel = (amount) => displayCurrencies.map((code) => `${code} ${formatCurrency(convertAmount(amount, primaryCurrency, code))}`).join(" · ");
  const nonTzsCurrencies = displayCurrencies.filter((code) => code !== "TZS");
  const isTruckQuote = formData.order_type === "truck";
  const showCurrencyPicker = isRoadmaster && isTruckQuote;
  return /* @__PURE__ */ React.createElement("div", { className: "quote-page" }, /* @__PURE__ */ React.createElement("style", null, `/* Create Quotation Clean Layout */
.quote-page {
    width: 100%;
    max-width: 1000px;
    margin-left: 120px;
    margin-right: auto;
    padding: 32px;
    background: #f8fafc;
}
.quote-layout, .quote-left { min-width:0; max-width:100% }
.quote-card { max-width:100%; box-sizing:border-box }
.quote-table-wrap { overflow: visible }
.quote-header { display:flex; justify-content:space-between; align-items:center; gap:18px; margin-bottom:32px }
.quote-title h1 { font-size:28px; font-weight:800; color:#1e293b; margin:0 }
.quote-title p { color:#64748b; font-size:14px; margin-top:6px }
.quote-back-link { color:#94a3b8; font-weight:500; display:inline-flex; align-items:center; gap:8px; text-decoration:none; white-space:nowrap }
.quote-back-link:hover { color:#475569; text-decoration:none }
.quote-actions { display:flex; gap:12px; align-items:center }
.quote-layout { display:flex; flex-direction:column; gap:24px; align-items:stretch }
.quote-left { min-width:0; display:flex; flex-direction:column; gap:20px }
.quote-top-grid { display:grid; grid-template-columns: 1fr; gap:20px }
.quote-card { background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.05); padding:24px }
.quote-card-header { display:flex; align-items:center; gap:10px; margin-bottom:20px; padding-bottom:16px; border-bottom:1px solid #f1f5f9; font-size:18px; font-weight:700; color:#0f172a }
.quote-card-header i { color:#2563eb }
.quote-field { display:block; font-size:14px; font-weight:500; color:#1e293b; margin-bottom:8px }
.quote-input, .quote-select, .quote-textarea { width:100%; height:46px; border:1px solid #e2e8f0; border-radius:10px; padding:0 16px; font-size:14px; color:#1e293b; background:#ffffff; }
.quote-select {
    appearance:none; -webkit-appearance:none; -moz-appearance:none;
    padding-right:2.5rem;
    border-radius:10px !important;
    background-image:url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http://www.w3.org/2000/svg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat:no-repeat;
    background-position:right 12px center;
    background-size:1.25rem;
}
.quote-textarea { min-height:90px; padding:12px; resize:vertical }
.quote-input:focus, .quote-select:focus, .quote-textarea:focus { border-color:#3b82f6; box-shadow:0 0 0 4px rgba(59,130,246,0.1) }
.quote-summary { position:static; width:calc(100% + 180px); max-width:none; margin-right:-180px; display:block }
.quote-summary .quote-card { width:100%; max-width:none; padding:18px }
.quote-summary-heading { display:flex; align-items:center; gap:8px; margin-bottom:10px; font-size:14px; font-weight:700; color:#0f172a }
.quote-form-row { display:grid; grid-template-columns:220px 1fr; align-items:start; gap:24px; margin-bottom:20px }
.quote-form-row:last-child { margin-bottom:0 }
.quote-form-label { font-size:14px; font-weight:500; color:#1e293b; padding-top:12px }
.quote-form-label.required::after { content:' *'; color:#ef4444; }
.quote-static-box { min-height:46px; display:flex; align-items:center; padding:0 16px; border:1px solid #e2e8f0; border-radius:10px; background:#fff; font-size:14px; color:#475569 }
.quote-inline-actions { display:flex; align-items:center; gap:12px; flex-wrap:wrap }
.quote-inline-link { color:#2563eb; font-size:13px; font-weight:700; text-decoration:none }
.quote-inline-link:hover { text-decoration:none; color:#1d4ed8 }
.summary-row { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid #f1f5f9; font-size:12px; color:#475569 }
.summary-row strong { color:#0f172a }
.summary-total { display:flex; justify-content:space-between; align-items:center; padding:12px 0; margin-top:4px; border-top:1px solid #e2e8f0 }
.summary-total span:first-child { font-size:12px; font-weight:800; text-transform:uppercase; color:#0f172a }
.summary-total span:last-child { font-size:18px; font-weight:900; color:#2563eb }
.summary-input { width:78px; height:32px; border:1px solid #e2e8f0; border-radius:8px; padding:0 8px; text-align:right; font-size:12px }
.quote-table-wrap { width:100%; max-width:100%; overflow-x:visible; overflow-y:visible; -ms-overflow-style:none; scrollbar-width:none }
.quote-table-wrap::-webkit-scrollbar { display:none; width:0; height:0 }
 .quote-table { width:100%; min-width:0; border-collapse:separate; border-spacing:0; table-layout:fixed }
.quote-table th { background:#f8fafc; color:#64748b; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.03em; padding:14px 10px; border-bottom:1px solid #e2e8f0; text-align:left; white-space:normal; line-height:1.2 }
.quote-table td { padding:14px 10px; border-bottom:1px solid #eef2f7; vertical-align:middle; overflow:hidden }
.quote-table .col-num { width:32px }
.quote-table .col-image { width:58px }
.quote-table .col-item { width:17% }
.quote-table .col-desc { width:18% }
.quote-table .col-qty { width:132px }
.quote-table td.col-qty { overflow:visible }
.quote-table .col-price { width:11% }
.quote-table .col-disc { width:58px }
.quote-table .col-tax { width:64px }
.quote-table .col-total { width:11% }
.quote-table .col-action { width:44px }
.li-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; gap:12px; flex-wrap:wrap; padding-bottom:16px; border-bottom:1px solid #f1f5f9 }
.line-items-card { width:calc(100% + 180px); max-width:none; margin-right:-180px }
.li-header-title { font-weight:800; font-size:16px; color:#0f172a; display:flex; align-items:center; gap:8px }
.li-header-actions { display:flex; gap:10px; flex-wrap:wrap }
.li-btn-catalogue { display:inline-flex; align-items:center; gap:8px; padding:9px 14px; font-size:13px; font-weight:700; color:#334155; background:#fff; border:1px solid #cbd5e1; border-radius:10px; text-decoration:none }
.li-btn-catalogue:hover { background:#f8fafc; border-color:#94a3b8 }
.li-btn-manual { display:inline-flex; align-items:center; gap:8px; padding:9px 14px; font-size:13px; font-weight:700; color:#fff; background:#3b82f6; border:1px solid #3b82f6; border-radius:10px; cursor:pointer; box-shadow:0 4px 12px rgba(59,130,246,0.2) }
.li-btn-manual:hover { background:#2563eb }
.li-item-cell { min-width:0; max-width:100% }
.li-item-card { display:block; min-width:0; max-width:100% }
.li-col-image { text-align:center; vertical-align:middle; padding:10px 6px !important }
.li-item-thumb { width:44px; height:44px; border-radius:8px; background:#f1f5f9; border:1px solid #e2e8f0; display:inline-flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0 }
.li-item-thumb img { width:100%; height:100%; object-fit:contain }
.li-item-meta { min-width:0; flex:1 }
.li-item-name { font-weight:700; font-size:13px; color:#0f172a; line-height:1.3; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:100% }
.li-item-code { display:inline-block; margin-top:4px; padding:2px 8px; font-size:11px; font-weight:600; color:#64748b; background:#f1f5f9; border-radius:6px }
.li-item-change { font-size:11px; color:#2563eb; font-weight:700; cursor:pointer; margin-top:4px; display:inline-block }
.li-item-change:hover { text-decoration:underline }
.li-search-input { width:100%; max-width:100%; min-width:0; height:38px; border:1px solid #cbd5e1; border-radius:8px; padding:0 10px; font-size:12px; box-sizing:border-box }
.li-desc-input { width:100%; max-width:100%; min-width:0; height:38px; border:1px solid #e2e8f0; border-radius:8px; padding:0 10px; font-size:12px; color:#334155; background:#fafafa; box-sizing:border-box }
.li-qty-stepper { display:inline-flex; align-items:center; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; background:#fff; max-width:100% }
.li-qty-stepper button { width:30px; height:38px; border:0; background:#f8fafc; color:#475569; font-size:14px; font-weight:700; cursor:pointer; line-height:1; flex-shrink:0 }
.li-qty-stepper button:hover { background:#e2e8f0 }
.li-qty-stepper input { width:72px; min-width:72px; height:38px; border:0; border-left:1px solid #e2e8f0; border-right:1px solid #e2e8f0; text-align:center; font-size:13px; font-weight:700; color:#0f172a; padding:0 4px; box-sizing:border-box }
.li-unit-price { font-weight:800; font-size:13px; color:#0f172a; white-space:nowrap }
.li-price-input { width:100%; max-width:100%; min-width:0; height:34px; border:1px solid #e2e8f0; border-radius:8px; padding:0 8px; font-size:12px; font-weight:700; text-align:right; box-sizing:border-box }
.li-disc-input { width:100%; max-width:52px; height:34px; border:1px solid #e2e8f0; border-radius:8px; padding:0 4px; font-size:12px; text-align:center; box-sizing:border-box }
.li-tax-select { width:100%; max-width:58px; height:34px; border:1px solid #e2e8f0; border-radius:8px; padding:0 4px; font-size:11px; font-weight:600; color:#0f172a; background:#fff; box-sizing:border-box }
.li-line-total { font-weight:800; font-size:13px; color:#0f172a; text-align:right; white-space:nowrap; overflow:hidden; text-overflow:ellipsis }
.li-action-btn { width:34px; height:34px; border:1px solid #fecaca; background:#fff; color:#ef4444; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center }
.li-action-btn:hover { background:#fef2f2 }
.li-footer { display:flex; gap:12px; margin-top:16px; flex-wrap:wrap }
.li-btn-add-row { display:inline-flex; align-items:center; gap:8px; padding:9px 14px; font-size:13px; font-weight:700; color:#334155; background:#fff; border:1px solid #cbd5e1; border-radius:10px; cursor:pointer }
.li-btn-add-row:hover { background:#f8fafc }
.li-btn-clear { display:inline-flex; align-items:center; gap:8px; padding:9px 14px; font-size:13px; font-weight:700; color:#ef4444; background:#fff; border:1px solid #fecaca; border-radius:10px; cursor:pointer }
.li-btn-clear:hover { background:#fef2f2 }
.col-num { color:#94a3b8; font-weight:700; font-size:12px; text-align:center }
.col-action { text-align:center; padding:8px 4px !important }
.quote-card:has(.quote-table) { overflow:hidden }
.btn-primary { background:#3b82f6; color:#ffffff; border:1px solid #3b82f6; border-radius:10px; padding:8px 14px; font-size:13px; font-weight:600; box-shadow:0 4px 12px rgba(59,130,246,0.2) }
.btn-primary:hover { background:#2563eb }
.btn-secondary { background:#ffffff; color:#334155; border:1px solid #cbd5e1; border-radius:10px; padding:8px 14px; font-size:13px; font-weight:700 }
.btn-danger { background:#7c3aed; color:#ffffff; border:1px solid #6d28d9; border-radius:10px; padding:8px 14px; font-size:13px; font-weight:700 }
.btn-danger:hover { background:#6d28d9 }
.summary-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; align-items:center; justify-content:flex-end }
.summary-actions .btn-primary,
.summary-actions .btn-secondary,
.summary-actions .btn-danger {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:auto;
    min-width:110px;
    text-decoration:none;
    line-height:1.2;
}
.inv-currency-picker { position:relative; max-width:100% }
.inv-currency-trigger { width:100%; min-height:46px; display:flex; align-items:center; justify-content:space-between; gap:10px; border:1px solid #e2e8f0; border-radius:10px; padding:0 14px; background:#fff; cursor:pointer; text-align:left }
.inv-currency-trigger:hover { border-color:#cbd5e1 }
.inv-currency-picker.is-open .inv-currency-trigger { border-color:#3b82f6; box-shadow:0 0 0 4px rgba(59,130,246,0.1) }
.inv-currency-flag { width:24px; height:16px; object-fit:cover; border-radius:2px; flex-shrink:0 }
.inv-currency-label { min-width:0; flex:1 }
.inv-currency-label .code { font-weight:700; color:#0f172a; margin-right:6px }
.inv-currency-label .name { color:#64748b }
.inv-currency-menu { position:absolute; left:0; right:0; top:calc(100% + 6px); z-index:40; max-height:280px; overflow:auto; background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 30px rgba(15,23,42,0.12); padding:6px }
.inv-currency-option { width:100%; display:flex; align-items:center; gap:10px; border:0; background:transparent; padding:10px 12px; cursor:pointer; text-align:left }
.inv-currency-option:hover, .inv-currency-option.is-checked { background:#eff6ff }
.inv-currency-option .code { font-weight:700; color:#0f172a; min-width:42px }
.inv-currency-option .name { color:#64748b }
.inv-currency-check { width:18px; text-align:center; color:#2563eb; font-weight:700 }
.inv-currency-chips { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px }
.inv-currency-chip { display:inline-flex; align-items:center; gap:8px; padding:8px 10px; border:1px solid #e2e8f0; border-radius:999px; background:#fff; font-size:12px }
.inv-currency-chip.is-primary { border-color:#93c5fd; background:#eff6ff }
.inv-currency-chip button { border:0; background:transparent; color:#64748b; font-size:11px; font-weight:700; cursor:pointer; padding:0 }
.inv-currency-chip button.is-active { color:#2563eb }
.inv-rate-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px }
.inv-rate-row label { display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:6px }
.inv-rate-hint { margin-top:8px; font-size:12px; color:#64748b }
.inv-rate-hint.is-loading { color:#2563eb }
@media (max-width: 1200px) { .quote-page { margin-left: 40px; margin-right: auto } .line-items-card { width:calc(100% + 90px); margin-right:-90px } .quote-summary { width:calc(100% + 90px); margin-right:-90px; position:static; } .quote-summary .quote-card { max-width:none } }
@media (max-width:850px) { .quote-page { padding:16px } .quote-top-grid, .quote-form-grid { grid-template-columns:1fr } .quote-header { flex-direction:column; align-items:flex-start } .quote-form-row { grid-template-columns:1fr; gap:8px; margin-bottom:18px } .quote-form-label { padding-top:0 } }
`), /* @__PURE__ */ React.createElement("form", { method: "POST" }, /* @__PURE__ */ React.createElement("input", { type: "hidden", name: "status", value: formData.status }), /* @__PURE__ */ React.createElement("input", { type: "hidden", name: "subtotal", value: totals.subtotal.toFixed(2) }), /* @__PURE__ */ React.createElement("input", { type: "hidden", name: "tax_amount", value: totals.taxAmt.toFixed(2) }), /* @__PURE__ */ React.createElement("input", { type: "hidden", name: "total_amount", value: totals.grandTotal.toFixed(2) }), /* @__PURE__ */ React.createElement("input", { type: "hidden", name: "order_type", value: formData.order_type }), showCurrencyPicker ? /* @__PURE__ */ React.createElement(React.Fragment, null, /* @__PURE__ */ React.createElement("input", { type: "hidden", name: "currency", value: primaryCurrency }), /* @__PURE__ */ React.createElement("input", { type: "hidden", name: "display_currencies", value: JSON.stringify(displayCurrencies) }), /* @__PURE__ */ React.createElement("input", { type: "hidden", name: "currency_rates", value: JSON.stringify(formData.exchange_rates || { TZS: "1.0000" }) })) : null, /* @__PURE__ */ React.createElement("div", { className: "quote-header" }, /* @__PURE__ */ React.createElement("div", { className: "quote-title" }, /* @__PURE__ */ React.createElement("h1", { className: "text-2xl font-bold text-slate-900 tracking-tight" }, "Create Quotation"), /* @__PURE__ */ React.createElement("p", { className: "text-sm text-slate-500 mt-1" }, "Draft a new quotation and add items from the catalogue or manually.")), /* @__PURE__ */ React.createElement("a", { href: "create.php", className: "quote-back-link" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-arrow-left text-xs" }), " Back to Quotations")), error && /* @__PURE__ */ React.createElement("div", { className: "mb-6 bg-red-50 border-l-4 border-red-500 p-4 rounded text-sm text-red-700 font-medium" }, /* @__PURE__ */ React.createElement("i", { className: "fas fa-exclamation-circle mr-2" }), error), /* @__PURE__ */ React.createElement("div", { className: "quote-layout" }, /* @__PURE__ */ React.createElement("div", { className: "quote-left" }, /* @__PURE__ */ React.createElement("div", { className: "quote-top-grid" }, /* @__PURE__ */ React.createElement("div", { className: "quote-card" }, /* @__PURE__ */ React.createElement("div", { className: "quote-card-header" }, "General Information"), /* @__PURE__ */ React.createElement("div", { className: "quote-form-row" }, /* @__PURE__ */ React.createElement("label", { className: "quote-form-label required" }, "Customer"), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement(
    "select",
    {
      name: "customer_id",
      value: formData.customer_id,
      onChange: (e) => setFormData({ ...formData, customer_id: e.target.value }),
      required: true,
      className: "quote-select"
    },
    /* @__PURE__ */ React.createElement("option", { value: "" }, "Select Customer"),
    customers.map((c) => /* @__PURE__ */ React.createElement("option", { key: c.id, value: c.id }, c.company_name, " ", c.contact_person ? `(${c.contact_person})` : ""))
  ))), /* @__PURE__ */ React.createElement("div", { className: "quote-form-row" }, /* @__PURE__ */ React.createElement("label", { className: "quote-form-label required" }, "Quote date"), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("input", { type: "date", name: "quote_date", value: formData.quote_date, onChange: (e) => setFormData({ ...formData, quote_date: e.target.value }), required: true, className: "quote-input" }))), /* @__PURE__ */ React.createElement("div", { className: "quote-form-row" }, /* @__PURE__ */ React.createElement("label", { className: "quote-form-label" }, "Valid until"), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("input", { type: "date", name: "valid_until", value: formData.valid_until, onChange: (e) => setFormData({ ...formData, valid_until: e.target.value }), className: "quote-input" }))), /* @__PURE__ */ React.createElement("div", { className: "quote-form-row" }, /* @__PURE__ */ React.createElement("label", { className: "quote-form-label" }, "Lead time"), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("input", { type: "number", name: "lead_time", placeholder: "e.g. 10", value: formData.lead_time, onChange: (e) => setFormData({ ...formData, lead_time: e.target.value }), min: "0", className: "quote-input" }))), showCurrencyPicker ? /* @__PURE__ */ React.createElement(React.Fragment, null, /* @__PURE__ */ React.createElement("div", { className: "quote-form-row" }, /* @__PURE__ */ React.createElement("label", { className: "quote-form-label required" }, "Currencies"), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("div", { className: "inv-currency-chips" }, displayCurrencies.map((code) => {
    const meta = currencyOptions[code] || { name: code, flag: "un" };
    const isPrimary = primaryCurrency === code;
    return /* @__PURE__ */ React.createElement("span", { key: code, className: "inv-currency-chip" + (isPrimary ? " is-primary" : "") }, /* @__PURE__ */ React.createElement("img", { src: `${flagBase}${meta.flag}.png`, alt: "", className: "inv-currency-flag" }), /* @__PURE__ */ React.createElement("strong", null, code), /* @__PURE__ */ React.createElement("button", { type: "button", className: isPrimary ? "is-active" : "", onClick: () => setPrimaryCurrency(code), title: "Use as billing currency" }, isPrimary ? "Billing" : "Set billing"));
  })), /* @__PURE__ */ React.createElement("div", { className: "inv-currency-picker" + (currencyMenuOpen ? " is-open" : "") }, /* @__PURE__ */ React.createElement("button", { type: "button", className: "inv-currency-trigger", onClick: (e) => {
    e.stopPropagation();
    setCurrencyMenuOpen((open) => !open);
  } }, /* @__PURE__ */ React.createElement("span", { className: "inv-currency-label" }, /* @__PURE__ */ React.createElement("span", { className: "code" }, "Add / remove currencies"), /* @__PURE__ */ React.createElement("span", { className: "name" }, displayCurrencies.length + " selected"))), currencyMenuOpen ? /* @__PURE__ */ React.createElement("div", { className: "inv-currency-menu", role: "listbox" }, Object.entries(currencyOptions).map(([code, meta]) => {
    const isChecked = displayCurrencies.includes(code);
    return /* @__PURE__ */ React.createElement("button", { key: code, type: "button", className: "inv-currency-option" + (isChecked ? " is-checked" : ""), onClick: () => toggleCurrency(code) }, /* @__PURE__ */ React.createElement("span", { className: "inv-currency-check" }, isChecked ? "✓" : ""), /* @__PURE__ */ React.createElement("img", { src: `${flagBase}${meta.flag}.png`, alt: "", className: "inv-currency-flag" }), /* @__PURE__ */ React.createElement("span", { className: "code" }, code), /* @__PURE__ */ React.createElement("span", { className: "name" }, meta.name));
  })) : null), /* @__PURE__ */ React.createElement("div", { className: "inv-rate-hint" }, "Billing currency: ", /* @__PURE__ */ React.createElement("strong", null, primaryCurrency), ". Amounts are stored in the billing currency and shown in every selected currency on the quote."))), nonTzsCurrencies.length > 0 ? /* @__PURE__ */ React.createElement("div", { className: "quote-form-row" }, /* @__PURE__ */ React.createElement("label", { className: "quote-form-label" }, "Exchange rates"), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("div", { className: "inv-rate-grid" }, nonTzsCurrencies.map((code) => /* @__PURE__ */ React.createElement("div", { className: "inv-rate-row", key: code }, /* @__PURE__ */ React.createElement("label", { htmlFor: `rate-${code}` }, code), /* @__PURE__ */ React.createElement("input", { id: `rate-${code}`, type: "number", step: "0.0001", min: "0", value: formData.exchange_rates?.[code] || "", onChange: (e) => updateExchangeRate(code, e.target.value), className: "quote-input", placeholder: "TZS per 1 unit" }))), /* @__PURE__ */ React.createElement("div", { className: "inv-rate-hint" + (rateLoadingCodes.length ? " is-loading" : "") }, rateLoadingCodes.length ? `Loading BOT rate for ${rateLoadingCodes.join(", ")}…` : rateHint)))) : null) : null, /* @__PURE__ */ React.createElement("input", { type: "hidden", name: "created_by", value: formData.created_by || currentUserId || "" }), /* @__PURE__ */ React.createElement("div", { className: "quote-form-row" }, /* @__PURE__ */ React.createElement("label", { className: "quote-form-label" }, "Customer tools"), /* @__PURE__ */ React.createElement("div", { className: "quote-inline-actions" }, /* @__PURE__ */ React.createElement("a", { href: customerCatalogueUrl, className: "li-btn-catalogue", style: { textDecoration: "none", fontSize: 12, padding: "6px 10px" } }, /* @__PURE__ */ React.createElement("i", { className: "fa-solid fa-table-cells" }), " Customer catalogue"), /* @__PURE__ */ React.createElement("a", { href: "../customers/index.php", className: "quote-inline-link" }, "Manage customers"))))), /* @__PURE__ */ React.createElement("div", { className: "quote-card line-items-card" }, /* @__PURE__ */ React.createElement("div", { className: "li-header" }, /* @__PURE__ */ React.createElement("div", { className: "li-header-title" }, /* @__PURE__ */ React.createElement("i", { className: "fa-solid fa-list-ul", style: { color: "#2563eb" } }), "Line Items"), /* @__PURE__ */ React.createElement("div", { className: "li-header-actions" }, /* @__PURE__ */ React.createElement("a", { href: catalogueUrl, onClick: closeAllDropdowns, className: "li-btn-catalogue" }, /* @__PURE__ */ React.createElement("i", { className: "fa-solid fa-table-cells" }), " Add from Catalogue"), /* @__PURE__ */ React.createElement("button", { type: "button", onClick: (e) => {
    e.stopPropagation();
    addItem();
  }, className: "li-btn-manual" }, /* @__PURE__ */ React.createElement("i", { className: "fa-solid fa-plus" }), " Add Manual Item"))), /* @__PURE__ */ React.createElement("div", { className: "quote-table-wrap" }, /* @__PURE__ */ React.createElement("table", { className: "quote-table" }, /* @__PURE__ */ React.createElement("thead", null, /* @__PURE__ */ React.createElement("tr", null, /* @__PURE__ */ React.createElement("th", { className: "col-num" }, "#"), /* @__PURE__ */ React.createElement("th", { className: "col-image" }, "Image"), /* @__PURE__ */ React.createElement("th", { className: "col-item" }, "Item"), /* @__PURE__ */ React.createElement("th", { className: "col-desc" }, "Description"), /* @__PURE__ */ React.createElement("th", { className: "col-qty" }, "Qty"), /* @__PURE__ */ React.createElement("th", { className: "col-price" }, "Unit Price"), /* @__PURE__ */ React.createElement("th", { className: "col-disc" }, "Disc %"), /* @__PURE__ */ React.createElement("th", { className: "col-tax" }, "Tax %"), /* @__PURE__ */ React.createElement("th", { className: "col-total", style: { textAlign: "right" } }, "Line Total"), /* @__PURE__ */ React.createElement("th", { className: "col-action" }, "Action"))), /* @__PURE__ */ React.createElement("tbody", null, items.map((item, index) => {
    const matchingProducts = item.showDropdown ? products.filter((p) => {
      if (!item.searchQuery) return true;
      const q = item.searchQuery.toLowerCase();
      return p.name.toLowerCase().includes(q) || p.product_code && p.product_code.toLowerCase().includes(q);
    }) : [];
    const currentProductObj = products.find((p) => String(p.id) === String(item.product_id));
    const hasProduct = item.product_id && (currentProductObj || item.searchQuery);
    const displayName = currentProductObj ? currentProductObj.name : item.searchQuery || "";
    const displayCode = currentProductObj ? currentProductObj.product_code || "" : "";
    const taxVal = item.tax_percent !== void 0 && item.tax_percent !== null ? item.tax_percent : defaultLineTax();
    return /* @__PURE__ */ React.createElement("tr", { key: item.id }, /* @__PURE__ */ React.createElement("td", { className: "col-num" }, /* @__PURE__ */ React.createElement("input", { type: "hidden", name: `items[${index}][product_id]`, value: item.product_id }), /* @__PURE__ */ React.createElement("input", { type: "hidden", name: `items[${index}][line_total]`, value: item.line_total }), index + 1), /* @__PURE__ */ React.createElement("td", { className: "li-col-image" }, /* @__PURE__ */ React.createElement("div", { className: "li-item-thumb", title: displayName || "Product" }, item.image ? /* @__PURE__ */ React.createElement("img", { src: item.image, alt: "" }) : /* @__PURE__ */ React.createElement("i", { className: "fa-solid fa-box text-slate-400" }))), /* @__PURE__ */ React.createElement("td", { className: "li-item-cell col-item product-search-cell" }, hasProduct && !item.showDropdown ? /* @__PURE__ */ React.createElement("div", { className: "li-item-card" }, /* @__PURE__ */ React.createElement("div", { className: "li-item-meta" }, /* @__PURE__ */ React.createElement("div", { className: "li-item-name" }, displayName), displayCode ? /* @__PURE__ */ React.createElement("span", { className: "li-item-code" }, displayCode) : null, /* @__PURE__ */ React.createElement("span", { className: "li-item-change", onClick: () => openDropdown(index) }, "Change product"))) : null, /* @__PURE__ */ React.createElement("div", { className: "relative", style: { display: hasProduct && !item.showDropdown ? "none" : "block" } }, /* @__PURE__ */ React.createElement(
      "input",
      {
        id: `item-search-${index}`,
        type: "text",
        placeholder: "Search product...",
        value: item.searchQuery,
        onChange: (e) => handleItemChange(index, "searchQuery", e.target.value),
        onFocus: () => openDropdown(index),
        onKeyDown: (e) => handleInputKey(index, e, matchingProducts),
        className: "li-search-input"
      }
    ), item.showDropdown && /* @__PURE__ */ React.createElement("div", { className: "product-search-dropdown", onMouseDown: (e) => e.preventDefault() }, /* @__PURE__ */ React.createElement("div", { className: "bg-white shadow-2xl rounded-xl py-2 text-sm border border-slate-100 max-h-72 overflow-y-auto custom-scroll", style: { position: "fixed", left: (item.dropdownPos ? item.dropdownPos.left : 0) + "px", top: (item.dropdownPos ? item.dropdownPos.top : 0) + "px", transform: "translateY(calc(-100% - 6px))", width: 320, paddingLeft: 8, paddingRight: 8, zIndex: 9999 } }, matchingProducts.length > 0 ? matchingProducts.map((p, mi) => /* @__PURE__ */ React.createElement(
      "div",
      {
        id: `prod-${index}-${mi}`,
        key: p.id,
        onMouseDown: (e) => e.preventDefault(),
        onClick: (e) => {
          e.preventDefault();
          e.stopPropagation();
          selectProduct(index, p);
        },
        onMouseEnter: () => {
          setItems((prev) => {
            const ni = [...prev];
            ni[index] = { ...ni[index], focusIndex: mi };
            return ni;
          });
        },
        className: "cursor-pointer py-2 px-3.5 flex items-center gap-3 border-b border-slate-100 last:border-0 " + (item.focusIndex === mi ? "bg-blue-50 text-blue-700" : "hover:bg-blue-50 hover:text-blue-700")
      },
      /* @__PURE__ */ React.createElement("div", { style: { width: 56, height: 48, background: "#f1f5f9", borderRadius: 8, display: "flex", alignItems: "center", justifyContent: "center", overflow: "hidden", flex: "0 0 56px" } }, p.image_url ? /* @__PURE__ */ React.createElement("img", { src: p.image_url, alt: "", style: { width: "100%", height: "100%", objectFit: "contain" } }) : /* @__PURE__ */ React.createElement("i", { className: "fa-solid fa-box text-sm text-slate-400" })),
      /* @__PURE__ */ React.createElement("div", { style: { flex: 1, minWidth: 0 } }, /* @__PURE__ */ React.createElement("div", { className: "font-semibold truncate text-sm text-slate-800" }, p.name), /* @__PURE__ */ React.createElement("div", { className: "flex justify-between text-[11px] text-slate-500 mt-0.5" }, /* @__PURE__ */ React.createElement("span", { className: "truncate" }, p.product_code), /* @__PURE__ */ React.createElement("span", { className: "font-medium text-blue-600" }, "TSh ", formatCurrency(p.selling_price))))
    )) : /* @__PURE__ */ React.createElement("div", { className: "py-2 px-3.5 text-slate-400 text-xs italic" }, "No matching products"))))), /* @__PURE__ */ React.createElement("td", { className: "col-desc" }, /* @__PURE__ */ React.createElement("input", { type: "text", name: `items[${index}][description]`, value: item.description, onChange: (e) => handleItemChange(index, "description", e.target.value), className: "li-desc-input", placeholder: "Description" })), /* @__PURE__ */ React.createElement("td", { className: "col-qty" }, /* @__PURE__ */ React.createElement("div", { className: "li-qty-stepper" }, /* @__PURE__ */ React.createElement("button", { type: "button", onClick: () => handleItemChange(index, "quantity", Math.max(1, (parseFloat(item.quantity) || 1) - 1)) }, "-"), /* @__PURE__ */ React.createElement("input", { type: "number", name: `items[${index}][quantity]`, min: "1", value: item.quantity, onChange: (e) => handleItemChange(index, "quantity", Math.max(1, parseFloat(e.target.value) || 1)) }), /* @__PURE__ */ React.createElement("button", { type: "button", onClick: () => handleItemChange(index, "quantity", (parseFloat(item.quantity) || 1) + 1) }, "+"))), /* @__PURE__ */ React.createElement("td", { className: "col-price" }, /* @__PURE__ */ React.createElement("input", { type: "number", step: "0.01", name: `items[${index}][unit_price]`, value: item.unit_price, onChange: (e) => handleItemChange(index, "unit_price", Math.max(0, parseFloat(e.target.value) || 0)), className: "li-price-input" })), /* @__PURE__ */ React.createElement("td", { className: "col-disc" }, /* @__PURE__ */ React.createElement("input", { type: "number", step: "0.01", min: "0", max: "100", name: `items[${index}][discount]`, value: item.discount, onChange: (e) => handleItemChange(index, "discount", Math.max(0, Math.min(100, parseFloat(e.target.value) || 0))), className: "li-disc-input" })), /* @__PURE__ */ React.createElement("td", { className: "col-tax" }, /* @__PURE__ */ React.createElement("select", { className: "li-tax-select", value: taxVal, onChange: (e) => handleItemChange(index, "tax_percent", parseFloat(e.target.value) || 0) }, [0, 10, 18, 20].map((t) => /* @__PURE__ */ React.createElement("option", { key: t, value: t }, t, "%")))), /* @__PURE__ */ React.createElement("td", { className: "li-line-total col-total" }, formatCurrency(item.line_total)), /* @__PURE__ */ React.createElement("td", { className: "col-action" }, /* @__PURE__ */ React.createElement("button", { type: "button", className: "li-action-btn", title: "Remove row", onClick: (e) => {
      e.stopPropagation();
      removeItem(index);
    } }, /* @__PURE__ */ React.createElement("i", { className: "fa-regular fa-trash-can" }))));
  })))), /* @__PURE__ */ React.createElement("div", { className: "li-footer" }, /* @__PURE__ */ React.createElement("button", { type: "button", onClick: (e) => {
    e.stopPropagation();
    addItem();
  }, className: "li-btn-add-row" }, /* @__PURE__ */ React.createElement("i", { className: "fa-solid fa-plus" }), " Add Row"), /* @__PURE__ */ React.createElement("button", { type: "button", onClick: (e) => {
    e.stopPropagation();
    clearAllItems();
  }, className: "li-btn-clear" }, /* @__PURE__ */ React.createElement("i", { className: "fa-regular fa-trash-can" }), " Clear All")))), /* @__PURE__ */ React.createElement("aside", { className: "quote-summary" }, /* @__PURE__ */ React.createElement("div", { className: "quote-card" }, /* @__PURE__ */ React.createElement("div", { className: "quote-summary-heading" }, /* @__PURE__ */ React.createElement("i", { className: "fa-solid fa-calculator", style: { color: "#2563eb" } }), /* @__PURE__ */ React.createElement("strong", null, "Order Summary")), /* @__PURE__ */ React.createElement("div", null, /* @__PURE__ */ React.createElement("div", { className: "summary-row" }, /* @__PURE__ */ React.createElement("span", null, taxMode === "inclusive" ? "Subtotal (excl. tax)" : "Subtotal"), /* @__PURE__ */ React.createElement("strong", null, showCurrencyPicker ? moneyLabel(totals.subtotal) : "TZS " + formatCurrency(totals.subtotal))), /* @__PURE__ */ React.createElement("div", { className: "summary-row" }, /* @__PURE__ */ React.createElement("span", null, "Discount Amount (-)"), /* @__PURE__ */ React.createElement("input", { type: "number", step: "0.01", name: "discount_amount", value: formData.discount_amount, onChange: (e) => setFormData({ ...formData, discount_amount: Math.max(0, parseFloat(e.target.value) || 0) }), className: "summary-input" })), /* @__PURE__ */ React.createElement("div", { className: "summary-row" }, /* @__PURE__ */ React.createElement("span", null, taxMode === "inclusive" ? "Tax included (%)" : "Tax (%)"), /* @__PURE__ */ React.createElement("div", { style: { display: "flex", gap: 8, alignItems: "center" } }, /* @__PURE__ */ React.createElement("input", { type: "number", step: "0.01", name: "tax_percentage", value: formData.tax_percentage, onChange: (e) => setFormData({ ...formData, tax_percentage: Math.max(0, Math.min(100, parseFloat(e.target.value) || 0)) }), className: "summary-input" }), /* @__PURE__ */ React.createElement("span", { style: { minWidth: 80, textAlign: "right" } }, showCurrencyPicker ? moneyLabel(totals.taxAmt) : "TZS " + formatCurrency(totals.taxAmt)))), /* @__PURE__ */ React.createElement("div", { className: "summary-row" }, /* @__PURE__ */ React.createElement("span", null, "Shipping Charges (+)"), /* @__PURE__ */ React.createElement("input", { type: "number", step: "0.01", name: "shipping_charges", value: formData.shipping_charges, onChange: (e) => setFormData({ ...formData, shipping_charges: Math.max(0, parseFloat(e.target.value) || 0) }), className: "summary-input" })), /* @__PURE__ */ React.createElement("div", { className: "summary-total" }, /* @__PURE__ */ React.createElement("span", null, "Grand Total"), /* @__PURE__ */ React.createElement("span", null, showCurrencyPicker ? moneyLabel(totals.grandTotal) : "TZS " + formatCurrency(totals.grandTotal))), /* @__PURE__ */ React.createElement("div", { className: "summary-actions" }, /* @__PURE__ */ React.createElement("button", { type: "button", onClick: (e) => submitOrder(e, "quotation"), className: "btn-primary" }, "Create Quotation"), /* @__PURE__ */ React.createElement("button", { type: "button", onClick: (e) => submitOrder(e, "draft"), className: "btn-secondary" }, "Save Draft"), /* @__PURE__ */ React.createElement("a", { href: "index.php", className: "btn-danger" }, "Cancel"))))))));
}
(function() {
  const rootEl = document.getElementById("react-root");
  window.addEventListener("error", function(ev) {
    try {
      const msg = ev && ev.message ? ev.message : String(ev);
      if (rootEl) rootEl.innerText = "JS ERROR: " + msg;
    } catch (e) {
    }
  });
  window.addEventListener("unhandledrejection", function(ev) {
    try {
      const reason = ev && ev.reason ? ev.reason.message || String(ev.reason) : String(ev);
      if (rootEl) rootEl.innerText = "Unhandled Rejection: " + reason;
    } catch (e) {
    }
  });
  try {
    const root = ReactDOM.createRoot(rootEl);
    root.render(
      /* @__PURE__ */ React.createElement(ErrorBoundary, null, /* @__PURE__ */ React.createElement(CreateOrderApp, null))
    );
  } catch (err) {
    console && console.error && console.error(err);
    if (rootEl) rootEl.innerText = "JS ERROR: " + (err && err.message ? err.message : String(err));
  }
})();
</script>
    </div><!-- /.flex-grow-1 -->
</div><!-- /.layout-main-wrapper -->
</body>

</html>
<?php $GLOBALS['_erp_layout_closed'] = true; ?>
