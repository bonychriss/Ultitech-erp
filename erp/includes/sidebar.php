<?php
// Sidebar component for ERP system
// Calculate path depth to erp root
$scriptPath = $_SERVER['SCRIPT_NAME'];
$depth = substr_count(str_replace('/erp/', '', $scriptPath), '/');
$baseHref = str_repeat('../', $depth);
?>
<style>
    /* Sidebar Styles */
    .sidebar {
        width: 220px;
        background: #1a1c20;
        color: #e8eaed;
        flex-shrink: 0;
        display: flex;
        flex-direction: column;
        position: fixed;
        height: 100vh;
        overflow-y: auto;
        left: 0;
        top: 0;
        z-index: 999;
    }

    /* Modern Ultra-Thin Scrollbar */
    .sidebar::-webkit-scrollbar {
        width: 4px;
    }

    .sidebar::-webkit-scrollbar-track {
        background: transparent;
    }

    .sidebar::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 2px;
        transition: background 0.2s ease;
    }

    .sidebar::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.4);
    }

    /* Firefox */
    .sidebar {
        scrollbar-width: thin;
        scrollbar-color: rgba(255, 255, 255, 0.2) transparent;
    }

    /* Apply to all scrollable elements */
    *::-webkit-scrollbar {
        width: 6px;
        height: 6px;
    }

    *::-webkit-scrollbar-track {
        background: transparent;
    }

    *::-webkit-scrollbar-thumb {
        background: rgba(0, 0, 0, 0.2);
        border-radius: 3px;
        transition: background 0.2s ease;
    }

    *::-webkit-scrollbar-thumb:hover {
        background: rgba(0, 0, 0, 0.4);
    }

    * {
        scrollbar-width: thin;
        scrollbar-color: rgba(0, 0, 0, 0.2) transparent;
    }

    .sidebar-header {
        padding: 20px;
        border-bottom: 1px solid #3c4043;
        font-size: 1.25rem;
        font-weight: 600;
        color: white;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .sidebar-nav {
        padding: 10px 0;
        flex: 1;
    }

    .nav-item {
        display: flex;
        align-items: center;
        padding: 12px 20px;
        color: #9aa0a6;
        text-decoration: none;
        transition: all 0.2s;
        font-size: 0.9rem;
    }

    .nav-item:hover,
    .nav-item.active {
        background: #303134;
        color: white;
    }

    .nav-icon {
        margin-right: 10px;
        font-size: 1rem;
        width: 20px;
        text-align: center;
        color: #e8eaed;
    }

    .nav-item:hover .nav-icon,
    .nav-item.active .nav-icon {
        color: white;
    }

    /* Section Dividers */
    .nav-section-title {
        padding: 16px 20px 8px 20px;
        font-size: 0.75rem;
        font-weight: 600;
        color: #5f6368;
        letter-spacing: 0.5px;
        margin-top: 8px;
    }

    .nav-section-title:first-of-type {
        margin-top: 0;
    }

    /* Ensure main content has proper spacing */
    body {
        margin: 0;
        padding: 0;
        overflow-x: hidden;
    }

    .page-wrapper {
        margin-left: 220px !important;
        min-height: 100vh;
        width: calc(100% - 220px) !important;
        box-sizing: border-box;
    }

    .main-content {
        margin-left: 220px !important;
        width: calc(100% - 220px) !important;
        padding: 24px;
        min-height: 100vh;
        box-sizing: border-box;
    }

    .container {
        max-width: 100%;
        margin: 0;
        padding: 24px;
        box-sizing: border-box;
    }

    .header {
        margin: 0 !important;
        width: 100% !important;
        box-sizing: border-box;
    }

    /* Ensure tables and cards are responsive */
    .section,
    .card,
    .stat-card {
        max-width: 100%;
        box-sizing: border-box;
    }

    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-220px);
            transition: transform 0.3s ease;
        }

        .page-wrapper,
        .main-content,
        .container,
        .header {
            margin-left: 0 !important;
            width: 100% !important;
        }
    }
</style>

<?php
// For all pages except the main ERP dashboard, remove the large header bar and title
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$isDashboard = (bool) preg_match('#/erp/index\.php$#', str_replace('\\', '/', $script));
if (!$isDashboard): ?>
    <style>
        .header {
            background: transparent !important;
            border: none !important;
            padding: 0 24px !important;
        }

        .header h1 {
            display: none !important;
        }

        .header .header-actions {
            margin: 12px 0 8px 0;
        }

        /* Ensure the next container pulls up neatly */
        .container {
            padding-top: 8px !important;
        }

        .card-header {
            border-top: 1px solid #e0e0e0;
        }

        @media (max-width: 768px) {
            .header {
                padding: 0 16px !important;
            }
        }
    </style>
<?php endif; ?>

