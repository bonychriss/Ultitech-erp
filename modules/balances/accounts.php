<?php
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/balances-guard.php';

$moduleParam = balances_guard_module_param();
balances_bootstrap_or_error('accounts.php', [
    'back_url' => balances_guard_accounts_url($moduleParam),
    'retry_url' => balances_guard_current_url(),
]);

requireLogin();

if (function_exists('getRequestedCompanySlug') && function_exists('applyWinningCompanySession')) {
    $bootSlug = strtolower(trim((string) getRequestedCompanySlug()));
    if ($bootSlug === '' && !empty($_SESSION['company_slug'])) {
        $bootSlug = strtolower(trim((string) $_SESSION['company_slug']));
    }
    if ($bootSlug !== '') {
        applyWinningCompanySession($bootSlug);
    }
}

try {
    if (function_exists('coa_ensure_parent_id_column')) {
        coa_ensure_parent_id_column($pdo);
    }
    if (function_exists('balances_ensure_default_accounts')) {
        balances_ensure_default_accounts($pdo);
    }
    if (function_exists('coa_backfill_sub_account_company_ids')) {
        coa_backfill_sub_account_company_ids($pdo);
    }
    if (function_exists('balances_ensure_system_locked_accounts')) {
        balances_ensure_system_locked_accounts($pdo);
    }
} catch (Throwable $e) {
    error_log('accounts.php schema seed: ' . $e->getMessage());
}
// Legacy pruning is disabled to prevent auto-deletion of user-created sub-accounts with codes in the legacy range.
// if (function_exists('balances_prune_legacy_default_sub_accounts')) {
//     balances_prune_legacy_default_sub_accounts($pdo);
// }

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!isAdmin() && !isFinance()) {
        $_SESSION['error'] = 'Access denied.';
        redirect(function_exists('balances_accounts_redirect_url') ? balances_accounts_redirect_url() : 'accounts.php');
    }

    if ($action === 'create' || $action === 'edit') {
        if ($action === 'edit' && !isAdmin()) {
            $_SESSION['error'] = 'Access denied. Only admins can edit accounts.';
            redirect(function_exists('balances_accounts_redirect_url') ? balances_accounts_redirect_url() : 'accounts.php');
        }

        $name = clean_input($_POST['name']);
        $type = clean_input($_POST['type']);
        $currency = clean_input($_POST['currency'] ?? 'TZS');
        $opening_balance = (float) ($_POST['opening_balance'] ?? 0);
        $status = clean_input($_POST['status'] ?? 'active');
        $id = (int) ($_POST['id'] ?? 0);

        if ($action === 'edit' && $id > 0) {
            $checkStmt = $pdo->prepare('SELECT is_system FROM financial_accounts WHERE id = ?');
            $checkStmt->execute([$id]);
            if ((int)($checkStmt->fetchColumn() ?? 0) === 1) {
                $_SESSION['error'] = 'System accounts are locked and cannot be edited.';
                redirect(function_exists('balances_accounts_redirect_url') ? balances_accounts_redirect_url() : 'accounts.php');
            }
        }

        if (empty($name)) {
            $_SESSION['error'] = 'Account name is required.';
        } else {
            if ($action === 'create') {
                $balanceSide = function_exists('coa_normal_balance_side_for_account_type')
                    ? coa_normal_balance_side_for_account_type($type)
                    : 'debit';
                $duplicate = function_exists('coa_find_account_by_name_and_balance_side')
                    ? coa_find_account_by_name_and_balance_side($pdo, $name, $balanceSide)
                    : null;
                if ($duplicate !== null) {
                    $_SESSION['error'] = function_exists('coa_duplicate_account_message')
                        ? coa_duplicate_account_message($name, $balanceSide)
                        : 'An account with this name and balance type already exists.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO financial_accounts (name, type, currency, opening_balance, current_balance, status) VALUES (?, ?, ?, ?, ?, ?)');
                    if ($stmt->execute([$name, $type, $currency, $opening_balance, $opening_balance, $status])) {
                        $_SESSION['bal_lottie_success'] = 'Your new account has been added and is ready to use.';
                    } else {
                        $_SESSION['error'] = 'Failed to create account.';
                    }
                }
            } elseif ($action === 'edit' && $id > 0) {
                $balanceSide = function_exists('coa_normal_balance_side_for_account_type')
                    ? coa_normal_balance_side_for_account_type($type)
                    : 'debit';
                $duplicate = function_exists('coa_find_account_by_name_and_balance_side')
                    ? coa_find_account_by_name_and_balance_side($pdo, $name, $balanceSide, $id)
                    : null;
                if ($duplicate !== null) {
                    $_SESSION['error'] = function_exists('coa_duplicate_account_message')
                        ? coa_duplicate_account_message($name, $balanceSide)
                        : 'An account with this name and balance type already exists.';
                } else {
                $stmt = $pdo->prepare('UPDATE financial_accounts SET name=?, type=?, currency=?, opening_balance=?, status=? WHERE id=?');
                if ($stmt->execute([$name, $type, $currency, $opening_balance, $status, $id])) {
                    recalculateBalance($id);
                    $_SESSION['success'] = 'Account updated successfully.';
                } else {
                    $_SESSION['error'] = 'Failed to update account.';
                }
                }
            }
        }
        redirect(function_exists('balances_accounts_redirect_url') ? balances_accounts_redirect_url() : 'accounts.php');
    }

    if ($action === 'create_sub_account') {
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        $accountName = trim((string) ($_POST['account_name'] ?? ''));
        $paymentWalletType = trim((string) ($_POST['payment_wallet_type'] ?? ''));
        $moduleForRedirect = (string) ($_POST['module'] ?? $_GET['module'] ?? 'balances');
        $redirectParams = array_filter([
            'module' => $moduleForRedirect,
            'selected' => $parentId > 0 ? $parentId : null,
        ]);
        if (!function_exists('balances_create_sub_account')) {
            $_SESSION['error'] = 'Sub-account creation is not available.';
        } else {
            $openingRaw = str_replace([',', ' '], '', (string) ($_POST['opening_balance'] ?? ''));
            $openingDateRaw = trim((string) ($_POST['opening_date'] ?? ''));
            $imagePath = '';
            $imageUploaded = false;
            if ($openingRaw !== '' && !is_numeric($openingRaw)) {
                $result = ['success' => false, 'message' => 'Opening balance must be a number.'];
            } else {
                try {
                    $imagePath = balances_store_uploaded_account_image();
                    $imageUploaded = $imagePath !== '';
                    if ($imagePath === '' && !empty($_POST['institution'])) {
                        $imagePath = balances_copy_institution_logo((string) $_POST['institution'], $pdo);
                    }
                    $result = balances_create_sub_account(
                        $pdo,
                        $parentId,
                        $accountName,
                        $paymentWalletType !== '' ? $paymentWalletType : null,
                        $openingRaw === '' ? 0.0 : (float) $openingRaw,
                        $openingDateRaw !== '' ? $openingDateRaw : null,
                        ['currency' => (string) ($_POST['currency'] ?? ''), 'account_image' => $imagePath]
                    );
                } catch (RuntimeException $e) {
                    $result = ['success' => false, 'message' => $e->getMessage()];
                }
                if (empty($result['success']) && $imagePath !== '') {
                    @unlink(dirname(__DIR__, 2) . '/' . $imagePath);
                }
            }
            if (!empty($result['success']) && $imageUploaded) {
                balances_save_custom_institution($pdo, $accountName, $paymentWalletType, $imagePath);
            }
            if (!empty($result['success'])) {
                $_SESSION['bal_lottie_success'] = (string) ($result['message'] ?? 'Sub-account created successfully.');
                $newCurrency = strtoupper(trim((string) ($_POST['currency'] ?? '')));
                if (is_file(dirname(__DIR__, 2) . '/includes/gl/gl_core.php')) {
                    require_once dirname(__DIR__, 2) . '/includes/gl/gl_core.php';
                    if ($newCurrency !== '' && $newCurrency !== gl_base_currency($pdo) && gl_exchange_rate($pdo, $newCurrency, date('Y-m-d')) === null) {
                        $_SESSION['bal_lottie_success'] .= ' Add a ' . $newCurrency . ' exchange rate (Accounting > Exchange Rates) so this account reaches the reports.';
                    }
                }
            } else {
                $_SESSION['error'] = (string) ($result['message'] ?? 'Could not create sub-account.');
            }
        }
        redirect(function_exists('balances_accounts_redirect_url') ? balances_accounts_redirect_url($redirectParams) : ('accounts.php?' . http_build_query($redirectParams)));
    }

    if ($action === 'assign_existing_sub_account') {
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $moduleForRedirect = (string) ($_POST['module'] ?? $_GET['module'] ?? 'balances');
        $redirectParams = array_filter([
            'module' => $moduleForRedirect,
            'selected' => $parentId > 0 ? $parentId : null,
        ]);
        if ($accountId > 0) {
            $checkStmt = $pdo->prepare('SELECT is_system FROM financial_accounts WHERE id = ?');
            $checkStmt->execute([$accountId]);
            if ((int)($checkStmt->fetchColumn() ?? 0) === 1) {
                $_SESSION['error'] = 'System accounts are locked and cannot be assigned or moved.';
                redirect(function_exists('balances_accounts_redirect_url') ? balances_accounts_redirect_url($redirectParams) : ('accounts.php?' . http_build_query($redirectParams)));
            }
        }
        if (!function_exists('balances_assign_account_as_sub_account')) {
            $_SESSION['error'] = 'Assigning existing accounts is not available.';
        } else {
            $result = balances_assign_account_as_sub_account($pdo, $accountId, $parentId);
            if (!empty($result['success'])) {
                $_SESSION['bal_lottie_success'] = (string) ($result['message'] ?? 'Account assigned successfully.');
            } else {
                $_SESSION['error'] = (string) ($result['message'] ?? 'Could not assign account.');
            }
        }
        redirect(function_exists('balances_accounts_redirect_url') ? balances_accounts_redirect_url($redirectParams) : ('accounts.php?' . http_build_query($redirectParams)));
    }

    if ($action === 'unassign_sub_account') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        $moduleForRedirect = (string) ($_POST['module'] ?? $_GET['module'] ?? 'balances');
        $redirectParams = array_filter([
            'module' => $moduleForRedirect,
            'selected' => $parentId > 0 ? $parentId : null,
        ]);
        if ($accountId > 0) {
            $checkStmt = $pdo->prepare('SELECT is_system FROM financial_accounts WHERE id = ?');
            $checkStmt->execute([$accountId]);
            if ((int)($checkStmt->fetchColumn() ?? 0) === 1) {
                $_SESSION['error'] = 'System accounts are locked and cannot be moved or unassigned.';
                redirect(function_exists('balances_accounts_redirect_url') ? balances_accounts_redirect_url($redirectParams) : ('accounts.php?' . http_build_query($redirectParams)));
            }
        }
        if (!function_exists('balances_unassign_sub_account')) {
            $_SESSION['error'] = 'Unassigning sub-accounts is not available.';
        } else {
            $result = balances_unassign_sub_account($pdo, $accountId);
            if (!empty($result['success'])) {
                $_SESSION['bal_lottie_success'] = (string) ($result['message'] ?? 'Account moved to main accounts.');
            } else {
                $_SESSION['error'] = (string) ($result['message'] ?? 'Could not unassign account.');
            }
        }
        redirect(function_exists('balances_accounts_redirect_url') ? balances_accounts_redirect_url($redirectParams) : ('accounts.php?' . http_build_query($redirectParams)));
    }

    if ($action === 'deactivate' || $action === 'delete_permanent') {
        if (!isAdmin()) {
            $_SESSION['error'] = 'Access denied. Only admins can delete accounts.';
            redirect(function_exists('balances_accounts_redirect_url') ? balances_accounts_redirect_url() : 'accounts.php');
        }

        $id = (int) ($_POST['id'] ?? 0);
        $moduleForRedirect = (string) ($_POST['module'] ?? $_GET['module'] ?? 'balances');
        $selectedAfter = (int) ($_POST['selected'] ?? $_GET['selected'] ?? 0);

        if ($id <= 0) {
            $_SESSION['error'] = 'Account not found.';
        } else {
            $checkStmt = $pdo->prepare('SELECT id, name, is_system FROM financial_accounts WHERE id = ?');
            $checkStmt->execute([$id]);
            $accountRow = $checkStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $isLocked = false;
            if ($accountRow) {
                [$accCode] = coa_parse_account_name_parts((string) ($accountRow['name'] ?? ''));
                $isLocked = (int) ($accountRow['is_system'] ?? 0) === 1
                    || (function_exists('coa_account_is_required_system_parent')
                        && coa_account_is_required_system_parent([
                            'code' => $accCode,
                            'name' => (string) ($accountRow['name'] ?? ''),
                            'is_system' => (int) ($accountRow['is_system'] ?? 0),
                        ]));
            }
            if ($isLocked) {
                $_SESSION['error'] = 'Petty Cash is required by the system and cannot be deactivated or deleted.';
                $redirectParams = array_filter([
                    'module' => $moduleForRedirect !== '' ? $moduleForRedirect : null,
                    'selected' => $selectedAfter > 0 ? $selectedAfter : null,
                ]);
                redirect(function_exists('balances_accounts_redirect_url') ? balances_accounts_redirect_url($redirectParams) : ('accounts.php?' . http_build_query($redirectParams)));
            }
            if ($action === 'deactivate') {
                $pdo->prepare("UPDATE financial_accounts SET status='inactive' WHERE id=?")->execute([$id]);
                $_SESSION['bal_lottie_success'] = 'Account removed from the list. History is kept and you can restore it later if needed.';
            } else {
                $accStmt = $pdo->prepare('SELECT name FROM financial_accounts WHERE id = ? LIMIT 1');
                $accStmt->execute([$id]);
                $accRow = $accStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($accRow) {
                    [$deletedCode] = coa_parse_account_name_parts((string) ($accRow['name'] ?? ''));
                    if ($deletedCode !== ''
                        && function_exists('coa_is_default_parent_account_code')
                        && coa_is_default_parent_account_code($deletedCode)
                        && !(function_exists('coa_is_required_default_parent_code') && coa_is_required_default_parent_code($deletedCode))
                    ) {
                        coa_suppress_default_account_code($pdo, $deletedCode);
                    }
                }

                $pdo->prepare('UPDATE financial_accounts SET parent_id = NULL WHERE parent_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM account_transactions WHERE account_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM financial_accounts WHERE id=?')->execute([$id]);
                $_SESSION['bal_lottie_success'] = 'Account deleted permanently.';
                balancesScheduleLedgerSync($pdo);
            }

            if ($selectedAfter === $id) {
                $selectedAfter = 0;
            }
        }

        $redirectParams = array_filter([
            'module' => $moduleForRedirect !== '' ? $moduleForRedirect : null,
            'selected' => $selectedAfter > 0 ? $selectedAfter : null,
        ]);
        redirect(function_exists('balances_accounts_redirect_url') ? balances_accounts_redirect_url($redirectParams) : ('accounts.php?' . http_build_query($redirectParams)));
    }

    if ($action === 'bulk_delete_sub_accounts') {
        if (!isAdmin()) {
            $_SESSION['error'] = 'Access denied. Only admins can delete accounts.';
            redirect(function_exists('balances_accounts_redirect_url') ? balances_accounts_redirect_url() : 'accounts.php');
        }

        $parentId = (int) ($_POST['parent_id'] ?? 0);
        $moduleForRedirect = (string) ($_POST['module'] ?? $_GET['module'] ?? 'balances');
        $selectedAfter = (int) ($_POST['selected'] ?? $_GET['selected'] ?? $parentId);
        $deleteMode = (string) ($_POST['delete_mode'] ?? 'deactivate') === 'delete_permanent'
            ? 'delete_permanent'
            : 'deactivate';
        $rawIds = $_POST['ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn ($id) => $id > 0)));

        if ($ids === []) {
            $_SESSION['error'] = 'Select at least one sub-account to delete.';
        } else {
            $deleted = 0;
            $skipped = 0;
            $parentCheck = $pdo->prepare(
                'SELECT id FROM financial_accounts WHERE id = ? AND (parent_id IS NULL OR parent_id = 0) LIMIT 1'
            );
            $parentCheck->execute([$parentId]);
            if (!$parentCheck->fetch(PDO::FETCH_ASSOC)) {
                $_SESSION['error'] = 'Parent account not found.';
            } else {
                $subCheck = $pdo->prepare(
                    'SELECT id, name, is_system FROM financial_accounts WHERE id = ? AND parent_id = ? LIMIT 1'
                );
                foreach ($ids as $id) {
                    $subCheck->execute([$id, $parentId]);
                    $row = $subCheck->fetch(PDO::FETCH_ASSOC) ?: null;
                    if (!$row || (int) ($row['is_system'] ?? 0) === 1) {
                        $skipped++;
                        continue;
                    }
                    [$rowCode] = coa_parse_account_name_parts((string) ($row['name'] ?? ''));
                    if (function_exists('coa_account_is_required_system_parent')
                        && coa_account_is_required_system_parent([
                            'code' => $rowCode,
                            'name' => (string) ($row['name'] ?? ''),
                            'is_system' => 0,
                        ])
                    ) {
                        $skipped++;
                        continue;
                    }

                    if ($deleteMode === 'deactivate') {
                        $pdo->prepare("UPDATE financial_accounts SET status='inactive' WHERE id=?")->execute([$id]);
                        $deleted++;
                        continue;
                    }

                    [$deletedCode] = coa_parse_account_name_parts((string) ($row['name'] ?? ''));
                    if ($deletedCode !== ''
                        && function_exists('coa_is_default_parent_account_code')
                        && coa_is_default_parent_account_code($deletedCode)
                    ) {
                        coa_suppress_default_account_code($pdo, $deletedCode);
                    }
                    $pdo->prepare('UPDATE financial_accounts SET parent_id = NULL WHERE parent_id = ?')->execute([$id]);
                    $pdo->prepare('DELETE FROM account_transactions WHERE account_id=?')->execute([$id]);
                    $pdo->prepare('DELETE FROM financial_accounts WHERE id=?')->execute([$id]);
                    $deleted++;
                }

                if ($deleted > 0) {
                    if ($deleteMode === 'delete_permanent') {
                        balancesScheduleLedgerSync($pdo);
                        $_SESSION['bal_lottie_success'] = $deleted === 1
                            ? 'Sub-account deleted permanently.'
                            : $deleted . ' sub-accounts deleted permanently.';
                    } else {
                        $_SESSION['bal_lottie_success'] = $deleted === 1
                            ? 'Sub-account removed from the list. History is kept.'
                            : $deleted . ' sub-accounts removed from the list. History is kept.';
                    }
                } elseif ($skipped > 0) {
                    $_SESSION['error'] = 'Selected sub-accounts could not be deleted (system or invalid accounts).';
                } else {
                    $_SESSION['error'] = 'No sub-accounts were deleted.';
                }

                if ($skipped > 0 && $deleted > 0) {
                    $_SESSION['success'] = ($deleted . ' deleted, ' . $skipped . ' skipped (system or invalid).');
                }
            }
        }

        $redirectParams = array_filter([
            'module' => $moduleForRedirect !== '' ? $moduleForRedirect : null,
            'selected' => $selectedAfter > 0 ? $selectedAfter : null,
        ]);
        redirect(function_exists('balances_accounts_redirect_url') ? balances_accounts_redirect_url($redirectParams) : ('accounts.php?' . http_build_query($redirectParams)));
    }
}

