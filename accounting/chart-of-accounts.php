<?php
$functions_path = __DIR__ . '/../includes/functions.php';
if (!file_exists($functions_path)) {
    $functions_path = __DIR__ . '/../../includes/functions.php';
}
require_once $functions_path;
requireLogin();
global $pdo;

// Fetch initial accounts to output as JSON on page load (eliminates initial load delay)
$stmt = $pdo->query("SELECT * FROM erp_accounts ORDER BY code ASC, id ASC");
$allAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
$accountsJson = json_encode($allAccounts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chart of Accounts - ERP</title>
    <link rel="stylesheet" href="<?= app_url('/assets/css/style.css') ?>?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #004560;
            --primary-hover: #002d3f;
            --primary-glow: rgba(0, 69, 96, 0.08);
            --bg-canvas: #f8fdff;
            --border: #e2e8f0;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            background: var(--bg-canvas); 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            color: var(--text-main); 
            min-height: 100vh;
        }
        
        .page-wrapper {
            margin-left: 240px;
            padding: 40px;
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        @media (max-width: 992px) {
            .page-wrapper { margin-left: 0; padding: 20px; }
        }

        /* Premium Header Styling */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .header-title h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: -0.5px;
        }

        .header-title p {
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .header-actions {
            display: flex;
            gap: 12px;
        }

        /* Buttons styling */
        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 14px rgba(0, 69, 96, 0.2);
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(0, 69, 96, 0.3);
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: #f1f5f9;
            border-color: var(--primary);
            transform: translateY(-1px);
        }

        .btn-outline {
            background: transparent;
            color: var(--text-main);
            border: 1px solid var(--border);
        }

        .btn-outline:hover {
            background: #f8fafc;
            border-color: var(--text-muted);
        }

        .btn-danger {
            background: #ef4444;
            color: white;
            box-shadow: 0 4px 14px rgba(239, 68, 68, 0.2);
        }
        .btn-danger:hover {
            background: #dc2626;
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.3);
        }

        .btn-icon {
            padding: 8px;
            border-radius: 8px;
            background: transparent;
            color: var(--text-muted);
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-icon:hover {
            background: #f1f5f9;
            color: var(--text-main);
        }

        /* Two-column layout grid */
        .accounts-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 28px;
            align-items: start;
        }

        @media (max-width: 1200px) {
            .accounts-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Card container */
        .card {
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border);
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            min-height: 500px;
            display: flex;
            flex-direction: column;
        }

        .card-header {
            padding: 24px 30px;
            border-bottom: 1px solid var(--border);
            background: rgba(248, 250, 252, 0.5);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
            font-family: 'Outfit', sans-serif;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Filters styling */
        .search-row {
            display: flex;
            gap: 12px;
            width: 100%;
            flex-wrap: wrap;
        }

        .search-box {
            flex: 1;
            min-width: 220px;
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 12px 16px 12px 42px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            outline: none;
            transition: all 0.2s;
            font-family: inherit;
        }

        .search-box input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-glow);
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 14px;
        }

        .filter-select {
            padding: 12px 24px 12px 16px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            background: white;
            outline: none;
            cursor: pointer;
            transition: all 0.2s;
            min-width: 150px;
        }

        .filter-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-glow);
        }

        /* Table Design */
        .table-wrap {
            overflow-x: auto;
            flex: 1;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .table th {
            padding: 16px 24px;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border);
            background: rgba(248, 250, 252, 0.8);
        }

        .table td {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
            vertical-align: middle;
        }

        .table tr.active-row {
            background-color: rgba(0, 69, 96, 0.04);
        }

        .table tr.cursor-pointer {
            cursor: pointer;
        }

        .table tr.cursor-pointer:hover {
            background: rgba(0, 69, 96, 0.02);
        }

        .table tr:last-child td {
            border-bottom: none;
        }

        /* Custom Badge styles */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-asset { background: #e0f2fe; color: #0369a1; }
        .badge-liability { background: #fee2e2; color: #b91c1c; }
        .badge-equity { background: #f3e8ff; color: #7e22ce; }
        .badge-revenue { background: #dcfce7; color: #15803d; }
        .badge-expense { background: #fef3c7; color: #b45309; }

        .badge-debit { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .badge-credit { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }

        /* Action Dropdown Menu */
        .dropdown {
            position: relative;
            display: inline-block;
        }

        .dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.05);
            z-index: 100;
            min-width: 120px;
            overflow: hidden;
            margin-top: 4px;
        }

        .dropdown:hover .dropdown-menu,
        .dropdown.active .dropdown-menu {
            display: block;
        }

        .dropdown-item {
            padding: 10px 16px;
            font-size: 13px;
            color: var(--text-main);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            background: none;
            border: none;
            width: 100%;
            text-align: left;
        }

        .dropdown-item:hover {
            background: #f8fafc;
        }

        .dropdown-item.text-destructive {
            color: #ef4444;
        }
        .dropdown-item.text-destructive:hover {
            background: #fef2f2;
        }

        /* Empty state design */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            padding: 80px 40px;
            text-align: center;
            flex: 1;
        }

        .empty-state-icon-wrapper {
            position: relative;
            width: 100px;
            height: 100px;
            margin-bottom: 24px;
        }

        .empty-state-bg-1 {
            position: absolute;
            inset: 0;
            background: rgba(0, 69, 96, 0.05);
            border-radius: 20px;
            transform: rotate(12deg);
        }

        .empty-state-bg-2 {
            position: absolute;
            inset: 0;
            background: rgba(0, 69, 96, 0.08);
            border-radius: 20px;
            transform: rotate(-6deg);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .empty-state-icon {
            font-size: 36px;
            color: var(--primary);
        }

        .empty-state h3 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text-main);
        }

        .empty-state p {
            font-size: 14px;
            color: var(--text-muted);
            max-width: 280px;
        }

        /* Modal Overlay Styles */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(4px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s ease-out;
            padding: 20px;
        }

        .modal-overlay.show {
            display: flex;
            opacity: 1;
        }

        .modal-container {
            background: white;
            border-radius: 16px;
            width: 100%;
            max-width: 480px;
            border: 1px solid var(--border);
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.1);
            transform: scale(0.95);
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            overflow: hidden;
        }

        .modal-overlay.show .modal-container {
            transform: scale(1);
        }

        .modal-header {
            padding: 24px 30px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
            font-family: 'Outfit', sans-serif;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 18px;
            color: var(--text-muted);
            cursor: pointer;
        }
        .modal-close:hover {
            color: var(--text-main);
        }

        .modal-body {
            padding: 30px;
        }

        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid var(--border);
            background: #f8fafc;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            outline: none;
            transition: all 0.2s;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-glow);
        }

        .form-control::placeholder {
            color: #94a3b8;
        }

        .form-control:disabled {
            background: #f1f5f9;
            color: #94a3b8;
            cursor: not-allowed;
        }

        .form-group-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .char-counter {
            font-size: 11px;
            color: var(--text-muted);
        }

        .text-destructive {
            color: #ef4444;
        }

        .font-mono {
            font-family: 'Fira Code', 'Courier New', monospace;
        }

        /* Toast notification */
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #0f172a;
            color: white;
            padding: 16px 24px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            z-index: 2000;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 600;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toast-success i { color: #22c55e; }
        .toast-error i { color: #ef4444; }

    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

<div class="page-wrapper">
    <div class="page-header">
        <div class="header-title">
            <h1><i class="fa-solid fa-file-invoice-dollar me-2"></i> Chart of Accounts</h1>
            <p>Establish and manage your company ledger, assets, liabilities, equity, revenues, and expenses.</p>
        </div>
        <div class="header-actions">
            <a href="../index.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
            <button class="btn btn-primary" onclick="openNewAccountModal()"><i class="fa-solid fa-plus"></i> New Account</button>
        </div>
    </div>
    
    <div class="container-fluid">
        <div class="accounts-grid">
            
            <!-- Left Panel: Accounts List -->
            <div class="card">
                <div class="card-header">
                    <div class="search-row">
                        <div class="search-box">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="text" id="searchAccounts" placeholder="Search account..." oninput="filterAndRender()">
                        </div>
                        <select id="filterType" class="filter-select" onchange="filterAndRender()">
                            <option value="all">All Types</option>
                            <option value="asset">Assets</option>
                            <option value="liability">Liabilities</option>
                            <option value="equity">Equity</option>
                            <option value="revenue">Revenue</option>
                            <option value="expense">Expenses</option>
                        </select>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Account</th>
                                <th style="text-align: center; width: 130px;">Sub-accounts</th>
                                <th style="text-align: center; width: 100px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="accountsTableBody">
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 48px; color: var(--text-muted);">
                                    <i class="fa-solid fa-spinner fa-spin fs-4 mb-2"></i><br>Loading accounts...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Right Panel: Account Details & Sub-accounts -->
            <div class="card" id="detailsCard">
                <div class="empty-state">
                    <div class="empty-state-icon-wrapper">
                        <div class="empty-state-bg-1"></div>
                        <div class="empty-state-bg-2">
                            <i class="fa-solid fa-file-invoice-dollar empty-state-icon"></i>
                        </div>
                    </div>
                    <h3>No Account Selected</h3>
                    <p>Select an account from the left list to view its sub-accounts and details here.</p>
                </div>
            </div>
            
        </div>
    </div>
</div>

<!-- ================= MODALS ================= -->

<!-- 1. Add New Account Modal (Parent) -->
<div class="modal-overlay" id="newAccountModal" onclick="closeModalOnBackdrop(event, 'newAccountModal')">
    <div class="modal-container">
        <div class="modal-header">
            <h3>Add New Account</h3>
            <button class="modal-close" onclick="closeModal('newAccountModal')">&times;</button>
        </div>
        <form id="newAccountForm" onsubmit="handleNewAccountSubmit(event)">
            <div class="modal-body">
                <div class="form-group">
                    <div class="form-group-header">
                        <label for="newAccountName">Name *</label>
                        <span class="char-counter" id="newNameCharCount">0/100</span>
                    </div>
                    <input type="text" id="newAccountName" required class="form-control" placeholder="Enter account name" maxlength="100" oninput="updateCharCount('newAccountName', 'newNameCharCount')">
                </div>
                <div class="form-group">
                    <label for="newAccountCategory">Category *</label>
                    <select id="newAccountCategory" required class="filter-select" style="width: 100%;" onchange="suggestNextCode('newAccountCategory', 'newAccountCode')">
                        <option value="">Select account category</option>
                        <option value="asset">Asset</option>
                        <option value="liability">Liability</option>
                        <option value="equity">Equity</option>
                        <option value="revenue">Revenue</option>
                        <option value="expense">Expense</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="newAccountCode">Account Code</label>
                    <input type="text" id="newAccountCode" class="form-control font-mono" placeholder="Auto-generated or enter code">
                </div>
                <div class="form-group">
                    <label for="newAccountDesc">Description</label>
                    <textarea id="newAccountDesc" rows="3" class="form-control" placeholder="Optional description"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('newAccountModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveAccountBtn">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Add New Sub-account Modal -->
<div class="modal-overlay" id="newSubAccountModal" onclick="closeModalOnBackdrop(event, 'newSubAccountModal')">
    <div class="modal-container">
        <div class="modal-header">
            <h3 id="subaccountModalTitle">Add New Sub-account</h3>
            <button class="modal-close" onclick="closeModal('newSubAccountModal')">&times;</button>
        </div>
        <form id="newSubAccountForm" onsubmit="handleNewSubAccountSubmit(event)">
            <div class="modal-body">
                <div class="form-group">
                    <div class="form-group-header">
                        <label for="newSubAccountName">Sub-account Name *</label>
                        <span class="char-counter" id="newSubCharCount">0/100</span>
                    </div>
                    <input type="text" id="newSubAccountName" required class="form-control" placeholder="Enter sub-account name" maxlength="100" oninput="updateCharCount('newSubAccountName', 'newSubCharCount')">
                </div>
                <div class="form-group">
                    <label for="newSubAccountCode">Account Code</label>
                    <input type="text" id="newSubAccountCode" class="form-control font-mono" placeholder="Auto-generated or enter code">
                </div>
                <div class="form-group">
                    <label for="newSubAccountDesc">Description</label>
                    <textarea id="newSubAccountDesc" rows="3" class="form-control" placeholder="Optional description"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('newSubAccountModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveSubAccountBtn">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Edit Account Modal -->
<div class="modal-overlay" id="editAccountModal" onclick="closeModalOnBackdrop(event, 'editAccountModal')">
    <div class="modal-container">
        <div class="modal-header">
            <h3 id="editModalTitle">Edit Account</h3>
            <button class="modal-close" onclick="closeModal('editAccountModal')">&times;</button>
        </div>
        <form id="editAccountForm" onsubmit="handleEditAccountSubmit(event)">
            <input type="hidden" id="editAccountId">
            <input type="hidden" id="editAccountIsSub">
            <div class="modal-body">
                <div class="form-group">
                    <div class="form-group-header">
                        <label for="editAccountName">Name *</label>
                        <span class="char-counter" id="editNameCharCount">0/100</span>
                    </div>
                    <input type="text" id="editAccountName" required class="form-control" placeholder="Account name" maxlength="100" oninput="updateCharCount('editAccountName', 'editNameCharCount')">
                </div>
                <div class="form-group" id="editCategoryGroup">
                    <label for="editAccountCategory">Category *</label>
                    <select id="editAccountCategory" class="filter-select" style="width: 100%;">
                        <option value="asset">Asset</option>
                        <option value="liability">Liability</option>
                        <option value="equity">Equity</option>
                        <option value="revenue">Revenue</option>
                        <option value="expense">Expense</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="editAccountCode">Account Code *</label>
                    <input type="text" id="editAccountCode" required class="form-control font-mono" placeholder="Account code">
                </div>
                <div class="form-group">
                    <label for="editAccountDesc">Description</label>
                    <textarea id="editAccountDesc" rows="3" class="form-control" placeholder="Optional description"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editAccountModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveEditBtn">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- 4. Delete Confirmation Modal -->
<div class="modal-overlay" id="deleteConfirmModal" onclick="closeModalOnBackdrop(event, 'deleteConfirmModal')">
    <div class="modal-container">
        <div class="modal-header">
            <h3 id="deleteModalTitle">Delete Account</h3>
            <button class="modal-close" onclick="closeModal('deleteConfirmModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size: 14px; line-height: 1.6; color: var(--text-main);">
                Are you sure you want to delete <strong id="deleteAccountNameDisplay"></strong>?
            </p>
            <p style="font-size: 13px; margin-top: 12px; color: #ef4444; font-weight: 500;">
                <i class="fa-solid fa-triangle-exclamation"></i> This action is permanent and cannot be undone. You can only delete accounts that have no sub-accounts and no transaction history.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('deleteConfirmModal')">Cancel</button>
            <button type="button" class="btn btn-danger" id="deleteAccountConfirmBtn" onclick="executeDelete()">Delete</button>
        </div>
    </div>
</div>

<!-- Toast Alert -->
<div class="toast" id="toastAlert">
    <i class="fa-solid fa-circle-check"></i>
    <span id="toastMessage">Success message</span>
</div>

<script>
    // Initial accounts list injected directly by PHP to avoid initial API round-trip delay
    let allAccountsRaw = <?= $accountsJson ?>;
    
    // UI state
    let selectedParentId = null;
    let searchQuery = '';
    let filterType = 'all';
    
    // Normal balance helper (based on category type)
    function getNormalBalance(category) {
        const cat = (category || '').toLowerCase();
        if (cat === 'asset' || cat === 'expense' || cat === 'cogs') {
            return 'debit';
        }
        return 'credit';
    }
    
    // Process raw accounts list to build hierarchical parent/child relations
    function getProcessedAccounts() {
        const parents = allAccountsRaw.filter(a => !a.parent_id);
        return parents.map(p => {
            return {
                id: parseInt(p.id),
                code: p.code || '',
                name: p.name || '',
                type: p.type || '',
                description: p.description || '',
                is_system: parseInt(p.is_system) === 1,
                normalBalance: getNormalBalance(p.type),
                subAccounts: allAccountsRaw.filter(s => parseInt(s.parent_id) === parseInt(p.id)).map(s => ({
                    id: parseInt(s.id),
                    code: s.code || '',
                    name: s.name || '',
                    type: s.type || p.type, // Inherit parent type
                    description: s.description || '',
                    parent_id: parseInt(p.id),
                    is_system: parseInt(s.is_system) === 1,
                    normalBalance: getNormalBalance(s.type || p.type)
                }))
            };
        });
    }

    // Refresh accounts data from API
    async function refreshAccountsData() {
        try {
            const formData = new FormData();
            formData.append('action', 'list');
            const resp = await fetch('../api/accounts.php', { method: 'POST', body: formData });
            const result = await resp.json();
            if (result.success) {
                allAccountsRaw = result.accounts;
                return true;
            } else {
                showToast(result.message || 'Failed to refresh accounts list.', 'error');
                return false;
            }
        } catch (e) {
            showToast('Connection error: unable to load accounts.', 'error');
            return false;
        }
    }

    // Filter and Render parent list and details panel
    function filterAndRender() {
        searchQuery = document.getElementById('searchAccounts').value.toLowerCase().trim();
        filterType = document.getElementById('filterType').value;
        renderAccounts();
    }

    // Render My Accounts (Left Pane)
    function renderAccounts() {
        const processed = getProcessedAccounts();
        
        // Filter parents by search query and type
        const filtered = processed.filter(p => {
            const matchesSearch = p.name.toLowerCase().includes(searchQuery) || p.code.toLowerCase().includes(searchQuery);
            const matchesType = filterType === 'all' || p.type === filterType;
            return matchesSearch && matchesType;
        });

        const tbody = document.getElementById('accountsTableBody');
        tbody.innerHTML = '';

        if (filtered.length === 0) {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align: center; padding: 48px; color: var(--text-muted);"><i class="fa-solid fa-box-open fs-3 d-block mb-2 text-muted"></i> No accounts found matching your filters.</td></tr>`;
            return;
        }

        filtered.forEach((p, idx) => {
            const tr = document.createElement('tr');
            tr.className = `cursor-pointer ${selectedParentId === p.id ? 'active-row' : ''}`;
            tr.onclick = () => selectAccount(p.id);
            
            // Build lock icon or actions dropdown
            let actionHtml = '';
            if (p.is_system) {
                actionHtml = `<span style="color: var(--text-muted); font-size: 12px; font-weight: 600;"><i class="fa-solid fa-lock text-muted"></i> System</span>`;
            } else {
                actionHtml = `
                    <div class="dropdown" onclick="event.stopPropagation();">
                        <button class="btn-icon" onclick="toggleDropdown(this)"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                        <div class="dropdown-menu">
                            <button type="button" class="dropdown-item" onclick="openEditAccountModal(${p.id}, false, event)"><i class="fa-solid fa-pen"></i> Edit</button>
                            <button type="button" class="dropdown-item text-destructive" onclick="openDeleteConfirmModal(${p.id}, '${p.name}', 'account', event)"><i class="fa-solid fa-trash"></i> Delete</button>
                        </div>
                    </div>
                `;
            }

            tr.innerHTML = `
                <td style="color: var(--text-muted); font-size: 13px;">${idx + 1}.</td>
                <td>
                    <div style="font-weight: 600; display: flex; align-items: center; gap: 8px;">
                        <span>${escapeHtml(p.name)}</span>
                        ${p.code ? `<span style="font-family: monospace; font-size: 12px; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; color: var(--primary);">${escapeHtml(p.code)}</span>` : ''}
                        <span class="badge badge-${p.type}">${p.type.toUpperCase()}</span>
                    </div>
                    ${p.description ? `<p style="font-size: 12px; color: var(--text-muted); font-weight: normal; margin-top: 2px;">${escapeHtml(p.description)}</p>` : ''}
                </td>
                <td style="text-align: center; font-weight: 600; color: var(--primary);">${p.subAccounts.length}</td>
                <td style="text-align: center;">${actionHtml}</td>
            `;
            tbody.appendChild(tr);
        });

        // Sync the right details panel
        renderDetails();
    }

    // Handle selection of a parent account
    function selectAccount(id) {
        selectedParentId = id;
        
        // Remove active class from all rows and add to selected
        const rows = document.querySelectorAll('#accountsTableBody tr');
        rows.forEach(r => r.classList.remove('active-row'));
        
        renderAccounts();
    }

    // Render selected parent account details and its sub-accounts (Right Pane)
    function renderDetails() {
        const detailsCard = document.getElementById('detailsCard');
        if (!selectedParentId) {
            detailsCard.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon-wrapper">
                        <div class="empty-state-bg-1"></div>
                        <div class="empty-state-bg-2">
                            <i class="fa-solid fa-file-invoice-dollar empty-state-icon"></i>
                        </div>
                    </div>
                    <h3>No Account Selected</h3>
                    <p>Select an account from the left list to view its sub-accounts and details here.</p>
                </div>
            `;
            return;
        }

        const processed = getProcessedAccounts();
        const p = processed.find(x => x.id === selectedParentId);
        if (!p) {
            selectedParentId = null;
            renderDetails();
            return;
        }

        // Generate Sub-accounts table body
        let tableRowsHtml = '';
        if (p.subAccounts.length === 0) {
            tableRowsHtml = `<tr><td colspan="2" style="text-align: center; padding: 36px; color: var(--text-muted);"><i class="fa-solid fa-folder-open mb-2 fs-4 text-muted"></i> No sub-accounts created for this account.</td></tr>`;
        } else {
            p.subAccounts.forEach(sub => {
                let subActionHtml = '';
                if (sub.is_system) {
                    subActionHtml = `<span style="color: var(--text-muted); font-size: 12px;"><i class="fa-solid fa-lock"></i> System</span>`;
                } else {
                    subActionHtml = `
                        <div class="dropdown">
                            <button class="btn-icon" onclick="toggleDropdown(this)"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                            <div class="dropdown-menu">
                                <button type="button" class="dropdown-item" onclick="openEditAccountModal(${sub.id}, true, event)"><i class="fa-solid fa-pen"></i> Edit</button>
                                <button type="button" class="dropdown-item text-destructive" onclick="openDeleteConfirmModal(${sub.id}, '${sub.name}', 'subaccount', event)"><i class="fa-solid fa-trash"></i> Delete</button>
                            </div>
                        </div>
                    `;
                }

                tableRowsHtml += `
                    <tr>
                        <td>
                            <div style="font-weight: 600; display: flex; align-items: center; gap: 8px;">
                                <span>${escapeHtml(sub.name)}</span>
                                ${sub.code ? `<span style="font-family: monospace; font-size: 11px; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; color: var(--primary);">${escapeHtml(sub.code)}</span>` : ''}
                            </div>
                            ${sub.description ? `<p style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">${escapeHtml(sub.description)}</p>` : ''}
                        </td>
                        <td style="text-align: right;">${subActionHtml}</td>
                    </tr>
                `;
            });
        }

        // Layout details card
        detailsCard.innerHTML = `
            <div class="card-header">
                <div class="card-title">
                    <span>${escapeHtml(p.name)}</span>
                    ${p.code ? `<span style="font-family: monospace; font-size: 13px; background: #e0f4fa; padding: 3px 8px; border-radius: 6px; color: var(--primary); font-weight: 600;">${escapeHtml(p.code)}</span>` : ''}
                    <span class="badge badge-debit"><i class="fa-solid fa-circle-nodes me-1" style="font-size: 8px;"></i> ${p.normalBalance.toUpperCase()}</span>
                    ${p.is_system ? `<span style="font-size: 12px; color: var(--text-muted); display: inline-flex; align-items: center; gap: 4px; font-weight: 500;"><i class="fa-solid fa-lock"></i> System</span>` : ''}
                </div>
                <button class="btn btn-secondary" style="padding: 8px 14px; font-size: 13px;" onclick="openNewSubAccountModal()"><i class="fa-solid fa-plus"></i> New Sub-account</button>
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Sub-account</th>
                            <th style="text-align: right; width: 100px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${tableRowsHtml}
                    </tbody>
                </table>
            </div>
        `;
    }

    // Toggle dropdown menus
    function toggleDropdown(btn) {
        // Close other active dropdowns
        const dropdowns = document.querySelectorAll('.dropdown');
        dropdowns.forEach(d => {
            if (d !== btn.parentElement) d.classList.remove('active');
        });
        
        btn.parentElement.classList.toggle('active');
    }

    // Close dropdowns if clicked outside
    document.addEventListener('click', () => {
        const dropdowns = document.querySelectorAll('.dropdown');
        dropdowns.forEach(d => d.classList.remove('active'));
    });

    // Modal helpers
    function openModal(id) {
        const modal = document.getElementById(id);
        modal.classList.add('show');
    }

    function closeModal(id) {
        const modal = document.getElementById(id);
        modal.classList.remove('show');
    }

    function closeModalOnBackdrop(e, id) {
        if (e.target.classList.contains('modal-overlay')) {
            closeModal(id);
        }
    }

    function updateCharCount(inputId, counterId) {
        const input = document.getElementById(inputId);
        const counter = document.getElementById(counterId);
        counter.textContent = `${input.value.length}/100`;
    }

    // Suggestions for automatic next code based on selected type
    async function suggestNextCode(typeSelectId, codeInputId) {
        const select = document.getElementById(typeSelectId);
        const input = document.getElementById(codeInputId);
        const type = select.value;
        if (!type) {
            input.value = '';
            return;
        }
        input.value = 'Generating...';
        try {
            const formData = new FormData();
            formData.append('action', 'get_next_code');
            formData.append('type', type);
            const resp = await fetch('../api/accounts.php', { method: 'POST', body: formData });
            const result = await resp.json();
            if (result.success) {
                input.value = result.next_code;
            } else {
                input.value = '';
            }
        } catch (e) {
            input.value = '';
        }
    }

    // ================= MODAL SUBMISSION HANDLERS =================

    // 1. Add Parent Account Modal
    function openNewAccountModal() {
        document.getElementById('newAccountForm').reset();
        document.getElementById('newNameCharCount').textContent = '0/100';
        openModal('newAccountModal');
    }

    async function handleNewAccountSubmit(e) {
        e.preventDefault();
        const saveBtn = document.getElementById('saveAccountBtn');
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';

        const name = document.getElementById('newAccountName').value.trim();
        const type = document.getElementById('newAccountCategory').value;
        const code = document.getElementById('newAccountCode').value.trim();
        const desc = document.getElementById('newAccountDesc').value.trim();

        try {
            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('name', name);
            formData.append('type', type);
            formData.append('code', code);
            formData.append('description', desc);

            const resp = await fetch('../api/accounts.php', { method: 'POST', body: formData });
            const result = await resp.json();
            
            if (result.success) {
                closeModal('newAccountModal');
                showToast('Account created successfully!', 'success');
                await refreshAccountsData();
                renderAccounts();
            } else {
                showToast(result.message || 'Error creating account.', 'error');
            }
        } catch (err) {
            showToast('Connection error: failed to create account.', 'error');
        } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save';
        }
    }

    // 2. Add Sub-account Modal
    function openNewSubAccountModal() {
        if (!selectedParentId) return;
        const processed = getProcessedAccounts();
        const p = processed.find(x => x.id === selectedParentId);
        if (!p) return;

        document.getElementById('newSubAccountForm').reset();
        document.getElementById('newSubCharCount').textContent = '0/100';
        document.getElementById('subaccountModalTitle').textContent = `Add New Sub-account to ${p.name}`;
        
        // Pre-suggest next code
        suggestNextCodeInput(p.type, 'newSubAccountCode');
        
        openModal('newSubAccountModal');
    }

    async function suggestNextCodeInput(type, codeInputId) {
        const input = document.getElementById(codeInputId);
        input.value = 'Generating...';
        try {
            const formData = new FormData();
            formData.append('action', 'get_next_code');
            formData.append('type', type);
            const resp = await fetch('../api/accounts.php', { method: 'POST', body: formData });
            const result = await resp.json();
            if (result.success) {
                input.value = result.next_code;
            } else {
                input.value = '';
            }
        } catch (e) {
            input.value = '';
        }
    }

    async function handleNewSubAccountSubmit(e) {
        e.preventDefault();
        if (!selectedParentId) return;

        const saveBtn = document.getElementById('saveSubAccountBtn');
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';

        const name = document.getElementById('newSubAccountName').value.trim();
        const code = document.getElementById('newSubAccountCode').value.trim();
        const desc = document.getElementById('newSubAccountDesc').value.trim();

        // Sub-account inherits type from the active parent
        const processed = getProcessedAccounts();
        const p = processed.find(x => x.id === selectedParentId);

        try {
            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('name', name);
            formData.append('type', p.type);
            formData.append('code', code);
            formData.append('description', desc);
            formData.append('parent_id', selectedParentId);

            const resp = await fetch('../api/accounts.php', { method: 'POST', body: formData });
            const result = await resp.json();
            
            if (result.success) {
                closeModal('newSubAccountModal');
                showToast('Sub-account created successfully!', 'success');
                await refreshAccountsData();
                renderAccounts();
            } else {
                showToast(result.message || 'Error creating sub-account.', 'error');
            }
        } catch (err) {
            showToast('Connection error: failed to create sub-account.', 'error');
        } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save';
        }
    }

    // 3. Edit Account Modal (Parent or Sub)
    function openEditAccountModal(id, isSub = false, e) {
        if (e) e.stopPropagation();
        
        // Find account info
        const acc = allAccountsRaw.find(x => parseInt(x.id) === id);
        if (!acc) return;

        document.getElementById('editAccountId').value = id;
        document.getElementById('editAccountIsSub').value = isSub ? '1' : '0';
        document.getElementById('editAccountName').value = acc.name || '';
        document.getElementById('editAccountCode').value = acc.code || '';
        document.getElementById('editAccountDesc').value = acc.description || '';
        
        document.getElementById('editNameCharCount').textContent = `${(acc.name || '').length}/100`;

        const categoryGroup = document.getElementById('editCategoryGroup');
        const editModalTitle = document.getElementById('editModalTitle');

        if (isSub) {
            editModalTitle.textContent = 'Edit Sub-account';
            categoryGroup.style.display = 'none'; // Sub-accounts inherit parent category, don't edit here
            document.getElementById('editAccountCategory').required = false;
        } else {
            editModalTitle.textContent = 'Edit Account';
            categoryGroup.style.display = 'block';
            document.getElementById('editAccountCategory').value = acc.type || '';
            document.getElementById('editAccountCategory').required = true;
        }

        openModal('editAccountModal');
    }

    async function handleEditAccountSubmit(e) {
        e.preventDefault();
        const id = document.getElementById('editAccountId').value;
        const isSub = document.getElementById('editAccountIsSub').value === '1';
        const name = document.getElementById('editAccountName').value.trim();
        const code = document.getElementById('editAccountCode').value.trim();
        const desc = document.getElementById('editAccountDesc').value.trim();

        const saveBtn = document.getElementById('saveEditBtn');
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';

        try {
            const formData = new FormData();
            formData.append('action', 'update');
            formData.append('id', id);
            formData.append('name', name);
            formData.append('code', code);
            formData.append('description', desc);

            // Fetch current account to see category or parent relation
            const acc = allAccountsRaw.find(x => parseInt(x.id) === parseInt(id));
            
            if (isSub) {
                // Keep subaccount's parent and inherit category
                formData.append('parent_id', acc.parent_id);
                formData.append('type', acc.type);
            } else {
                const type = document.getElementById('editAccountCategory').value;
                formData.append('type', type);
            }

            const resp = await fetch('../api/accounts.php', { method: 'POST', body: formData });
            const result = await resp.json();
            
            if (result.success) {
                closeModal('editAccountModal');
                showToast('Account updated successfully!', 'success');
                await refreshAccountsData();
                renderAccounts();
            } else {
                showToast(result.message || 'Error updating account.', 'error');
            }
        } catch (err) {
            showToast('Connection error: failed to save changes.', 'error');
        } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Changes';
        }
    }

    // 4. Delete Confirmation Modal
    let pendingDeleteId = null;
    let pendingDeleteType = '';

    function openDeleteConfirmModal(id, name, type, e) {
        if (e) e.stopPropagation();
        pendingDeleteId = id;
        pendingDeleteType = type;

        document.getElementById('deleteAccountNameDisplay').textContent = name;
        document.getElementById('deleteModalTitle').textContent = type === 'subaccount' ? 'Delete Sub-account' : 'Delete Account';
        
        openModal('deleteConfirmModal');
    }

    async function executeDelete() {
        if (!pendingDeleteId) return;

        const deleteBtn = document.getElementById('deleteAccountConfirmBtn');
        deleteBtn.disabled = true;
        deleteBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Deleting...';

        try {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', pendingDeleteId);

            const resp = await fetch('../api/accounts.php', { method: 'POST', body: formData });
            const result = await resp.json();
            
            if (result.success) {
                closeModal('deleteConfirmModal');
                showToast('Account deleted successfully.', 'success');
                
                // If the selected parent account was deleted, clear selection
                if (parseInt(pendingDeleteId) === parseInt(selectedParentId)) {
                    selectedParentId = null;
                }
                
                await refreshAccountsData();
                renderAccounts();
            } else {
                showToast(result.message || 'Error deleting account.', 'error');
            }
        } catch (err) {
            showToast('Connection error: failed to delete account.', 'error');
        } finally {
            deleteBtn.disabled = false;
            deleteBtn.textContent = 'Delete';
            pendingDeleteId = null;
            pendingDeleteType = '';
        }
    }

    // ================= TOAST NOTIFICATION =================

    let toastTimer = null;
    function showToast(message, type = 'success') {
        const toast = document.getElementById('toastAlert');
        const msgSpan = document.getElementById('toastMessage');
        
        // Clear previous timer
        if (toastTimer) clearTimeout(toastTimer);

        msgSpan.textContent = message;
        
        // Reset classes
        toast.className = 'toast';
        if (type === 'success') {
            toast.classList.add('toast-success');
            toast.querySelector('i').className = 'fa-solid fa-circle-check';
        } else {
            toast.classList.add('toast-error');
            toast.querySelector('i').className = 'fa-solid fa-circle-xmark';
        }

        toast.classList.add('show');

        toastTimer = setTimeout(() => {
            toast.classList.remove('show');
        }, 4000);
    }

    // Utility: HTML escaping to prevent XSS
    function escapeHtml(str) {
        if (!str) return '';
        return str
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Initial render
    renderAccounts();
</script>
</body>
</html>