<div class="sidebar">
    <div class="sidebar-header">
        <span><i class="fas fa-rocket"></i> ERP System</span>
    </div>
    <div class="sidebar-nav">
        <!-- Dashboard -->
        <a href="<?= $baseHref ?>index.php" class="nav-item">
            <span class="nav-icon"><i class="fas fa-home"></i></span> Dashboard
        </a>

        <!-- Section Divider -->
        <div class="nav-section-title">SALES</div>

        <a href="<?= $baseHref ?>crm/leads.php" class="nav-item" data-path="crm/leads.php">
            <span class="nav-icon"><i class="fas fa-bullseye"></i></span> Leads
        </a>
        <a href="<?= $baseHref ?>crm/opportunities.php" class="nav-item" data-path="crm/opportunities.php">
            <span class="nav-icon"><i class="fas fa-briefcase"></i></span> Opportunities
        </a>
        <a href="<?= $baseHref ?>sales/quotes.php" class="nav-item" data-path="sales/quotes.php">
            <span class="nav-icon"><i class="fas fa-file-alt"></i></span> Quotations
        </a>
        <a href="<?= $baseHref ?>sales/sales-orders.php" class="nav-item" data-path="sales/sales-orders.php">
            <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span> Sales Orders
        </a>
        <a href="<?= $baseHref ?>sales/invoices.php" class="nav-item" data-path="sales/invoices.php">
            <span class="nav-icon"><i class="fas fa-file-invoice"></i></span> Invoices
        </a>
        <a href="<?= $baseHref ?>customers/list.php" class="nav-item" data-path="customers/list.php">
            <span class="nav-icon"><i class="fas fa-users"></i></span> Customers
        </a>

        <!-- Section Divider -->
        <div class="nav-section-title">OPERATIONS</div>

        <a href="<?= $baseHref ?>purchasing/requests.php" class="nav-item" data-path="purchasing/requests.php">
            <span class="nav-icon"><i class="fas fa-hand-holding-usd"></i></span> Purchase Requests
        </a>
        <a href="<?= $baseHref ?>products/list.php" class="nav-item" data-path="products/list.php">
            <span class="nav-icon"><i class="fas fa-box"></i></span> Products & Inventory
        </a>
        <a href="<?= $baseHref ?>../deliveries/delivery_notes.php" class="nav-item" data-path="deliveries/delivery_notes.php">
            <span class="nav-icon"><i class="fas fa-truck"></i></span> Deliveries
        </a>
        <a href="<?= $baseHref ?>purchasing/purchase-orders.php" class="nav-item"
            data-path="purchasing/purchase-orders.php">
            <span class="nav-icon"><i class="fas fa-shopping-cart"></i></span> Purchase Orders
        </a>
        <a href="<?= $baseHref ?>purchasing/suppliers.php" class="nav-item" data-path="purchasing/suppliers.php">
            <span class="nav-icon"><i class="fas fa-truck-loading"></i></span> Suppliers
        </a>

        <!-- Section Divider -->
        <div class="nav-section-title">FINANCE</div>

        <a href="<?= $baseHref ?>accounting/profit-loss.php" class="nav-item" data-path="accounting/profit-loss.php">
            <span class="nav-icon"><i class="fas fa-chart-line"></i></span> P&L Statement
        </a>
        <a href="<?= $baseHref ?>accounting/balance-sheet.php" class="nav-item"
            data-path="accounting/balance-sheet.php">
            <span class="nav-icon"><i class="fas fa-balance-scale"></i></span> Balance Sheet
        </a>
        <a href="<?= $baseHref ?>accounting/trial-balance.php" class="nav-item" data-path="accounting/trial-balance.php">
            <span class="nav-icon"><i class="fas fa-table-list"></i></span> Trial Balance
        </a>
        <a href="<?= $baseHref ?>reports/stock-valuation.php" class="nav-item" data-path="reports/stock-valuation.php">
            <span class="nav-icon"><i class="fas fa-cubes"></i></span> Stock Valuation
        </a>
        <a href="<?= $baseHref ?>accounting/journal-entries.php" class="nav-item"
            data-path="accounting/journal-entries.php">
            <span class="nav-icon"><i class="fas fa-book"></i></span> Journal Entries
        </a>
        <a href="<?= $baseHref ?>accounting/chart-of-accounts.php" class="nav-item"
            data-path="accounting/chart-of-accounts.php">
            <span class="nav-icon"><i class="fas fa-folder-tree"></i></span> Chart of Accounts
        </a>
        <a href="<?= $baseHref ?>banking/bank-accounts.php" class="nav-item" data-path="banking/bank-accounts.php">
            <span class="nav-icon"><i class="fas fa-university"></i></span> Banking
        </a>
        <a href="<?= $baseHref ?>accounting/expenses.php" class="nav-item" data-path="accounting/expenses.php">
            <span class="nav-icon"><i class="fas fa-receipt"></i></span> Expenses
        </a>
        <a href="<?= $baseHref ?>petty-cash/index.php" class="nav-item" data-path="petty-cash/index.php">
            <span class="nav-icon"><i class="fas fa-wallet"></i></span> Petty Cash
        </a>

        <!-- Section Divider -->
        <div class="nav-section-title">PEOPLE</div>

        <a href="<?= $baseHref ?>hr/employees.php" class="nav-item" data-path="hr/employees.php">
            <span class="nav-icon"><i class="fas fa-user-tie"></i></span> Employees
        </a>
        <a href="<?= $baseHref ?>hr/payroll.php" class="nav-item" data-path="hr/payroll.php">
            <span class="nav-icon"><i class="fas fa-money-check-alt"></i></span> Payroll
        </a>
        <a href="<?= $baseHref ?>hr/leave.php" class="nav-item" data-path="hr/leave.php">
            <span class="nav-icon"><i class="fas fa-calendar-alt"></i></span> Leave Management
        </a>

        <!-- Section Divider -->
        <div class="nav-section-title">INSIGHTS</div>

        <a href="<?= $baseHref ?>reports/index.php" class="nav-item" data-path="reports/index.php">
            <span class="nav-icon"><i class="fas fa-chart-bar"></i></span> Reports & Analytics
        </a>
        <a href="<?= str_repeat('../', $depth + 1) ?>modules/sales-reports/index.php?module=analytics" class="nav-item" data-path="sales-reports">
            <span class="nav-icon"><i class="fas fa-file-alt"></i></span> Sales Reports
        </a>
        <a href="<?= $baseHref ?>reports/financial.php" class="nav-item" data-path="reports/financial.php">
            <span class="nav-icon"><i class="fas fa-chart-line"></i></span> Financial Reports
        </a>
        <a href="<?= $baseHref ?>accounting/cash-flow.php" class="nav-item" data-path="accounting/cash-flow.php">
            <span class="nav-icon"><i class="fas fa-stream"></i></span> Cash Flow
        </a>
        <a href="<?= $baseHref ?>reports/aging.php" class="nav-item" data-path="reports/aging.php">
            <span class="nav-icon"><i class="fas fa-clock"></i></span> Aging Analysis
        </a>
        <a href="<?= $baseHref ?>settings/index.php" class="nav-item" data-path="settings/index.php">
            <span class="nav-icon"><i class="fas fa-cog"></i></span> Settings
        </a>
    </div>
    <div style="padding: 20px; border-top: 1px solid #3c4043;">
        <a href="<?= str_repeat('../', $depth + 1) ?>employee/dashboard.php" class="nav-item" style="color: #e8eaed;">
            <span class="nav-icon"><i class="fas fa-arrow-left"></i></span> Back to Portal
        </a>
    </div>
