<?php
require_once '../../../includes/config.php';
require_once '../../../includes/functions.php';

require_once '../functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
}

$productsHasItemType = false;
try {
    $prodCols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
    $productsHasItemType = is_array($prodCols) && in_array('item_type', $prodCols, true);
} catch (Throwable $e) {
    $productsHasItemType = false;
}
$vehicleLineSelect = $productsHasItemType
    ? '(SELECT COUNT(*) FROM sales_order_items soi INNER JOIN products p ON p.id = soi.product_id WHERE soi.order_id = so.id AND LOWER(TRIM(COALESCE(p.item_type, \'\'))) IN (\'vehicle\', \'truck\')) AS _rm_vehicle_lines'
    : '0 AS _rm_vehicle_lines';

$salesDb = sales_pdo();
$sql = "SELECT so.*, c.company_name, c.contact_person, u.full_name AS salesperson,
        $vehicleLineSelect
        FROM sales_orders so
        LEFT JOIN customers c ON so.customer_id = c.id
        LEFT JOIN users u ON so.created_by = u.id";
$scope = salesCompanyScopeSql('sales_orders', 'so');
if ($scope[0] !== '') {
    $sql .= ' WHERE 1=1' . $scope[0];
}
$sql .= ' ORDER BY so.created_at DESC';
$listParams = $scope[1];
if (!empty($listParams)) {
    $stmtOrders = $salesDb->prepare($sql);
    $stmtOrders->execute($listParams);
    $orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);
} else {
    $orders = $salesDb->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
foreach ($orders as &$oRow) {
    $vehicleLines = (int) ($oRow['_rm_vehicle_lines'] ?? 0);
    unset($oRow['_rm_vehicle_lines']);
    $ot = isset($oRow['order_type']) ? trim((string) $oRow['order_type']) : '';
    $storedTruck = (strtolower($ot) === 'truck');
    $oRow['order_type'] = ($storedTruck || $vehicleLines > 0) ? 'truck' : 'spare';
}
unset($oRow);

$isRoadmaster = isRoadmaster();

$ordDefaultCurrency = 'TZS';
try {
    if (function_exists('currentCompanyId')) {
        $cidOrd = (int) currentCompanyId();
        if ($cidOrd > 0) {
            $stOrd = $pdo->prepare('SELECT default_currency FROM sales_settings WHERE company_id = ? LIMIT 1');
            $stOrd->execute([$cidOrd]);
            $rowOrd = $stOrd->fetch(PDO::FETCH_ASSOC);
            if (!empty($rowOrd['default_currency'])) {
                $ordDefaultCurrency = (string) $rowOrd['default_currency'];
            }
        }
    }
    if ($ordDefaultCurrency === 'TZS') {
        $rowOrd = $pdo->query('SELECT default_currency FROM sales_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!empty($rowOrd['default_currency'])) {
            $ordDefaultCurrency = (string) $rowOrd['default_currency'];
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
    <title>Sales Orders | Sales</title>
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
        html:has(body.page-sales-orders),
        body.page-sales-orders {
            background-color: #f8f9fc !important;
        }
        body.page-sales-orders {
            font-family: 'Outfit', system-ui, -apple-system, sans-serif;
            color: #374151;
            font-size: 16px;
            min-height: 100vh;
        }
        body.page-sales-orders .layout-main-wrapper {
            background-color: #f8f9fc;
            width: 100%;
            max-width: 100%;
        }
        body.page-sales-orders .layout-main-wrapper > .flex-grow-1 {
            flex: 1 1 0%;
            min-width: 0;
            max-width: none;
            width: 100%;
            background-color: #f8f9fc;
        }
        body.page-sales-orders header.employee-header {
            background: #f8f9fc !important;
            box-shadow: none !important;
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
        }
        body.page-sales-orders .employee-header .header-content {
            background: transparent;
        }
        body.page-sales-orders #native-sidebar {
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
        html body .main-content.sales-orders-shell {
            padding-left: 1rem !important;
            padding-right: 1rem !important;
            box-sizing: border-box !important;
            flex: 1 1 auto;
            width: 100% !important;
            max-width: none !important;
            min-width: 0;
            background-color: #f8f9fc;
        }
        body.page-sales-orders #react-root {
            width: 100%;
            min-width: 0;
        }
        @media (min-width: 993px) {
            html body .main-content.sales-orders-shell {
                padding-left: 1.75rem !important;
                padding-right: 1.5rem !important;
            }
        }
        .so-btn-primary {
            background-color: #2563EB;
            color: #fff;
            border: 1px solid #2563EB;
        }
        .so-btn-primary:hover {
            background-color: #1D4ED8;
            border-color: #1D4ED8;
        }
        .so-checkbox {
            appearance: none;
            width: 1rem;
            height: 1rem;
            border: 1px solid #D1D5DB;
            border-radius: 0.125rem;
            background: #fff;
            cursor: pointer;
            position: relative;
        }
        .so-checkbox:checked {
            background-color: #2563EB;
            border-color: #2563EB;
        }
        .so-checkbox:checked::after {
            content: '\2713';
            color: #fff;
            position: absolute;
            font-size: 11px;
            font-weight: bold;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        button.orders-new-btn,
        a.orders-new-btn {
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
    </style>
</head>
<body class="page-sales-orders">
    <?php include '../../../includes/header_employee.php'; ?>

    <div class="main-content sales-orders-shell" id="react-root"></div>

    <script>
        window.APP_DATA = {
            orders: <?= sales_json_script($orders) ?>,
            currentUserId: <?= (int) ($_SESSION['user_id'] ?? 0) ?>,
            isRoadmaster: <?= sales_json_script($isRoadmaster) ?>,
            ordersShellLayout: <?= sales_json_script(function_exists('ordersListUsesRoadmasterShell') && ordersListUsesRoadmasterShell()) ?>,
            defaultCurrency: <?= sales_json_script($ordDefaultCurrency) ?>
        };

    </script>

    <script type="text/babel">
        const { useState, useMemo, useEffect } = React;
        const { ordersShellLayout, defaultCurrency: appOrdCurrency } = window.APP_DATA;
        const useRmShell = ordersShellLayout === true;
        const defaultCurrency = appOrdCurrency || 'TZS';

        const VIEW_MODES = ['list', 'cards', 'board'];
        const PAGE_SIZE = 25;

        const BOARD_COLS = [
            { key: 'draft', label: 'Draft' },
            { key: 'quotation', label: 'Quotation' },
            { key: 'confirmed', label: 'Sales order' },
            { key: 'invoiced', label: 'Invoiced' },
            { key: 'processing', label: 'Processing' },
            { key: 'shipped', label: 'Shipped' },
            { key: 'delivered', label: 'Delivered' },
            { key: 'paid', label: 'Paid' },
            { key: 'cancelled', label: 'Cancelled' },
            { key: 'other', label: 'Other' }
        ];

        function boardColumnForStatus(status) {
            const s = (status || '').toLowerCase().trim();
            if (s === 'draft') return 'draft';
            if (s === 'quotation') return 'quotation';
            if (s === 'confirmed') return 'confirmed';
            if (s === 'invoiced') return 'invoiced';
            if (s === 'processing' || s === 'pending') return 'processing';
            if (s === 'shipped') return 'shipped';
            if (s === 'delivered') return 'delivered';
            if (s === 'paid' || s === 'completed') return 'paid';
            if (s === 'cancelled' || s === 'canceled') return 'cancelled';
            return 'other';
        }

        function statusPill(status) {
            const st = (status || '').toLowerCase().trim();
            const labels = {
                draft: 'Draft',
                quotation: 'Quotation',
                confirmed: 'Sales Order',
                invoiced: 'Invoiced',
                processing: 'Processing',
                pending: 'Pending',
                shipped: 'Shipped',
                delivered: 'Delivered',
                paid: 'Paid',
                completed: 'Completed',
                cancelled: 'Cancelled'
            };
            const label = labels[st] || (status || 'â€”');
            let cls = 'bg-gray-500 text-white';
            if (st === 'confirmed' || st === 'invoiced' || st === 'paid' || st === 'completed' || st === 'delivered') cls = 'bg-[#28A745] text-white';
            else if (st === 'quotation' || st === 'sent') cls = 'bg-[#17A2B8] text-white';
            else if (st === 'draft' || st === 'pending' || st === 'processing') cls = 'bg-[#FFC107] text-gray-900';
            else if (st === 'cancelled' || st === 'canceled') cls = 'bg-[#DC3545] text-white';
            else if (st === 'shipped') cls = 'bg-[#6f42c1] text-white';
            return <span className={'inline-block px-2.5 py-0.5 text-sm font-semibold rounded-full ' + cls}>{label}</span>;
        }

        function initials(name) {
            if (!name || !String(name).trim()) return '?';
            return String(name).split(/\s+/).map((w) => w[0]).join('').toUpperCase().slice(0, 2);
        }

        const SALESPERSON_AVATAR_STYLES = [
            { fill: 'bg-sky-100 text-sky-800', glow: 'shadow-[0_0_16px_rgba(56,189,248,0.45)] ring-1 ring-sky-300/80' },
            { fill: 'bg-violet-100 text-violet-800', glow: 'shadow-[0_0_16px_rgba(167,139,250,0.45)] ring-1 ring-violet-300/80' },
            { fill: 'bg-emerald-100 text-emerald-800', glow: 'shadow-[0_0_16px_rgba(52,211,153,0.45)] ring-1 ring-emerald-300/80' },
            { fill: 'bg-amber-100 text-amber-900', glow: 'shadow-[0_0_16px_rgba(251,191,36,0.5)] ring-1 ring-amber-300/80' },
            { fill: 'bg-rose-100 text-rose-800', glow: 'shadow-[0_0_16px_rgba(251,113,133,0.45)] ring-1 ring-rose-300/80' },
            { fill: 'bg-cyan-100 text-cyan-800', glow: 'shadow-[0_0_16px_rgba(34,211,238,0.45)] ring-1 ring-cyan-300/80' },
            { fill: 'bg-indigo-100 text-indigo-800', glow: 'shadow-[0_0_16px_rgba(129,140,248,0.45)] ring-1 ring-indigo-300/80' },
            { fill: 'bg-teal-100 text-teal-800', glow: 'shadow-[0_0_16px_rgba(45,212,191,0.45)] ring-1 ring-teal-300/80' },
            { fill: 'bg-orange-100 text-orange-800', glow: 'shadow-[0_0_16px_rgba(251,146,60,0.45)] ring-1 ring-orange-300/80' },
            { fill: 'bg-pink-100 text-pink-800', glow: 'shadow-[0_0_16px_rgba(244,114,182,0.45)] ring-1 ring-pink-300/80' },
            { fill: 'bg-lime-100 text-lime-800', glow: 'shadow-[0_0_16px_rgba(163,230,53,0.45)] ring-1 ring-lime-300/80' },
            { fill: 'bg-fuchsia-100 text-fuchsia-800', glow: 'shadow-[0_0_16px_rgba(232,121,249,0.45)] ring-1 ring-fuchsia-300/80' },
        ];

        function salespersonAvatarClasses(name) {
            const s = (name && String(name).trim()) || '';
            if (!s) {
                return 'bg-gray-100 text-gray-600 shadow-[0_0_12px_rgba(156,163,175,0.35)] ring-1 ring-gray-200/90';
            }
            let h = 0;
            for (let i = 0; i < s.length; i++) {
                h = ((h << 5) - h + s.charCodeAt(i)) | 0;
            }
            const st = SALESPERSON_AVATAR_STYLES[Math.abs(h) % SALESPERSON_AVATAR_STYLES.length];
            return st.fill + ' ' + st.glow;
        }

        function OrdersApp() {
            const { orders: initialOrders, currentUserId, isRoadmaster } = window.APP_DATA;

            const [orders] = useState(initialOrders || []);
            const [search, setSearch] = useState('');
            const [statusFilter, setStatusFilter] = useState('');
            const [myOrdersOnly, setMyOrdersOnly] = useState(false);
            const [selectedIds, setSelectedIds] = useState(new Set());
            const [openMenuId, setOpenMenuId] = useState(null);
            const [page, setPage] = useState(1);
            const [viewMode, setViewMode] = useState(() => {
                try {
                    const v = localStorage.getItem('sales_orders_view_mode');
                    if (VIEW_MODES.includes(v)) return v;
                } catch (e) {}
                return 'list';
            });

            useEffect(() => {
                try { localStorage.setItem('sales_orders_view_mode', viewMode); } catch (e) {}
            }, [viewMode]);

            useEffect(() => { setPage(1); }, [search, statusFilter, myOrdersOnly, viewMode]);

            useEffect(() => {
                if (openMenuId == null) return;
                const close = (ev) => {
                    if (ev.target && typeof ev.target.closest === 'function' && ev.target.closest('[data-order-actions]')) return;
                    setOpenMenuId(null);
                };
                document.addEventListener('click', close);
                return () => document.removeEventListener('click', close);
            }, [openMenuId]);

            const formatCurrency = (amount) =>
                new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount || 0);

            const formatDateTime = (dateStr) => {
                if (!dateStr) return 'â€”';
                const d = new Date(dateStr);
                if (Number.isNaN(d.getTime())) return 'â€”';
                const pad = (n) => String(n).padStart(2, '0');
                return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
            };

            const filteredOrders = useMemo(() => {
                return orders.filter((o) => {
                    const mineOk = !myOrdersOnly || Number(o.created_by) === Number(currentUserId);
                    const matchesSearch =
                        (o.order_number || '').toLowerCase().includes(search.toLowerCase()) ||
                        (o.company_name || '').toLowerCase().includes(search.toLowerCase()) ||
                        (o.contact_person || '').toLowerCase().includes(search.toLowerCase()) ||
                        (o.salesperson || '').toLowerCase().includes(search.toLowerCase());
                    const matchesStatus = !statusFilter || (o.status || '').toLowerCase() === statusFilter;
                    return mineOk && matchesSearch && matchesStatus;
                });
            }, [orders, search, statusFilter, myOrdersOnly, currentUserId]);

            const pageSize = useRmShell ? 10 : PAGE_SIZE;

            const orderStats = useMemo(() => {
                const y = new Date().getFullYear();
                const terminal = new Set(['paid', 'completed', 'delivered', 'cancelled', 'canceled']);
                let totalVal = 0;
                let pipeline = 0;
                let closedYtd = 0;
                orders.forEach((o) => {
                    totalVal += parseFloat(o.total_amount) || 0;
                    const st = (o.status || '').toLowerCase().trim();
                    if (!terminal.has(st)) pipeline++;
                    if (st === 'paid' || st === 'completed' || st === 'delivered') {
                        const d = o.created_at ? new Date(o.created_at) : null;
                        if (d && !Number.isNaN(d.getTime()) && d.getFullYear() === y) closedYtd++;
                    }
                });
                return { total: orders.length, totalVal, pipeline, closedYtd };
            }, [orders]);

            const pageCount = Math.max(1, Math.ceil(filteredOrders.length / pageSize));
            const safePage = Math.min(page, pageCount);
            const pagedOrders = useMemo(() => {
                const start = (safePage - 1) * pageSize;
                return filteredOrders.slice(start, start + pageSize);
            }, [filteredOrders, safePage, pageSize]);

            const byBoard = useMemo(() => {
                const m = { draft: [], quotation: [], confirmed: [], invoiced: [], processing: [], shipped: [], delivered: [], paid: [], cancelled: [], other: [] };
                filteredOrders.forEach((o) => {
                    m[boardColumnForStatus(o.status)].push(o);
                });
                return m;
            }, [filteredOrders]);

            const handleSelectAll = (e) => {
                if (e.target.checked) {
                    setSelectedIds(new Set(pagedOrders.map((o) => o.id)));
                } else {
                    setSelectedIds(new Set());
                }
            };

            const handleNewClick = () => {
                if (!isRoadmaster && !useRmShell) {
                    window.location.href = 'create.php?mode=new';
                    return;
                }
                Swal.fire({
                    title: 'Select Quotation Type',
                    text: 'What kind of quotation would you like to create?',
                    icon: 'question',
                    showDenyButton: true,
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-truck me-2"></i> Truck Quote',
                    denyButtonText: '<i class="fas fa-cogs me-2"></i> Spare Part Quote',
                    confirmButtonColor: '#714b67',
                    denyButtonColor: '#008784',
                    cancelButtonColor: '#94a3b8'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = 'create.php?mode=new&type=truck';
                    } else if (result.isDenied) {
                        window.location.href = 'create.php?mode=new&type=spare';
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

            const rangeStart = filteredOrders.length === 0 ? 0 : (safePage - 1) * pageSize + 1;
            const rangeEnd = Math.min(safePage * pageSize, filteredOrders.length);

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

            const selectedList = useMemo(
                () => filteredOrders.filter((o) => selectedIds.has(o.id)),
                [filteredOrders, selectedIds]
            );
            const canInvoice = selectedList.length === 1 && selectedList[0] && selectedList[0].status === 'confirmed';

            const showDeliveryNote = (st) => ['confirmed', 'invoiced', 'paid', 'shipped', 'delivered', 'completed'].includes((st || '').toLowerCase());

            const RowCells = ({ o }) => {
                const chkCls = useRmShell ? 'q-checkbox' : 'so-checkbox';
                const stLo = (o.status || '').toLowerCase();
                return (
                    <>
                        <td className={'px-3 py-2.5 align-middle ' + (useRmShell ? 'py-3' : '')} onClick={(e) => e.stopPropagation()}>
                            <input type="checkbox" className={chkCls} checked={selectedIds.has(o.id)} onChange={(e) => toggleSelection(o.id, e)} />
                        </td>
                        <td className={'px-3 py-2.5 text-base font-semibold text-gray-900 whitespace-nowrap ' + (useRmShell ? 'py-3' : '')}>
                            <a href={'view.php?id=' + o.id} className="hover:text-[#2563EB] hover:underline" onClick={(e) => e.stopPropagation()}>{o.order_number}</a>
                        </td>
                        <td className={'px-3 py-2.5 text-base text-gray-600 whitespace-nowrap ' + (useRmShell ? 'py-3' : '')}>{formatDateTime(o.created_at)}</td>
                        <td
                            className={
                                'px-3 py-2.5 truncate max-w-[200px] ' +
                                (useRmShell ? 'py-3 text-base font-bold uppercase tracking-tight text-gray-900' : 'text-base text-gray-900 font-medium')
                            }
                            title={o.company_name || ''}
                        >
                            {o.company_name || 'â€”'}
                        </td>
                        <td className={'px-3 py-2.5 whitespace-nowrap ' + (useRmShell ? 'py-3' : '')}>
                            <div className="flex items-center gap-2">
                                <span className={'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold ' + salespersonAvatarClasses(o.salesperson)}>{initials(o.salesperson)}</span>
                                <span className="text-base text-gray-700 truncate max-w-[140px]">{o.salesperson || 'â€”'}</span>
                            </div>
                        </td>
                        <td className={'px-3 py-2.5 text-base font-semibold text-gray-900 text-right whitespace-nowrap ' + (useRmShell ? 'py-3' : '')}>{formatCurrency(o.total_amount)}</td>
                        <td className={'px-3 py-2.5 text-center whitespace-nowrap ' + (useRmShell ? 'py-3' : '')}>{statusPill(o.status)}</td>
                        {useRmShell ? (
                            <td className="px-3 py-3 text-right whitespace-nowrap w-14" onClick={(e) => e.stopPropagation()}>
                                <div className="relative flex justify-end" data-order-actions="1">
                                    <button
                                        type="button"
                                        className="p-2 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-800"
                                        title="Actions"
                                        aria-label="Actions"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setOpenMenuId((id) => (id === o.id ? null : o.id));
                                        }}
                                    >
                                        <i className="fas fa-ellipsis-vertical"></i>
                                    </button>
                                    {openMenuId === o.id && (
                                        <div className="absolute right-0 top-full mt-1 w-48 bg-white border border-gray-200 rounded-lg shadow-lg z-50 py-1 text-sm text-left" onClick={(e) => e.stopPropagation()}>
                                            <a href={'view.php?id=' + o.id} className="block px-3 py-2 hover:bg-gray-50 text-gray-700 no-underline">
                                                <i className="fas fa-eye w-5 text-gray-400"></i> View
                                            </a>
                                            {stLo === 'draft' && (
                                                <a href={'edit.php?id=' + o.id} className="block px-3 py-2 hover:bg-gray-50 text-gray-700 no-underline">
                                                    <i className="fas fa-edit w-5 text-gray-400"></i> Edit
                                                </a>
                                            )}
                                            {stLo === 'confirmed' && (
                                                <a href={'../invoices/create.php?order_id=' + o.id} className="block px-3 py-2 hover:bg-gray-50 text-gray-700 no-underline">
                                                    <i className="fas fa-file-invoice-dollar w-5 text-gray-400"></i> Invoice
                                                </a>
                                            )}
                                            {showDeliveryNote(o.status) && (
                                                <a href={'delivery_note.php?id=' + o.id} target="_blank" rel="noopener noreferrer" className="block px-3 py-2 hover:bg-gray-50 text-gray-700 no-underline">
                                                    <i className="fas fa-truck w-5 text-gray-400"></i> Delivery note
                                                </a>
                                            )}
                                            <a href={'print.php?id=' + o.id} target="_blank" rel="noopener noreferrer" className="block px-3 py-2 hover:bg-gray-50 text-gray-700 no-underline">
                                                <i className="fas fa-print w-5 text-gray-400"></i> Print
                                            </a>
                                        </div>
                                    )}
                                </div>
                            </td>
                        ) : (
                            <td className="px-3 py-2.5 text-right whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity" onClick={(e) => e.stopPropagation()}>
                                {o.status === 'confirmed' && (
                                    <a href={'../invoices/create.php?order_id=' + o.id} className="text-gray-400 hover:text-emerald-600 me-2" title="Invoice"><i className="fas fa-file-invoice-dollar"></i></a>
                                )}
                                {showDeliveryNote(o.status) && (
                                    <a href={'delivery_note.php?id=' + o.id} target="_blank" rel="noopener noreferrer" className="text-gray-400 hover:text-indigo-600 me-2" title="Delivery note"><i className="fas fa-truck"></i></a>
                                )}
                                <a href={'print.php?id=' + o.id} target="_blank" rel="noopener noreferrer" className="text-gray-400 hover:text-gray-700 me-2" title="Print"><i className="fas fa-print"></i></a>
                                {o.status === 'draft' && (
                                    <a href={'edit.php?id=' + o.id} className="text-gray-400 hover:text-[#2563EB] me-2" title="Edit"><i className="fas fa-edit"></i></a>
                                )}
                                <a href={'view.php?id=' + o.id} className="text-gray-400 hover:text-gray-800" title="View"><i className="fas fa-eye"></i></a>
                            </td>
                        )}
                    </>
                );
            };

            const theadRowClass = useRmShell
                ? 'border-b border-gray-200 bg-slate-50 text-xs font-semibold text-gray-600 uppercase tracking-wider'
                : 'border-b-2 border-gray-200 bg-white text-sm font-bold text-gray-500 uppercase tracking-wide';

            function renderOrdersBody() {
                if (filteredOrders.length === 0) {
                    return (
                        <div className={'text-center py-20 ' + (useRmShell ? 'px-4' : 'bg-white border border-gray-100 m-4 rounded-lg')}>
                            <i className="fas fa-receipt text-4xl text-gray-300 mb-3"></i>
                            <p className="text-gray-600 font-medium text-lg">No orders found</p>
                            <p className="text-gray-400 text-base mt-1">Adjust search or filters</p>
                        </div>
                    );
                }
                if (viewMode === 'list') {
                    return (
                        <div className={'overflow-x-auto ' + (useRmShell ? 'bg-white' : 'bg-white border-t border-gray-200')}>
                            <table className={'w-full text-left border-collapse ' + (useRmShell ? 'min-w-[900px]' : 'min-w-[860px]')}>
                                <thead>
                                    <tr className={theadRowClass}>
                                        <th className={'w-10 ' + (useRmShell ? 'px-3 py-3' : 'px-3 py-2')}>
                                            <input
                                                type="checkbox"
                                                className={useRmShell ? 'q-checkbox' : 'so-checkbox'}
                                                onChange={handleSelectAll}
                                                checked={pagedOrders.length > 0 && pagedOrders.every((x) => selectedIds.has(x.id))}
                                            />
                                        </th>
                                        <th className={useRmShell ? 'px-3 py-3' : 'px-3 py-2.5'}>Number</th>
                                        <th className={(useRmShell ? 'px-3 py-3 ' : 'px-3 py-2.5 ') + 'whitespace-nowrap'}>Created</th>
                                        <th className={useRmShell ? 'px-3 py-3' : 'px-3 py-2.5'}>Customer</th>
                                        <th className={useRmShell ? 'px-3 py-3' : 'px-3 py-2.5'}>Salesperson</th>
                                        <th className={(useRmShell ? 'px-3 py-3 ' : 'px-3 py-2.5 ') + 'text-right'}>Total</th>
                                        <th className={(useRmShell ? 'px-3 py-3 ' : 'px-3 py-2.5 ') + 'text-center'}>Status</th>
                                        <th className={(useRmShell ? 'w-14 px-3 py-3 ' : 'w-28 px-3 py-2 ') + 'text-right whitespace-nowrap'}>
                                            {useRmShell ? 'Actions' : <i className="fas fa-sliders-h text-gray-400" title="Actions"></i>}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className={'divide-y divide-gray-100 ' + (useRmShell ? 'bg-white' : '')}>
                                    {pagedOrders.map((o) => (
                                        <tr key={o.id} className="hover:bg-gray-50 group cursor-pointer" onClick={() => { window.location.href = 'view.php?id=' + o.id; }}>
                                            <RowCells o={o} />
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    );
                }
                if (viewMode === 'cards') {
                    return (
                        <div className={'p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3 ' + (useRmShell ? 'bg-white' : '')}>
                            {pagedOrders.map((o) => (
                                <div key={o.id} className="bg-white border border-gray-200 rounded-lg p-4 shadow-sm hover:shadow-md transition-shadow">
                                    <div className="flex justify-between items-start gap-2 mb-2">
                                        <a href={'view.php?id=' + o.id} className="font-bold text-gray-900 text-base hover:text-[#2563EB]">{o.order_number}</a>
                                        <div className="flex flex-col items-end gap-1.5">
                                            {statusPill(o.status)}
                                        </div>
                                    </div>
                                    <p className="text-base font-medium text-gray-800 truncate">{o.company_name || 'â€”'}</p>
                                    <p className="text-sm text-gray-500 mt-1">{formatDateTime(o.created_at)}</p>
                                    <div className="flex items-center gap-2 mt-2">
                                        <span className={'h-7 w-7 shrink-0 rounded-full text-xs font-bold flex items-center justify-center ' + salespersonAvatarClasses(o.salesperson)}>{initials(o.salesperson)}</span>
                                        <span className="text-sm text-gray-600 truncate">{o.salesperson || 'â€”'}</span>
                                    </div>
                                    <p className="text-base font-semibold text-gray-900 mt-3">{formatCurrency(o.total_amount)}</p>
                                    <div className="flex flex-wrap gap-2 mt-3">
                                        <a href={'view.php?id=' + o.id} className="flex-1 text-center py-2 text-sm font-semibold rounded border border-gray-200 hover:bg-gray-50 min-w-[4rem]">View</a>
                                        <a href={'print.php?id=' + o.id} target="_blank" rel="noopener noreferrer" className="flex-1 text-center py-2 text-sm font-semibold rounded bg-gray-800 text-white hover:bg-gray-900 min-w-[4rem]">Print</a>
                                    </div>
                                </div>
                            ))}
                        </div>
                    );
                }
                return (
                    <div className={'p-3 overflow-x-auto no-scrollbar ' + (useRmShell ? 'bg-white' : '')}>
                        <div className="flex gap-3 min-w-max pb-2">
                            {BOARD_COLS.map((col) => {
                                const items = byBoard[col.key] || [];
                                return (
                                    <div key={col.key} className={'w-72 shrink-0 rounded-lg flex flex-col max-h-[72vh] ' + (useRmShell ? 'bg-gray-100/90 shadow-sm' : 'bg-white border border-gray-200')}>
                                        <div className="px-3 py-2.5 border-b border-gray-200 bg-white/95 rounded-t-lg flex justify-between items-center">
                                            <span className="text-sm font-bold text-gray-600 uppercase tracking-wide">{col.label}</span>
                                            <span className="text-xs font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">{items.length}</span>
                                        </div>
                                        <div className="p-2 space-y-2 overflow-y-auto flex-1">
                                            {items.length === 0 ? (
                                                <p className="text-sm text-gray-400 text-center py-6">â€”</p>
                                            ) : (
                                                items.map((o) => (
                                                    <div key={o.id} className="bg-white border border-gray-200 rounded-md p-3 shadow-sm">
                                                        <a href={'view.php?id=' + o.id} className="font-semibold text-base text-gray-900 hover:text-[#2563EB] block truncate">{o.order_number}</a>
                                                        <p className="text-sm text-gray-600 truncate mt-1">{o.company_name}</p>
                                                        <p className="text-sm text-gray-500 mt-1">{formatCurrency(o.total_amount)}</p>
                                                        <div className="mt-2 flex items-center gap-2">
                                                            {statusPill(o.status)}
                                                            <a href={'print.php?id=' + o.id} target="_blank" rel="noopener noreferrer" className="text-sm text-gray-500 hover:text-gray-800 ms-auto"><i className="fas fa-print"></i></a>
                                                        </div>
                                                    </div>
                                                ))
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                );
            }

            const statusSelect = (
                <select
                    value={statusFilter}
                    onChange={(e) => setStatusFilter(e.target.value)}
                    className={
                        (useRmShell ? 'text-sm border border-gray-200 rounded-full px-4 py-2.5 min-w-[150px] shadow-sm ' : 'text-base border border-gray-200 rounded-md px-2.5 py-2 shadow-sm ') +
                        'bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#2563EB]/20'
                    }
                >
                    <option value="">All statuses</option>
                    <option value="draft">Draft</option>
                    <option value="quotation">Quotation</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="invoiced">Invoiced</option>
                    <option value="processing">Processing</option>
                    <option value="shipped">Shipped</option>
                    <option value="delivered">Delivered</option>
                    <option value="paid">Paid</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            );

            const ordToolbar = (
                <div className="flex flex-wrap items-center gap-3 p-4 border-b border-gray-50 bg-white">
                    <div className="relative flex-1 min-w-[200px] max-w-md">
                        <i className="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search number, customer, contact, salespersonâ€¦"
                            className="w-full pl-9 pr-4 py-2.5 text-sm bg-white border border-gray-200 rounded-full focus:outline-none focus:ring-2 focus:ring-[#2563EB]/20 focus:border-[#2563EB] shadow-sm"
                        />
                    </div>
                    <select
                        value={myOrdersOnly ? 'mine' : 'all'}
                        onChange={(e) => setMyOrdersOnly(e.target.value === 'mine')}
                        className="text-sm border border-gray-200 rounded-full px-4 py-2.5 bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#2563EB]/20 min-w-[150px] shadow-sm"
                    >
                        <option value="all">All orders</option>
                        <option value="mine">My orders</option>
                    </select>
                    {statusSelect}
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
                        <span className="tabular-nums whitespace-nowrap px-1">{rangeStart}-{rangeEnd} of {filteredOrders.length}</span>
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
                            {canInvoice && (
                                <a
                                    href={'../invoices/create.php?order_id=' + Array.from(selectedIds)[0]}
                                    className="text-sm font-semibold px-3 py-2 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-gray-800"
                                >
                                    <i className="fas fa-file-invoice-dollar text-[#2563EB] me-1"></i>Invoice
                                </a>
                            )}
                        </div>
                    )}
                </div>
            );

            const ordFooter = (filteredOrders.length > 0 && viewMode === 'list') ? (
                <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-t border-gray-100 bg-white text-sm text-gray-600">
                    <span>
                        Showing <span className="font-semibold text-gray-800">{rangeStart}</span> to <span className="font-semibold text-gray-800">{rangeEnd}</span> of{' '}
                        <span className="font-semibold text-gray-800">{filteredOrders.length}</span> orders
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
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <h1 className="text-3xl font-bold text-gray-900 tracking-tight">Sales orders</h1>
                                        <a href="../settings/index.php" className="text-gray-400 hover:text-[#2563EB] p-1 rounded-md hover:bg-gray-100/80" title="Sales settings">
                                            <i className="fas fa-cog text-lg"></i>
                                        </a>
                                        <a href="create.php" className="text-sm font-semibold text-[#2563EB] hover:underline ms-1">Quotations</a>
                                    </div>
                                    <p className="text-gray-500 mt-1 text-base max-w-xl leading-snug">Track quotations, confirmations, and fulfilment in one place.</p>
                                </div>
                                <button
                                    type="button"
                                    onClick={handleNewClick}
                                    className="orders-new-btn inline-flex items-center justify-center gap-2 !rounded-full bg-[#7C3AED] hover:bg-[#6D28D9] text-white px-8 py-3 text-base font-semibold shadow-sm hover:shadow-md transition-colors border-0 cursor-pointer whitespace-nowrap"
                                >
                                    <i className="fas fa-plus"></i> Create quotation
                                </button>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 mb-5">
                                <div className="bg-white rounded-lg border border-gray-200 px-3.5 py-3 shadow-sm flex items-center gap-3">
                                    <div className="h-10 w-10 shrink-0 rounded-lg bg-violet-100 flex items-center justify-center text-violet-600">
                                        <i className="fas fa-shopping-bag text-base"></i>
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium text-gray-500 leading-snug">Total orders</p>
                                        <p className="text-2xl font-bold text-gray-900 mt-1 leading-tight tabular-nums">{fmtBig(orderStats.total)}</p>
                                        <p className="text-xs text-gray-400 mt-1 leading-snug">All time</p>
                                    </div>
                                </div>
                                <div className="bg-white rounded-lg border border-gray-200 px-3.5 py-3 shadow-sm flex items-center gap-3">
                                    <div className="h-10 w-10 shrink-0 rounded-lg bg-emerald-100 flex items-center justify-center text-emerald-600">
                                        <i className="fas fa-wallet text-base"></i>
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium text-gray-500 leading-snug">Total value</p>
                                        <p className="text-2xl font-bold text-gray-900 mt-1 leading-tight truncate" title={defaultCurrency + ' ' + formatCurrency(orderStats.totalVal)}>
                                            {defaultCurrency} {formatCurrency(orderStats.totalVal)}
                                        </p>
                                        <p className="text-xs text-gray-400 mt-1 leading-snug">All time</p>
                                    </div>
                                </div>
                                <div className="bg-white rounded-lg border border-gray-200 px-3.5 py-3 shadow-sm flex items-center gap-3">
                                    <div className="h-10 w-10 shrink-0 rounded-lg bg-amber-100 flex items-center justify-center text-amber-600">
                                        <i className="fas fa-spinner text-base"></i>
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium text-gray-500 leading-snug">Active pipeline</p>
                                        <p className="text-2xl font-bold text-gray-900 mt-1 leading-tight tabular-nums">{fmtBig(orderStats.pipeline)}</p>
                                        <p className="text-xs text-gray-400 mt-1 leading-snug">Not closed</p>
                                    </div>
                                </div>
                                <div className="bg-white rounded-lg border border-gray-200 px-3.5 py-3 shadow-sm flex items-center gap-3">
                                    <div className="h-10 w-10 shrink-0 rounded-lg bg-green-100 flex items-center justify-center text-green-600">
                                        <i className="fas fa-circle-check text-base"></i>
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium text-gray-500 leading-snug">Closed YTD</p>
                                        <p className="text-2xl font-bold text-gray-900 mt-1 leading-tight tabular-nums">{fmtBig(orderStats.closedYtd)}</p>
                                        <p className="text-xs text-gray-400 mt-1 leading-snug">Paid / delivered</p>
                                    </div>
                                </div>
                            </div>

                            <div className="bg-white rounded-xl shadow-sm overflow-hidden border border-gray-200">
                                {ordToolbar}
                                {renderOrdersBody()}
                                {ordFooter}
                            </div>
                        </div>
                    </div>
                );
            }

            return (
                <div className="max-w-full ml-0 animate-fade-in">
                    <div className="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
                        <div className="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                            {(isRoadmaster || useRmShell) ? (
                                <button type="button" onClick={handleNewClick} className="orders-new-btn rounded-full bg-[#7C3AED] hover:bg-[#6D28D9] text-white px-5 py-2 text-base font-bold shadow-md hover:shadow-lg transition-all duration-200 border-0 cursor-pointer inline-flex items-center gap-2">
                                    <i className="fas fa-plus text-sm"></i> Create quotation
                                </button>
                            ) : (
                                <a href="create.php?mode=new" className="orders-new-btn rounded-full bg-[#7C3AED] hover:bg-[#6D28D9] text-white px-5 py-2 text-base font-bold shadow-md hover:shadow-lg transition-all duration-200 border-0 cursor-pointer inline-flex items-center gap-2 text-decoration-none">
                                    <i className="fas fa-plus text-sm"></i> Create quotation
                                </a>
                            )}

                            <a href="create.php" className="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white">
                                Quotations
                            </a>
                            <div className="flex items-center gap-2 min-w-0">
                                <h1 className="text-xl font-bold text-gray-900 truncate">Sales Orders</h1>
                                <a href="../settings/index.php" className="text-gray-400 hover:text-[#2563EB]" title="Sales settings"><i className="fas fa-cog text-base"></i></a>
                            </div>
                            <div className="flex-1" />
                            <div className="flex items-center gap-2 text-base text-gray-600">
                                <button
                                    type="button"
                                    disabled={safePage <= 1}
                                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                                    className="p-1 rounded border border-gray-200 bg-white disabled:opacity-40 hover:bg-gray-50"
                                ><i className="fas fa-chevron-left text-sm"></i></button>
                                <span className="tabular-nums whitespace-nowrap">{rangeStart}-{rangeEnd} / {filteredOrders.length}</span>
                                <button
                                    type="button"
                                    disabled={safePage >= pageCount}
                                    onClick={() => setPage((p) => Math.min(pageCount, p + 1))}
                                    className="p-1 rounded border border-gray-200 bg-white disabled:opacity-40 hover:bg-gray-50"
                                ><i className="fas fa-chevron-right text-sm"></i></button>
                            </div>
                            <div className="flex items-center gap-0.5 bg-gray-100 rounded-lg border border-gray-200 p-0.5" role="group" aria-label="View">
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
                                    placeholder="Search number, customer, contact, salespersonâ€¦"
                                    className="w-full pl-9 pr-3 py-2 text-base bg-white border border-gray-200 rounded-md focus:outline-none focus:ring-2 focus:ring-[#2563EB]/20 focus:border-[#2563EB]"
                                />
                            </div>
                            {myOrdersOnly ? (
                                <button
                                    type="button"
                                    onClick={() => setMyOrdersOnly(false)}
                                    className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md bg-[#2563EB]/10 text-[#1D4ED8] text-sm font-semibold border border-[#2563EB]/30"
                                >
                                    My orders
                                    <i className="fas fa-times text-xs"></i>
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => setMyOrdersOnly(true)}
                                    className="text-sm font-medium text-gray-600 hover:text-[#2563EB] border border-dashed border-gray-300 rounded-md px-2.5 py-1.5 hover:border-[#2563EB]"
                                >
                                    + My orders
                                </button>
                            )}
                            {statusSelect}
                            {selectedIds.size > 0 && (
                                <div className="flex items-center gap-2 flex-wrap ms-auto">
                                    <span className="text-sm text-gray-500">{selectedIds.size} selected</span>
                                    {canInvoice && (
                                        <a
                                            href={'../invoices/create.php?order_id=' + Array.from(selectedIds)[0]}
                                            className="text-sm font-semibold px-2.5 py-1.5 rounded border border-gray-200 bg-white hover:bg-gray-50 text-gray-800"
                                        >
                                            <i className="fas fa-file-invoice-dollar text-[#2563EB] me-1"></i>Invoice
                                        </a>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="bg-transparent min-h-[50vh] pb-8">
                        {renderOrdersBody()}
                    </div>
                </div>
            );
        }

        const root = ReactDOM.createRoot(document.getElementById('react-root'));
        root.render(<OrdersApp />);
    </script>
</body>
</html>

