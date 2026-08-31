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
            $result = balances_create_sub_account(
                $pdo,
                $parentId,
                $accountName,
                $paymentWalletType !== '' ? $paymentWalletType : null
            );
            if (!empty($result['success'])) {
                $_SESSION['bal_lottie_success'] = (string) ($result['message'] ?? 'Sub-account created successfully.');
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

$selectedId = (int) ($_GET['selected'] ?? 0);
if ($selectedId <= 0 && !empty($allParentAccounts)) {
    $selectedId = (int) $allParentAccounts[0]['id'];
}

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
$selectedParentShowsPaymentType = is_array($selectedParentRaw)
    && function_exists('balances_parent_is_payment_wallet_group')
    && balances_parent_is_payment_wallet_group($selectedParentRaw);
$selectedParentDefaultPaymentType = $selectedParentShowsPaymentType && function_exists('balances_infer_payment_wallet_type')
    ? balances_infer_payment_wallet_type($selectedParentRaw)
    : 'cash';

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
$selectedQs = http_build_query($accountsPageQuery);

$hideHeaderCompanyBranding = true;
$employeeHeaderExtraClass = 'employee-header--balances-accounts';
$bodyExtraClass = 'page-balances-accounts';

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
        grid-template-columns: minmax(0, 1.05fr) minmax(0, 0.95fr);
        gap: 1.25rem;
        align-items: start;
    }
    @media (max-width: 1100px) {
        .ray-accounts-grid { grid-template-columns: 1fr; }
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
    .ray-panel-title { font-size: 1.125rem; font-weight: 600; color: #111827; margin: 0; }
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
    .ray-search input {
        width: 100%;
        padding: 0.65rem 0.85rem;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        font-size: 0.875rem;
        color: #111827;
        background: #fff;
    }
    .ray-search input::placeholder { color: #9ca3af; }
    .ray-search input:focus {
        outline: none;
        border-color: #94a3b8;
        box-shadow: 0 0 0 3px rgba(148, 163, 184, 0.15);
    }
    .ray-table { width: 100%; border-collapse: collapse; }
    .ray-table thead th {
        padding: 0.7rem 1rem;
        font-size: 0.75rem;
        font-weight: 500;
        color: #6b7280;
        text-align: left;
        border-bottom: 1px solid #f1f5f9;
        background: #fafbfc;
    }
    .ray-table tbody td {
        padding: 0.95rem 1rem;
        border-bottom: 1px solid #f8fafc;
        vertical-align: top;
        font-size: 0.875rem;
        color: #111827;
    }
    .ray-table tbody tr:last-child td { border-bottom: 0; }
    .ray-parent-row { cursor: pointer; transition: background 0.15s ease; }
    .ray-parent-row:hover { background: #f8fafc; }
    .ray-parent-row.is-selected { background: #eff6ff; }
    .ray-account-name { font-weight: 600; color: #111827; display: flex; align-items: center; flex-wrap: wrap; gap: 6px; }
    .ray-account-desc { font-size: 0.75rem; color: #6b7280; margin-top: 4px; line-height: 1.45; max-width: 360px; }
    .ray-badge {
        display: inline-flex;
        align-items: center;
        padding: 2px 7px;
        border-radius: 6px;
        font-size: 0.6875rem;
        font-weight: 600;
        line-height: 1.2;
    }
    .ray-badge-code { background: #f3f4f6; color: #374151; }
    .ray-badge-dr { background: #dbeafe; color: #1d4ed8; }
    .ray-badge-cr { background: #ede9fe; color: #6d28d9; }
    .ray-sub-count { font-size: 0.875rem; color: #111827; font-weight: 500; }
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
        display: flex;
        flex-direction: row;
        flex-wrap: nowrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 0;
        border-bottom: none;
        background: transparent;
    }
    .ray-detail-head > div:first-child {
        min-width: 0;
        flex: 1 1 auto;
    }
    .ray-detail-title {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        font-size: 1.25rem;
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
    .ray-sub-bulk-bar {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 10px;
        padding: 0 1rem 0.35rem;
    }
    .ray-sub-bulk-bar[hidden] { display: none !important; }
    .ray-sub-bulk-count {
        font-size: 0.8125rem;
        font-weight: 500;
        color: #6b7280;
    }
    .ray-sub-bulk-delete {
        color: #9ca3af;
    }
    .ray-sub-bulk-delete:hover {
        background: #f3f4f6;
        color: #dc2626;
    }
    .ray-sub-check {
        width: 16px;
        height: 16px;
        accent-color: #7c3aed;
        cursor: pointer;
    }
    .ray-sub-check:disabled { cursor: not-allowed; opacity: 0.45; }
    .ray-table tbody tr.is-sub-selected { background: #fef2f2; }
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
        font-size: 0.875rem; color: #111827;
    }
    .sub-modal-input:focus {
        outline: none; border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
    }
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
        background: #0f172a !important;
        color: #e2e8f0 !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .employee-header.employee-header--balances-accounts {
        background: #0f172a !important;
        border-bottom-color: #1e293b !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-badge {
        color: inherit;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-panel {
        background: #1e293b;
        border-color: #334155;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.35);
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-panel-head,
    html[data-theme="dark"] body.page-balances-accounts .ray-search {
        border-bottom-color: #334155;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-panel-title,
    html[data-theme="dark"] body.page-balances-accounts .ray-detail-title,
    html[data-theme="dark"] body.page-balances-accounts .ray-account-name,
    html[data-theme="dark"] body.page-balances-accounts .ray-sub-count,
    html[data-theme="dark"] body.page-balances-accounts .ray-table tbody td,
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-head h2,
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-label-row label {
        color: #f8fafc !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-account-desc,
    html[data-theme="dark"] body.page-balances-accounts .ray-sub-bulk-count,
    html[data-theme="dark"] body.page-balances-accounts .ray-empty,
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-hint,
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-count,
    html[data-theme="dark"] body.page-balances-accounts .ray-table thead th {
        color: #94a3b8 !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-table thead th {
        background: #0f172a;
        border-bottom-color: #334155;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-table tbody td {
        border-bottom-color: #334155;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-parent-row:hover {
        background: #334155;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-parent-row.is-selected {
        background: rgba(124, 58, 237, 0.18);
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-table tbody tr.is-sub-selected {
        background: rgba(185, 28, 28, 0.18);
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-badge-code {
        background: #334155;
        color: #e2e8f0 !important;
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
        background: #0f172a;
        border-color: #475569;
        color: #f8fafc !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-search input::placeholder {
        color: #64748b;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-search input:focus,
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-input:focus {
        border-color: #7c3aed;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.2);
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-btn-new,
    html[data-theme="dark"] body.page-balances-accounts .ray-btn-secondary,
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-btn-cancel {
        background: #334155;
        border-color: #475569;
        color: #e2e8f0 !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-btn-new:hover,
    html[data-theme="dark"] body.page-balances-accounts .ray-btn-secondary:hover,
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-btn-cancel:hover {
        background: #475569;
        color: #f8fafc !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-btn-new-account,
    html[data-theme="dark"] body.page-balances-accounts .ray-btn-new-account:hover {
        background: #7c3aed;
        border-color: #7c3aed;
        color: #fff !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-action-btn {
        color: #94a3b8;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-action-btn:hover {
        background: #334155;
        color: #e2e8f0;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-empty-icon {
        background: #0f172a;
        color: #64748b;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-alert {
        background: rgba(127, 29, 29, 0.35);
        color: #fecaca;
        border: 1px solid #991b1b;
    }
    html[data-theme="dark"] body.page-balances-accounts .sub-modal {
        background: #1e293b;
        border: 1px solid #334155;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.45);
    }
    html[data-theme="dark"] body.page-balances-accounts .sub-modal-close {
        color: #94a3b8;
    }
    html[data-theme="dark"] body.page-balances-accounts .text-gray-400,
    html[data-theme="dark"] body.page-balances-accounts .text-xs.text-gray-400 {
        color: #94a3b8 !important;
    }
    html[data-theme="dark"] body.page-balances-accounts .ray-panel-head span,
    html[data-theme="dark"] body.page-balances-accounts .ray-account-name span {
        color: inherit;
    }
</style>

<main class="main-content">
    <div class="ray-accounts-wrap">
        <?php if ($sessionError !== ''): ?>
            <div class="ray-alert"><?= htmlspecialchars($sessionError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="ray-accounts-grid">
            <!-- Left: My Accounts -->
            <section class="ray-panel" aria-label="My accounts">
                <div class="ray-panel-head">
                    <h1 class="ray-panel-title">My Accounts</h1>
                    <?php if ($canManage): ?>
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
                    <input type="text" name="search" value="<?= htmlspecialchars($searchQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search account">
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
                                    $isSelected = (int) $parentRow['id'] === $selectedId;
                                    $rowUrl = function_exists('balances_accounts_redirect_url')
                                        ? balances_accounts_redirect_url(array_filter([
                                            'module' => $moduleParam,
                                            'selected' => (int) $parentRow['id'],
                                            'search' => $searchQ !== '' ? $searchQ : null,
                                        ]))
                                        : ('accounts.php?' . http_build_query(array_filter([
                                            'module' => $moduleParam,
                                            'selected' => (int) $parentRow['id'],
                                            'search' => $searchQ !== '' ? $searchQ : null,
                                        ])));
                                    $editUrl = 'coa_edit.php?id=' . (int) $parentRow['id'] . ($moduleParam !== '' ? '&module=' . rawurlencode($moduleParam) : '');
                                ?>
                                <tr class="ray-parent-row<?= $isSelected ? ' is-selected' : '' ?>"
                                    data-parent-url="<?= htmlspecialchars($rowUrl, ENT_QUOTES, 'UTF-8') ?>"
                                    onclick="window.location.href=this.dataset.parentUrl">
                                    <td><?= $index + 1 ?></td>
                                    <td>
                                        <div class="ray-account-name">
                                            <?= htmlspecialchars($parentRow['name'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php if (!empty($parentRow['is_system'])): ?>
                                                <i class="fas fa-lock text-gray-400 ms-1 text-xs" title="System Account"></i>
                                            <?php endif; ?>
                                            <span class="ray-badge ray-badge-code"><?= htmlspecialchars($parentRow['code'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="ray-badge <?= $parentRow['normal_balance_short'] === 'Cr' ? 'ray-badge-cr' : 'ray-badge-dr' ?>"><?= htmlspecialchars($parentRow['normal_balance_short'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <div class="ray-account-desc"><?= htmlspecialchars($parentRow['description'], ENT_QUOTES, 'UTF-8') ?></div>
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
                                                    <li><a class="dropdown-item" href="<?= htmlspecialchars('view_account.php?' . http_build_query(['module' => $moduleParam, 'id' => (int) $parentRow['id']]), ENT_QUOTES, 'UTF-8') ?>">View</a></li>
                                                    <?php if ($canManage): ?>
                                                        <li>
                                                            <button type="button"
                                                                    class="dropdown-item"
                                                                    onclick="openMoveParentModal(<?= (int) $parentRow['id'] ?>)">
                                                                Move under parent account
                                                            </button>
                                                        </li>
                                                    <?php endif; ?>
                                                    <?php if (isAdmin()): ?>
                                                        <li><a class="dropdown-item" href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>">Edit</a></li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li><button type="button" class="dropdown-item text-danger" onclick="deleteAccount(<?= (int) $parentRow['id'] ?>)">Delete</button></li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Right: Sub-accounts -->
            <?php if ($selectedParent): ?>
            <div class="ray-detail-column" aria-label="Sub-accounts">
                <div class="ray-detail-head">
                    <div>
                        <div class="ray-detail-title">
                            <?= htmlspecialchars($selectedParent['name'], ENT_QUOTES, 'UTF-8') ?>
                            <span class="ray-badge ray-badge-code"><?= htmlspecialchars($selectedParent['code'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="ray-badge <?= $selectedParent['normal_balance_short'] === 'Cr' ? 'ray-badge-cr' : 'ray-badge-dr' ?>"><?= htmlspecialchars($selectedParent['normal_balance_short'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                    <?php if ($canManage): ?>
                        <div class="ray-head-actions">
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
                <section class="ray-panel ray-panel--subaccounts">
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
                            <?php
                            $deletableSubCount = 0;
                            foreach ($selectedChildren as $childRow) {
                                if (empty($childRow['is_system'])) {
                                    $deletableSubCount++;
                                }
                            }
                            ?>
                            <?php if (isAdmin() && $deletableSubCount > 0): ?>
                                <div class="ray-sub-bulk-bar" id="subBulkBar" hidden>
                                    <span class="ray-sub-bulk-count" id="subBulkCount">0 selected</span>
                                    <button type="button" class="ray-action-btn ray-sub-bulk-delete" id="subBulkDeleteBtn" aria-label="Delete selected sub-accounts" title="Delete selected">
                                        <i class="fas fa-trash-can" aria-hidden="true"></i>
                                    </button>
                                </div>
                            <?php endif; ?>
                            <table class="ray-table" id="subAccountsTable">
                                <thead>
                                    <tr>
                                        <?php if (isAdmin() && $deletableSubCount > 0): ?>
                                            <th style="width:40px;">
                                                <input type="checkbox"
                                                       class="ray-sub-check"
                                                       id="subSelectAll"
                                                       aria-label="Select all sub-accounts">
                                            </th>
                                        <?php endif; ?>
                                        <th>Sub-account</th>
                                        <th style="width:52px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($selectedChildren as $childRow):
                                        $childViewUrl = 'view_account.php?' . http_build_query(['module' => $moduleParam, 'id' => (int) $childRow['id']]);
                                        $childEditUrl = 'coa_edit.php?id=' . (int) $childRow['id'] . ($moduleParam !== '' ? '&module=' . rawurlencode($moduleParam) : '');
                                        $childIsSystem = !empty($childRow['is_system']);
                                    ?>
                                    <tr data-sub-id="<?= (int) $childRow['id'] ?>"<?= $childIsSystem ? ' data-sub-system="1"' : '' ?>>
                                        <?php if (isAdmin() && $deletableSubCount > 0): ?>
                                            <td>
                                                <?php if (!$childIsSystem): ?>
                                                    <input type="checkbox"
                                                           class="ray-sub-check sub-account-select"
                                                           value="<?= (int) $childRow['id'] ?>"
                                                           aria-label="Select <?= htmlspecialchars($childRow['name'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                        <td>
                                            <div class="ray-account-name">
                                                <?= htmlspecialchars($childRow['name'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php if (!empty($childRow['is_system'])): ?>
                                                    <i class="fas fa-lock text-gray-400 ms-1 text-xs" title="System Account"></i>
                                                <?php endif; ?>
                                                <span class="ray-badge ray-badge-code"><?= htmlspecialchars($childRow['code'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="ray-badge <?= $childRow['normal_balance_short'] === 'Cr' ? 'ray-badge-cr' : 'ray-badge-dr' ?>"><?= htmlspecialchars($childRow['normal_balance_short'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <div class="ray-account-desc"><?= htmlspecialchars($childRow['description'], ENT_QUOTES, 'UTF-8') ?></div>
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

<?php if ($selectedParent && $canManage): ?>
<div class="sub-modal-backdrop" id="subAccountModal" role="presentation">
    <div class="sub-modal" role="dialog" aria-modal="true" aria-labelledby="subAccountModalTitle">
        <form method="POST" action="">
            <input type="hidden" name="action" value="create_sub_account">
            <input type="hidden" name="parent_id" value="<?= (int) $selectedParent['id'] ?>">
            <input type="hidden" name="module" value="<?= htmlspecialchars($moduleParam, ENT_QUOTES, 'UTF-8') ?>">
            <div class="sub-modal-head">
                <h2 id="subAccountModalTitle">Add New Sub-account to <?= htmlspecialchars($selectedParent['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                <button type="button" class="sub-modal-close" id="closeSubAccountModal" aria-label="Close">&times;</button>
            </div>
            <div class="sub-modal-label-row">
                <label for="subAccountName">Name</label>
                <span class="sub-modal-count"><span id="subNameCount">0</span>/100</span>
            </div>
            <input type="text"
                   class="sub-modal-input"
                   id="subAccountName"
                   name="account_name"
                   maxlength="100"
                   required
                   placeholder="Enter sub-account name"
                   autocomplete="off">
            <?php if ($selectedParentShowsPaymentType): ?>
            <div class="sub-modal-label-row" style="margin-top:14px;">
                <label for="subPaymentWalletType">Payment type</label>
            </div>
            <select class="sub-modal-select" id="subPaymentWalletType" name="payment_wallet_type" required>
                <?php foreach (balances_payment_wallet_types() as $walletSlug => $walletLabel): ?>
                    <option value="<?= htmlspecialchars($walletSlug, ENT_QUOTES, 'UTF-8') ?>"<?= $walletSlug === $selectedParentDefaultPaymentType ? ' selected' : '' ?>>
                        <?= htmlspecialchars($walletLabel, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="sub-modal-hint">How this wallet is treated on expenses and transfers (cash, bank, or mobile money).</p>
            <?php endif; ?>
            <div class="sub-modal-actions">
                <button type="button" class="sub-modal-btn sub-modal-btn-cancel" id="cancelSubAccountModal">Cancel</button>
                <button type="submit" class="sub-modal-btn sub-modal-btn-save">Save</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($selectedParent && $canManage && !empty($assignableAccounts)): ?>
<div class="sub-modal-backdrop" id="assignAccountModal" role="presentation">
    <div class="sub-modal" role="dialog" aria-modal="true" aria-labelledby="assignAccountModalTitle">
        <form method="POST" action="">
            <input type="hidden" name="action" value="assign_existing_sub_account">
            <input type="hidden" name="parent_id" value="<?= (int) $selectedParent['id'] ?>">
            <input type="hidden" name="module" value="<?= htmlspecialchars($moduleParam, ENT_QUOTES, 'UTF-8') ?>">
            <div class="sub-modal-head">
                <h2 id="assignAccountModalTitle">Assign existing account to <?= htmlspecialchars($selectedParent['name'], ENT_QUOTES, 'UTF-8') ?></h2>
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

<form id="deleteForm" method="POST" class="d-none">
    <input type="hidden" name="action" id="deleteAction" value="deactivate">
    <input type="hidden" name="id" id="deleteId">
    <input type="hidden" name="module" value="<?= htmlspecialchars($moduleParam, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="selected" value="<?= (int) $selectedId ?>">
</form>

<form id="bulkDeleteForm" method="POST" class="d-none">
    <input type="hidden" name="action" value="bulk_delete_sub_accounts">
    <input type="hidden" name="delete_mode" id="bulkDeleteMode" value="deactivate">
    <input type="hidden" name="parent_id" value="<?= (int) $selectedId ?>">
    <input type="hidden" name="module" value="<?= htmlspecialchars($moduleParam, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="selected" value="<?= (int) $selectedId ?>">
    <div id="bulkDeleteIdsContainer"></div>
</form>

<script>
var moveParentTargets = <?= json_encode($moveParentTargets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

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

function getSelectedSubAccountIds() {
    return Array.prototype.slice.call(document.querySelectorAll('.sub-account-select:checked'))
        .map(function (el) { return parseInt(el.value, 10); })
        .filter(function (id) { return id > 0; });
}

function updateSubBulkBar() {
    var bar = document.getElementById('subBulkBar');
    var countEl = document.getElementById('subBulkCount');
    var deleteBtn = document.getElementById('subBulkDeleteBtn');
    var selectAll = document.getElementById('subSelectAll');
    if (!bar || !countEl || !deleteBtn) return;

    var ids = getSelectedSubAccountIds();
    var total = document.querySelectorAll('.sub-account-select').length;
    bar.hidden = ids.length === 0;
    countEl.textContent = ids.length === 1 ? '1 selected' : ids.length + ' selected';

    document.querySelectorAll('.sub-account-select').forEach(function (cb) {
        var row = cb.closest('tr');
        if (row) row.classList.toggle('is-sub-selected', cb.checked);
    });

    if (selectAll) {
        selectAll.indeterminate = ids.length > 0 && ids.length < total;
        selectAll.checked = total > 0 && ids.length === total;
    }
}

function deleteSelectedSubAccounts() {
    var ids = getSelectedSubAccountIds();
    if (ids.length === 0) return;

    var noun = ids.length === 1 ? 'this sub-account' : ids.length + ' sub-accounts';
    Swal.fire({
        icon: 'warning',
        title: 'Remove selected sub-accounts?',
        text: 'Remove from list keeps transaction history (recommended). Delete permanently erases the selected accounts and their transactions.',
        showCancelButton: true,
        showDenyButton: true,
        confirmButtonText: 'Remove from list',
        denyButtonText: 'Delete permanently',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#2563eb',
        denyButtonColor: '#dc2626'
    }).then(function (result) {
        if (!result.isConfirmed && !result.isDenied) return;

        var form = document.getElementById('bulkDeleteForm');
        var container = document.getElementById('bulkDeleteIdsContainer');
        var modeInput = document.getElementById('bulkDeleteMode');
        if (!form || !container || !modeInput) return;

        container.innerHTML = '';
        ids.forEach(function (id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ids[]';
            input.value = String(id);
            container.appendChild(input);
        });
        modeInput.value = result.isDenied ? 'delete_permanent' : 'deactivate';
        form.submit();
    });
}

document.addEventListener('DOMContentLoaded', function () {
    var subModal = document.getElementById('subAccountModal');
    var subNameInput = document.getElementById('subAccountName');
    var subNameCount = document.getElementById('subNameCount');

    function openSubModal() {
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

    var subSelectAll = document.getElementById('subSelectAll');
    if (subSelectAll) {
        subSelectAll.addEventListener('change', function () {
            var checked = subSelectAll.checked;
            document.querySelectorAll('.sub-account-select').forEach(function (cb) {
                cb.checked = checked;
            });
            updateSubBulkBar();
        });
    }
    document.querySelectorAll('.sub-account-select').forEach(function (cb) {
        cb.addEventListener('change', updateSubBulkBar);
    });
    var subBulkDeleteBtn = document.getElementById('subBulkDeleteBtn');
    if (subBulkDeleteBtn) {
        subBulkDeleteBtn.addEventListener('click', deleteSelectedSubAccounts);
    }
    updateSubBulkBar();
});
</script>

<?php
$pc_lottie_mobile_only = false;
$bal_lottie_okay_label = 'OK';
include __DIR__ . '/includes/footer.php';
?>
