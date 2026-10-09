<?php
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../functions.php';

if (session_status() == PHP_SESSION_NONE) session_start();
$_SESSION['active_module'] = 'sales';

requireAdmin();

ensureSalesCommissionsSchema();

function ensureSalesReassignmentsSchema() {
    global $pdo;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sales_reassignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_user_id INT NOT NULL,
            to_user_id INT NOT NULL,
            performed_by INT NULL,
            date_from DATE NULL,
            date_to DATE NULL,
            move_invoices TINYINT(1) NOT NULL DEFAULT 1,
            move_orders TINYINT(1) NOT NULL DEFAULT 1,
            move_commissions TINYINT(1) NOT NULL DEFAULT 0,
            invoices_moved INT NOT NULL DEFAULT 0,
            orders_moved INT NOT NULL DEFAULT 0,
            commissions_moved INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (Exception $e) {
        // ignore
    }
}

function buildInPlaceholders(array $ids): string {
    $ids = array_values(array_filter($ids, fn($v) => is_int($v) && $v > 0));
    if (count($ids) === 0) return '(NULL)';
    return '(' . implode(',', array_fill(0, count($ids), '?')) . ')';
}

// Load users
$users = [];
try {
    $users = $pdo->query("SELECT id, username, full_name FROM users ORDER BY full_name, username")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback if full_name missing
    try {
        $users = $pdo->query("SELECT id, username, username AS full_name FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {
        $users = [];
    }
}

$result = null;
$error = null;
$available = ['invoices' => [], 'orders' => [], 'commissions' => []];

function fetchAvailableForUser($fromId, $dateFrom, $dateTo, $moveInvoices, $moveOrders, $moveCommissions) {
    global $pdo;
    $out = ['invoices' => [], 'orders' => [], 'commissions' => []];

    if ($moveInvoices) {
        $sql = "SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.status,
                       c.company_name AS customer_name
                FROM invoices i
                LEFT JOIN customers c ON i.customer_id = c.id
                WHERE i.created_by = ?";
        $params = [$fromId];
        if ($dateFrom) { $sql .= " AND i.invoice_date >= ?"; $params[] = $dateFrom; }
        if ($dateTo)   { $sql .= " AND i.invoice_date <= ?"; $params[] = $dateTo; }
        $sql .= " ORDER BY i.invoice_date DESC, i.id DESC LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out['invoices'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($moveOrders) {
        $sql = "SELECT so.id, so.order_number, so.quote_date, so.total_amount, so.status,
                       c.company_name AS customer_name
                FROM sales_orders so
                LEFT JOIN customers c ON so.customer_id = c.id
                WHERE so.created_by = ?";
        $params = [$fromId];
        if ($dateFrom) { $sql .= " AND so.quote_date >= ?"; $params[] = $dateFrom; }
        if ($dateTo)   { $sql .= " AND so.quote_date <= ?"; $params[] = $dateTo; }
        $sql .= " ORDER BY so.quote_date DESC, so.id DESC LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out['orders'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($moveCommissions) {
        $sql = "SELECT id, commission_amount, created_at
                FROM sales_commissions
                WHERE sales_rep_id = ?";
        $params = [$fromId];
        if ($dateFrom) { $sql .= " AND DATE(created_at) >= ?"; $params[] = $dateFrom; }
        if ($dateTo)   { $sql .= " AND DATE(created_at) <= ?"; $params[] = $dateTo; }
        $sql .= " ORDER BY created_at DESC, id DESC LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out['commissions'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    return $out;
}

function applyMoveSelected(int $fromId, int $toId, array $invoiceIds, array $orderIds, array $commissionIds): array {
    global $pdo;
    $moved = ['invoices' => 0, 'orders' => 0, 'commissions' => 0];

    $pdo->beginTransaction();
    try {
        $invoiceIds = array_values(array_filter(array_map('intval', $invoiceIds), fn($v) => $v > 0));
        $orderIds = array_values(array_filter(array_map('intval', $orderIds), fn($v) => $v > 0));
        $commissionIds = array_values(array_filter(array_map('intval', $commissionIds), fn($v) => $v > 0));

        if (count($invoiceIds) > 0) {
            $in = buildInPlaceholders($invoiceIds);
            $sql = "UPDATE invoices SET created_by = ? WHERE created_by = ? AND id IN $in";
            $params = array_merge([$toId, $fromId], $invoiceIds);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $moved['invoices'] = $stmt->rowCount();
        }

        if (count($orderIds) > 0) {
            $in = buildInPlaceholders($orderIds);
            $sql = "UPDATE sales_orders SET created_by = ? WHERE created_by = ? AND id IN $in";
            $params = array_merge([$toId, $fromId], $orderIds);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $moved['orders'] = $stmt->rowCount();
        }

        if (count($commissionIds) > 0) {
            $in = buildInPlaceholders($commissionIds);
            $sql = "UPDATE sales_commissions SET sales_rep_id = ? WHERE sales_rep_id = ? AND id IN $in";
            $params = array_merge([$toId, $fromId], $commissionIds);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $moved['commissions'] = $stmt->rowCount();
        }

        $pdo->commit();
        return $moved;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromUser = (int)($_POST['from_user_id'] ?? 0);
    $toUser = (int)($_POST['to_user_id'] ?? 0);
    $dateFrom = trim((string)($_POST['date_from'] ?? ''));
    $dateTo = trim((string)($_POST['date_to'] ?? ''));
    $dateFrom = $dateFrom !== '' ? $dateFrom : null;
    $dateTo = $dateTo !== '' ? $dateTo : null;

    $moveInvoices = isset($_POST['move_invoices']);
    $moveOrders = isset($_POST['move_orders']);
    $moveCommissions = isset($_POST['move_commissions']);

    $action = $_POST['action'] ?? 'preview';

    $selectedInvoiceIds = $_POST['invoice_ids'] ?? [];
    $selectedOrderIds = $_POST['order_ids'] ?? [];
    $selectedCommissionIds = $_POST['commission_ids'] ?? [];
    if (!is_array($selectedInvoiceIds)) $selectedInvoiceIds = [$selectedInvoiceIds];
    if (!is_array($selectedOrderIds)) $selectedOrderIds = [$selectedOrderIds];
    if (!is_array($selectedCommissionIds)) $selectedCommissionIds = [$selectedCommissionIds];

    try {
        if ($action === 'apply') {
            if ($fromUser <= 0 || $toUser <= 0 || $fromUser === $toUser) {
                throw new Exception("Please select two different users.");
            }
            if (!$moveInvoices && !$moveOrders && !$moveCommissions) {
                throw new Exception("Select at least one item type to move.");
            }
        } else {
            // Preview mode: allow selecting From user first, then choosing Move options.
            if ($fromUser <= 0) {
                throw new Exception("Select From user first.");
            }
        }

        $available = ($moveInvoices || $moveOrders || $moveCommissions)
            ? fetchAvailableForUser($fromUser, $dateFrom, $dateTo, $moveInvoices, $moveOrders, $moveCommissions)
            : ['invoices' => [], 'orders' => [], 'commissions' => []];
        $counts = [
            'invoices' => count($available['invoices']),
            'orders' => count($available['orders']),
            'commissions' => count($available['commissions']),
        ];

        if ($action === 'apply') {
            if ($moveInvoices && count(array_filter(array_map('intval', $selectedInvoiceIds))) === 0) {
                throw new Exception("Select at least one invoice to move.");
            }
            if ($moveOrders && count(array_filter(array_map('intval', $selectedOrderIds))) === 0) {
                throw new Exception("Select at least one order/quotation to move.");
            }
            if ($moveCommissions && count(array_filter(array_map('intval', $selectedCommissionIds))) === 0) {
                throw new Exception("Select at least one commission row to move.");
            }

            ensureSalesReassignmentsSchema();
            $moved = applyMoveSelected($fromUser, $toUser, $moveInvoices ? $selectedInvoiceIds : [], $moveOrders ? $selectedOrderIds : [], $moveCommissions ? $selectedCommissionIds : []);

            try {
                $stmt = $pdo->prepare("INSERT INTO sales_reassignments
                    (from_user_id, to_user_id, performed_by, date_from, date_to, move_invoices, move_orders, move_commissions, invoices_moved, orders_moved, commissions_moved)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $fromUser,
                    $toUser,
                    (int)($_SESSION['user_id'] ?? 0) ?: null,
                    $dateFrom,
                    $dateTo,
                    $moveInvoices ? 1 : 0,
                    $moveOrders ? 1 : 0,
                    $moveCommissions ? 1 : 0,
                    (int)$moved['invoices'],
                    (int)$moved['orders'],
                    (int)$moved['commissions'],
                ]);
            } catch (Exception $e) {
                // ignore audit failures
            }

            $result = [
                'mode' => 'applied',
                'counts' => $counts,
                'moved' => $moved,
                'available' => $available,
            ];
        } else {
            $result = [
                'mode' => 'preview',
                'counts' => $counts,
                'available' => $available,
            ];
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$backSalesUrl = function_exists('sales_module_url')
    ? sales_module_url('orders/index.php')
    : (defined('APP_BASE_PATH') ? rtrim((string) APP_BASE_PATH, '/') . '/modules/sales/orders/index.php' : '/modules/sales/orders/index.php');
$reassignPageUrl = function_exists('sales_module_url')
    ? sales_module_url('admin/reassign-sales.php', ['module' => 'sales'])
    : (defined('APP_BASE_PATH') ? rtrim((string) APP_BASE_PATH, '/') . '/modules/sales/admin/reassign-sales.php?module=sales' : '/modules/sales/admin/reassign-sales.php?module=sales');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reassign Sales | Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { corePlugins: { preflight: false } };
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="/stock/assets/css/style.css" rel="stylesheet">
    <link href="/assets/css/sales-mobile.css" rel="stylesheet">
    
    <!-- React & Babel -->
    <script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone@7.23.9/babel.min.js"></script>

    <style>
        body { 
            background-color: #F9F9F9; 
            font-family: 'Outfit', sans-serif;
            color: #1e293b;
        }
        .main-content { padding: 0; }
        .prod-shell {
            font-family: 'Outfit', system-ui, -apple-system, sans-serif;
            font-size: 16px;
            color: #374151;
        }
        .prod-btn-primary {
            background-color: #2563EB !important;
            color: #fff !important;
            border-color: #2563EB !important;
        }
        .prod-btn-primary:hover {
            background-color: #1D4ED8 !important;
            border-color: #1D4ED8 !important;
            color: #fff !important;
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeIn 0.4s ease-out forwards; }
        .custom-scrollbar::-webkit-scrollbar { width: 5px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }

        .rs-kpi { min-width: 0; min-height: 4.75rem; padding: 0.7rem 0.9rem; background: #fff; border: 1px solid #e7edf3; border-radius: 14px; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
        .rs-kpi-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .rs-kpi-label { font-size: 0.8rem; font-weight: 650; color: #64748b; }
        .rs-kpi-icon { width: 28px; height: 28px; flex-shrink: 0; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; }
        .rs-kpi-value { display: block; margin: 0.45rem 0 0.3rem; font-size: 1.2rem; font-weight: 780; letter-spacing: -0.02em; line-height: 1.15; color: #111827; }
        .rs-kpi-foot { font-size: 0.74rem; color: #475569; font-weight: 550; }
        .rs-kpi--blue .rs-kpi-icon { background: #eef4ff; color: #2563eb; }
        .rs-kpi--orange .rs-kpi-icon { background: #fff7eb; color: #d97706; }
        .rs-kpi--green .rs-kpi-icon { background: #ecfbf3; color: #16a34a; }

        .rs-table { border-collapse: separate; border-spacing: 0; }
        .rs-table thead th { background: #1e293b; color: #fff; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; text-align: left; white-space: nowrap; padding: 0.72rem 0.75rem; border: 0; }
        .rs-table tbody td { background: #fff; border-bottom: 1px solid #eef2f6; vertical-align: middle; }
        .rs-table tbody tr:nth-child(even) td { background: #f8fafc; }
        .rs-table tbody tr:hover td { background: #eef4ff; }
        .rs-table tbody tr:last-child td { border-bottom: 0; }
        .rs-table .rs-col-check { width: 2.75rem; text-align: center; }
        .rs-table .rs-amt { text-align: right; font-weight: 650; color: #0f172a; font-variant-numeric: tabular-nums; }
        .rs-table input[type="checkbox"] { accent-color: #2563eb; width: 1rem; height: 1rem; cursor: pointer; }

        html[data-theme="dark"] .rs-kpi--blue { background: linear-gradient(135deg, #1c2a44 0%, #1e3a6e 100%); border-color: #2c4a80; }
        html[data-theme="dark"] .rs-kpi--orange { background: linear-gradient(135deg, #3a2a14 0%, #5a3a12 100%); border-color: #6e4a1a; }
        html[data-theme="dark"] .rs-kpi--green { background: linear-gradient(135deg, #163226 0%, #1b4a33 100%); border-color: #25603f; }
        html[data-theme="dark"] .rs-kpi { box-shadow: none; }
        html[data-theme="dark"] .rs-kpi-label, html[data-theme="dark"] .rs-kpi-foot { color: #e2e8f0; }
        html[data-theme="dark"] .rs-kpi-icon { color: #fff; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.25); }
        html[data-theme="dark"] .rs-kpi--blue .rs-kpi-icon { background: #2563eb; }
        html[data-theme="dark"] .rs-kpi--orange .rs-kpi-icon { background: #ea8a0c; }
        html[data-theme="dark"] .rs-kpi--green .rs-kpi-icon { background: #16a34a; }
        html[data-theme="dark"] .rs-kpi-value { color: #f8fafc; }
        html[data-theme="dark"] .rs-table thead th { background: #0f172a; }
        html[data-theme="dark"] .rs-table tbody td,
        html[data-theme="dark"] .rs-table tbody tr:nth-child(even) td { background: #1e293b; color: #e2e8f0; border-color: #334155; }
        html[data-theme="dark"] .rs-table tbody tr:hover td { background: #334155; }
        html[data-theme="dark"] .rs-table .rs-amt { color: #f8fafc; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../../includes/header_employee.php'; ?>
    
    <main class="main-content prod-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
        <div class="max-w-full mx-auto">
            <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
                <div class="px-4 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100">
                    <a href="<?= htmlspecialchars($backSalesUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                        <i class="fas fa-arrow-left text-sm"></i> Sales
                    </a>
                    <div class="flex items-center gap-2 min-w-0">
                        <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                            <i class="fas fa-people-arrows text-[#2563EB]"></i><span>Reassign sales</span>
                        </h1>
                    </div>
                    <div class="flex-1 min-w-[8px]"></div>
                    <a href="<?= htmlspecialchars($reassignPageUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn prod-btn-primary px-4 py-2 rounded-md text-base font-semibold shadow-sm inline-flex items-center gap-2 border-0 no-underline">
                        <i class="fas fa-rotate-right text-sm"></i> Refresh
                    </a>
                </div>
                <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-base bg-gray-50/80 border-b border-gray-100">
                    <span class="text-gray-600"><i class="fas fa-info-circle text-gray-400 me-1"></i>Move invoices, quotations, and commission rows from one user to another.</span>
                </div>
            </div>

            <div class="px-4 pt-4">
                <div id="react-root"></div>
            </div>
        </div>
    </main>

    <script>
        window.APP_DATA = {
            users: <?= json_encode($users) ?>,
            available: <?= json_encode($available) ?>,
            result: <?= json_encode($result) ?>,
            error: <?= json_encode($error) ?>,
            formData: <?= json_encode($_POST) ?>,
            selectedInvoices: <?= json_encode(array_map('intval', (array)($selectedInvoiceIds ?? []))) ?>,
            selectedOrders: <?= json_encode(array_map('intval', (array)($selectedOrderIds ?? []))) ?>,
            selectedCommissions: <?= json_encode(array_map('intval', (array)($selectedCommissionIds ?? []))) ?>
        };
    </script>

    <script type="text/babel">
        const { useState, useEffect, useMemo } = React;

        function formatCurrency(amount) {
            return new Intl.NumberFormat('en-TZ', {
                style: 'currency',
                currency: 'TZS',
                minimumFractionDigits: 0
            }).format(amount);
        }

        function ReassignApp() {
            const data = window.APP_DATA;
            const [fromUser, setFromUser] = useState(data.formData.from_user_id || '');
            const [toUser, setToUser] = useState(data.formData.to_user_id || '');
            const [dateFrom, setDateFrom] = useState(data.formData.date_from || '');
            const [dateTo, setDateTo] = useState(data.formData.date_to || '');
            
            const [moveInvoices, setMoveInvoices] = useState(data.formData.hasOwnProperty('move_invoices'));
            const [moveOrders, setMoveOrders] = useState(data.formData.hasOwnProperty('move_orders'));
            const [moveCommissions, setMoveCommissions] = useState(data.formData.hasOwnProperty('move_commissions'));

            const [selectedInvoices, setSelectedInvoices] = useState(data.selectedInvoices);
            const [selectedOrders, setSelectedOrders] = useState(data.selectedOrders);
            const [selectedCommissions, setSelectedCommissions] = useState(data.selectedCommissions);

            const [search, setSearch] = useState('');

            // Filter available items based on search
            const filteredInvoices = useMemo(() => {
                const list = data.available.invoices || [];
                if (!search) return list;
                const s = search.toLowerCase();
                return list.filter(i => 
                    i.invoice_number.toLowerCase().includes(s) || 
                    (i.customer_name || '').toLowerCase().includes(s)
                );
            }, [search, data.available.invoices]);

            const filteredOrders = useMemo(() => {
                const list = data.available.orders || [];
                if (!search) return list;
                const s = search.toLowerCase();
                return list.filter(o => 
                    o.order_number.toLowerCase().includes(s) || 
                    (o.customer_name || '').toLowerCase().includes(s)
                );
            }, [search, data.available.orders]);

            const toggleSelection = (id, type) => {
                const setters = {
                    invoices: [selectedInvoices, setSelectedInvoices],
                    orders: [selectedOrders, setSelectedOrders],
                    commissions: [selectedCommissions, setSelectedCommissions]
                };
                const [selection, setSelection] = setters[type];
                if (selection.includes(id)) {
                    setSelection(selection.filter(x => x !== id));
                } else {
                    setSelection([...selection, id]);
                }
            };

            const toggleAll = (type) => {
                const lists = {
                    invoices: filteredInvoices,
                    orders: filteredOrders,
                    commissions: data.available.commissions || []
                };
                const selections = {
                    invoices: [selectedInvoices, setSelectedInvoices],
                    orders: [selectedOrders, setSelectedOrders],
                    commissions: [selectedCommissions, setSelectedCommissions]
                };
                const items = lists[type];
                const [current, set] = selections[type];
                
                const allVisibleIds = items.map(x => parseInt(x.id));
                const allSelected = allVisibleIds.every(id => current.includes(id));

                if (allSelected) {
                    set(current.filter(id => !allVisibleIds.includes(id)));
                } else {
                    set([...new Set([...current, ...allVisibleIds])]);
                }
            };

            const handleSubmit = (e, action = 'preview') => {
                if (action === 'preview' && e) e.preventDefault();
                
                // We'll use a hidden form to submit if it's truly a submit action
                if (action === 'apply') {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    
                    const addInput = (name, value) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        input.value = value;
                        form.appendChild(input);
                    };

                    addInput('action', 'apply');
                    addInput('from_user_id', fromUser);
                    addInput('to_user_id', toUser);
                    addInput('date_from', dateFrom);
                    addInput('date_to', dateTo);
                    if (moveInvoices) addInput('move_invoices', 'on');
                    if (moveOrders) addInput('move_orders', 'on');
                    if (moveCommissions) addInput('move_commissions', 'on');
                    
                    selectedInvoices.forEach(id => addInput('invoice_ids[]', id));
                    selectedOrders.forEach(id => addInput('order_ids[]', id));
                    selectedCommissions.forEach(id => addInput('commission_ids[]', id));

                    document.body.appendChild(form);
                    
                    Swal.fire({
                        title: 'Are you sure?',
                        text: `You are about to reassign ${selectedInvoices.length + selectedOrders.length + selectedCommissions.length} items.`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#4f46e5',
                        cancelButtonColor: '#94a3b8',
                        confirmButtonText: 'Yes, move them'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            form.submit();
                        }
                    });
                } else {
                    // Just refresh page with preview data
                    const form = document.getElementById('main-form');
                    const actionField = document.getElementById('action-field');
                    actionField.value = 'preview';
                    form.submit();
                }
            };

            const activeRows = {
                invoices: moveInvoices ? filteredInvoices : [],
                orders: moveOrders ? filteredOrders : [],
                commissions: moveCommissions ? (data.available.commissions || []) : []
            };
            const totals = {
                invoices: (data.available.invoices || []).reduce((s, x) => s + (parseFloat(x.total_amount) || 0), 0),
                orders: (data.available.orders || []).reduce((s, x) => s + (parseFloat(x.total_amount) || 0), 0),
                commissions: (data.available.commissions || []).reduce((s, x) => s + (parseFloat(x.commission_amount) || 0), 0),
            };
            const [activeTab, setActiveTab] = useState('invoices');
            const selectedCount = selectedInvoices.length + selectedOrders.length + selectedCommissions.length;

            const renderTableRows = () => {
                if (activeTab === 'invoices') {
                    return activeRows.invoices.map(inv => (
                        <tr key={`inv-${inv.id}`}>
                            <td className="px-3 py-2 rs-col-check"><input type="checkbox" checked={selectedInvoices.includes(parseInt(inv.id))} onChange={() => toggleSelection(parseInt(inv.id), 'invoices')} /></td>
                            <td className="px-3 py-2 text-xs font-semibold text-slate-500">Invoice</td>
                            <td className="px-3 py-2 text-xs font-bold text-blue-600">{inv.invoice_number}</td>
                            <td className="px-3 py-2 text-xs text-slate-700">{inv.customer_name || '-'}</td>
                            <td className="px-3 py-2 text-xs rs-amt">{formatCurrency(inv.total_amount)}</td>
                            <td className="px-3 py-2 text-xs text-slate-500">{inv.invoice_date}</td>
                        </tr>
                    ));
                }
                if (activeTab === 'orders') {
                    return activeRows.orders.map(ord => (
                        <tr key={`ord-${ord.id}`}>
                            <td className="px-3 py-2 rs-col-check"><input type="checkbox" checked={selectedOrders.includes(parseInt(ord.id))} onChange={() => toggleSelection(parseInt(ord.id), 'orders')} /></td>
                            <td className="px-3 py-2 text-xs font-semibold text-slate-500">Order</td>
                            <td className="px-3 py-2 text-xs font-bold text-amber-600">{ord.order_number}</td>
                            <td className="px-3 py-2 text-xs text-slate-700">{ord.customer_name || '-'}</td>
                            <td className="px-3 py-2 text-xs rs-amt">{formatCurrency(ord.total_amount)}</td>
                            <td className="px-3 py-2 text-xs text-slate-500">{ord.quote_date}</td>
                        </tr>
                    ));
                }
                return activeRows.commissions.map(comm => (
                    <tr key={`com-${comm.id}`}>
                        <td className="px-3 py-2 rs-col-check"><input type="checkbox" checked={selectedCommissions.includes(parseInt(comm.id))} onChange={() => toggleSelection(parseInt(comm.id), 'commissions')} /></td>
                        <td className="px-3 py-2 text-xs font-semibold text-slate-500">Commission</td>
                        <td className="px-3 py-2 text-xs font-bold text-emerald-600">COM-{comm.id}</td>
                        <td className="px-3 py-2 text-xs text-slate-700">Commission row</td>
                        <td className="px-3 py-2 text-xs rs-amt">{formatCurrency(comm.commission_amount)}</td>
                        <td className="px-3 py-2 text-xs text-slate-500">{comm.created_at}</td>
                    </tr>
                ));
            };

            return (
                <div className="animate-fade-in space-y-5">
                    {data.error && <div className="p-3 bg-red-50 border border-red-200 rounded-xl text-sm text-red-700">{data.error}</div>}
                    {data.result?.mode === 'applied' && <div className="p-3 bg-emerald-50 border border-emerald-200 rounded-xl text-sm text-emerald-700">Moved {data.result.moved.invoices} invoices, {data.result.moved.orders} orders and {data.result.moved.commissions} commissions.</div>}

                    <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                        {[
                            { tone: 'blue', label: 'Invoices Ready', icon: 'fa-file-invoice', count: (data.available.invoices || []).length, total: totals.invoices },
                            { tone: 'orange', label: 'Orders Ready', icon: 'fa-file-signature', count: (data.available.orders || []).length, total: totals.orders },
                            { tone: 'green', label: 'Commission Rows', icon: 'fa-hand-holding-dollar', count: (data.available.commissions || []).length, total: totals.commissions },
                        ].map(card => (
                            <div key={card.label} className={`rs-kpi rs-kpi--${card.tone}`}>
                                <div className="rs-kpi-top">
                                    <span className="rs-kpi-label">{card.label}</span>
                                    <span className="rs-kpi-icon" aria-hidden="true"><i className={`fas ${card.icon}`}></i></span>
                                </div>
                                <strong className="rs-kpi-value">{card.count}</strong>
                                <div className="rs-kpi-foot">Total amount {formatCurrency(card.total)}</div>
                            </div>
                        ))}
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-4">
                        <div className="lg:col-span-4 bg-white border border-slate-200 rounded-xl p-4">
                            <h3 className="text-sm font-bold mb-1">Transfer Setup</h3>
                            <p className="text-xs text-slate-500 mb-4">Choose users, item types and date range.</p>
                            <form id="main-form" method="POST" className="space-y-3">
                                <input type="hidden" name="action" id="action-field" value="preview" />
                                <div>
                                    <label className="text-[11px] font-semibold text-slate-500">From User</label>
                                    <select name="from_user_id" value={fromUser} onChange={(e) => { setFromUser(e.target.value); if (e.target.value) handleSubmit(); }} className="w-full mt-1 border border-slate-200 rounded-lg px-3 py-2 text-sm" required>
                                        <option value="">Choose source user...</option>
                                        {data.users.map(u => <option key={u.id} value={u.id}>{u.full_name || u.username}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <label className="text-[11px] font-semibold text-slate-500">To User</label>
                                    <select name="to_user_id" value={toUser} onChange={(e) => setToUser(e.target.value)} className="w-full mt-1 border border-slate-200 rounded-lg px-3 py-2 text-sm">
                                        <option value="">Choose target user...</option>
                                        {data.users.map(u => <option key={u.id} value={u.id}>{u.full_name || u.username}</option>)}
                                    </select>
                                </div>
                                <div className="grid grid-cols-3 gap-2">
                                    <label className="border rounded-lg p-2 text-xs"><input type="checkbox" name="move_invoices" checked={moveInvoices} onChange={(e) => { setMoveInvoices(e.target.checked); handleSubmit(); }} /> Invoices</label>
                                    <label className="border rounded-lg p-2 text-xs"><input type="checkbox" name="move_orders" checked={moveOrders} onChange={(e) => { setMoveOrders(e.target.checked); handleSubmit(); }} /> Orders/Quotes</label>
                                    <label className="border rounded-lg p-2 text-xs"><input type="checkbox" name="move_commissions" checked={moveCommissions} onChange={(e) => { setMoveCommissions(e.target.checked); handleSubmit(); }} /> Commissions</label>
                                </div>
                                <div className="grid grid-cols-2 gap-2">
                                    <input type="date" name="date_from" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                                    <input type="date" name="date_to" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="border border-slate-200 rounded-lg px-3 py-2 text-sm" />
                                </div>
                                <div className="bg-amber-50 text-amber-700 border border-amber-100 rounded-lg p-2 text-xs">Selected items will be transferred to the target user and removed from current owner.</div>
                                <div className="flex gap-2">
                                    <button type="submit" onClick={(e) => handleSubmit(e, 'preview')} className="flex-1 border border-slate-200 rounded-lg px-3 py-2 text-sm">Preview</button>
                                    <button type="button" disabled={!toUser || selectedCount === 0} onClick={(e) => handleSubmit(e, 'apply')} className="flex-1 bg-blue-600 text-white disabled:bg-slate-200 rounded-lg px-3 py-2 text-sm">Apply Reassignment</button>
                                </div>
                            </form>
                        </div>

                        <div className="lg:col-span-8 bg-white border border-slate-200 rounded-xl overflow-hidden">
                            <div className="p-4 border-b border-slate-100 flex flex-wrap gap-2 items-center justify-between">
                                <div>
                                    <h3 className="text-sm font-bold">Available Items</h3>
                                    <p className="text-xs text-slate-500">Select the records to transfer to the target user.</p>
                                </div>
                                <input type="text" placeholder="Search by reference, customer..." value={search} onChange={(e) => setSearch(e.target.value)} className="border border-slate-200 rounded-lg px-3 py-2 text-sm w-64" />
                            </div>
                            <div className="px-4 py-2 border-b border-slate-100 flex gap-2">
                                <button onClick={() => setActiveTab('invoices')} className={`px-3 py-1.5 rounded-lg text-xs font-semibold ${activeTab === 'invoices' ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-600'}`}>Invoices {(activeRows.invoices || []).length}</button>
                                <button onClick={() => setActiveTab('orders')} className={`px-3 py-1.5 rounded-lg text-xs font-semibold ${activeTab === 'orders' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600'}`}>Orders/Quotes {(activeRows.orders || []).length}</button>
                                <button onClick={() => setActiveTab('commissions')} className={`px-3 py-1.5 rounded-lg text-xs font-semibold ${activeTab === 'commissions' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>Commissions {(activeRows.commissions || []).length}</button>
                                <div className="ml-auto text-xs text-slate-500 self-center">{selectedCount} items selected</div>
                            </div>
                            <div className="overflow-auto">
                                <table className="w-full rs-table">
                                    <thead>
                                        <tr>
                                            <th className="rs-col-check" aria-label="Select"></th>
                                            <th>Type</th>
                                            <th>Reference No.</th>
                                            <th>Customer</th>
                                            <th className="rs-amt">Amount</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {renderTableRows()}
                                        {((activeTab === 'invoices' && activeRows.invoices.length === 0) || (activeTab === 'orders' && activeRows.orders.length === 0) || (activeTab === 'commissions' && activeRows.commissions.length === 0)) && (
                                            <tr><td colSpan="6" className="px-3 py-10 text-center text-sm text-slate-400">No items found for this tab.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            );
        }

        const root = ReactDOM.createRoot(document.getElementById('react-root'));
        root.render(<ReassignApp />);
    </script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>


