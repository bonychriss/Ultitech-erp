<?php
// modules/sales/invoices/index.php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../functions.php';

requireLogin();

$salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
$invoices = array();
$soHasOrderType = false;
$productsHasItemType = false;
$soHasOrderNumber = false;
$soCols = array();

try {
    $soCols = $salesDb->query('SHOW COLUMNS FROM sales_orders')->fetchAll(PDO::FETCH_COLUMN) ?: array();
    $soHasOrderType = in_array('order_type', $soCols, true);
    $soHasOrderNumber = in_array('order_number', $soCols, true);
    $prodCols = $salesDb->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN) ?: array();
    $productsHasItemType = in_array('item_type', $prodCols, true);
} catch (Throwable $e) {
    error_log('sales invoices index schema probe: ' . $e->getMessage());
    $soHasOrderType = false;
    $productsHasItemType = false;
    $soHasOrderNumber = false;
}

$orderTypeSelect = $soHasOrderType ? 'so.order_type' : 'NULL AS order_type';
if ($soHasOrderNumber) {
    $orderNumberSelect = 'so.order_number';
} elseif (in_array('formatted_number', $soCols, true)) {
    $orderNumberSelect = 'so.formatted_number AS order_number';
} else {
    $orderNumberSelect = "CONCAT('SO-', so.id) AS order_number";
}
$vehicleLineSelect = $productsHasItemType
    ? "(SELECT COUNT(*) FROM sales_order_items soi INNER JOIN products p ON p.id = soi.product_id WHERE soi.order_id = so.id AND LOWER(TRIM(COALESCE(p.item_type, ''))) IN ('vehicle', 'truck')) AS _rm_vehicle_lines"
    : '0 AS _rm_vehicle_lines';

$sql = "SELECT i.*, c.company_name AS customer_name, {$orderNumberSelect}, {$orderTypeSelect}, u.full_name AS salesperson,
        {$vehicleLineSelect}
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        LEFT JOIN sales_orders so ON i.order_id = so.id
        LEFT JOIN users u ON i.created_by = u.id";
$scope = function_exists('salesCompanyScopeSql') ? salesCompanyScopeSql('invoices', 'i') : array('', array());
if (!empty($scope[0])) {
    $sql .= ' WHERE 1=1' . $scope[0];
}
$sql .= ' ORDER BY i.created_at DESC';