$sessionError = '';
if (!empty($_SESSION['error'])) {
    $sessionError = (string) $_SESSION['error'];
    unset($_SESSION['error']);
}

// Always render balances from live transactions so UI reflects system-created data.
// Formula: opening_balance + total_credits - total_debits.
$accounts = [];
$accountRows = [];
$totalAccounts = 0;
$activeAccounts = 0;
$inactiveAccounts = 0;
$totalBalance = 0.0;

try {
    $accounts = function_exists('balancesFetchAccountsWithLiveBalance')
        ? balancesFetchAccountsWithLiveBalance($pdo, true)
        : [];
    if (function_exists('balances_merge_missing_sub_accounts')) {
        $accounts = balances_merge_missing_sub_accounts($pdo, $accounts, true);
    }

    $totalAccounts = count($accounts);

    foreach ($accounts as $acc) {
        $status = strtolower((string) ($acc['status'] ?? 'inactive'));
        if ($status === 'active') {
            $activeAccounts++;
        } else {
            $inactiveAccounts++;
        }

        $balance = isset($acc['live_balance']) ? (float) $acc['live_balance'] : (float) ($acc['current_balance'] ?? 0);
        $totalBalance += $balance;

        $nameRaw = (string) ($acc['name'] ?? '');
        $code = '';
        $name = $nameRaw;
        if (preg_match('/^\s*([0-9]{3,10})\s*-\s*(.+)$/', $nameRaw, $m)) {
            $code = trim($m[1]);
            $name = trim($m[2]);
        }

        $typeRaw = strtolower((string) ($acc['type'] ?? ''));
        $typeLabelMap = [
            'asset' => 'Asset',
            'liability' => 'Liability',
            'equity' => 'Equity',
            'revenue' => 'Revenue',
            'expense' => 'Expense',
            'cash' => 'Asset',
            'bank' => 'Asset',
            'mobile' => 'Asset',
        ];
        $typeLabel = $typeLabelMap[$typeRaw] ?? ucfirst(str_replace('_', ' ', $typeRaw));
        $typeLabelDisplay = ucwords(strtolower((string) $typeLabel));
        $typeSlug = strtolower((string) $typeLabel);
        $normalBalance = in_array($typeSlug, ['liability', 'equity', 'revenue'], true) ? 'credit' : 'debit';
        $accountImagePath = trim((string) ($acc['account_image'] ?? ''));
        $accountImageUrl = $accountImagePath !== '' && function_exists('balancesAccountImageUrl')
            ? balancesAccountImageUrl($accountImagePath)
            : '';
        $description = function_exists('coa_account_description_for_code')
            ? coa_account_description_for_code(
                $code !== '' ? $code : '-',
                function_exists('coa_account_type_description') ? coa_account_type_description($typeSlug) : 'Chart of accounts category'
            )
            : (function_exists('coa_account_type_description') ? coa_account_type_description($typeSlug) : 'Chart of accounts category');
        $displayOrder = 999;
        if (function_exists('coa_default_accounts_catalog')) {
            foreach (coa_default_accounts_catalog() as $catalogParent) {
                if ((string) ($catalogParent['code'] ?? '') === $code) {
                    $displayOrder = (int) ($catalogParent['display_order'] ?? 999);
                    break;
                }
            }
        }

        $accountRows[] = [
            'id' => (int) ($acc['id'] ?? 0),
            'code' => $code !== '' ? $code : '-',
            'name' => $name !== '' ? $name : '-',
            'type' => $typeSlug,
            'type_label' => $typeLabelDisplay,
            'description' => $description,
            'display_order' => $displayOrder,
            'parent_id' => (int) ($acc['parent_id'] ?? 0),
            'normal_balance' => $normalBalance,
            'normal_balance_short' => $normalBalance === 'credit' ? 'Cr' : 'Dr',
            'status' => $status === 'active' ? 'active' : 'inactive',
            'currency' => (string) ($acc['currency'] ?? 'TZS'),
            'balance' => $balance,
            'image_url' => $accountImageUrl,
            'is_system' => (
                (int) ($acc['is_system'] ?? 0) === 1
                || (function_exists('coa_account_is_required_system_parent') && coa_account_is_required_system_parent([
                    'code' => $code,
                    'name' => $name,
                    'is_system' => (int) ($acc['is_system'] ?? 0),
                ]))
            ) ? 1 : 0,
            'raw' => $acc,
        ];
    }
} catch (Throwable $e) {
    error_log('accounts.php load: ' . $e->getMessage());
    if ($sessionError === '') {
        $sessionError = 'Could not load accounts. Please refresh or contact support if this continues.';
    }
}

$searchQ = trim((string) ($_GET['search'] ?? ''));
$subSearchQ = trim((string) ($_GET['sub_search'] ?? ''));

$parentAccounts = [];
$childrenByParent = [];
foreach ($accountRows as $row) {
    $pid = (int) ($row['parent_id'] ?? 0);
    if ($pid > 0) {
        $childrenByParent[$pid][] = $row;
        continue;
    }
    $parentAccounts[] = $row;
}