</div>

<script>
    (function () {
        // Mark active link based on current path
        var here = (location.pathname || '').replace(/\\+/g, '/');
        var links = document.querySelectorAll('.sidebar .nav-item[data-path]');
        links.forEach(function (a) {
            var rel = '/erp/' + a.getAttribute('data-path');
            if (here.indexOf(rel) !== -1) {
                a.classList.add('active');
            }
        });
    })();
</script>

<?php
// Pull one-time flash if present and render toast
if (function_exists('get_flash')) {
    $flash = get_flash();
} else {
    $flash = null;
}
?>
<?php if ($flash): ?>
    <div class="toast-container" id="toast-container">
        <div class="toast" id="toast" role="alert" aria-live="assertive" aria-atomic="true">
            <?= htmlspecialchars($flash['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        </div>
    </div>
    <script>
        (function () {
            var t = document.getElementById('toast');
            if (!t) return;
            t.classList.add('show');
            setTimeout(function () { t.classList.remove('show'); t.classList.add('hide'); }, 3500);
            setTimeout(function () { var c = document.getElementById('toast-container'); if (c) { c.remove(); } }, 4200);
        })();
    </script>
<?php endif; ?>

<!-- Global Notifications (SweetAlert2) -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // Global Toast Configuration
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    });

    /**
     * Show a toast notification
     * @param {string} type 'success', 'error', 'warning', 'info'
     * @param {string} message The message to display
     */
    function showToast(type, message) {
        Toast.fire({
            icon: type,
            title: message
        });
    }

    /**
     * Show a confirmation dialog
     * @param {string} title Default 'Are you sure?'
     * @param {string} text Default "You won't be able to revert this!"
     * @param {string} confirmButtonText Default 'Yes, delete it!'
     * @param {function} onConfirm Callback function if confirmed
     */
    function confirmAction(title, text, confirmButtonText, onConfirm) {
        Swal.fire({
            title: title || 'Are you sure?',
            text: text || "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: confirmButtonText || 'Yes, do it!'
        }).then((result) => {
            if (result.isConfirmed) {
                onConfirm();
            }
        });
    }
</script>