try {
    if (function_exists('sales_connection_has_table') && !sales_connection_has_table($salesDb, 'invoices')) {
        $invoices = array();
    } else {
        $params = isset($scope[1]) && is_array($scope[1]) ? $scope[1] : array();
        if (!empty($params)) {
            $stmtInvoices = $salesDb->prepare($sql);
            $stmtInvoices->execute($params);
            $invoices = $stmtInvoices->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $invoices = $salesDb->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {
    error_log('sales invoices index query: ' . $e->getMessage());
    $invoices = array();
}
foreach ($invoices as &$invRow) {
    $vehicleLines = (int) ($invRow['_rm_vehicle_lines'] ?? 0);
    unset($invRow['_rm_vehicle_lines']);
    $ot = isset($invRow['order_type']) ? trim((string) $invRow['order_type']) : '';
    $storedTruck = (strtolower($ot) === 'truck');
    $invRow['order_type'] = ($storedTruck || $vehicleLines > 0) ? 'truck' : 'spare';
}
unset($invRow);

$isRoadmaster = isRoadmaster();
$showOrderTypeColumn = $isRoadmaster && $soHasOrderType;

$invDefaultCurrency = 'TZS';
$settingsDb = $salesDb;
try {
    if (function_exists('sales_connection_has_table') && !sales_connection_has_table($settingsDb, 'sales_settings')) {
        $settingsDb = $pdo;
    }
    if (function_exists('currentCompanyId')) {
        $cidInv = (int) currentCompanyId();
        if ($cidInv > 0) {
            $stInv = $settingsDb->prepare('SELECT default_currency FROM sales_settings WHERE company_id = ? LIMIT 1');
            $stInv->execute([$cidInv]);
            $rowInv = $stInv->fetch(PDO::FETCH_ASSOC);
            if (!empty($rowInv['default_currency'])) {
                $invDefaultCurrency = (string) $rowInv['default_currency'];
            }
        }
    }
    if ($invDefaultCurrency === 'TZS') {
        $rowInv = $settingsDb->query('SELECT default_currency FROM sales_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!empty($rowInv['default_currency'])) {
            $invDefaultCurrency = (string) $rowInv['default_currency'];
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
    <title>Invoices | Sales Module</title>
    <script>
        tailwind.config = { corePlugins: { preflight: false } };
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <?php if (function_exists('app_url')): ?>
    <link href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>" rel="stylesheet">
    <?php endif; ?>

    <script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone@7.23.9/babel.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        /* Sales dashboard canvas; sidebar uses Appearance (sidebar_themes.css) */
        html:has(body.page-sales-invoices),
        body.page-sales-invoices {
            background-color: #f8f9fc !important;
        }
        body.page-sales-invoices {
            font-family: 'Outfit', system-ui, -apple-system, sans-serif;
            color: #374151;
            min-height: 100vh;
        }
        body.page-sales-invoices .layout-main-wrapper {
            background-color: #f8f9fc;
            width: 100%;
            max-width: 100%;
        }
        body.page-sales-invoices .layout-main-wrapper > .flex-grow-1 {
            flex: 1 1 0%;
            min-width: 0;
            max-width: none;
            width: 100%;
            background-color: #f8f9fc;
        }
        body.page-sales-invoices header.employee-header {
            background: #f8f9fc !important;
            box-shadow: none !important;
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
        }
        body.page-sales-invoices .employee-header .header-content {
            background: transparent;
        }
        body.page-sales-invoices #native-sidebar {
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
        html body .main-content.sales-invoices-shell {
            padding-left: 1rem !important;
            padding-right: 1rem !important;
            box-sizing: border-box !important;
            flex: 1 1 auto;
            width: 100% !important;
            max-width: none !important;
            min-width: 0;
            background-color: #f8f9fc;
        }
        body.page-sales-invoices #react-root {
            width: 100%;
            min-width: 0;
        }
        @media (min-width: 993px) {
            html body .main-content.sales-invoices-shell {
                padding-left: 1.75rem !important;
                padding-right: 1.5rem !important;
            }
        }
        .animate-fade-in { animation: fadeIn 0.2s ease-out forwards; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        /* Bootstrap (sidebar) can zero out button radius; force pill for New */
        button.invoices-new-btn,
        a.invoices-new-btn {
            border-radius: 9999px !important;
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
    </style>
</head>
<body class="page-sales-invoices">
    <?php include '../../../includes/header_employee.php'; ?>

    <div class="main-content sales-invoices-shell" id="react-root"></div>

    <script>
        window.APP_DATA = {
            invoices: <?= sales_json_script($invoices) ?>,
            currentUserId: <?= (int) ($_SESSION['user_id'] ?? 0) ?>,
            isRoadmaster: <?= sales_json_script($isRoadmaster) ?>,
            showOrderTypeColumn: <?= sales_json_script($showOrderTypeColumn) ?>,
            invoicesShellLayout: <?= sales_json_script(function_exists('invoicesListUsesRoadmasterShell') && invoicesListUsesRoadmasterShell()) ?>,
            defaultCurrency: <?= sales_json_script($invDefaultCurrency) ?>
        };

    </script>

    <script type="text/babel">
        const { useState, useMemo, useEffect } = React;
        const { invoices: initialInvoices, currentUserId, isRoadmaster, showOrderTypeColumn, invoicesShellLayout, defaultCurrency: appInvCurrency } = window.APP_DATA;
        const useRmShell = invoicesShellLayout === true;
        const defaultCurrency = appInvCurrency || 'TZS';

        const VIEW_MODES = ['list', 'cards', 'board'];
        const PAGE_SIZE = 25;

        const BOARD_COLUMNS = [
            { key: 'draft', label: 'Draft' },
            { key: 'sent', label: 'Sent' },
            { key: 'overdue', label: 'Overdue' },
            { key: 'paid', label: 'Paid' },
            { key: 'cancelled', label: 'Cancelled' },
            { key: 'other', label: 'Other' }
        ];

        function normalizeBoardColumn(status) {
            const s = (status || '').toLowerCase().trim();
            if (s === 'draft') return 'draft';
            if (s === 'sent' || s === 'invoiced') return 'sent';
            if (s === 'overdue') return 'overdue';
            if (s === 'paid') return 'paid';
            if (s === 'cancelled' || s === 'canceled') return 'cancelled';
            return 'other';
        }

        function getStatusConfig(status) {
            const st = (status || '').toLowerCase().trim();
            const configs = {
                paid: { color: 'bg-emerald-50 text-emerald-600 border-emerald-100', label: 'Paid' },
                sent: { color: 'bg-blue-50 text-blue-600 border-blue-100', label: 'Sent' },
                invoiced: { color: 'bg-blue-50 text-blue-600 border-blue-100', label: 'Sent' },
                overdue: { color: 'bg-rose-50 text-rose-600 border-rose-100', label: 'Overdue' },
                cancelled: { color: 'bg-slate-50 text-slate-500 border-slate-100', label: 'Cancelled' },
                draft: { color: 'bg-amber-50 text-amber-600 border-amber-100', label: 'Draft' }
            };
            return configs[st] || { color: 'bg-slate-50 text-slate-600 border-slate-100', label: status || 'â€”' };
        }

        function statusBadgeClass(config) {
            if (config.color.includes('emerald')) return 'bg-[#28A745] text-white';
            if (config.color.includes('blue')) return 'bg-[#17A2B8] text-white';
            if (config.color.includes('rose')) return 'bg-[#DC3545] text-white';
            if (config.color.includes('amber')) return 'bg-[#FFC107] text-gray-900';
            return 'bg-gray-500 text-white';
        }

        function initials(name) {
            if (!name || !String(name).trim()) return '?';
            return String(name).split(/\s+/).map((w) => w[0]).join('').toUpperCase().slice(0, 2);
        }

        const SALESPERSON_AVATAR_PALETTES = [
            'from-violet-100 to-purple-50 text-violet-700 ring-violet-200/60',
            'from-sky-100 to-blue-50 text-sky-800 ring-sky-200/60',
            'from-teal-100 to-cyan-50 text-teal-800 ring-teal-200/60',
            'from-amber-100 to-orange-50 text-amber-800 ring-amber-200/60',
            'from-rose-100 to-pink-50 text-rose-700 ring-rose-200/60',
            'from-emerald-100 to-green-50 text-emerald-800 ring-emerald-200/60',
            'from-indigo-100 to-violet-50 text-indigo-800 ring-indigo-200/60',
            'from-fuchsia-100 to-purple-50 text-fuchsia-800 ring-fuchsia-200/60',
            'from-cyan-100 to-sky-50 text-cyan-800 ring-cyan-200/60',
            'from-lime-100 to-emerald-50 text-lime-800 ring-lime-200/60',
        ];

        function salespersonAvatarClass(name) {
            const s = String(name ?? '').trim() || '?';
            let h = 0;
            for (let i = 0; i < s.length; i++) {
                h = (Math.imul(31, h) + s.charCodeAt(i)) | 0;
            }
            const idx = Math.abs(h) % SALESPERSON_AVATAR_PALETTES.length;
            return 'bg-gradient-to-br ' + SALESPERSON_AVATAR_PALETTES[idx] + ' ring-1';
        }

        function SalespersonAvatar({ name, size }) {
            const dims = size === 'sm' ? 'h-7 w-7 text-[10px]' : 'h-8 w-8 text-xs';
            return (
                <span
                    className={
                        'inline-flex shrink-0 items-center justify-center rounded-full font-semibold ' +
                        dims +
                        ' ' +
                        salespersonAvatarClass(name)
                    }
                    title={name || ''}
                    aria-hidden="true"
                >
                    {initials(name)}
                </span>
            );
        }

        function InvoicesApp() {
            const { invoices: initialInvoices, currentUserId, isRoadmaster } = window.APP_DATA;

            const [invoices] = useState(initialInvoices || []);
            const [search, setSearch] = useState('');
            const [statusFilter, setStatusFilter] = useState('');
            const [myInvoicesOnly, setMyInvoicesOnly] = useState(false);
            const [selectedIds, setSelectedIds] = useState(new Set());
            const [openMenuId, setOpenMenuId] = useState(null);
            const [page, setPage] = useState(1);
            const [viewMode, setViewMode] = useState(() => {
                try {
                    const v = localStorage.getItem('sales_invoices_view_mode');
                    if (VIEW_MODES.includes(v)) return v;
                } catch (e) {}
                return 'list';
            });

            useEffect(() => {
                try { localStorage.setItem('sales_invoices_view_mode', viewMode); } catch (e) {}
            }, [viewMode]);

            useEffect(() => { setPage(1); }, [search, statusFilter, myInvoicesOnly, viewMode]);

            useEffect(() => {
                if (openMenuId == null) return;
                const close = (ev) => {
                    if (ev.target && typeof ev.target.closest === 'function' && ev.target.closest('[data-invoice-actions]')) return;
                    setOpenMenuId(null);
                };
                document.addEventListener('click', close);
                return () => document.removeEventListener('click', close);
            }, [openMenuId]);

            const formatCurrency = (amount) =>
                new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount || 0);

            const formatDate = (dateStr) => {
                if (!dateStr) return 'â€”';
                const d = new Date(dateStr);
                return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
            };

            const formatDateTime = (dateStr) => {
                if (!dateStr) return 'â€”';
                const d = new Date(dateStr);
                if (Number.isNaN(d.getTime())) return 'â€”';
                const pad = (n) => String(n).padStart(2, '0');
                return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
            };

            const filteredInvoices = useMemo(() => {
                return invoices.filter((i) => {
                    const mineOk = !myInvoicesOnly || Number(i.created_by) === Number(currentUserId);
                    const matchesSearch =
                        (i.invoice_number || '').toLowerCase().includes(search.toLowerCase()) ||
                        (i.order_number || '').toLowerCase().includes(search.toLowerCase()) ||
                        (i.customer_name || '').toLowerCase().includes(search.toLowerCase()) ||
                        (i.salesperson || '').toLowerCase().includes(search.toLowerCase());
                    const matchesStatus = !statusFilter || (i.status || '').toLowerCase() === statusFilter;
                    return mineOk && matchesSearch && matchesStatus;
                });
            }, [invoices, search, statusFilter, myInvoicesOnly, currentUserId]);

            const pageSize = useRmShell ? 10 : PAGE_SIZE;

            const invoiceStats = useMemo(() => {
                const y = new Date().getFullYear();
                let totalVal = 0;
                let outstanding = 0;
                let paidYtd = 0;
                invoices.forEach((inv) => {
                    totalVal += parseFloat(inv.total_amount) || 0;
                    const bal = parseFloat(inv.balance_due) || 0;
                    if (bal > 0.005) outstanding++;
                    const st = (inv.status || '').toLowerCase();
                    if (st === 'paid') {
                        const d = inv.invoice_date ? new Date(inv.invoice_date) : (inv.created_at ? new Date(inv.created_at) : null);
                        if (d && !Number.isNaN(d.getTime()) && d.getFullYear() === y) paidYtd++;
                    }
                });
                return { total: invoices.length, totalVal, outstanding, paidYtd };
            }, [invoices]);

            const pageCount = Math.max(1, Math.ceil(filteredInvoices.length / pageSize));
            const safePage = Math.min(page, pageCount);
            const pagedInvoices = useMemo(() => {
                const start = (safePage - 1) * pageSize;
                return filteredInvoices.slice(start, start + pageSize);
            }, [filteredInvoices, safePage, pageSize]);

            const byBoard = useMemo(() => {
                const m = { draft: [], sent: [], overdue: [], paid: [], cancelled: [], other: [] };
                filteredInvoices.forEach((inv) => {
                    m[normalizeBoardColumn(inv.status)].push(inv);
                });
                return m;
            }, [filteredInvoices]);

            const handleSelectAll = (e) => {
                if (e.target.checked) {
                    setSelectedIds(new Set(pagedInvoices.map((i) => i.id)));
                } else {
                    setSelectedIds(new Set());
                }
            };

            const handleNewClick = () => {
                        // If the page was opened with module=sales, skip the type chooser and go straight to create
                        try {
                            const params = new URLSearchParams(window.location.search || '');
                            if (params.get('module') === 'sales') {
                                window.location.href = "create.php";
                                return;
                            }
                        } catch (e) {}

                        if (!isRoadmaster && !useRmShell) {
                            window.location.href = "create.php";
                            return;
                        }

                        Swal.fire({
                            title: 'Create New Invoice',
                            text: 'Select the type of invoice you want to create:',
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonText: '<i class="fas fa-truck"></i> Truck Invoice',
                            cancelButtonText: '<i class="fas fa-cogs"></i> Spare Invoice',
                            confirmButtonColor: '#0D2A4A',
                            cancelButtonColor: '#6d28d9',
                            customClass: {
                                confirmButton: 'px-4 py-2 rounded-md font-semibold mx-2',
                                cancelButton: 'px-4 py-2 rounded-md font-semibold mx-2'
                            }
                        }).then((result) => {
                            if (result.isConfirmed) {
                                window.location.href = "create.php?type=truck";
                            } else if (result.dismiss === Swal.DismissReason.cancel) {
                                window.location.href = "create.php?type=spare";
                            }
                        });
            };

            const toggleSelection = (id, e) => {

                e.stopPropagation();
                const next = new Set(selectedIds);
                if (next.has(id)) next.delete(id);
                else next.add(id);
                setSelectedIds(next);
            };

            const rangeStart = filteredInvoices.length === 0 ? 0 : (safePage - 1) * pageSize + 1;
            const rangeEnd = Math.min(safePage * pageSize, filteredInvoices.length);

            const viewBtn = (mode, icon, title) => (
                <button
                    type="button"
                    title={title}
                    aria-pressed={viewMode === mode}
                    onClick={() => setViewMode(mode)}
                    className={
                        'px-2.5 py-1.5 rounded text-sm transition-colors ' +
                        (viewMode === mode
                            ? 'bg-sky-100 text-sky-800 border border-sky-200 shadow-sm'
                            : 'text-gray-500 hover:text-gray-800 hover:bg-gray-100 border border-transparent')
                    }
                >
                    <i className={'fas ' + icon}></i>
                </button>
            );

            function RowCells({ inv }) {
                const config = getStatusConfig(inv.status);
                const chkCls = useRmShell ? 'q-checkbox' : 'w-4 h-4 rounded border-gray-300 text-[#2563EB] focus:ring-[#2563EB]/20';
                return (
                    <>
                        <td className={'px-3 py-2.5 align-middle ' + (useRmShell ? 'px-3 py-3' : '')} onClick={(e) => e.stopPropagation()}>
                            <input
                                type="checkbox"
                                className={chkCls}
                                checked={selectedIds.has(inv.id)}
                                onChange={(e) => toggleSelection(inv.id, e)}
                            />
                        </td>
                        <td className={'px-3 py-2.5 text-base font-semibold text-gray-900 whitespace-nowrap ' + (useRmShell ? 'py-3' : '')}>
                            <a href={'view.php?id=' + inv.id} className="hover:text-[#2563EB] hover:underline" onClick={(e) => e.stopPropagation()}>{inv.invoice_number}</a>
                        </td>
                        {showOrderTypeColumn && (
                            <td className={'px-3 py-2.5 whitespace-nowrap ' + (useRmShell ? 'py-3' : '')}>
                                {inv.order_type === 'truck' ? (
                                    <span className="px-2 py-0.5 rounded text-[11px] font-bold bg-[#0D2A4A]/10 text-[#0D2A4A] border border-[#0D2A4A]/20">TRUCK</span>
                                ) : (
                                    <span className="px-2 py-0.5 rounded text-[11px] font-bold bg-purple-50 text-purple-700 border border-purple-100">SPARE</span>
                                )}
                            </td>
                        )}
                        <td className={'px-3 py-2.5 text-base text-gray-600 whitespace-nowrap ' + (useRmShell ? 'py-3' : '')}>{formatDateTime(inv.created_at)}</td>
                        <td className={'px-3 py-2.5 truncate max-w-[200px] ' + (useRmShell ? 'py-3 text-base font-bold uppercase tracking-tight text-gray-900' : 'text-base text-gray-900 font-medium')} title={inv.customer_name || ''}>{inv.customer_name || 'â€”'}</td>
                        <td className={'px-3 py-2.5 whitespace-nowrap ' + (useRmShell ? 'py-3' : '')}>
                            <div className="flex items-center gap-2">
                                <SalespersonAvatar name={inv.salesperson} />
                                <span className="text-base text-gray-700 truncate max-w-[130px]">{inv.salesperson || 'â€”'}</span>
                            </div>
                        </td>
                        <td className={'px-3 py-2.5 text-base text-gray-700 whitespace-nowrap ' + (useRmShell ? 'py-3' : '')}>{formatDate(inv.invoice_date)}</td>
                        <td className={'px-3 py-2.5 text-base text-gray-700 whitespace-nowrap ' + (useRmShell ? 'py-3' : '')}>{formatDate(inv.due_date)}</td>
                        <td className={'px-3 py-2.5 text-base font-semibold text-gray-900 text-right whitespace-nowrap ' + (useRmShell ? 'py-3' : '')}>{formatCurrency(inv.total_amount)}</td>
                        <td className={'px-3 py-2.5 text-base font-semibold text-gray-900 text-right whitespace-nowrap ' + (useRmShell ? 'py-3' : '')}>{formatCurrency(inv.balance_due)}</td>
                        <td className={'px-3 py-2.5 text-center whitespace-nowrap ' + (useRmShell ? 'py-3' : '')}>
                            <span className={'inline-block px-2 py-0.5 text-sm font-semibold rounded-full ' + statusBadgeClass(config)}>{config.label}</span>
                        </td>
                        {useRmShell ? (
                            <td className="px-3 py-3 text-right whitespace-nowrap w-14" onClick={(e) => e.stopPropagation()}>
                                <div className="relative flex justify-end" data-invoice-actions="1">
                                    <button
                                        type="button"
                                        className="p-2 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-800"
                                        title="Actions"
                                        aria-label="Actions"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setOpenMenuId((id) => (id === inv.id ? null : inv.id));
                                        }}
                                    >
                                        <i className="fas fa-ellipsis-vertical"></i>
                                    </button>
                                    {openMenuId === inv.id && (
                                        <div className="absolute right-0 top-full mt-1 w-44 bg-white border border-gray-200 rounded-lg shadow-lg z-50 py-1 text-sm text-left" onClick={(e) => e.stopPropagation()}>
                                            <a href={'view.php?id=' + inv.id} className="block px-3 py-2 hover:bg-gray-50 text-gray-700 no-underline">
                                                <i className="fas fa-eye w-5 text-gray-400"></i> View
                                            </a>
                                            <a href={'print.php?id=' + inv.id} target="_blank" rel="noopener noreferrer" className="block px-3 py-2 hover:bg-gray-50 text-gray-700 no-underline">
                                                <i className="fas fa-print w-5 text-gray-400"></i> Print
                                            </a>
                                        </div>
                                    )}
                                </div>
                            </td>
                        ) : (
                            <td className="px-3 py-2.5 text-right whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity">
                                <a href={'view.php?id=' + inv.id} className="text-gray-400 hover:text-gray-700 me-2" title="View"><i className="fas fa-eye"></i></a>
                                <a href={'print.php?id=' + inv.id} target="_blank" rel="noopener noreferrer" className="text-gray-400 hover:text-gray-700" title="Print" onClick={(e) => e.stopPropagation()}><i className="fas fa-print"></i></a>
                            </td>
                        )}
                    </>
                );
            }

            const theadRowClass = useRmShell
                ? 'border-b border-gray-200 bg-slate-50 text-xs font-semibold text-gray-600 uppercase tracking-wider'
                : 'bg-white text-sm font-bold text-gray-500 uppercase tracking-wide';

            function renderInvoicesBody() {
                if (filteredInvoices.length === 0) {
                    return (
                        <div className={'text-center py-20 ' + (useRmShell ? 'px-4' : 'bg-white border border-gray-100 m-4 rounded-lg')}>
                            <i className="fas fa-file-invoice text-4xl text-gray-300 mb-3"></i>
                            <p className="text-gray-600 font-medium text-lg">No invoices found</p>
                            <p className="text-gray-400 text-base mt-1">Adjust search or filters</p>
                        </div>
                    );
                }
                if (viewMode === 'list') {
                    return (
                        <div className={'overflow-x-auto ' + (useRmShell ? 'bg-white' : 'bg-white shadow-sm')}>
                            <table className={'w-full text-left border-collapse ' + (useRmShell ? 'min-w-[960px]' : 'min-w-[880px]')}>
                                <thead>
                                    <tr className={theadRowClass}>
                                        <th className={'w-10 ' + (useRmShell ? 'px-3 py-3' : 'px-3 py-2')}>
                                            <input
                                                type="checkbox"
                                                className={useRmShell ? 'q-checkbox' : 'w-4 h-4 rounded border-gray-300 text-[#2563EB] focus:ring-[#2563EB]/20'}
                                                onChange={handleSelectAll}
                                                checked={pagedInvoices.length > 0 && pagedInvoices.every((i) => selectedIds.has(i.id))}
                                            />
                                        </th>
                                        <th className={useRmShell ? 'px-3 py-3' : 'px-3 py-2.5'}>Number</th>
                                        {showOrderTypeColumn && <th className={useRmShell ? 'px-3 py-3' : 'px-3 py-2.5'}>Type</th>}
                                        <th className={(useRmShell ? 'px-3 py-3 ' : 'px-3 py-2.5 ') + 'whitespace-nowrap'}>Created</th>
                                        <th className={useRmShell ? 'px-3 py-3' : 'px-3 py-2.5'}>Customer</th>
                                        <th className={useRmShell ? 'px-3 py-3' : 'px-3 py-2.5'}>Salesperson</th>
                                        <th className={(useRmShell ? 'px-3 py-3 ' : 'px-3 py-2.5 ') + 'whitespace-nowrap'}>Invoice date</th>
                                        <th className={(useRmShell ? 'px-3 py-3 ' : 'px-3 py-2.5 ') + 'whitespace-nowrap'}>Due</th>
                                        <th className={(useRmShell ? 'px-3 py-3 ' : 'px-3 py-2.5 ') + 'text-right'}>Total</th>
                                        <th className={(useRmShell ? 'px-3 py-3 ' : 'px-3 py-2.5 ') + 'text-right'}>Balance</th>
                                        <th className={(useRmShell ? 'px-3 py-3 ' : 'px-3 py-2.5 ') + 'text-center'}>Status</th>
                                        <th className={(useRmShell ? 'w-14 px-3 py-3 ' : 'w-12 px-3 py-2 ') + 'text-right whitespace-nowrap'}>
                                            {useRmShell ? 'Actions' : <i className="fas fa-sliders-h text-gray-400" title="Actions"></i>}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 bg-white">
                                    {pagedInvoices.map((inv) => (
                                        <tr key={inv.id} className="hover:bg-gray-50 group cursor-pointer" onClick={() => { window.location.href = 'view.php?id=' + inv.id; }}>
                                            <RowCells inv={inv} />
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    );
                }
                if (viewMode === 'cards') {
                    return (
                        <div className="p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3 bg-white">
                            {pagedInvoices.map((inv) => {
                                const config = getStatusConfig(inv.status);
                                return (
                                    <div key={inv.id} className="bg-white border border-gray-200 rounded-lg p-4 shadow-sm hover:shadow-md transition-shadow">
                                        <div className="flex justify-between items-start gap-2 mb-2">
                                            <div className="flex flex-col gap-1">
                                                <a href={'view.php?id=' + inv.id} className="font-bold text-gray-900 text-base hover:text-[#2563EB]">{inv.invoice_number}</a>
                                                {showOrderTypeColumn && (
                                                    inv.order_type === 'truck' ? (
                                                        <span className="text-[10px] font-bold bg-[#0D2A4A]/10 text-[#0D2A4A] px-1.5 py-0.5 rounded border border-[#0D2A4A]/20 w-fit">TRUCK</span>
                                                    ) : (
                                                        <span className="text-[10px] font-bold bg-purple-50 text-purple-700 px-1.5 py-0.5 rounded border border-purple-100 w-fit">SPARE</span>
                                                    )
                                                )}
                                            </div>
                                            <span className={'shrink-0 inline-block px-2 py-0.5 text-sm font-semibold rounded-full ' + statusBadgeClass(config)}>{config.label}</span>
                                        </div>
                                        <p className="text-base font-medium text-gray-800 truncate">{inv.customer_name || 'â€”'}</p>
                                        <div className="flex items-center gap-2 mt-2">
                                            <SalespersonAvatar name={inv.salesperson} size="sm" />
                                            <span className="text-sm text-gray-600 truncate">{inv.salesperson || 'â€”'}</span>
                                        </div>
                                        <div className="mt-3 pt-3 border-t border-gray-100 flex justify-between text-sm text-gray-600">
                                            <span>{formatDate(inv.invoice_date)}</span>
                                            <span className="font-semibold text-gray-900">{formatCurrency(inv.total_amount)}</span>
                                        </div>
                                        <p className="text-sm text-gray-500 mt-1">Due {formatDate(inv.due_date)} Â· Bal {formatCurrency(inv.balance_due)}</p>
                                        <div className="mt-3 flex gap-2">
                                            <a href={'view.php?id=' + inv.id} className="flex-1 text-center py-2 text-sm font-semibold rounded border border-gray-200 hover:bg-gray-50">View</a>
                                            <a href={'print.php?id=' + inv.id} target="_blank" rel="noopener noreferrer" className="flex-1 text-center py-2 text-sm font-semibold rounded bg-gray-800 text-white hover:bg-gray-900">Print</a>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    );
                }
                return (
                    <div className="p-3 overflow-x-auto no-scrollbar bg-white">
                        <div className="flex gap-3 min-w-max pb-2">
                            {BOARD_COLUMNS.map((col) => {
                                const items = byBoard[col.key] || [];
                                return (
                                    <div key={col.key} className="w-72 shrink-0 bg-gray-100/90 rounded-lg shadow-sm flex flex-col max-h-[72vh]">
                                        <div className="px-3 py-2.5 border-b border-gray-200 bg-white/95 rounded-t-lg flex justify-between items-center">
                                            <span className="text-sm font-bold text-gray-600 uppercase tracking-wide">{col.label}</span>
                                            <span className="text-xs font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">{items.length}</span>
                                        </div>
                                        <div className="p-2 space-y-2 overflow-y-auto flex-1">
                                            {items.length === 0 ? (
                                                <p className="text-sm text-gray-400 text-center py-6">â€”</p>
                                            ) : (
                                                items.map((inv) => {
                                                    const config = getStatusConfig(inv.status);
                                                    return (
                                                        <div key={inv.id} className="bg-white border border-gray-200 rounded-xl p-3 shadow-sm hover:shadow-md transition-shadow">
                                                            <a href={'view.php?id=' + inv.id} className="font-semibold text-base text-gray-900 hover:text-[#2563EB] block truncate">{inv.invoice_number}</a>
                                                            {showOrderTypeColumn && (
                                                                inv.order_type === 'truck' ? (
                                                                    <span className="text-[10px] font-bold bg-[#0D2A4A]/10 text-[#0D2A4A] px-1.5 py-0.5 rounded border border-[#0D2A4A]/20 w-fit mb-1 block">TRUCK</span>
                                                                ) : (
                                                                    <span className="text-[10px] font-bold bg-purple-50 text-purple-700 px-1.5 py-0.5 rounded border border-purple-100 w-fit mb-1 block">SPARE</span>
                                                                )
                                                            )}
                                                            <p className="text-sm text-gray-600 truncate mt-1">{inv.customer_name || 'â€”'}</p>
                                                            <div className="mt-1.5 flex items-center gap-2 min-w-0">
                                                                <SalespersonAvatar name={inv.salesperson} size="sm" />
                                                                <span className="text-xs text-gray-600 truncate font-medium">{inv.salesperson || 'â€”'}</span>
                                                            </div>
                                                            <p className="text-sm text-gray-500 mt-1">{formatCurrency(inv.total_amount)} Â· Bal {formatCurrency(inv.balance_due)}</p>
                                                            <div className="mt-2 flex items-center gap-2">
                                                                <span className={'inline-block px-2 py-0.5 text-sm font-medium rounded-full ' + statusBadgeClass(config)}>{config.label}</span>
                                                                <a href={'print.php?id=' + inv.id} target="_blank" rel="noopener noreferrer" className="text-sm text-gray-500 hover:text-gray-800 ms-auto"><i className="fas fa-print"></i></a>
                                                            </div>
                                                        </div>
                                                    );
                                                })
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                );
            }

            const invToolbar = (
                <div className="flex flex-wrap items-center gap-3 p-4 border-b border-gray-50 bg-white">
                    <div className="relative flex-1 min-w-[200px] max-w-md">
                        <i className="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search number, order, customer, salespersonâ€¦"
                            className="w-full pl-9 pr-4 py-2.5 text-sm bg-white border border-gray-200 rounded-full focus:outline-none focus:ring-2 focus:ring-[#2563EB]/20 focus:border-[#2563EB] shadow-sm"
                        />
                    </div>
                    <select
                        value={myInvoicesOnly ? 'mine' : 'all'}
                        onChange={(e) => setMyInvoicesOnly(e.target.value === 'mine')}
                        className="text-sm border border-gray-200 rounded-full px-4 py-2.5 bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#2563EB]/20 min-w-[150px] shadow-sm"
                    >
                        <option value="all">All invoices</option>
                        <option value="mine">My invoices</option>
                    </select>
                    <select
                        value={statusFilter}
                        onChange={(e) => setStatusFilter(e.target.value)}
                        className="text-sm border border-gray-200 rounded-full px-4 py-2.5 bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#2563EB]/20 min-w-[150px] shadow-sm"
                    >
                        <option value="">All statuses</option>
                        <option value="paid">Paid</option>
                        <option value="sent">Sent</option>
                        <option value="invoiced">Invoiced</option>
                        <option value="overdue">Overdue</option>
                        <option value="draft">Draft</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <button type="button" className="inline-flex items-center gap-2 px-4 py-2.5 rounded-full border border-gray-200 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 shadow-sm transition-colors">
                        <i className="fas fa-sliders-h text-gray-500"></i> Filters
                    </button>
                    <button type="button" className="p-2.5 rounded-lg border border-gray-200 bg-white text-gray-600 hover:bg-gray-50" title="Date range">
                        <i className="fas fa-calendar-alt"></i>
                    </button>
                    <div className="flex-1 min-w-[8px]" />
                    <div className="flex items-center gap-0.5 bg-white rounded-full border border-gray-200 p-0.5 shadow-sm" role="group" aria-label="View">
                        {viewBtn('list', 'fa-list', 'List')}
                        {viewBtn('cards', 'fa-th-large', 'Cards')}
                        {viewBtn('board', 'fa-columns', 'Board')}
                    </div>
                    <div className="flex items-center gap-2 text-sm text-gray-600">
                        <button
                            type="button"
                            disabled={safePage <= 1}
                            onClick={() => setPage((p) => Math.max(1, p - 1))}
                            className="p-2 rounded-lg border border-gray-200 bg-white disabled:opacity-40 hover:bg-gray-50"
                        ><i className="fas fa-chevron-left text-xs"></i></button>
                        <span className="tabular-nums whitespace-nowrap px-1">{rangeStart}-{rangeEnd} of {filteredInvoices.length}</span>
                        <button
                            type="button"
                            disabled={safePage >= pageCount}
                            onClick={() => setPage((p) => Math.min(pageCount, p + 1))}
                            className="p-2 rounded-lg border border-gray-200 bg-white disabled:opacity-40 hover:bg-gray-50"
                        ><i className="fas fa-chevron-right text-xs"></i></button>
                    </div>
                    {selectedIds.size > 0 && (
                        <div className="flex items-center gap-2 flex-wrap w-full justify-end border-t border-gray-100 pt-3 mt-1">
                            <span className="text-sm text-gray-500">{selectedIds.size} selected</span>
                        </div>
                    )}
                </div>
            );

            const invFooter = (filteredInvoices.length > 0 && viewMode === 'list') ? (
                <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-t border-gray-100 bg-white text-sm text-gray-600">
                    <span>
                        Showing <span className="font-semibold text-gray-800">{rangeStart}</span> to <span className="font-semibold text-gray-800">{rangeEnd}</span> of{' '}
                        <span className="font-semibold text-gray-800">{filteredInvoices.length}</span> invoices
                    </span>
                    <div className="flex items-center gap-1">
                        <button
                            type="button"
                            disabled={safePage <= 1}
                            onClick={() => setPage((p) => Math.max(1, p - 1))}
                            className="px-3 py-1.5 rounded-lg border border-gray-200 bg-white text-sm font-medium disabled:opacity-40 hover:bg-gray-50"
                        >
                            Previous
                        </button>
                        {Array.from({ length: pageCount }, (_, i) => i + 1).map((pn) => (
                            <button
                                key={pn}
                                type="button"
                                onClick={() => setPage(pn)}
                                className={
                                    'min-w-[2.25rem] px-2 py-1.5 rounded-lg text-sm font-semibold border ' +
                                    (pn === safePage ? 'bg-[#2563EB] text-white border-[#2563EB]' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50')
                                }
                            >
                                {pn}
                            </button>
                        ))}
                        <button
                            type="button"
                            disabled={safePage >= pageCount}
                            onClick={() => setPage((p) => Math.min(pageCount, p + 1))}
                            className="px-3 py-1.5 rounded-lg border border-gray-200 bg-white text-sm font-medium disabled:opacity-40 hover:bg-gray-50"
                        >
                            Next
                        </button>
                    </div>
                </div>
            ) : null;

            if (useRmShell) {
                const fmtBig = (n) => new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(n);
                return (
                    <div className="w-full max-w-full ml-0 animate-fade-in bg-transparent min-h-[calc(100vh-4rem)] pb-10">
                        <div className="w-full max-w-full ml-0 px-4 md:px-6 pt-6 pb-6">
                            <div className="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3 mb-2">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <h1 className="text-3xl font-bold text-gray-900 tracking-tight">Invoices</h1>
                                        <a href="../settings/index.php" className="text-gray-400 hover:text-[#2563EB] p-1 rounded-md hover:bg-gray-100/80" title="Sales settings">
                                            <i className="fas fa-cog text-lg"></i>
                                        </a>
                                    </div>
                                    <p className="text-gray-500 mt-1 text-base max-w-xl leading-snug">View, print and track all customer invoices.</p>
                                </div>
                                <button
                                    type="button"
                                    onClick={handleNewClick}
                                    className="invoices-new-btn inline-flex items-center justify-center gap-2 !rounded-full bg-[#7C3AED] hover:bg-[#6D28D9] text-white px-8 py-3 text-base font-semibold shadow-sm hover:shadow-md transition-colors border-0 cursor-pointer whitespace-nowrap"
                                >
                                    <i className="fas fa-plus"></i> New invoice
                                </button>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 mb-5">
                                <div className="bg-white rounded-lg border border-gray-200 px-3.5 py-3 shadow-sm flex items-center gap-3">
                                    <div className="h-10 w-10 shrink-0 rounded-lg bg-violet-100 flex items-center justify-center text-violet-600">
                                        <i className="fas fa-file-invoice text-base"></i>
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium text-gray-500 leading-snug">Total Invoices</p>
                                        <p className="text-2xl font-bold text-gray-900 mt-1 leading-tight tabular-nums">{fmtBig(invoiceStats.total)}</p>
                                        <p className="text-xs text-gray-400 mt-1 leading-snug">All time</p>
                                    </div>
                                </div>
                                <div className="bg-white rounded-lg border border-gray-200 px-3.5 py-3 shadow-sm flex items-center gap-3">
                                    <div className="h-10 w-10 shrink-0 rounded-lg bg-emerald-100 flex items-center justify-center text-emerald-600">
                                        <i className="fas fa-wallet text-base"></i>
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium text-gray-500 leading-snug">Total Value</p>
                                        <p className="text-2xl font-bold text-gray-900 mt-1 leading-tight truncate" title={defaultCurrency + ' ' + formatCurrency(invoiceStats.totalVal)}>
                                            {defaultCurrency} {formatCurrency(invoiceStats.totalVal)}
                                        </p>
                                        <p className="text-xs text-gray-400 mt-1 leading-snug">All time</p>
                                    </div>
                                </div>
                                <div className="bg-white rounded-lg border border-gray-200 px-3.5 py-3 shadow-sm flex items-center gap-3">
                                    <div className="h-10 w-10 shrink-0 rounded-lg bg-amber-100 flex items-center justify-center text-amber-600">
                                        <i className="fas fa-clock text-base"></i>
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium text-gray-500 leading-snug">Outstanding</p>
                                        <p className="text-2xl font-bold text-gray-900 mt-1 leading-tight tabular-nums">{fmtBig(invoiceStats.outstanding)}</p>
                                        <p className="text-xs text-gray-400 mt-1 leading-snug">Unpaid balance</p>
                                    </div>
                                </div>
                                <div className="bg-white rounded-lg border border-gray-200 px-3.5 py-3 shadow-sm flex items-center gap-3">
                                    <div className="h-10 w-10 shrink-0 rounded-lg bg-green-100 flex items-center justify-center text-green-600">
                                        <i className="fas fa-circle-check text-base"></i>
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium text-gray-500 leading-snug">Paid</p>
                                        <p className="text-2xl font-bold text-gray-900 mt-1 leading-tight tabular-nums">{fmtBig(invoiceStats.paidYtd)}</p>
                                        <p className="text-xs text-gray-400 mt-1 leading-snug">This year</p>
                                    </div>
                                </div>
                            </div>

                            <div className="bg-white rounded-xl shadow-sm overflow-hidden border border-gray-200">
                                {invToolbar}
                                {renderInvoicesBody()}
                                {invFooter}
                            </div>
                        </div>
                    </div>
                );
            }

            return (
                <div className="max-w-full mx-auto animate-fade-in">
                    <div className="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
                        <div className="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                            {(isRoadmaster || useRmShell) ? (
                                <button type="button" onClick={handleNewClick} className="invoices-new-btn !rounded-full bg-[#7C3AED] hover:bg-[#6D28D9] text-white px-6 py-2.5 text-base font-bold shadow-md hover:shadow-lg transition-all duration-200 border-0 cursor-pointer inline-flex items-center gap-2">
                                    <i className="fas fa-plus text-sm"></i> New
                                </button>
                            ) : (
                                <a href="create.php" className="invoices-new-btn !rounded-full bg-[#7C3AED] hover:bg-[#6D28D9] text-white px-6 py-2.5 text-base font-bold shadow-md hover:shadow-lg transition-all duration-200 border-0 cursor-pointer inline-flex items-center gap-2 no-underline">
                                    <i className="fas fa-plus text-sm"></i> New
                                </a>
                            )}

                            <div className="flex items-center gap-2 min-w-0">
                                <h1 className="text-xl font-bold text-gray-900 truncate m-0">Invoices</h1>
                                <a href="../settings/index.php" className="text-gray-400 hover:text-[#2563EB]" title="Sales settings"><i className="fas fa-cog text-base"></i></a>
                            </div>
                            <div className="flex-1" />
                            <div className="flex items-center gap-2 text-base text-gray-600">
                                <button
                                    type="button"
                                    disabled={safePage <= 1}
                                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                                    className="p-1.5 rounded-full border border-gray-200 bg-white disabled:opacity-40 hover:bg-gray-50 transition-colors"
                                ><i className="fas fa-chevron-left text-xs"></i></button>
                                <span className="tabular-nums whitespace-nowrap">{rangeStart}-{rangeEnd} / {filteredInvoices.length}</span>
                                <button
                                    type="button"
                                    disabled={safePage >= pageCount}
                                    onClick={() => setPage((p) => Math.min(pageCount, p + 1))}
                                    className="p-2 rounded-full border border-gray-200 bg-white disabled:opacity-40 hover:bg-gray-50 transition-colors"
                                ><i className="fas fa-chevron-right text-xs"></i></button>
                            </div>
                            <div className="flex items-center gap-0.5 bg-white rounded-full border border-gray-200 p-0.5 shadow-sm" role="group" aria-label="View">
                                {viewBtn('list', 'fa-list', 'List')}
                                {viewBtn('cards', 'fa-th-large', 'Cards')}
                                {viewBtn('board', 'fa-columns', 'Board')}
                            </div>
                        </div>

                        <div className="px-4 py-2 flex flex-wrap items-center gap-3 bg-gray-50/80">
                            <div className="relative flex-1 min-w-[200px] max-w-xl">
                                <i className="fas fa-filter absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                <input
                                    type="text"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search number, order, customer, salespersonâ€¦"
                                    className="w-full pl-9 pr-4 py-2.5 text-sm bg-white border border-gray-200 rounded-full focus:outline-none focus:ring-2 focus:ring-[#2563EB]/20 focus:border-[#2563EB] shadow-sm"
                                />
                            </div>
                            {myInvoicesOnly ? (
                                <button
                                    type="button"
                                    onClick={() => setMyInvoicesOnly(false)}
                                    className="inline-flex items-center gap-1 px-4 py-2.5 rounded-full bg-[#2563EB]/10 text-[#1D4ED8] text-sm font-semibold border border-[#2563EB]/30"
                                >
                                    My invoices
                                    <i className="fas fa-times text-xs"></i>
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => setMyInvoicesOnly(true)}
                                    className="text-sm font-medium text-gray-600 hover:text-[#2563EB] border border-dashed border-gray-300 rounded-full px-4 py-2.5 hover:border-[#2563EB] transition-colors"
                                >
                                    + My invoices
                                </button>
                            )}
                            <select
                                value={statusFilter}
                                onChange={(e) => setStatusFilter(e.target.value)}
                                className="text-sm border border-gray-200 rounded-full px-4 py-2.5 bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#2563EB]/20 shadow-sm"
                            >
                                <option value="">All statuses</option>
                                <option value="paid">Paid</option>
                                <option value="sent">Sent</option>
                                <option value="invoiced">Invoiced</option>
                                <option value="overdue">Overdue</option>
                                <option value="draft">Draft</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                            {selectedIds.size > 0 && (
                                <span className="text-sm text-gray-500 ms-auto">{selectedIds.size} selected</span>
                            )}
                        </div>
                    </div>

                    <div className="bg-transparent min-h-[50vh] pb-8">
                        {filteredInvoices.length === 0 ? (
                            <div className="bg-white border border-gray-100 m-4 py-20 text-center rounded-lg">
                                <i className="fas fa-file-invoice text-4xl text-gray-300 mb-3"></i>
                                <p className="text-gray-600 font-medium text-lg">No invoices found</p>
                                <p className="text-gray-400 text-base mt-1">Adjust search or filters</p>
                            </div>
                        ) : viewMode === 'list' ? (
                            <div className="bg-white overflow-x-auto shadow-sm">
                                <table className="w-full text-left border-collapse min-w-[880px]">
                                    <thead>
                                        <tr className="bg-white text-sm font-bold text-gray-500 uppercase tracking-wide">
                                            <th className="w-10 px-3 py-2">
                                                <input
                                                    type="checkbox"
                                                    className="w-4 h-4 rounded border-gray-300 text-[#2563EB] focus:ring-[#2563EB]/20"
                                                    onChange={handleSelectAll}
                                                    checked={pagedInvoices.length > 0 && pagedInvoices.every((i) => selectedIds.has(i.id))}
                                                />
                                            </th>
                                            <th className="px-3 py-2.5">Number</th>
                                            {showOrderTypeColumn && <th className="px-3 py-2.5">Type</th>}

                                            <th className="px-3 py-2.5 whitespace-nowrap">Created</th>
                                            <th className="px-3 py-2.5">Customer</th>
                                            <th className="px-3 py-2.5">Salesperson</th>
                                            <th className="px-3 py-2.5 whitespace-nowrap">Invoice date</th>
                                            <th className="px-3 py-2.5 whitespace-nowrap">Due</th>
                                            <th className="px-3 py-2.5 text-right">Total</th>
                                            <th className="px-3 py-2.5 text-right">Balance</th>
                                            <th className="px-3 py-2.5 text-center">Status</th>
                                            <th className="w-12 px-3 py-2 text-right"><i className="fas fa-sliders-h text-gray-400" title="Actions"></i></th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {pagedInvoices.map((inv) => (
                                            <tr key={inv.id} className="hover:bg-gray-50 group cursor-pointer" onClick={() => { window.location.href = 'view.php?id=' + inv.id; }}>
                                                <RowCells inv={inv} />
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : viewMode === 'cards' ? (
                            <div className="p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                                {pagedInvoices.map((inv) => {
                                    const config = getStatusConfig(inv.status);
                                    return (
                                        <div key={inv.id} className="bg-white border-0 rounded-lg p-4 shadow-sm hover:shadow-md transition-shadow">
                                            <div className="flex justify-between items-start gap-2 mb-2">
                                                <div className="flex flex-col gap-1">
                                                    <a href={'view.php?id=' + inv.id} className="font-bold text-gray-900 text-base hover:text-[#2563EB]">{inv.invoice_number}</a>
                                                    {showOrderTypeColumn && (
                                                        inv.order_type === 'truck' ? (
                                                            <span className="text-[10px] font-bold bg-[#0D2A4A]/10 text-[#0D2A4A] px-1.5 py-0.5 rounded border border-[#0D2A4A]/20 w-fit">TRUCK</span>
                                                        ) : (
                                                            <span className="text-[10px] font-bold bg-purple-50 text-purple-700 px-1.5 py-0.5 rounded border border-purple-100 w-fit">SPARE</span>
                                                        )
                                                    )}

                                                </div>
                                                <span className={'shrink-0 inline-block px-2 py-0.5 text-sm font-semibold rounded-full ' + statusBadgeClass(config)}>{config.label}</span>
                                            </div>

                                            <p className="text-base font-medium text-gray-800 truncate">{inv.customer_name || 'â€”'}</p>
                                            <div className="flex items-center gap-2 mt-2">
                                                <SalespersonAvatar name={inv.salesperson} size="sm" />
                                                <span className="text-sm text-gray-600 truncate">{inv.salesperson || 'â€”'}</span>
                                            </div>
                                            <div className="mt-3 pt-3 border-t border-gray-100 flex justify-between text-sm text-gray-600">
                                                <span>{formatDate(inv.invoice_date)}</span>
                                                <span className="font-semibold text-gray-900">{formatCurrency(inv.total_amount)}</span>
                                            </div>
                                            <p className="text-sm text-gray-500 mt-1">Due {formatDate(inv.due_date)} Â· Bal {formatCurrency(inv.balance_due)}</p>
                                            <div className="mt-3 flex gap-2">
                                                <a href={'view.php?id=' + inv.id} className="flex-1 text-center py-2 text-sm font-semibold rounded border border-gray-200 hover:bg-gray-50">View</a>
                                                <a href={'print.php?id=' + inv.id} target="_blank" rel="noopener noreferrer" className="flex-1 text-center py-2 text-sm font-semibold rounded bg-gray-800 text-white hover:bg-gray-900">Print</a>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="p-3 overflow-x-auto no-scrollbar">
                                <div className="flex gap-3 min-w-max pb-2">
                                    {BOARD_COLUMNS.map((col) => {
                                        const items = byBoard[col.key] || [];
                                        return (
                                            <div key={col.key} className="w-72 shrink-0 bg-gray-100/90 rounded-lg shadow-sm flex flex-col max-h-[72vh]">
                                                <div className="px-3 py-2.5 border-b border-gray-200 bg-white/95 rounded-t-lg flex justify-between items-center">
                                                    <span className="text-sm font-bold text-gray-600 uppercase tracking-wide">{col.label}</span>
                                                    <span className="text-xs font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">{items.length}</span>
                                                </div>
                                                <div className="p-2 space-y-2 overflow-y-auto flex-1">
                                                    {items.length === 0 ? (
                                                        <p className="text-sm text-gray-400 text-center py-6">â€”</p>
                                                    ) : (
                                                        items.map((inv) => {
                                                            const config = getStatusConfig(inv.status);
                                                            return (
                                                                <div key={inv.id} className="bg-white border-0 rounded-xl p-3 shadow-sm hover:shadow-md transition-shadow">
                                                                    <a href={'view.php?id=' + inv.id} className="font-semibold text-base text-gray-900 hover:text-[#2563EB] block truncate">{inv.invoice_number}</a>
                                                                    {showOrderTypeColumn && (
                                                                        inv.order_type === 'truck' ? (
                                                                            <span className="text-[10px] font-bold bg-[#0D2A4A]/10 text-[#0D2A4A] px-1.5 py-0.5 rounded border border-[#0D2A4A]/20 w-fit mb-1 block">TRUCK</span>
                                                                        ) : (
                                                                            <span className="text-[10px] font-bold bg-purple-50 text-purple-700 px-1.5 py-0.5 rounded border border-purple-100 w-fit mb-1 block">SPARE</span>
                                                                        )
                                                                    )}

                                                                    <p className="text-sm text-gray-600 truncate mt-1">{inv.customer_name || 'â€”'}</p>
                                                                    <div className="mt-1.5 flex items-center gap-2 min-w-0">
                                                                        <SalespersonAvatar name={inv.salesperson} size="sm" />
                                                                        <span className="text-xs text-gray-600 truncate font-medium">{inv.salesperson || 'â€”'}</span>
                                                                    </div>

                                                                    <p className="text-sm text-gray-500 mt-1">{formatCurrency(inv.total_amount)} Â· Bal {formatCurrency(inv.balance_due)}</p>
                                                                    <div className="mt-2 flex items-center gap-2">
                                                                        <span className={'inline-block px-2 py-0.5 text-sm font-medium rounded-full ' + statusBadgeClass(config)}>{config.label}</span>
                                                                        <a href={'print.php?id=' + inv.id} target="_blank" rel="noopener noreferrer" className="text-sm text-gray-500 hover:text-gray-800 ms-auto"><i className="fas fa-print"></i></a>
                                                                    </div>
                                                                </div>
                                                            );
                                                        })
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            );
        }

        const root = ReactDOM.createRoot(document.getElementById('react-root'));
        root.render(<InvoicesApp />);
    </script>
</body>
</html>