usort($parentAccounts, static function ($a, $b) {
    $order = ($a['display_order'] ?? 999) <=> ($b['display_order'] ?? 999);
    if ($order !== 0) {
        return $order;
    }
    $codeA = (int) preg_replace('/\D/', '', (string) ($a['code'] ?? '0'));
    $codeB = (int) preg_replace('/\D/', '', (string) ($b['code'] ?? '0'));
    if ($codeA !== $codeB) {
        return $codeA <=> $codeB;
    }

    return strcasecmp((string) $a['name'], (string) $b['name']);
});
foreach ($childrenByParent as &$childGroup) {
    usort($childGroup, static fn($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
}
unset($childGroup);

foreach ($parentAccounts as &$parentRow) {
    $parentRow['child_count'] = function_exists('balances_count_child_accounts')
        ? balances_count_child_accounts($pdo, (int) ($parentRow['id'] ?? 0), true)
        : count($childrenByParent[$parentRow['id']] ?? []);
}
unset($parentRow);

$allParentAccounts = $parentAccounts;

if ($searchQ !== '') {
    $needle = strtolower($searchQ);
    $parentAccounts = array_values(array_filter($parentAccounts, static function ($row) use ($needle) {
        $hay = strtolower($row['code'] . ' ' . $row['name'] . ' ' . $row['type_label'] . ' ' . $row['description']);
        return strpos($hay, $needle) !== false;
    }));
}

$selectedExplicit = isset($_GET['selected']) && (int) ($_GET['selected'] ?? 0) > 0;
$selectedId = (int) ($_GET['selected'] ?? 0);
if ($selectedId <= 0 && !empty($allParentAccounts)) {
    $selectedId = (int) $allParentAccounts[0]['id'];
}
// On mobile, only show the detail pane when the user explicitly opened an account.
$mobileShowDetail = $selectedExplicit;

$selectedParent = null;
foreach ($allParentAccounts as $parentRow) {
    if ((int) $parentRow['id'] === $selectedId) {
        $selectedParent = $parentRow;
        break;
    }
}
if (!$selectedParent && $selectedId > 0) {
    try {
        $parentStmt = $pdo->prepare('SELECT * FROM financial_accounts WHERE id = ? LIMIT 1');
        $parentStmt->execute([$selectedId]);
        $rawParent = $parentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($rawParent && (int) ($rawParent['parent_id'] ?? 0) <= 0) {
            $selectedParent = function_exists('balances_format_account_row_for_ui')
                ? balances_format_account_row_for_ui($rawParent)
                : null;
        }
    } catch (Throwable $e) {
    }
}
if (!$selectedParent && !empty($allParentAccounts)) {
    $selectedParent = $allParentAccounts[0];
    $selectedId = (int) $selectedParent['id'];
}

$selectedParentRaw = null;
if (is_array($selectedParent)) {
    $selectedParentRaw = is_array($selectedParent['raw'] ?? null) ? $selectedParent['raw'] : null;
    if ($selectedParentRaw === null && (int) ($selectedParent['id'] ?? 0) > 0) {
        try {
            $rawStmt = $pdo->prepare('SELECT * FROM financial_accounts WHERE id = ? LIMIT 1');
            $rawStmt->execute([(int) $selectedParent['id']]);
            $selectedParentRaw = $rawStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $selectedParentRaw = null;
        }
    }
}
$selectedParentPaymentMode = is_array($selectedParentRaw) ? balances_parent_payment_type_mode($selectedParentRaw) : 'none';
$selectedParentShowsPaymentType = $selectedParentPaymentMode === 'required';
$selectedParentDefaultPaymentType = $selectedParentShowsPaymentType
    ? balances_infer_payment_wallet_type($selectedParentRaw)
    : '';

$accountRowsById = [];
foreach ($accountRows as $row) {
    $accountRowsById[(int) ($row['id'] ?? 0)] = $row;
}

$selectedChildren = function_exists('balances_fetch_child_rows_for_parent')
    ? balances_fetch_child_rows_for_parent($pdo, $selectedId, $accountRowsById, true)
    : ($childrenByParent[$selectedId] ?? []);

if ($subSearchQ !== '' && $selectedChildren !== []) {
    $subNeedle = strtolower($subSearchQ);
    $selectedChildren = array_values(array_filter($selectedChildren, static function ($row) use ($subNeedle) {
        $hay = strtolower($row['code'] . ' ' . $row['name'] . ' ' . $row['description']);
        return strpos($hay, $subNeedle) !== false;
    }));
}

$assignableAccounts = function_exists('balances_list_assignable_accounts')
    ? balances_list_assignable_accounts($accountRows, $selectedParent, $childrenByParent)
    : [];

// Mobile accordion: children + assignable options per parent account.
$mobileParentMeta = [];
foreach ($parentAccounts as $parentRow) {
    $pid = (int) ($parentRow['id'] ?? 0);
    if ($pid <= 0) {
        continue;
    }
    $parentRaw = is_array($parentRow['raw'] ?? null) ? $parentRow['raw'] : null;
    if ($parentRaw === null) {
        try {
            $rawStmt = $pdo->prepare('SELECT * FROM financial_accounts WHERE id = ? LIMIT 1');
            $rawStmt->execute([$pid]);
            $parentRaw = $rawStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $parentRaw = null;
        }
    }
    $paymentMode = is_array($parentRaw) ? balances_parent_payment_type_mode($parentRaw) : 'none';
    $showsPayment = $paymentMode === 'required';
    $assignRows = function_exists('balances_list_assignable_accounts')
        ? balances_list_assignable_accounts($accountRows, $parentRow, $childrenByParent)
        : [];
    $assignOptions = [];
    foreach ($assignRows as $assignRow) {
        $label = trim((string) ($assignRow['name'] ?? ''));
        if (($assignRow['code'] ?? '-') !== '-' && ($assignRow['code'] ?? '') !== '') {
            $label = $assignRow['code'] . ' - ' . $label;
        }
        $balanceLabel = number_format((float) ($assignRow['balance'] ?? 0), 2) . ' ' . (string) ($assignRow['currency'] ?? 'TZS');
        $assignOptions[] = [
            'id' => (int) ($assignRow['id'] ?? 0),
            'label' => $label . ' (' . $balanceLabel . ')',
        ];
    }
    $mobileParentMeta[$pid] = [
        'id' => $pid,
        'name' => (string) ($parentRow['name'] ?? ''),
        'payment_type_mode' => $paymentMode,
        'currency' => strtoupper((string) ($parentRow['currency'] ?? 'TZS')),
        'default_payment_type' => $showsPayment ? balances_infer_payment_wallet_type($parentRaw) : '',
        'assignable' => $assignOptions,
    ];
}

$moveParentTargets = [];
if (function_exists('balances_list_target_parent_accounts')) {
    foreach ($allParentAccounts as $parentRow) {
        $sourceId = (int) ($parentRow['id'] ?? 0);
        if ($sourceId <= 0) {
            continue;
        }
        $targets = balances_list_target_parent_accounts($allParentAccounts, $sourceId, $parentRow);
        $moveParentTargets[$sourceId] = [
            'name' => (string) ($parentRow['name'] ?? ''),
            'code' => (string) ($parentRow['code'] ?? '-'),
            'child_count' => (int) ($parentRow['child_count'] ?? 0),
            'targets' => array_map(static function ($targetRow) {
                $label = function_exists('coa_format_catalog_parent_option_label')
                    ? coa_format_catalog_parent_option_label($targetRow)
                    : (string) ($targetRow['name'] ?? '');

                return [
                    'id' => (int) ($targetRow['id'] ?? 0),
                    'label' => $label,
                ];
            }, $targets),
        ];
    }
}

$displayCount = count($parentAccounts);
$moduleParam = (string) ($_GET['module'] ?? 'balances');
$moduleQs = $moduleParam !== '' ? '?module=' . rawurlencode($moduleParam) : '';
$coaCreateUrl = 'coa_create.php?' . http_build_query(['module' => $moduleParam]);
$canManage = isAdmin() || isFinance();

$page_title = 'Accounts';
$accountsPageQuery = array_filter([
    'module' => $moduleParam,
    'selected' => $selectedId > 0 ? $selectedId : null,
    'search' => $searchQ !== '' ? $searchQ : null,
]);
$accountsPageUrl = function_exists('balances_accounts_redirect_url')
    ? balances_accounts_redirect_url($accountsPageQuery)
    : ('accounts.php?' . http_build_query($accountsPageQuery));
$accountsGetAction = function_exists('balances_accounts_redirect_url')
    ? balances_accounts_redirect_url(array_filter(['module' => $moduleParam !== '' ? $moduleParam : null]))
    : 'accounts.php';
$accountsListUrl = function_exists('balances_accounts_redirect_url')
    ? balances_accounts_redirect_url(array_filter([
        'module' => $moduleParam !== '' ? $moduleParam : null,
        'search' => $searchQ !== '' ? $searchQ : null,
    ]))
    : ('accounts.php?' . http_build_query(array_filter([
        'module' => $moduleParam !== '' ? $moduleParam : null,
        'search' => $searchQ !== '' ? $searchQ : null,
    ])));
$selectedQs = http_build_query($accountsPageQuery);

$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--balances-accounts';
$bodyExtraClass = 'page-balances-accounts';

$rayAccountAvatar = static function (array $row) use ($pdo): string {
    $raw = (array) ($row['raw'] ?? []);
    $url = balances_account_logo_url($raw, $pdo);
    if ($url !== '') {
        return '<span class="ray-acc-logo"><img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy"></span>';
    }
    $label = trim(preg_replace('/^\s*\d+\s*-\s*/', '', (string) ($row['name'] ?? '')));
    $initial = $label !== '' ? mb_strtoupper(mb_substr($label, 0, 1)) : '?';

    return '<span class="ray-acc-logo ray-acc-logo--initial" aria-hidden="true">' . htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') . '</span>';
};

$rayResolveParentIcon = static function (array $parentRow): array {
    $iconKey = strtolower(trim((string) ($parentRow['name'] ?? '') . ' ' . (string) ($parentRow['type'] ?? '') . ' ' . (string) ($parentRow['type_label'] ?? '')));
    $iconTone = 'slate';
    $iconClass = 'fa-wallet';
    if (str_contains($iconKey, 'expense')) {
        $iconTone = 'violet';
        $iconClass = 'fa-file-invoice';
    } elseif (str_contains($iconKey, 'revenue') || str_contains($iconKey, 'income')) {
        $iconTone = 'purple';
        $iconClass = 'fa-chart-line';
    } elseif (str_contains($iconKey, 'cogs') || str_contains($iconKey, 'cost of goods') || str_contains($iconKey, 'inventory')) {
        $iconTone = 'orange';
        $iconClass = 'fa-box';
    } elseif (str_contains($iconKey, 'asset')) {
        $iconTone = 'green';
        $iconClass = 'fa-coins';
    } elseif (str_contains($iconKey, 'bank') || str_contains($iconKey, 'uba') || str_contains($iconKey, 'crdb') || str_contains($iconKey, 'nmb')) {
        $iconTone = 'blue';
        $iconClass = 'fa-university';
    } elseif (str_contains($iconKey, 'cash') || str_contains($iconKey, 'petty') || str_contains($iconKey, 'mobile')) {
        $iconTone = 'rose';
        $iconClass = 'fa-wallet';
    } elseif (str_contains($iconKey, 'liabilit')) {
        $iconTone = 'amber';
        $iconClass = 'fa-balance-scale';
    } elseif (str_contains($iconKey, 'equity') || str_contains($iconKey, 'capital')) {
        $iconTone = 'indigo';
        $iconClass = 'fa-landmark';
    }

    return [$iconTone, $iconClass];
};

include __DIR__ . '/includes/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body.page-balances-accounts.dashboard .layout-main-wrapper,
    body.page-balances-accounts.dashboard .layout-main-wrapper > .flex-grow-1 {
        background: #f4f6f8 !important;
    }
    body.page-balances-accounts .employee-header.employee-header--balances-accounts {
        background: #f4f6f8 !important;
        border: none !important;
        box-shadow: none !important;
        padding: 0 1.75rem !important;
        margin-bottom: 0;
        height: auto !important;
        min-height: 0;
        position: relative !important;
        top: auto !important;
    }
    body.page-balances-accounts .employee-header--balances-accounts::after {
        display: none !important;
    }
    body.page-balances-accounts .employee-header--balances-accounts .header-content {
        padding: 0.75rem 0 0.25rem !important;
        min-height: 0;
        background: transparent !important;
    }
    body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; }
    .main-content { background: #f4f6f8; color: #111827; }
    .ray-accounts-wrap { max-width: 1400px; margin: 0 auto; padding: 1.5rem 1.75rem 2.5rem; }
    .ray-accounts-grid {
        display: grid;
        grid-template-columns: minmax(410px, 0.75fr) minmax(0, 1.25fr);
        gap: 1.25rem;
        align-items: start;
    }
    @media (max-width: 1100px) {
        .ray-accounts-grid { grid-template-columns: 1fr; }
    }
    .ray-mobile-back {
        display: none;
    }
    .ray-accounts-mobile {
        display: none;
    }
    .ray-panel {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        overflow: hidden;
        min-height: 560px;
        display: flex;
        flex-direction: column;
    }
    .ray-panel-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #f1f5f9;
    }
    .ray-panel-title { font-size: 1rem; font-weight: 600; color: #111827; margin: 0; }
    .ray-btn-new {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 0.5rem 1rem;
        border-radius: 9999px !important;
        border: 1px solid #d1d5db;
        background: #fff;
        color: #111827;
        font-size: 0.8125rem;
        font-weight: 500;
        text-decoration: none;
        white-space: nowrap;
        cursor: pointer;
        min-height: 2.35rem;
    }
    .ray-btn-new:hover { background: #f9fafb; color: #111827; }
    .ray-btn-new-account {
        border-color: #7c3aed !important;
        background: #7c3aed;
        color: #fff;
        border-radius: 9999px !important;
    }
    .ray-btn-new-account:hover {
        background: #6d28d9;
        border-color: #6d28d9;
        color: #fff;
    }
    .ray-btn-secondary {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 0.5rem 1rem;
        border-radius: 9999px !important;
        border: 1px solid #d1d5db;
        background: #f9fafb;
        color: #374151;
        font-size: 0.8125rem;
        font-weight: 500;
        text-decoration: none;
        white-space: nowrap;
        cursor: pointer;
        min-height: 2.35rem;
    }
    .ray-btn-secondary:hover { background: #f3f4f6; color: #111827; }
    .ray-head-actions {
        display: flex;
        flex-direction: row;
        flex-wrap: nowrap;
        align-items: center;
        gap: 8px;
        justify-content: flex-end;
        flex-shrink: 0;
    }
    .ray-head-actions .ray-btn-new,
    .ray-head-actions .ray-btn-new-account {
        flex: 0 0 auto;
        width: auto;
    }
    .ray-search {
        padding: 0.85rem 1.25rem 1rem;
        border-bottom: 1px solid #f8fafc;
    }
    .ray-search-field {
        position: relative;
    }
    .ray-search-icon {
        display: none;
    }
    .ray-search input {
        width: 100%;
        padding: 0.65rem 0.85rem;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        font-size: 0.8125rem;
        color: #111827;
        background: #fff;
    }
    .ray-search input::placeholder { color: #9ca3af; }
    .ray-search input:focus {
        outline: none;
        border-color: #94a3b8;
        box-shadow: 0 0 0 3px rgba(148, 163, 184, 0.15);
    }
    .ray-mobile-icon,
    .ray-mobile-card-main {
        display: contents;
    }
    .ray-mobile-card-copy {
        display: contents;
    }
    .ray-table { width: 100%; border-collapse: collapse; }
    .ray-table thead th {
        padding: 0.6rem 1rem;
        font-size: 0.6875rem;
        font-weight: 500;
        color: #6b7280;
        text-align: left;
        border-bottom: 1px solid #f1f5f9;
        background: #fafbfc;
    }
    .ray-table tbody td {
        padding: 0.55rem 1rem;
        border-bottom: 1px solid #f8fafc;
        vertical-align: middle;
        font-size: 0.8125rem;
        color: #111827;
    }
    .ray-table tbody tr:last-child td { border-bottom: 0; }
    .ray-parent-row { cursor: pointer; transition: background 0.15s ease; }
    .ray-parent-row:hover { background: #f8fafc; }
    .ray-parent-row.is-selected { background: #eff6ff; }
    .ray-acc-logo {
        flex: 0 0 28px; width: 28px; height: 28px; border-radius: 8px; overflow: hidden;
        display: inline-flex; align-items: center; justify-content: center; margin-right: 4px;
        background: #fff; box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.08);
    }
    .ray-acc-logo img { width: 100%; height: 100%; object-fit: contain; }
    .ray-acc-logo--initial { background: #ede9fe; color: #6d28d9; font-size: 0.75rem; font-weight: 700; box-shadow: none; }
    html[data-theme="dark"] body.page-balances-accounts .ray-acc-logo--initial { background: #2a3942; color: #e9edef; }
    .ray-account-name { font-weight: 600; color: #111827; display: flex; align-items: center; flex-wrap: nowrap; white-space: nowrap; gap: 6px; }
    .ray-sub-check { display: none; width: 15px; height: 15px; margin: 0 2px 0 0; accent-color: #dc2626; cursor: pointer; flex-shrink: 0; }
    .ray-panel--subaccounts.is-selecting .ray-sub-check { display: inline-block; }
    .ray-panel--subaccounts.is-selecting tr[data-sub-id].is-checked td { background: #fef2f2; }
    .ray-btn-select[aria-pressed="true"] { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
    .ray-select-bar { display: flex; align-items: center; gap: 10px; margin: 0 1rem 8px; padding: 6px 10px; border: 1px solid #fecaca; background: #fff7f7; border-radius: 8px; font-size: 0.78rem; color: #7f1d1d; }
    .ray-select-bar[hidden] { display: none; }
    .ray-select-all { display: inline-flex; align-items: center; gap: 5px; margin: 0; font-weight: 600; cursor: pointer; }
    .ray-select-all input { accent-color: #dc2626; }
    .ray-select-delete { margin-left: auto; border: 0; border-radius: 6px; padding: 4px 10px; background: #dc2626; color: #fff; font-weight: 600; }
    .ray-select-delete:disabled { opacity: 0.45; cursor: not-allowed; }
    .ray-select-cancel { border: 1px solid #e5e7eb; border-radius: 6px; padding: 4px 10px; background: #fff; color: #374151; }
    html[data-theme="dark"] body.page-balances-accounts .ray-select-bar { background: #2a1416; border-color: #7f1d1d; color: #fecaca; }
    html[data-theme="dark"] body.page-balances-accounts .ray-panel--subaccounts.is-selecting tr[data-sub-id].is-checked td { background: #2a1416 !important; }
    .ray-account-desc { font-size: 0.75rem; color: #6b7280; margin-top: 4px; line-height: 1.45; max-width: 360px; }
    .ray-table tr.ray-sub-group-row td { background: #f8fafc; padding: 0.55rem 1rem; border-bottom: 1px solid #eef2f7; }
    .ray-sub-group-row td, .ray-m-sub-group { font-size: 0.75rem; }
    .ray-m-sub-group { display: flex; align-items: center; gap: 0.4rem; padding: 0.6rem 0.25rem 0.2rem; }
    .ray-sub-group-name { font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.03em; }
    .ray-sub-group-count { display: inline-flex; min-width: 20px; justify-content: center; padding: 1px 6px; border-radius: 999px; background: #e2e8f0; color: #0f172a; font-weight: 800; margin-left: 6px; }
    .ray-sub-group-total { float: right; font-weight: 600; color: #0f172a; font-variant-numeric: tabular-nums; }
    .ray-m-sub-group .ray-sub-group-total { float: none; margin-left: auto; }
    .ray-group-toggle { cursor: pointer; user-select: none; }
    .ray-group-toggle:hover td, .ray-m-sub-group.ray-group-toggle:hover { background: #f1f5f9; }
    .ray-group-chevron { font-size: 0.625rem; color: #64748b; width: 12px; margin-right: 6px; transition: transform 0.15s ease; }
    .ray-group-toggle.is-collapsed .ray-group-chevron { transform: rotate(-90deg); }
    [data-group-member].is-group-hidden { display: none !important; }
    html[data-theme="dark"] body.page-balances-accounts .ray-group-toggle:hover td,
    html[data-theme="dark"] body.page-balances-accounts .ray-m-sub-group.ray-group-toggle:hover { background: #202c33 !important; }
    html[data-theme="dark"] body.page-balances-accounts .ray-table tr.ray-sub-group-row td { background: #0b141a !important; }
    html[data-theme="dark"] body.page-balances-accounts .ray-sub-group-name { color: #e9edef; }
    html[data-theme="dark"] body.page-balances-accounts .ray-sub-group-count { background: #2a3942; color: #e9edef; }
    html[data-theme="dark"] body.page-balances-accounts .ray-sub-group-total { color: #e9edef; }
    .ray-badge {
        display: inline-flex;
        align-items: center;
        padding: 2px 7px;
        border-radius: 6px;
        font-size: 0.625rem;
        font-weight: 600;
        line-height: 1.2;
    }
    .ray-badge-code { background: #f3f4f6; color: #374151; }
    .ray-badge-dr { background: #dbeafe; color: #1d4ed8; }
    .ray-badge-cr { background: #ede9fe; color: #6d28d9; }
    .ray-sub-count { font-size: 0.8125rem; color: #111827; font-weight: 500; }
    .ray-action-btn {
        width: 30px;
        height: 30px;
        border: none;
        background: transparent;
        color: #9ca3af;
        border-radius: 6px;
        cursor: pointer;
    }
    .ray-action-btn:hover { background: #f3f4f6; color: #374151; }
    .ray-panel-body { flex: 1; overflow: auto; }
    .ray-detail-head {
        flex-wrap: nowrap;
    }
    .ray-detail-head > div:first-child {
        min-width: 0;
        flex: 1 1 auto;
    }
    .ray-detail-title {
        display: flex;
        align-items: center;
        flex-wrap: nowrap;
        white-space: nowrap;
        gap: 8px;
        font-size: 1.05rem;
        font-weight: 700;
        color: #111827;
    }
    .ray-detail-column {
        display: flex;
        flex-direction: column;
        gap: 0.85rem;
        min-height: 560px;
    }
    .ray-panel--subaccounts {
        flex: 1;
        min-height: 0;
    }
    .ray-empty {
        padding: 3rem 1.5rem;
        text-align: center;
        color: #6b7280;
        font-size: 0.875rem;
    }
    .ray-empty-icon {
        width: 52px; height: 52px; margin: 0 auto 1rem;
        border-radius: 50%; background: #f8fafc; color: #94a3b8;
        display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
    }
    .ray-alert {
        margin-bottom: 1rem;
        padding: 0.75rem 1rem;
        border-radius: 10px;
        background: #fef2f2;
        color: #b91c1c;
        font-size: 0.875rem;
    }
    .sub-modal-backdrop {
        position: fixed; inset: 0; z-index: 10900;
        background: rgba(15, 23, 42, 0.45);
        display: none; align-items: center; justify-content: center; padding: 1rem;
    }
    .sub-modal-backdrop.is-open { display: flex; }
    .sub-modal {
        width: 100%; max-width: 560px; background: #fff; border-radius: 12px;
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.18); padding: 1.5rem 1.5rem 1.25rem;
    }
    .sub-modal-head {
        display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 1.25rem;
    }
    .sub-modal-head h2 { margin: 0; font-size: 1.125rem; font-weight: 700; color: #111827; line-height: 1.35; }
    .sub-modal-close {
        border: none; background: transparent; color: #9ca3af; font-size: 1.5rem; line-height: 1; cursor: pointer;
    }
    .sub-modal-label-row {
        display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px;
    }
    .sub-modal-label-row label { font-size: 0.875rem; font-weight: 500; color: #111827; }
    .sub-modal-count { font-size: 0.75rem; color: #9ca3af; }
    .sub-modal-input {
        width: 100%; padding: 0.8rem 0.9rem; border: 1px solid #d1d5db; border-radius: 8px;
        font-size: 0.8125rem; color: #111827;
    }
    .sub-modal-input:focus {
        outline: none; border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
    }
    .sub-modal-opening-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .sub-modal-pair { display: flex; gap: 12px; }
    .sub-modal-pair > * { flex: 1 1 0; min-width: 0; }
    .sub-modal-pair > [hidden] { display: none; }
    #subAccountModal .sub-modal { padding: 1.1rem 1.25rem 1rem; max-height: calc(100vh - 2rem); overflow-y: auto; }
    #subAccountModal .sub-modal-head { margin-bottom: 0.75rem; }
    #subAccountModal .sub-modal-label-row { margin-bottom: 5px; }
    #subAccountModal .sub-modal-pair,
    #subAccountModal .sub-modal-opening-row { margin-top: 12px; }
    #subAccountModal .sub-modal-input { padding: 0.55rem 0.8rem; }
    #subAccountModal .sub-modal-select { padding-top: 0.55rem; padding-bottom: 0.55rem; }
    #subAccountModal .sub-cur-trigger { padding-top: 7px; padding-bottom: 7px; }
    #subAccountModal .sub-modal-image { flex-basis: 38px; width: 38px; height: 38px; }
    #subAccountModal .sub-modal-hint { margin: 5px 0 0; }
    #subAccountModal .sub-modal-actions { margin-top: 1rem; }
    #subAccountModal .sub-modal-btn { padding: 0.5rem 1.1rem; }
    @media (max-width: 520px) {
        .sub-modal-pair { flex-direction: column; gap: 0; }
        .sub-modal-pair > * + * { margin-top: 12px; }
    }
    .sub-cur-picker { position: relative; }
    .sub-cur-native { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); border: 0; }
    .sub-cur-trigger {
        width: 100%; display: flex; align-items: center; gap: 10px; padding: 10px 36px 10px 12px;
        border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.875rem; color: #111827; text-align: left; cursor: pointer;
        background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' fill='none' stroke='%239ca3af' stroke-width='2' stroke-linecap='round'%3E%3Cpath d='M5 8l5 5 5-5'/%3E%3C/svg%3E") no-repeat right 10px center;
    }
    .sub-cur-trigger:focus { outline: none; border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12); }
    .sub-cur-flag { width: 24px; height: 17px; object-fit: cover; border-radius: 2px; box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.08); flex-shrink: 0; }
    .sub-cur-code { font-weight: 600; color: #111827; min-width: 38px; }
    .sub-cur-name { color: #6b7280; font-size: 0.8125rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sub-cur-menu {
        position: absolute; z-index: 30; top: calc(100% + 4px); left: 0; right: 0; max-height: 240px; overflow-y: auto;
        background: #fff; border: 1px solid #d1d5db; border-radius: 8px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12); padding: 4px;
    }
    .sub-cur-menu[hidden] { display: none; }
    .sub-cur-option {
        width: 100%; display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 0;
        background: transparent; border-radius: 6px; cursor: pointer; text-align: left; font-size: 0.875rem;
    }
    .sub-cur-option:hover, .sub-cur-option.is-selected { background: #f3f4f6; }
    html[data-theme="dark"] body.page-balances-accounts .sub-cur-trigger,
    html[data-theme="dark"] body.page-balances-accounts .sub-cur-menu { background-color: #0b141a; border-color: #2a3942; color: #e9edef; }
    html[data-theme="dark"] body.page-balances-accounts .sub-cur-code { color: #e9edef; }
    html[data-theme="dark"] body.page-balances-accounts .sub-cur-name { color: #8696a0; }
    html[data-theme="dark"] body.page-balances-accounts .sub-cur-option:hover,
    html[data-theme="dark"] body.page-balances-accounts .sub-cur-option.is-selected { background: #202c33; }
    .sub-modal-identity { display: flex; align-items: center; gap: 10px; }
    .sub-inst-wrap { position: relative; flex: 1; min-width: 0; }
    .sub-inst-wrap .sub-modal-input { width: 100%; }
    .sub-inst-list {
        position: absolute; z-index: 40; top: calc(100% + 4px); left: 0; right: 0; max-height: 320px; overflow-y: auto;
        background: #fff; border: 1px solid #d1d5db; border-radius: 10px; box-shadow: 0 12px 28px rgba(15, 23, 42, 0.14); padding: 4px;
    }
    .sub-inst-list[hidden] { display: none; }
    .sub-inst-item {
        width: 100%; display: flex; align-items: center; gap: 10px; padding: 7px 10px; border: 0; background: transparent;
        border-radius: 8px; cursor: pointer; text-align: left; font-size: 0.875rem; color: #111827;
    }
    .sub-inst-item:hover, .sub-inst-item.is-active { background: #f1f5f9; }
    .sub-inst-item img { width: 32px; height: 32px; border-radius: 8px; object-fit: contain; background: #fff; box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.08); flex-shrink: 0; }
    .sub-inst-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .sub-inst-type { font-size: 0.6875rem; font-weight: 600; padding: 2px 8px; border-radius: 999px; flex-shrink: 0; }
    .sub-inst-type--bank { background: #dbeafe; color: #1d4ed8; }
    .sub-inst-type--mobile { background: #fce7f3; color: #be185d; }
    .sub-inst-type--cash { background: #dcfce7; color: #15803d; }
    .sub-inst-type--saved { background: #f3f4f6; color: #4b5563; }
    html[data-theme="dark"] body.page-balances-accounts .sub-inst-list { background: #0b141a; border-color: #2a3942; }
    html[data-theme="dark"] body.page-balances-accounts .sub-inst-item { color: #e9edef; }
    html[data-theme="dark"] body.page-balances-accounts .sub-inst-item:hover,
    html[data-theme="dark"] body.page-balances-accounts .sub-inst-item.is-active { background: #202c33; }
    .sub-modal-image {
        position: relative; flex: 0 0 44px; width: 44px; height: 44px; border-radius: 10px;
        border: 1px dashed #cbd5e1; background: #f8fafc; color: #64748b;
        display: inline-flex; align-items: center; justify-content: center; cursor: pointer; overflow: hidden;
    }
    .sub-modal-image:hover { border-color: #0f766e; color: #0f766e; }
    .sub-modal-image input[type="file"] { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
    .sub-modal-image img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .sub-modal-image.has-image { border-style: solid; }
    .sub-modal-image.has-image i { display: none; }
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-image { background: #0b141a; border-color: #2a3942; color: #8696a0; }
    .sub-modal-select {
        width: 100%;
        padding: 0.8rem 2.25rem 0.8rem 0.9rem;
        border: 1px solid #d1d5db;
        border-radius: 8px !important;
        font-size: 0.875rem;
        color: #111827;
        background-color: #fff;
        appearance: none;
        -webkit-appearance: none;
        -moz-appearance: none;
        background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http://www.w3.org/2000/svg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 0.75rem center;
        background-size: 1.1rem;
    }
    .sub-modal-select option {
        white-space: normal;
        padding: 6px 0;
    }
    .sub-modal-hint {
        margin-top: 10px;
        font-size: 0.75rem;
        color: #6b7280;
        line-height: 1.45;
    }
    .sub-modal-actions {
        display: flex; justify-content: flex-end; gap: 10px; margin-top: 1.5rem;
    }
    .sub-modal-btn {
        padding: 0.65rem 1.1rem; border-radius: 8px; font-size: 0.875rem; font-weight: 500; cursor: pointer; border: 1px solid transparent;
    }
    .sub-modal-btn-cancel { background: #fff; border-color: #d1d5db; color: #374151; }
    .sub-modal-btn-save { background: #0f766e; border-color: #0f766e; color: #fff; }
    .sub-modal-btn-save:hover { background: #0d6660; }

    /* Dark theme: keep panels readable (global dark forces white text onto light cards). */
    html[data-theme="dark"] body.page-balances-accounts.dashboard .layout-main-wrapper,
    html[data-theme="dark"] body.page-balances-accounts.dashboard .layout-main-wrapper > .flex-grow-1,
    html[data-theme="dark"] body.page-balances-accounts .main-content {
        background: #0b141a !important;
        color: #e9edef !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .employee-header.employee-header--balances-accounts {
        background: #0b141a !important;
        border-bottom-color: #2a3942 !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-badge {
        color: inherit;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-panel {
        background: #111b21 !important;
        border-color: #2a3942 !important;
        box-shadow: none !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-panel-head,
    html[data-theme="dark"] body.page-balances-accounts .ray-search {
        border-bottom-color: #2a3942;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-panel-title,
    html[data-theme="dark"] body.page-balances-accounts .ray-detail-title,
    html[data-theme="dark"] body.page-balances-accounts .ray-account-name,
    html[data-theme="dark"] body.page-balances-accounts .ray-sub-count,
    html[data-theme="dark"] body.page-balances-accounts .ray-table tbody td,
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-head h2,
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-label-row label {
        color: #e9edef !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-account-desc,
    html[data-theme="dark"] body.page-balances-accounts .ray-empty,
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-hint,
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-count,
    html[data-theme="dark"] body.page-balances-accounts .ray-table thead th {
        color: #8696a0 !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-table,
    html[data-theme="dark"] body.page-balances-accounts table.ray-table {
        background: #111b21 !important;
        background-color: #111b21 !important;
        color: #e9edef !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-table thead th,
    html[data-theme="dark"] body.page-balances-accounts .ray-table th {
        background: #111b21 !important;
        background-color: #111b21 !important;
        color: #8696a0 !important;
        border-bottom-color: #2a3942 !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-table tbody td,
    html[data-theme="dark"] body.page-balances-accounts .ray-table td {
        background: #111b21 !important;
        background-color: #111b21 !important;
        border-bottom-color: #2a3942 !important;
        color: #e9edef !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-panel-head,
    html[data-theme="dark"] body.page-balances-accounts .ray-search,
    html[data-theme="dark"] body.page-balances-accounts .ray-panel-body {
        background: #111b21 !important;
        border-bottom-color: #2a3942 !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-parent-row:hover,
    html[data-theme="dark"] body.page-balances-accounts .ray-parent-row:hover td {
        background: #202c33 !important;
        background-color: #202c33 !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-parent-row.is-selected,
    html[data-theme="dark"] body.page-balances-accounts .ray-parent-row.is-selected td {
        background: #202c33 !important;
        background-color: #202c33 !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-badge-code {
        background: #2a3942;
        color: #e9edef !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-badge-dr {
        background: rgba(59, 130, 246, 0.22);
        color: #93c5fd !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-badge-cr {
        background: rgba(124, 58, 237, 0.25);
        color: #c4b5fd !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-search input,
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-input,
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-select {
        background: #0b141a !important;
        border-color: #2a3942 !important;
        color: #e9edef !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-search input::placeholder {
        color: #8696a0;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-search input:focus,
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-input:focus {
        border-color: #7c3aed;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.2);
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-btn-new,
    html[data-theme="dark"] body.page-balances-accounts .ray-btn-secondary,
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-btn-cancel {
        background: #2a3942;
        border-color: #3b4a54;
        color: #e9edef !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-btn-new:hover,
    html[data-theme="dark"] body.page-balances-accounts .ray-btn-secondary:hover,
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-btn-cancel:hover {
        background: #3b4a54;
        color: #e9edef !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-btn-new-account,
    html[data-theme="dark"] body.page-balances-accounts .ray-btn-new-account:hover {
        background: #7c3aed;
        border-color: #7c3aed;
        color: #fff !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-action-btn {
        color: #8696a0;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-action-btn:hover {
        background: #2a3942;
        color: #e9edef;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-empty-icon {
        background: #0b141a;
        color: #8696a0;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-alert {
        background: rgba(127, 29, 29, 0.35);
        color: #fecaca;
        border: 1px solid #991b1b;
    }
    html[data-theme="dark"] body.page-balances-accounts .sub-modal {
        background: #111b21;
        border: 1px solid #2a3942;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.45);
    }
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-close {
        color: #8696a0;
    }
    html[data-theme="dark"] body.page-balances-accounts .text-gray-400,
    html[data-theme="dark"] body.page-balances-accounts .text-xs.text-gray-400 {
        color: #8696a0 !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-panel-head span,
    html[data-theme="dark"] body.page-balances-accounts .ray-account-name span {
        color: inherit;
    }

    /* Mobile: dedicated card list (no table) */
    @media (max-width: 767.98px) {
        body.page-balances-accounts,
        body.page-balances-accounts.dashboard .layout-main-wrapper,
        body.page-balances-accounts.dashboard .layout-main-wrapper > .flex-grow-1,
        body.page-balances-accounts .main-content {
            background: #f1f5f9 !important;
            overflow-x: hidden !important;
        }
        html[data-theme="dark"] body.page-balances-accounts,
        html[data-theme="dark"] body.page-balances-accounts.dashboard .layout-main-wrapper,
        html[data-theme="dark"] body.page-balances-accounts.dashboard .layout-main-wrapper > .flex-grow-1,
        html[data-theme="dark"] body.page-balances-accounts .main-content {
            background: #0b141a !important;
        }
        body.page-balances-accounts .employee-header.employee-header--balances-accounts {
            padding: 0 1rem !important;
        }
        body.page-balances-accounts .main-content {
            padding: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box;
        }
        .ray-accounts-wrap {
            padding: 0.35rem 1rem 6.5rem;
            max-width: 100%;
            width: 100%;
            margin: 0;
            box-sizing: border-box;
            overflow: visible;
        }
        .ray-accounts-grid {
            display: block;
            width: 100%;
        }
        .ray-accounts-wrap .ray-detail-column,
        .ray-accounts-wrap > .ray-accounts-grid > .ray-panel[aria-label="Sub-accounts"] {
            display: none !important;
        }

        .ray-panel[aria-label="My accounts"] {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            overflow: visible !important;
            min-height: 0 !important;
            width: 100%;
            max-width: 100%;
        }
        .ray-panel[aria-label="My accounts"] .ray-panel-head {
            padding: 0.15rem 0 0.75rem;
            border-bottom: none;
            align-items: center;
            gap: 0.75rem;
        }
        .ray-panel[aria-label="My accounts"] .ray-panel-title {
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: #0f172a;
        }
        .ray-panel[aria-label="My accounts"] .ray-btn-new-account {
            padding: 0.5rem 0.9rem;
            font-size: 0.78rem;
            font-weight: 600;
            box-shadow: 0 8px 18px rgba(124, 58, 237, 0.28);
            white-space: nowrap;
        }
        .ray-panel[aria-label="My accounts"] .ray-search {
            display: block;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            padding: 0 0 0.95rem;
            border-bottom: none;
            margin: 0;
        }
        .ray-panel[aria-label="My accounts"] .ray-panel-body {
            overflow: visible !important;
            width: 100%;
            max-width: 100%;
            scrollbar-gutter: auto;
        }

        .ray-panel[aria-label="My accounts"] .ray-search-field {
            position: relative;
            display: block;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        .ray-panel[aria-label="My accounts"] .ray-search-icon {
            display: block !important;
            position: absolute;
            left: 1.1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #8696a0;
            font-size: 1rem;
            pointer-events: none;
            z-index: 2;
            line-height: 1;
        }
        .ray-panel[aria-label="My accounts"] .ray-search input[name="search"],
        .ray-panel[aria-label="My accounts"] .ray-search-field > input[type="text"] {
            display: block !important;
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            box-sizing: border-box !important;
            min-height: 3.25rem !important;
            height: 3.25rem !important;
            padding: 0 1.1rem 0 3.1rem !important;
            border-radius: 16px !important;
            border: 1px solid #e9edef !important;
            background: #fff !important;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
            font-size: 1rem !important;
            line-height: 1.25 !important;
            color: #0f172a !important;
            -webkit-appearance: none;
            appearance: none;
        }

        .ray-accounts-desktop { display: none !important; }
        .ray-accounts-mobile {
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            padding: 0;
            margin: 0;
        }
        .ray-m-item {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }

        .ray-m-card {
            display: block;
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 0.6rem 0.9rem;
            border: 1px solid #e8edf3;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
            box-sizing: border-box;
            cursor: pointer;
            overflow: hidden;
        }
        .ray-m-card.is-selected,
        .ray-m-card.is-open {
            border-color: #ddd6fe;
            box-shadow: 0 4px 14px rgba(124, 58, 237, 0.12);
        }
        .ray-m-card-main {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            width: 100%;
            min-width: 0;
        }
        .ray-m-icon {
            width: 2.2rem;
            height: 2.2rem;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 2.2rem;
            font-size: 1rem;
        }
        .ray-m-icon--violet { background: #f3e8ff; color: #7c3aed; }
        .ray-m-icon--purple { background: #ede9fe; color: #6d28d9; }
        .ray-m-icon--orange { background: #ffedd5; color: #ea580c; }
        .ray-m-icon--green { background: #dcfce7; color: #16a34a; }
        .ray-m-icon--blue { background: #dbeafe; color: #2563eb; }
        .ray-m-icon--rose { background: #ffe4e6; color: #e11d48; }
        .ray-m-icon--amber { background: #fef3c7; color: #d97706; }
        .ray-m-icon--indigo { background: #e0e7ff; color: #4338ca; }
        .ray-m-icon--slate { background: #f1f5f9; color: #475569; }

        .ray-m-copy {
            flex: 1 1 0;
            min-width: 0;
            max-width: 100%;
        }
        .ray-m-title {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.3rem 0.4rem;
            min-width: 0;
            width: 100%;
        }
        .ray-m-name {
            font-size: 0.875rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.3;
            word-break: break-word;
        }
        .ray-m-lock {
            color: #8696a0;
            font-size: 0.72rem;
        }
        .ray-m-desc {
            margin: 0.35rem 0 0;
            padding: 0;
            font-size: 0.78rem;
            color: #8696a0;
            line-height: 1.45;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            word-break: break-word;
            max-width: 100%;
        }
        .ray-m-chevron { display: none !important; }
        .ray-accounts-mobile .ray-badge {
            border-radius: 999px;
            padding: 0.15rem 0.48rem;
            font-size: 0.625rem;
            flex-shrink: 0;
            line-height: 1.2;
        }
        .ray-accounts-mobile .ray-badge-code {
            background: #f1f5f9;
            color: #8696a0;
        }

        .ray-m-accordion {
            display: none !important;
            width: 100%;
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            height: 0;
            overflow: hidden;
        }
        .ray-m-accordion.is-open {
            display: block !important;
            height: auto;
            overflow: visible;
            margin: 0;
        }
        .ray-m-accordion-panel {
            padding: 0.9rem 1rem;
            border: 1px solid #e8edf3;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
            box-sizing: border-box;
            width: 100%;
            max-width: 100%;
            overflow: visible;
        }
        .ray-m-accordion-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        .ray-m-accordion-actions .ray-btn-new,
        .ray-m-accordion-actions .ray-btn-new-account {
            flex: 1 1 calc(50% - 0.25rem);
            justify-content: center;
            min-height: 2.4rem;
            font-size: 0.78rem;
        }
        .ray-m-sub-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            overflow: visible;
        }
        .ray-m-sub-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.55rem;
            padding: 0.55rem 0.85rem;
            font-size: 0.8125rem;
            border: 1px solid #eef2f7;
            border-radius: 12px;
            background: #f8fafc;
            box-sizing: border-box;
            width: 100%;
            max-width: 100%;
            overflow: visible;
            position: relative;
        }
        .ray-m-sub-copy { min-width: 0; flex: 1 1 auto; overflow: hidden; }
        .ray-m-sub-actions {
            flex-shrink: 0;
            position: relative;
            z-index: 2;
        }
        .ray-m-floating-menu {
            position: fixed;
            z-index: 10950;
            min-width: 12.5rem;
            padding: 0.4rem 0;
            border: 1px solid #e9edef;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 14px 32px rgba(15, 23, 42, 0.2);
            display: none;
        }
        .ray-m-floating-menu.is-open {
            display: block;
        }
        .ray-m-floating-menu a,
        .ray-m-floating-menu button {
            display: block;
            width: 100%;
            text-align: left;
            padding: 0.72rem 1rem;
            border: 0;
            background: transparent;
            color: #0f172a;
            font-size: 0.9rem;
            text-decoration: none;
            cursor: pointer;
        }
        .ray-m-floating-menu a:active,
        .ray-m-floating-menu button:active {
            background: #f8fafc;
        }
        .ray-m-floating-menu .is-danger {
            color: #dc2626;
        }
        .ray-m-floating-menu .ray-m-menu-sep {
            height: 1px;
            margin: 0.3rem 0;
            background: #f1f5f9;
        }
        html[data-theme="dark"] body.page-balances-accounts .ray-m-floating-menu {
            background: #111b21;
            border-color: #2a3942;
        }
        html[data-theme="dark"] body.page-balances-accounts .ray-m-floating-menu a,
        html[data-theme="dark"] body.page-balances-accounts .ray-m-floating-menu button {
            color: #e9edef;
        }
        html[data-theme="dark"] body.page-balances-accounts .ray-m-floating-menu .is-danger {
            color: #fca5a5;
        }
        html[data-theme="dark"] body.page-balances-accounts .ray-m-floating-menu .ray-m-menu-sep {
            background: #2a3942;
        }
        .ray-m-sub-empty {
            padding: 0.75rem 0.15rem;
            color: #8696a0;
            font-size: 0.8rem;
            text-align: center;
        }

        html[data-theme="dark"] body.page-balances-accounts .ray-m-card,
        html[data-theme="dark"] body.page-balances-accounts .ray-m-accordion-panel,
        html[data-theme="dark"] body.page-balances-accounts .ray-search input {
            background: #111b21;
            border-color: #2a3942;
        }
        html[data-theme="dark"] body.page-balances-accounts .ray-m-sub-item {
            background: #0b141a;
            border-color: #2a3942;
        }
        html[data-theme="dark"] body.page-balances-accounts .ray-panel[aria-label="My accounts"] .ray-panel-title,
        html[data-theme="dark"] body.page-balances-accounts .ray-m-name {
            color: #e9edef !important;
        }
    }

    html[data-theme="dark"] body.page-balances-accounts .sidebar,
    html[data-theme="dark"] body.page-balances-accounts #native-sidebar,
    html[data-theme="dark"] body.page-balances-accounts .sidebar-container {
        --sidebar-bg: #0b141a !important;
        background: #0b141a !important;
        background-color: #0b141a !important;
        border-right-color: #2a3942 !important;
        color: #e9edef !important;
    }
</style>

<main class="main-content">
    <div class="ray-accounts-wrap ray-mobile-list">
        <?php if ($sessionError !== ''): ?>
            <div class="ray-alert"><?= htmlspecialchars($sessionError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="ray-accounts-grid">
            <!-- Left: My Accounts -->
            <section class="ray-panel" aria-label="My accounts">
                <div class="ray-panel-head">
                    <h1 class="ray-panel-title">My Accounts</h1>
                    <?php if (isAdmin()): ?>
                        <a href="<?= htmlspecialchars($coaCreateUrl, ENT_QUOTES, 'UTF-8') ?>" class="ray-btn-new ray-btn-new-account">
                            <i class="fas fa-plus text-xs"></i> New Account
                        </a>
                    <?php endif; ?>
                </div>
                <form method="GET" action="<?= htmlspecialchars($accountsGetAction, ENT_QUOTES, 'UTF-8') ?>" class="ray-search">
                    <?php if ($moduleParam !== ''): ?>
                        <input type="hidden" name="module" value="<?= htmlspecialchars($moduleParam, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>
                    <?php if ($selectedId > 0): ?>
                        <input type="hidden" name="selected" value="<?= (int) $selectedId ?>">
                    <?php endif; ?>
                    <div class="ray-search-field">
                        <i class="fas fa-search ray-search-icon" aria-hidden="true"></i>
                        <input type="text" name="search" value="<?= htmlspecialchars($searchQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search account">
                    </div>
                </form>
                <div class="ray-panel-body">
                    <?php if (empty($parentAccounts)): ?>
                        <div class="ray-empty">
                            <div class="ray-empty-icon"><i class="fas fa-wallet"></i></div>
                            <div>No accounts found</div>
                            <?php if ($canManage): ?>
                                <div style="margin-top:12px;"><a href="<?= htmlspecialchars($coaCreateUrl, ENT_QUOTES, 'UTF-8') ?>" class="ray-btn-new ray-btn-new-account">Create first account</a></div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="ray-accounts-desktop">
                        <table class="ray-table">
                            <thead>
                                <tr>
                                    <th style="width:42px;">#</th>
                                    <th>Account</th>
                                    <th style="width:110px;">Sub-accounts</th>
                                    <th style="width:52px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($parentAccounts as $index => $parentRow):
                                    $parentId = (int) $parentRow['id'];
                                    $isSelected = $parentId === $selectedId;
                                    $rowUrl = function_exists('balances_accounts_redirect_url')
                                        ? balances_accounts_redirect_url(array_filter([
                                            'module' => $moduleParam,
                                            'selected' => $parentId,
                                            'search' => $searchQ !== '' ? $searchQ : null,
                                        ]))
                                        : ('accounts.php?' . http_build_query(array_filter([
                                            'module' => $moduleParam,
                                            'selected' => $parentId,
                                          'search' => $searchQ !== '' ? $searchQ : null,
                                        ])));
                                    $editUrl = 'coa_edit.php?id=' . $parentId . ($moduleParam !== '' ? '&module=' . rawurlencode($moduleParam) : '');
                                ?>
                                <tr class="ray-parent-row<?= $isSelected ? ' is-selected' : '' ?>"
                                    data-parent-url="<?= htmlspecialchars($rowUrl, ENT_QUOTES, 'UTF-8') ?>"
                                    onclick="window.location.href=this.dataset.parentUrl">
                                    <td><?= $index + 1 ?></td>
                                    <td>
                                        <div class="ray-account-name" title="<?= htmlspecialchars($parentRow['description'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($parentRow['name'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php if (!empty($parentRow['is_system'])): ?>
                                                <i class="fas fa-lock text-gray-400 ms-1 text-xs" title="System Account"></i>
                                            <?php endif; ?>
                                            <span class="ray-badge ray-badge-code"><?= htmlspecialchars($parentRow['code'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="ray-badge <?= $parentRow['normal_balance_short'] === 'Cr' ? 'ray-badge-cr' : 'ray-badge-dr' ?>"><?= htmlspecialchars($parentRow['normal_balance_short'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </td>
                                    <td><span class="ray-sub-count"><?= (int) $parentRow['child_count'] ?></span></td>
                                    <td onclick="event.stopPropagation();">
                                        <?php if (!empty($parentRow['is_system'])): ?>
                                            <span class="text-xs text-gray-400 font-medium px-2 py-1 select-none">System</span>
                                        <?php else: ?>
                                            <div class="dropdown">
                                                <button type="button" class="ray-action-btn" data-bs-toggle="dropdown" aria-label="Account actions">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                    <li><a class="dropdown-item" href="<?= htmlspecialchars('view_account.php?' . http_build_query(['module' => $moduleParam, 'id' => $parentId]), ENT_QUOTES, 'UTF-8') ?>">View</a></li>
                                                    <?php if ($canManage): ?>
                                                        <li>
                                                            <button type="button"
                                                                    class="dropdown-item"
                                                                    onclick="openMoveParentModal(<?= $parentId ?>)">
                                                                Move under parent account
                                                            </button>
                                                        </li>
                                                    <?php endif; ?>
                                                    <?php if (isAdmin()): ?>
                                                        <li><a class="dropdown-item" href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>">Edit</a></li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li><button type="button" class="dropdown-item text-danger" onclick="deleteAccount(<?= $parentId ?>)">Delete</button></li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>

                        <div class="ray-accounts-mobile" aria-label="Accounts list">
                            <?php foreach ($parentAccounts as $parentRow):
                                $parentId = (int) $parentRow['id'];
                                $isSelected = $parentId === $selectedId;
                                [$iconTone, $iconClass] = $rayResolveParentIcon($parentRow);
                                $rowChildren = function_exists('balances_fetch_child_rows_for_parent')
                                    ? balances_fetch_child_rows_for_parent($pdo, $parentId, $accountRowsById, true)
                                    : ($childrenByParent[$parentId] ?? []);
                                $parentMeta = $mobileParentMeta[$parentId] ?? ['assignable' => []];
                                $hasAssignable = !empty($parentMeta['assignable']);
                            ?>
                            <div class="ray-m-item">
                                <article class="ray-m-card<?= $isSelected ? ' is-selected' : '' ?>"
                                         data-parent-id="<?= $parentId ?>"
                                         onclick="rayParentRowClick(event, this)">
                                    <div class="ray-m-card-main">
                                        <span class="ray-m-icon ray-m-icon--<?= htmlspecialchars($iconTone, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true">
                                            <i class="fas <?= htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8') ?>"></i>
                                        </span>
                                        <div class="ray-m-copy">
                                            <div class="ray-m-title">
                                                <span class="ray-m-name"><?= htmlspecialchars($parentRow['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php if (!empty($parentRow['is_system'])): ?>
                                                    <i class="fas fa-lock ray-m-lock" title="System Account" aria-label="System account"></i>
                                                <?php endif; ?>
                                                <span class="ray-badge ray-badge-code"><?= htmlspecialchars($parentRow['code'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="ray-badge <?= $parentRow['normal_balance_short'] === 'Cr' ? 'ray-badge-cr' : 'ray-badge-dr' ?>"><?= htmlspecialchars($parentRow['normal_balance_short'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                                <div class="ray-m-accordion" id="ray-accordion-<?= $parentId ?>" data-parent-id="<?= $parentId ?>">
                                    <div class="ray-m-accordion-panel">
                                        <?php if ($canManage): ?>
                                            <div class="ray-m-accordion-actions">
                                                <?php if ($hasAssignable): ?>
                                                    <button type="button" class="ray-btn-new ray-btn-new-account" onclick="event.stopPropagation(); openAssignForParent(<?= $parentId ?>)">
                                                        <i class="fas fa-link text-xs"></i> Assign existing
                                                    </button>
                                                <?php endif; ?>
                                                <button type="button" class="ray-btn-new ray-btn-new-account" onclick="event.stopPropagation(); openSubForParent(<?= $parentId ?>)">
                                                    <i class="fas fa-plus text-xs"></i> New Sub-account
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (empty($rowChildren)): ?>
                                            <div class="ray-m-sub-empty">No sub-accounts yet</div>
                                        <?php else: ?>
                                            <div class="ray-m-sub-list">
                                                <?php foreach (balances_group_sub_accounts_by_payment_type($rowChildren) as $subGroup): ?>
                                                <?php if ($subGroup['label'] !== null): ?>
                                                <div class="ray-m-sub-group ray-group-toggle" role="button" tabindex="0" aria-expanded="true" data-group="<?= $parentId ?>-<?= htmlspecialchars($subGroup['key'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <i class="fas fa-chevron-down ray-group-chevron" aria-hidden="true"></i>
                                                    <span class="ray-sub-group-name"><?= htmlspecialchars($subGroup['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    <span class="ray-sub-group-count"><?= count($subGroup['rows']) ?></span>
                                                    <?php if ($subGroup['currency'] !== ''): ?>
                                                        <span class="ray-sub-group-total"<?= !empty($subGroup['approx']) ? ' title="Mixed currencies converted with the latest exchange rates"' : '' ?>><?= !empty($subGroup['approx']) ? '&asymp; ' : '' ?><?= htmlspecialchars($subGroup['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format($subGroup['total'], 2) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endif; ?>
                                                <?php foreach ($subGroup['rows'] as $childRow):
                                                    $childViewUrl = 'view_account.php?' . http_build_query(['module' => $moduleParam, 'id' => (int) $childRow['id']]);
                                                    $childEditUrl = 'coa_edit.php?id=' . (int) $childRow['id'] . ($moduleParam !== '' ? '&module=' . rawurlencode($moduleParam) : '');
                                                ?>
                                                <div class="ray-m-sub-item"<?= $subGroup['label'] !== null ? ' data-group-member="' . $parentId . '-' . htmlspecialchars($subGroup['key'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                                                    <?= $rayAccountAvatar($childRow) ?>
                                                    <div class="ray-m-sub-copy">
                                                        <div class="ray-m-title">
                                                            <span class="ray-m-name"><?= htmlspecialchars($childRow['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                            <?php if (!empty($childRow['is_system'])): ?>
                                                                <i class="fas fa-lock ray-m-lock" title="System Account"></i>
                                                            <?php endif; ?>
                                                            <span class="ray-badge ray-badge-code"><?= htmlspecialchars($childRow['code'], ENT_QUOTES, 'UTF-8') ?></span>
                                                            <span class="ray-badge <?= $childRow['normal_balance_short'] === 'Cr' ? 'ray-badge-cr' : 'ray-badge-dr' ?>"><?= htmlspecialchars($childRow['normal_balance_short'], ENT_QUOTES, 'UTF-8') ?></span>
                                                        </div>
                                                    </div>
                                                    <div class="ray-m-sub-actions">
                                                        <?php if (!empty($childRow['is_system'])): ?>
                                                            <span class="text-xs text-gray-400 font-medium">System</span>
                                                        <?php else: ?>
                                                            <button type="button"
                                                                    class="ray-action-btn ray-m-sub-menu-btn"
                                                                    aria-haspopup="menu"
                                                                    aria-expanded="false"
                                                                    aria-label="Sub-account actions"
                                                                    data-view-url="<?= htmlspecialchars($childViewUrl, ENT_QUOTES, 'UTF-8') ?>"
                                                                    data-edit-url="<?= isAdmin() ? htmlspecialchars($childEditUrl, ENT_QUOTES, 'UTF-8') : '' ?>"
                                                                    data-child-id="<?= (int) $childRow['id'] ?>"
                                                                    data-parent-id="<?= $parentId ?>"
                                                                    data-can-edit="<?= isAdmin() ? '1' : '0' ?>"
                                                                    data-can-manage="<?= $canManage ? '1' : '0' ?>"
                                                                    data-can-delete="<?= isAdmin() ? '1' : '0' ?>">
                                                                <i class="fas fa-ellipsis-v"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Right: Sub-accounts -->
            <?php if ($selectedParent): ?>
            <div class="ray-detail-column" aria-label="Sub-accounts">
                <a class="ray-mobile-back" href="<?= htmlspecialchars($accountsListUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i> All accounts
                </a>
                <section class="ray-panel ray-panel--subaccounts">
                <div class="ray-panel-head ray-detail-head">
                    <div>
                        <div class="ray-detail-title">
                            <?= htmlspecialchars($selectedParent['name'], ENT_QUOTES, 'UTF-8') ?>
                            <span class="ray-badge ray-badge-code"><?= htmlspecialchars($selectedParent['code'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="ray-badge <?= $selectedParent['normal_balance_short'] === 'Cr' ? 'ray-badge-cr' : 'ray-badge-dr' ?>"><?= htmlspecialchars($selectedParent['normal_balance_short'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                    <?php if ($canManage): ?>
                        <div class="ray-head-actions">
                            <?php if (isAdmin() && !empty($selectedChildren)): ?>
                                <button type="button" class="ray-btn-new ray-btn-select" id="toggleSubSelect" aria-pressed="false">
                                    <i class="fas fa-check-square text-xs"></i> Select
                                </button>
                            <?php endif; ?>
                            <?php if (!empty($assignableAccounts)): ?>
                                <button type="button" class="ray-btn-new ray-btn-new-account" id="openAssignAccountModal">
                                    <i class="fas fa-link text-xs"></i> Assign existing
                                </button>
                            <?php endif; ?>
                            <button type="button" class="ray-btn-new ray-btn-new-account" id="openSubAccountModal">
                                <i class="fas fa-plus text-xs"></i> New Sub-account
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
                    <form method="GET" action="<?= htmlspecialchars($accountsGetAction, ENT_QUOTES, 'UTF-8') ?>" class="ray-search">
                        <?php if ($moduleParam !== ''): ?>
                            <input type="hidden" name="module" value="<?= htmlspecialchars($moduleParam, ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>
                        <input type="hidden" name="selected" value="<?= (int) $selectedId ?>">
                        <?php if ($searchQ !== ''): ?>
                            <input type="hidden" name="search" value="<?= htmlspecialchars($searchQ, ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>
                        <input type="text" name="sub_search" value="<?= htmlspecialchars($subSearchQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search sub-accounts">
                    </form>
                    <?php if (isAdmin() && !empty($selectedChildren)): ?>
                        <div class="ray-select-bar" id="subSelectBar" hidden>
                            <label class="ray-select-all"><input type="checkbox" id="subSelectAll"> All</label>
                            <span id="subSelectCount">0 selected</span>
                            <button type="button" class="ray-select-delete" id="subSelectDelete" disabled><i class="fas fa-trash-alt text-xs"></i> Delete selected</button>
                            <button type="button" class="ray-select-cancel" id="subSelectCancel">Cancel</button>
                        </div>
                    <?php endif; ?>
                    <div class="ray-panel-body">
                        <?php if (empty($selectedChildren)): ?>
                            <div class="ray-empty">
                                <div class="ray-empty-icon"><i class="fas fa-sitemap"></i></div>
                                <div>No sub-accounts yet</div>
                                <?php if ($canManage): ?>
                                    <div class="ray-head-actions" style="margin-top:12px;justify-content:center;">
                                        <?php if (!empty($assignableAccounts)): ?>
                                            <button type="button" class="ray-btn-new ray-btn-new-account open-assign-modal-trigger">Assign existing account</button>
                                        <?php endif; ?>
                                        <button type="button" class="ray-btn-new ray-btn-new-account open-sub-modal-trigger">Add first sub-account</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <table class="ray-table" id="subAccountsTable">
                                <thead>
                                    <tr>
                                        <th>Sub-account</th>
                                        <th style="width:52px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (balances_group_sub_accounts_by_payment_type($selectedChildren) as $subGroup): ?>
                                    <?php if ($subGroup['label'] !== null): ?>
                                    <tr class="ray-sub-group-row ray-group-toggle" role="button" tabindex="0" aria-expanded="true" data-group="<?= (int) $selectedId ?>-<?= htmlspecialchars($subGroup['key'], ENT_QUOTES, 'UTF-8') ?>">
                                        <td colspan="2">
                                            <i class="fas fa-chevron-down ray-group-chevron" aria-hidden="true"></i>
                                            <span class="ray-sub-group-name"><?= htmlspecialchars($subGroup['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="ray-sub-group-count"><?= count($subGroup['rows']) ?></span>
                                            <?php if ($subGroup['currency'] !== ''): ?>
                                                <span class="ray-sub-group-total"<?= !empty($subGroup['approx']) ? ' title="Mixed currencies converted with the latest exchange rates"' : '' ?>><?= !empty($subGroup['approx']) ? '&asymp; ' : '' ?><?= htmlspecialchars($subGroup['currency'], ENT_QUOTES, 'UTF-8') ?> <?= number_format($subGroup['total'], 2) ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php foreach ($subGroup['rows'] as $childRow):
                                        $childViewUrl = 'view_account.php?' . http_build_query(['module' => $moduleParam, 'id' => (int) $childRow['id']]);
                                        $childEditUrl = 'coa_edit.php?id=' . (int) $childRow['id'] . ($moduleParam !== '' ? '&module=' . rawurlencode($moduleParam) : '');
                                        $childIsSystem = !empty($childRow['is_system']);
                                    ?>
                                    <tr data-sub-id="<?= (int) $childRow['id'] ?>"<?= $childIsSystem ? ' data-sub-system="1"' : '' ?><?= $subGroup['label'] !== null ? ' data-group-member="' . (int) $selectedId . '-' . htmlspecialchars($subGroup['key'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                                        <td>
                                            <div class="ray-account-name" title="<?= htmlspecialchars($childRow['description'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?php if (!$childIsSystem && isAdmin()): ?>
                                                    <input type="checkbox" class="ray-sub-check" value="<?= (int) $childRow['id'] ?>" aria-label="Select <?= htmlspecialchars($childRow['name'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?php endif; ?>
                                                <?= $rayAccountAvatar($childRow) ?>
                                                <?= htmlspecialchars($childRow['name'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php if (!empty($childRow['is_system'])): ?>
                                                    <i class="fas fa-lock text-gray-400 ms-1 text-xs" title="System Account"></i>
                                                <?php endif; ?>
                                                <span class="ray-badge ray-badge-code"><?= htmlspecialchars($childRow['code'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="ray-badge <?= $childRow['normal_balance_short'] === 'Cr' ? 'ray-badge-cr' : 'ray-badge-dr' ?>"><?= htmlspecialchars($childRow['normal_balance_short'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($childRow['is_system'])): ?>
                                                <span class="text-xs text-gray-400 font-medium px-2 py-1 select-none">System</span>
                                            <?php else: ?>
                                                <div class="dropdown">
                                                    <button type="button" class="ray-action-btn" data-bs-toggle="dropdown" aria-label="Sub-account actions">
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                        <li><a class="dropdown-item" href="<?= htmlspecialchars($childViewUrl, ENT_QUOTES, 'UTF-8') ?>">View</a></li>
                                                        <?php if (isAdmin()): ?>
                                                            <li><a class="dropdown-item" href="<?= htmlspecialchars($childEditUrl, ENT_QUOTES, 'UTF-8') ?>">Edit</a></li>
                                                        <?php endif; ?>
                                                        <?php if ($canManage): ?>
                                                            <li>
                                                                <button type="button"
                                                                        class="dropdown-item"
                                                                        onclick="unassignSubAccount(<?= (int) $childRow['id'] ?>, <?= (int) $selectedId ?>)">
                                                                    Move to main accounts
                                                                </button>
                                                            </li>
                                                        <?php endif; ?>
                                                        <?php if (isAdmin()): ?>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li><button type="button" class="dropdown-item text-danger" onclick="deleteAccount(<?= (int) $childRow['id'] ?>)">Delete</button></li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
            <?php else: ?>
            <section class="ray-panel" aria-label="Sub-accounts">
                    <div class="ray-empty" style="min-height:560px;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                        <div class="ray-empty-icon"><i class="fas fa-hand-pointer"></i></div>
                        <div>Select an account to view its sub-accounts</div>
                    </div>
            </section>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php if ($canManage): ?>
<div class="sub-modal-backdrop" id="moveParentModal" role="presentation">
    <div class="sub-modal" role="dialog" aria-modal="true" aria-labelledby="moveParentModalTitle">
        <form method="POST" action="">
            <input type="hidden" name="action" value="assign_existing_sub_account">
            <input type="hidden" name="account_id" id="moveParentAccountId" value="">
            <input type="hidden" name="module" value="<?= htmlspecialchars($moduleParam, ENT_QUOTES, 'UTF-8') ?>">
            <div class="sub-modal-head">
                <h2 id="moveParentModalTitle">Move account under parent</h2>
                <button type="button" class="sub-modal-close" id="closeMoveParentModal" aria-label="Close">&times;</button>
            </div>
            <p id="moveParentSourceLabel" class="sub-modal-hint" style="margin-top:0;margin-bottom:14px;"></p>
            <div class="sub-modal-label-row">
                <label for="moveParentTargetId">Parent account</label>
            </div>
            <select class="sub-modal-select" id="moveParentTargetId" name="parent_id" required>
                <option value="">Select parent account</option>
            </select>
            <p class="sub-modal-hint">This moves the account from My Accounts into sub-accounts. Balances, transactions, and any existing sub-accounts are kept.</p>
            <div class="sub-modal-actions">
                <button type="button" class="sub-modal-btn sub-modal-btn-cancel" id="cancelMoveParentModal">Cancel</button>
                <button type="submit" class="sub-modal-btn sub-modal-btn-save" id="moveParentSubmitBtn">Move</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="sub-modal-backdrop" id="subAccountModal" role="presentation">
    <div class="sub-modal" role="dialog" aria-modal="true" aria-labelledby="subAccountModalTitle">
        <form method="POST" action="" id="subAccountForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create_sub_account">
            <input type="hidden" name="parent_id" id="subParentId" value="<?= (int) ($selectedParent['id'] ?? 0) ?>">
            <input type="hidden" name="module" value="<?= htmlspecialchars($moduleParam, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="selected" id="subSelectedId" value="<?= (int) ($selectedParent['id'] ?? $selectedId) ?>">
            <div class="sub-modal-head">
                <h2 id="subAccountModalTitle">Add New Sub-account</h2>
                <button type="button" class="sub-modal-close" id="closeSubAccountModal" aria-label="Close">&times;</button>
            </div>
            <div class="sub-modal-label-row">
                <label for="subAccountName">Name</label>
                <span class="sub-modal-count"><span id="subNameCount">0</span>/100</span>
            </div>
            <div class="sub-modal-identity">
                <label class="sub-modal-image" for="subAccountImage" title="Upload account image (optional)">
                    <input type="file" id="subAccountImage" name="account_image" accept=".jpg,.jpeg,.png,.webp,.gif,image/*">
                    <img id="subAccountImagePreview" alt="" hidden>
                    <i class="fas fa-camera" aria-hidden="true"></i>
                </label>
                <div class="sub-inst-wrap">
                    <input type="text"
                           class="sub-modal-input"
                           id="subAccountName"
                           name="account_name"
                           maxlength="100"
                           required
                           placeholder="Enter sub-account name, e.g. CRDB or M-Pesa"
                           autocomplete="off"
                           role="combobox"
                           aria-autocomplete="list"
                           aria-controls="subInstitutionList"
                           aria-expanded="false">
                    <div class="sub-inst-list" id="subInstitutionList" role="listbox" hidden></div>
                </div>
                <input type="hidden" name="institution" id="subInstitution" value="">
            </div>
            <p class="sub-modal-hint">Optional logo &middot; JPG, PNG, WEBP, GIF &middot; max 2MB &middot; <a href="<?= htmlspecialchars('institutions.php' . ($moduleParam !== '' ? '?module=' . rawurlencode($moduleParam) : ''), ENT_QUOTES, 'UTF-8') ?>">Manage saved logos</a></p>
            <div class="sub-modal-pair">
            <div>
            <div class="sub-modal-label-row">
                <label for="subCurrencyTrigger">Currency</label>
            </div>
            <?php
            $subCurrencies = balances_currency_catalog();
            $subDefaultCurrency = strtoupper((string) ($selectedParent['currency'] ?? 'TZS'));
            if (!isset($subCurrencies[$subDefaultCurrency])) {
                $subDefaultCurrency = 'TZS';
            }
            $subFlagUrl = static fn (string $flag): string => 'https://flagcdn.com/w40/' . strtolower($flag) . '.png';
            ?>
            <div class="sub-cur-picker" id="subCurrencyPicker">
                <select id="subCurrency" name="currency" class="sub-cur-native" tabindex="-1" aria-hidden="true">
                    <?php foreach ($subCurrencies as $curCode => $curMeta): ?>
                        <option value="<?= htmlspecialchars($curCode, ENT_QUOTES, 'UTF-8') ?>"<?= $curCode === $subDefaultCurrency ? ' selected' : '' ?>><?= htmlspecialchars($curCode . ' - ' . $curMeta['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="sub-cur-trigger" id="subCurrencyTrigger" aria-haspopup="listbox" aria-expanded="false">
                    <img src="<?= htmlspecialchars($subFlagUrl($subCurrencies[$subDefaultCurrency]['flag']), ENT_QUOTES, 'UTF-8') ?>" alt="" class="sub-cur-flag" id="subCurrencyFlag" width="24" height="17">
                    <span class="sub-cur-code" id="subCurrencyCode"><?= htmlspecialchars($subDefaultCurrency, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="sub-cur-name" id="subCurrencyName"><?= htmlspecialchars($subCurrencies[$subDefaultCurrency]['name'], ENT_QUOTES, 'UTF-8') ?></span>
                </button>
                <div class="sub-cur-menu" id="subCurrencyMenu" role="listbox" hidden>
                    <?php foreach ($subCurrencies as $curCode => $curMeta): ?>
                        <button type="button"
                                class="sub-cur-option<?= $curCode === $subDefaultCurrency ? ' is-selected' : '' ?>"
                                role="option"
                                data-value="<?= htmlspecialchars($curCode, ENT_QUOTES, 'UTF-8') ?>"
                                data-flag="<?= htmlspecialchars($subFlagUrl($curMeta['flag']), ENT_QUOTES, 'UTF-8') ?>"
                                data-name="<?= htmlspecialchars($curMeta['name'], ENT_QUOTES, 'UTF-8') ?>"
                                aria-selected="<?= $curCode === $subDefaultCurrency ? 'true' : 'false' ?>">
                            <img src="<?= htmlspecialchars($subFlagUrl($curMeta['flag']), ENT_QUOTES, 'UTF-8') ?>" alt="" class="sub-cur-flag" width="24" height="17" loading="lazy">
                            <span class="sub-cur-code"><?= htmlspecialchars($curCode, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="sub-cur-name"><?= htmlspecialchars($curMeta['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            </div>
            <div id="subPaymentTypeWrap"<?= $selectedParentPaymentMode !== 'none' ? '' : ' hidden' ?>>
                <div class="sub-modal-label-row">
                    <label for="subPaymentWalletType">Account type</label>
                </div>
                <select class="sub-modal-select" id="subPaymentWalletType" name="payment_wallet_type"<?= $selectedParentShowsPaymentType ? ' required' : '' ?>>
                    <option value="" id="subPaymentTypeRegular"<?= $selectedParentShowsPaymentType ? ' hidden disabled' : ' selected' ?>>Regular asset account</option>
                    <?php foreach (balances_payment_wallet_types() as $walletSlug => $walletLabel): ?>
                        <option value="<?= htmlspecialchars($walletSlug, ENT_QUOTES, 'UTF-8') ?>"<?= $walletSlug === $selectedParentDefaultPaymentType ? ' selected' : '' ?>>
                            <?= htmlspecialchars($walletLabel, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            </div>
            <div id="subOpeningWrap" hidden>
                <div class="sub-modal-opening-row">
                    <div>
                        <div class="sub-modal-label-row">
                            <label for="subOpeningBalance">Opening balance</label>
                        </div>
                        <input type="text" class="sub-modal-input" id="subOpeningBalance" name="opening_balance" inputmode="decimal" placeholder="0.00" autocomplete="off">
                    </div>
                    <div>
                        <div class="sub-modal-label-row">
                            <label for="subOpeningDate">As of date</label>
                        </div>
                        <input type="date" class="sub-modal-input" id="subOpeningDate" name="opening_date" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <p class="sub-modal-hint">Balance on the statement or wallet on that date; later money in or out is recorded as transactions.</p>
            </div>
            <div class="sub-modal-actions">
                <button type="button" class="sub-modal-btn sub-modal-btn-cancel" id="cancelSubAccountModal">Cancel</button>
                <button type="submit" class="sub-modal-btn sub-modal-btn-save">Save</button>
            </div>
        </form>
    </div>
</div>

<div class="sub-modal-backdrop" id="assignAccountModal" role="presentation">
    <div class="sub-modal" role="dialog" aria-modal="true" aria-labelledby="assignAccountModalTitle">
        <form method="POST" action="" id="assignAccountForm">
            <input type="hidden" name="action" value="assign_existing_sub_account">
            <input type="hidden" name="parent_id" id="assignParentId" value="<?= (int) ($selectedParent['id'] ?? 0) ?>">
            <input type="hidden" name="module" value="<?= htmlspecialchars($moduleParam, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="selected" id="assignSelectedId" value="<?= (int) ($selectedParent['id'] ?? $selectedId) ?>">
            <div class="sub-modal-head">
                <h2 id="assignAccountModalTitle">Assign existing account</h2>
                <button type="button" class="sub-modal-close" id="closeAssignAccountModal" aria-label="Close">&times;</button>
            </div>
            <div class="sub-modal-label-row">
                <label for="assignAccountId">Account</label>
            </div>
            <select class="sub-modal-select" id="assignAccountId" name="account_id" required>
                <option value="">Select an account</option>
                <?php foreach ($assignableAccounts as $assignRow):
                    $label = trim((string) $assignRow['name']);
                    if (($assignRow['code'] ?? '-') !== '-') {
                        $label = $assignRow['code'] . ' - ' . $label;
                    }
                    $balanceLabel = number_format((float) ($assignRow['balance'] ?? 0), 2) . ' ' . htmlspecialchars((string) ($assignRow['currency'] ?? 'TZS'), ENT_QUOTES, 'UTF-8');
                ?>
                <option value="<?= (int) $assignRow['id'] ?>">
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> (<?= $balanceLabel ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <p class="sub-modal-hint">Choose an existing account from My Accounts to add here. Balances and transaction history are preserved.</p>
            <div class="sub-modal-actions">
                <button type="button" class="sub-modal-btn sub-modal-btn-cancel" id="cancelAssignAccountModal">Cancel</button>
                <button type="submit" class="sub-modal-btn sub-modal-btn-save">Assign</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<form id="unassignForm" method="POST" class="d-none">
    <input type="hidden" name="action" value="unassign_sub_account">
    <input type="hidden" name="account_id" id="unassignAccountId">
    <input type="hidden" name="parent_id" id="unassignParentId">
    <input type="hidden" name="module" value="<?= htmlspecialchars($moduleParam, ENT_QUOTES, 'UTF-8') ?>">
</form>

<div id="rayMobileSubMenu" class="ray-m-floating-menu" role="menu" aria-hidden="true" hidden></div>

<form id="deleteForm" method="POST" class="d-none">
    <input type="hidden" name="action" id="deleteAction" value="deactivate">
    <input type="hidden" name="id" id="deleteId">
    <input type="hidden" name="module" value="<?= htmlspecialchars($moduleParam, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="selected" value="<?= (int) $selectedId ?>">
</form>

<?php if (isAdmin() && !empty($selectedChildren)): ?>
<form id="bulkDeleteForm" method="POST" class="d-none">
    <input type="hidden" name="action" value="bulk_delete_sub_accounts">
    <input type="hidden" name="delete_mode" id="bulkDeleteMode" value="deactivate">
    <input type="hidden" name="parent_id" value="<?= (int) $selectedId ?>">
    <input type="hidden" name="module" value="<?= htmlspecialchars($moduleParam, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="selected" value="<?= (int) $selectedId ?>">
</form>
<?php endif; ?>

<script>
var moveParentTargets = <?= json_encode($moveParentTargets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var mobileParentMeta = <?= json_encode($mobileParentMeta, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function rayIsMobileAccounts() {
    return window.matchMedia('(max-width: 767.98px)').matches;
}

function rayParentRowClick(event, row) {
    if (!row) return;
    if (rayIsMobileAccounts()) {
        if (event) event.preventDefault();
        rayToggleParentAccordion(row);
        return;
    }
    var url = row.getAttribute('data-parent-url');
    if (url) window.location.href = url;
}

function rayCloseMobileSubMenu() {
    var menu = document.getElementById('rayMobileSubMenu');
    if (!menu) return;
    menu.classList.remove('is-open');
    menu.setAttribute('hidden', 'hidden');
    menu.setAttribute('aria-hidden', 'true');
    menu.innerHTML = '';
    document.querySelectorAll('.ray-m-sub-menu-btn[aria-expanded="true"]').forEach(function (btn) {
        btn.setAttribute('aria-expanded', 'false');
    });
}

function rayOpenMobileSubMenu(btn) {
    var menu = document.getElementById('rayMobileSubMenu');
    if (!menu || !btn) return;

    var viewUrl = btn.getAttribute('data-view-url') || '';
    var editUrl = btn.getAttribute('data-edit-url') || '';
    var childId = parseInt(btn.getAttribute('data-child-id') || '0', 10);
    var parentId = parseInt(btn.getAttribute('data-parent-id') || '0', 10);
    var canEdit = btn.getAttribute('data-can-edit') === '1';
    var canManage = btn.getAttribute('data-can-manage') === '1';
    var canDelete = btn.getAttribute('data-can-delete') === '1';

    var html = '';
    if (viewUrl) {
        html += '<a role="menuitem" href="' + viewUrl.replace(/"/g, '&quot;') + '">View</a>';
    }
    if (canEdit && editUrl) {
        html += '<a role="menuitem" href="' + editUrl.replace(/"/g, '&quot;') + '">Edit</a>';
    }
    if (canManage && childId > 0 && parentId > 0) {
        html += '<button type="button" role="menuitem" data-ray-action="unassign" data-child-id="' + childId + '" data-parent-id="' + parentId + '">Move to main accounts</button>';
    }
    if (canDelete && childId > 0) {
        html += '<div class="ray-m-menu-sep" role="separator"></div>';
        html += '<button type="button" role="menuitem" class="is-danger" data-ray-action="delete" data-child-id="' + childId + '">Delete</button>';
    }
    if (!html) return;

    rayCloseMobileSubMenu();
    menu.innerHTML = html;
    menu.removeAttribute('hidden');
    menu.setAttribute('aria-hidden', 'false');
    menu.classList.add('is-open');
    btn.setAttribute('aria-expanded', 'true');

    var rect = btn.getBoundingClientRect();
    var menuWidth = 200;
    var left = Math.min(window.innerWidth - menuWidth - 12, Math.max(12, rect.right - menuWidth));
    menu.style.left = left + 'px';
    menu.style.top = '0px';
    menu.style.visibility = 'hidden';
    var menuHeight = menu.offsetHeight || 160;
    var top = rect.bottom + 6;
    if (top + menuHeight > window.innerHeight - 12) {
        top = Math.max(12, rect.top - menuHeight - 6);
    }
    menu.style.top = top + 'px';
    menu.style.visibility = '';
}

function rayToggleParentAccordion(row) {
    var parentId = row.getAttribute('data-parent-id');
    if (!parentId) return;
    var panel = document.getElementById('ray-accordion-' + parentId);
    var willOpen = panel && !panel.classList.contains('is-open');

    rayCloseMobileSubMenu();

    document.querySelectorAll('.ray-m-card.is-open, .ray-m-card.is-selected').forEach(function (el) {
        el.classList.remove('is-open', 'is-selected');
    });
    document.querySelectorAll('.ray-m-accordion.is-open').forEach(function (el) {
        el.classList.remove('is-open');
    });

    if (willOpen && panel) {
        row.classList.add('is-open', 'is-selected');
        panel.classList.add('is-open');
        try {
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } catch (e) {}
    }
}

function raySyncOpeningField() {
    var payWrap = document.getElementById('subPaymentTypeWrap');
    var paySelect = document.getElementById('subPaymentWalletType');
    var openingWrap = document.getElementById('subOpeningWrap');
    if (!openingWrap) return;
    openingWrap.hidden = !payWrap || payWrap.hidden || !paySelect || ['bank', 'mobile', 'cash'].indexOf(paySelect.value) === -1;
}
function raySetSubCurrency(code) {
    var menu = document.getElementById('subCurrencyMenu');
    var select = document.getElementById('subCurrency');
    var opt = menu ? menu.querySelector('.sub-cur-option[data-value="' + code + '"]') : null;
    if (!select || !opt) return;
    select.value = code;
    document.getElementById('subCurrencyFlag').src = opt.getAttribute('data-flag');
    document.getElementById('subCurrencyCode').textContent = code;
    document.getElementById('subCurrencyName').textContent = opt.getAttribute('data-name');
    menu.querySelectorAll('.sub-cur-option').forEach(function (o) {
        var on = o === opt;
        o.classList.toggle('is-selected', on);
        o.setAttribute('aria-selected', on ? 'true' : 'false');
    });
}
document.addEventListener('DOMContentLoaded', function () {
    var picker = document.getElementById('subCurrencyPicker');
    var trigger = document.getElementById('subCurrencyTrigger');
    var menu = document.getElementById('subCurrencyMenu');
    if (!picker || !trigger || !menu) return;
    function setOpen(open) {
        menu.hidden = !open;
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    trigger.addEventListener('click', function () { setOpen(menu.hidden); });
    menu.querySelectorAll('.sub-cur-option').forEach(function (opt) {
        opt.addEventListener('click', function () {
            raySetSubCurrency(opt.getAttribute('data-value'));
            setOpen(false);
        });
    });
    document.addEventListener('click', function (e) {
        if (!picker.contains(e.target)) setOpen(false);
    });
});

function rayResetSubImage() {
    var input = document.getElementById('subAccountImage');
    var preview = document.getElementById('subAccountImagePreview');
    var institution = document.getElementById('subInstitution');
    if (input) input.value = '';
    if (institution) institution.value = '';
    if (preview) { preview.hidden = true; preview.removeAttribute('src'); }
    if (input && input.parentElement) input.parentElement.classList.remove('has-image');
}

var balancesInstitutions = <?= json_encode(array_values(array_map(static fn ($i) => [
    'slug' => $i['slug'],
    'name' => $i['name'],
    'type' => $i['type'],
    'aliases' => $i['aliases'],
    'logo' => $i['logo'] !== '' ? balancesAccountImageUrl($i['logo']) : '',
    'custom' => $i['custom'],
], balances_institutions($pdo))), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

document.addEventListener('DOMContentLoaded', function () {
    var nameInput = document.getElementById('subAccountName');
    var list = document.getElementById('subInstitutionList');
    var hidden = document.getElementById('subInstitution');
    var payWrap = document.getElementById('subPaymentTypeWrap');
    var paySelect = document.getElementById('subPaymentWalletType');
    var preview = document.getElementById('subAccountImagePreview');
    var imageInput = document.getElementById('subAccountImage');
    if (!nameInput || !list || !hidden) return;
    var matches = [];
    var active = -1;
    var typeLabels = { bank: 'Bank', mobile: 'Mobile money', cash: 'Cash' };

    function close() {
        list.hidden = true;
        nameInput.setAttribute('aria-expanded', 'false');
        active = -1;
    }
    function render() {
        list.innerHTML = '';
        matches.forEach(function (inst, i) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'sub-inst-item' + (i === active ? ' is-active' : '');
            item.setAttribute('role', 'option');
            var img = document.createElement('img');
            img.alt = '';
            img.src = inst.logo;
            var name = document.createElement('span');
            name.className = 'sub-inst-name';
            name.textContent = inst.name;
            var badge = document.createElement('span');
            badge.className = 'sub-inst-type sub-inst-type--' + inst.type;
            badge.textContent = typeLabels[inst.type] || inst.type;
            item.appendChild(img);
            item.appendChild(name);
            if (inst.custom) {
                var saved = document.createElement('span');
                saved.className = 'sub-inst-type sub-inst-type--saved';
                saved.textContent = 'Saved';
                item.appendChild(saved);
            }
            item.appendChild(badge);
            item.addEventListener('mousedown', function (e) { e.preventDefault(); choose(inst); });
            list.appendChild(item);
        });
        list.hidden = matches.length === 0;
        nameInput.setAttribute('aria-expanded', matches.length ? 'true' : 'false');
    }
    function search() {
        var q = nameInput.value.trim().toLowerCase();
        if (q.length < 1 || (payWrap && payWrap.hidden)) { matches = []; close(); return; }
        var scored = [];
        balancesInstitutions.forEach(function (inst) {
            var hay = [inst.name.toLowerCase()].concat(inst.aliases);
            var best = -1;
            hay.forEach(function (h) {
                if (h.indexOf(q) === 0) best = Math.max(best, 2);
                else if (h.indexOf(' ' + q) !== -1 || h.indexOf(q) !== -1) best = Math.max(best, 1);
            });
            if (best >= 0) scored.push({ inst: inst, score: best });
        });
        scored.sort(function (a, b) { return b.score - a.score || a.inst.name.localeCompare(b.inst.name); });
        matches = scored.slice(0, 8).map(function (s) { return s.inst; });
        active = matches.length ? 0 : -1;
        render();
    }
    function choose(inst) {
        nameInput.value = inst.name;
        nameInput.dispatchEvent(new Event('input', { bubbles: true }));
        hidden.value = inst.slug;
        if (imageInput) imageInput.value = '';
        if (paySelect) {
            var opt = paySelect.querySelector('option[value="' + inst.type + '"]');
            if (opt && !opt.disabled) {
                paySelect.value = inst.type;
                raySyncOpeningField();
            }
        }
        if (preview && inst.logo) {
            preview.src = inst.logo;
            preview.hidden = false;
            preview.parentElement.classList.add('has-image');
        }
        matches = [];
        close();
    }

    nameInput.addEventListener('input', function (e) {
        if (hidden.value && e.isTrusted) {
            hidden.value = '';
            if (preview && !(imageInput && imageInput.files && imageInput.files.length)) {
                preview.hidden = true;
                preview.removeAttribute('src');
                preview.parentElement.classList.remove('has-image');
            }
        }
        if (e.isTrusted) search();
    });
    nameInput.addEventListener('focus', function () { if (!hidden.value) search(); });
    nameInput.addEventListener('blur', function () { setTimeout(close, 120); });
    nameInput.addEventListener('keydown', function (e) {
        if (list.hidden || !matches.length) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            active = (active + (e.key === 'ArrowDown' ? 1 : matches.length - 1)) % matches.length;
            render();
        } else if (e.key === 'Enter' && active >= 0) {
            e.preventDefault();
            choose(matches[active]);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            close();
        }
    });
    if (imageInput) imageInput.addEventListener('change', function () { hidden.value = ''; });
});
document.addEventListener('DOMContentLoaded', function () {
    var storageKey = 'balancesCollapsedGroups';
    var searching = <?= json_encode($subSearchQ !== '') ?>;
    var collapsed = {};
    try { collapsed = JSON.parse(localStorage.getItem(storageKey) || '{}') || {}; } catch (e) { collapsed = {}; }

    function apply(toggle, isCollapsed) {
        var key = toggle.getAttribute('data-group');
        toggle.classList.toggle('is-collapsed', isCollapsed);
        toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
        document.querySelectorAll('[data-group-member="' + key + '"]').forEach(function (el) {
            el.classList.toggle('is-group-hidden', isCollapsed);
        });
    }
    document.querySelectorAll('.ray-group-toggle').forEach(function (toggle) {
        var key = toggle.getAttribute('data-group');
        if (!searching && collapsed[key]) apply(toggle, true);
        function flip() {
            var next = !toggle.classList.contains('is-collapsed');
            apply(toggle, next);
            if (next) collapsed[key] = 1; else delete collapsed[key];
            try { localStorage.setItem(storageKey, JSON.stringify(collapsed)); } catch (e) {}
        }
        toggle.addEventListener('click', flip);
        toggle.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); flip(); }
        });
    });
});
document.addEventListener('DOMContentLoaded', function () {
    var paySelect = document.getElementById('subPaymentWalletType');
    if (paySelect) paySelect.addEventListener('change', raySyncOpeningField);
    raySyncOpeningField();

    var imageInput = document.getElementById('subAccountImage');
    var imagePreview = document.getElementById('subAccountImagePreview');
    if (imageInput && imagePreview) {
        imageInput.addEventListener('change', function () {
            var file = imageInput.files && imageInput.files[0];
            if (!file || !file.type || file.type.indexOf('image/') !== 0) {
                rayResetSubImage();
                return;
            }
            if (file.size > 2 * 1024 * 1024) {
                alert('Account image must be 2MB or smaller.');
                rayResetSubImage();
                return;
            }
            imagePreview.src = URL.createObjectURL(file);
            imagePreview.hidden = false;
            imageInput.parentElement.classList.add('has-image');
        });
    }
});

function openSubForParent(parentId) {
    var meta = mobileParentMeta[String(parentId)] || mobileParentMeta[parentId] || {};
    var modal = document.getElementById('subAccountModal');
    var parentInput = document.getElementById('subParentId');
    var selectedInput = document.getElementById('subSelectedId');
    var title = document.getElementById('subAccountModalTitle');
    var payWrap = document.getElementById('subPaymentTypeWrap');
    var paySelect = document.getElementById('subPaymentWalletType');
    if (!modal || !parentInput) return;

    parentInput.value = String(parentId);
    if (selectedInput) selectedInput.value = String(parentId);
    if (title) {
        title.textContent = 'Add New Sub-account to ' + (meta.name || 'account');
    }
    if (payWrap && paySelect) {
        var mode = meta.payment_type_mode || 'none';
        var regularOpt = document.getElementById('subPaymentTypeRegular');
        payWrap.hidden = mode === 'none';
        paySelect.required = mode === 'required';
        if (regularOpt) {
            regularOpt.hidden = mode === 'required';
            regularOpt.disabled = mode === 'required';
        }
        paySelect.value = mode === 'required' ? (meta.default_payment_type || 'cash') : '';
    }
    var openingInput = document.getElementById('subOpeningBalance');
    if (openingInput) openingInput.value = '';
    raySyncOpeningField();
    raySetSubCurrency(meta.currency || 'TZS');
    rayResetSubImage();
    modal.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    var nameInput = document.getElementById('subAccountName');
    var nameCount = document.getElementById('subNameCount');
    if (nameInput) {
        nameInput.value = '';
        if (nameCount) nameCount.textContent = '0';
        setTimeout(function () { nameInput.focus(); }, 50);
    }
}

function openAssignForParent(parentId) {
    var meta = mobileParentMeta[String(parentId)] || mobileParentMeta[parentId] || {};
    var modal = document.getElementById('assignAccountModal');
    var parentInput = document.getElementById('assignParentId');
    var selectedInput = document.getElementById('assignSelectedId');
    var title = document.getElementById('assignAccountModalTitle');
    var select = document.getElementById('assignAccountId');
    if (!modal || !parentInput || !select) return;

    parentInput.value = String(parentId);
    if (selectedInput) selectedInput.value = String(parentId);
    if (title) {
        title.textContent = 'Assign existing account to ' + (meta.name || 'account');
    }
    select.innerHTML = '<option value="">Select an account</option>';
    (meta.assignable || []).forEach(function (opt) {
        var option = document.createElement('option');
        option.value = String(opt.id);
        option.textContent = opt.label;
        select.appendChild(option);
    });
    modal.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    setTimeout(function () { select.focus(); }, 50);
}

function openMoveParentModal(sourceId) {
    var modal = document.getElementById('moveParentModal');
    var config = moveParentTargets[String(sourceId)] || moveParentTargets[sourceId];
    if (!modal || !config) return;

    var accountInput = document.getElementById('moveParentAccountId');
    var title = document.getElementById('moveParentModalTitle');
    var sourceLabel = document.getElementById('moveParentSourceLabel');
    var targetSelect = document.getElementById('moveParentTargetId');
    var submitBtn = document.getElementById('moveParentSubmitBtn');
    if (!accountInput || !title || !sourceLabel || !targetSelect) return;

    accountInput.value = String(sourceId);
    var sourceName = config.name || 'Account';
    if (config.code && config.code !== '-') {
        sourceName = config.code + ' - ' + sourceName;
    }
    title.textContent = 'Move under parent account';
    var childNote = (config.child_count || 0) > 0
        ? ' Its ' + config.child_count + ' sub-account(s) will stay under the new parent.'
        : '';
    sourceLabel.textContent = 'Moving: ' + sourceName + '.' + childNote;

    targetSelect.innerHTML = '<option value="">Select parent account</option>';
    var targets = config.targets || [];
    targets.forEach(function (target) {
        var option = document.createElement('option');
        option.value = String(target.id);
        option.textContent = target.label;
        targetSelect.appendChild(option);
    });
    targetSelect.disabled = targets.length === 0;
    if (targets.length === 0) {
        sourceLabel.textContent += ' No compatible parent account is available yet.';
    }
    if (submitBtn) {
        submitBtn.disabled = targets.length === 0;
    }

    modal.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    if (!targetSelect.disabled) {
        setTimeout(function () { targetSelect.focus(); }, 50);
    }
}

function closeMoveParentModal() {
    var modal = document.getElementById('moveParentModal');
    if (!modal) return;
    modal.classList.remove('is-open');
    document.body.style.overflow = '';
}

function unassignSubAccount(accountId, parentId) {
    Swal.fire({
        icon: 'question',
        title: 'Move to main accounts?',
        text: 'This account will appear in My Accounts again. Balances and transactions are not changed.',
        showCancelButton: true,
        confirmButtonText: 'Move',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#0f766e'
    }).then(function (result) {
        if (!result.isConfirmed) return;
        document.getElementById('unassignAccountId').value = accountId;
        document.getElementById('unassignParentId').value = parentId;
        document.getElementById('unassignForm').submit();
    });
}

function deleteAccount(id) {
    Swal.fire({
        icon: 'warning',
        title: 'Remove this account?',
        text: 'Remove from list keeps transaction history (recommended). Delete permanently erases the account and its transactions.',
        showCancelButton: true,
        showDenyButton: true,
        confirmButtonText: 'Remove from list',
        denyButtonText: 'Delete permanently',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#2563eb',
        denyButtonColor: '#dc2626'
    }).then(function (result) {
        if (!result.isConfirmed && !result.isDenied) return;
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteAction').value = result.isDenied ? 'delete_permanent' : 'deactivate';
        document.getElementById('deleteForm').submit();
    });
}

(function () {
    var toggle = document.getElementById('toggleSubSelect');
    var panel = document.querySelector('.ray-panel--subaccounts');
    var bar = document.getElementById('subSelectBar');
    var form = document.getElementById('bulkDeleteForm');
    if (!toggle || !panel || !bar || !form) return;
    var allBox = document.getElementById('subSelectAll');
    var countEl = document.getElementById('subSelectCount');
    var deleteBtn = document.getElementById('subSelectDelete');

    function boxes() { return Array.prototype.slice.call(panel.querySelectorAll('.ray-sub-check')); }
    function visibleBoxes() { return boxes().filter(function (b) { return b.offsetParent !== null; }); }
    function checked() { return boxes().filter(function (b) { return b.checked; }); }

    function refresh() {
        var n = checked().length;
        countEl.textContent = n + ' selected';
        deleteBtn.disabled = n === 0;
        var vis = visibleBoxes();
        allBox.checked = vis.length > 0 && vis.every(function (b) { return b.checked; });
        allBox.indeterminate = n > 0 && !allBox.checked;
        boxes().forEach(function (b) {
            var row = b.closest('tr');
            if (row) row.classList.toggle('is-checked', b.checked);
        });
    }

    function setMode(on) {
        panel.classList.toggle('is-selecting', on);
        toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
        bar.hidden = !on;
        if (!on) boxes().forEach(function (b) { b.checked = false; });
        refresh();
    }

    toggle.addEventListener('click', function () { setMode(!panel.classList.contains('is-selecting')); });
    document.getElementById('subSelectCancel').addEventListener('click', function () { setMode(false); });
    allBox.addEventListener('change', function () {
        visibleBoxes().forEach(function (b) { b.checked = allBox.checked; });
        refresh();
    });
    panel.addEventListener('change', function (e) {
        if (e.target.classList && e.target.classList.contains('ray-sub-check')) refresh();
    });
    panel.addEventListener('click', function (e) {
        if (e.target.classList && e.target.classList.contains('ray-sub-check')) e.stopPropagation();
    }, true);

    deleteBtn.addEventListener('click', function () {
        var ids = checked().map(function (b) { return b.value; });
        if (!ids.length) return;
        var label = ids.length === 1 ? 'this sub-account' : ids.length + ' sub-accounts';
        Swal.fire({
            icon: 'warning',
            title: 'Remove ' + label + '?',
            text: 'Remove from list keeps transaction history (recommended). Delete permanently erases the accounts and their transactions.',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonText: 'Remove from list',
            denyButtonText: 'Delete permanently',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#2563eb',
            denyButtonColor: '#dc2626'
        }).then(function (result) {
            if (!result.isConfirmed && !result.isDenied) return;
            form.querySelectorAll('input[name="ids[]"]').forEach(function (el) { el.remove(); });
            ids.forEach(function (id) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = id;
                form.appendChild(input);
            });
            document.getElementById('bulkDeleteMode').value = result.isDenied ? 'delete_permanent' : 'deactivate';
            form.submit();
        });
    });
})();

document.addEventListener('DOMContentLoaded', function () {
    var subModal = document.getElementById('subAccountModal');
    var subNameInput = document.getElementById('subAccountName');
    var subNameCount = document.getElementById('subNameCount');

    function openSubModal() {
        var selectedParentId = <?= (int) ($selectedParent['id'] ?? $selectedId) ?>;
        if (selectedParentId > 0) {
            openSubForParent(selectedParentId);
            return;
        }
        if (!subModal) return;
        subModal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        if (subNameInput) {
            subNameInput.value = '';
            if (subNameCount) subNameCount.textContent = '0';
            setTimeout(function () { subNameInput.focus(); }, 50);
        }
    }
    function closeSubModal() {
        if (!subModal) return;
        subModal.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    var openBtn = document.getElementById('openSubAccountModal');
    if (openBtn) openBtn.addEventListener('click', openSubModal);
    document.querySelectorAll('.open-sub-modal-trigger').forEach(function (btn) {
        btn.addEventListener('click', openSubModal);
    });
    var closeBtn = document.getElementById('closeSubAccountModal');
    var cancelBtn = document.getElementById('cancelSubAccountModal');
    if (closeBtn) closeBtn.addEventListener('click', closeSubModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeSubModal);
    if (subModal) {
        subModal.addEventListener('click', function (e) {
            if (e.target === subModal) closeSubModal();
        });
    }
    if (subNameInput && subNameCount) {
        subNameInput.addEventListener('input', function () {
            subNameCount.textContent = String(subNameInput.value.length);
        });
    }

    var assignModal = document.getElementById('assignAccountModal');
    function openAssignModal() {
        var selectedParentId = <?= (int) ($selectedParent['id'] ?? $selectedId) ?>;
        if (selectedParentId > 0) {
            openAssignForParent(selectedParentId);
            return;
        }
        if (!assignModal) return;
        assignModal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        var assignSelect = document.getElementById('assignAccountId');
        if (assignSelect) {
            assignSelect.selectedIndex = 0;
            setTimeout(function () { assignSelect.focus(); }, 50);
        }
    }
    function closeAssignModal() {
        if (!assignModal) return;
        assignModal.classList.remove('is-open');
        document.body.style.overflow = '';
    }
    var openAssignBtn = document.getElementById('openAssignAccountModal');
    if (openAssignBtn) openAssignBtn.addEventListener('click', openAssignModal);
    document.querySelectorAll('.open-assign-modal-trigger').forEach(function (btn) {
        btn.addEventListener('click', openAssignModal);
    });
    var closeAssignBtn = document.getElementById('closeAssignAccountModal');
    var cancelAssignBtn = document.getElementById('cancelAssignAccountModal');
    if (closeAssignBtn) closeAssignBtn.addEventListener('click', closeAssignModal);
    if (cancelAssignBtn) cancelAssignBtn.addEventListener('click', closeAssignModal);
    if (assignModal) {
        assignModal.addEventListener('click', function (e) {
            if (e.target === assignModal) closeAssignModal();
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeSubModal();
            closeAssignModal();
            closeMoveParentModal();
        }
    });

    var moveParentModal = document.getElementById('moveParentModal');
    var closeMoveParentBtn = document.getElementById('closeMoveParentModal');
    var cancelMoveParentBtn = document.getElementById('cancelMoveParentModal');
    if (closeMoveParentBtn) closeMoveParentBtn.addEventListener('click', closeMoveParentModal);
    if (cancelMoveParentBtn) cancelMoveParentBtn.addEventListener('click', closeMoveParentModal);
    if (moveParentModal) {
        moveParentModal.addEventListener('click', function (e) {
            if (e.target === moveParentModal) closeMoveParentModal();
        });
    }

    // Mobile: if URL has an explicit selection, open that accordion on load.
    if (rayIsMobileAccounts()) {
        var explicitSelected = <?= $selectedExplicit ? 'true' : 'false' ?>;
        var selectedId = <?= (int) $selectedId ?>;
        if (explicitSelected && selectedId > 0) {
            var selectedRow = document.querySelector('.ray-m-card[data-parent-id="' + selectedId + '"]');
            if (selectedRow) rayToggleParentAccordion(selectedRow);
        }
    }

    // One shared floating ⋮ menu (avoids stacked Bootstrap dropdowns).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.ray-m-sub-menu-btn') : null;
        var menu = document.getElementById('rayMobileSubMenu');
        var inMenu = menu && menu.contains(e.target);

        if (btn) {
            e.preventDefault();
            e.stopPropagation();
            if (btn.getAttribute('aria-expanded') === 'true') {
                rayCloseMobileSubMenu();
            } else {
                rayOpenMobileSubMenu(btn);
            }
            return;
        }

        if (inMenu) {
            var actionBtn = e.target.closest ? e.target.closest('[data-ray-action]') : null;
            if (actionBtn) {
                e.preventDefault();
                e.stopPropagation();
                var action = actionBtn.getAttribute('data-ray-action');
                var childId = parseInt(actionBtn.getAttribute('data-child-id') || '0', 10);
                var parentId = parseInt(actionBtn.getAttribute('data-parent-id') || '0', 10);
                rayCloseMobileSubMenu();
                if (action === 'unassign' && childId > 0 && parentId > 0) {
                    unassignSubAccount(childId, parentId);
                } else if (action === 'delete' && childId > 0) {
                    deleteAccount(childId);
                }
            }
            return;
        }

        rayCloseMobileSubMenu();
    });
    window.addEventListener('scroll', rayCloseMobileSubMenu, { passive: true });
    window.addEventListener('resize', rayCloseMobileSubMenu);
});
</script>

<?php
$pc_lottie_mobile_only = false;
$bal_lottie_okay_label = 'OK';
include __DIR__ . '/includes/footer.php';
?>
