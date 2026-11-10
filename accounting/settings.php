<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/accounting_settings.php';
require_once __DIR__ . '/../includes/gl/gl_core.php';
require_once __DIR__ . '/../includes/gl/gl_fx_auto.php';
require_once __DIR__ . '/../includes/gl/gl_year_end.php';

requireLogin();

if (!isAdmin() && !isFinance()) {
    http_response_code(403);
    die('Access denied.');
}

global $pdo;

$module = isset($_GET['module']) ? htmlspecialchars((string) $_GET['module']) : 'accounting';
$success = '';
$error = '';
$fetchInfo = false;

require_once __DIR__ . '/../includes/accounting_journals.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_journal') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $jrnResult = ['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'];
    } else {
        $jrnResult = accounting_journal_toggle_status($pdo, trim((string) ($_POST['id'] ?? '')));
    }
    $_SESSION['journal_config_flash'] = ['message' => $jrnResult['ok'] ? 'Journal status updated.' : $jrnResult['error'], 'error' => !$jrnResult['ok']];
    header('Location: settings.php?module=' . rawurlencode((string) ($_GET['module'] ?? 'accounting')) . '#as-journals');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_journal') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $jrnResult = ['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'];
    } else {
        $jrnId = trim((string) ($_POST['id'] ?? ''));
        $jrnResult = accounting_journal_update_basic($pdo, $jrnId, $_POST);
    }
    $_SESSION['journal_config_flash'] = ['message' => $jrnResult['ok'] ? 'Journal saved.' : $jrnResult['error'], 'error' => !$jrnResult['ok']];
    header('Location: settings.php?module=' . rawurlencode((string) ($_GET['module'] ?? 'accounting')) . '#as-journals');
    exit;
}if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_journal') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $jrnResult = ['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'];
    } else {
        $newType = strtolower(trim((string) ($_POST['type'] ?? '')));
        $newCode = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $moduleByType = ['general' => 'General', 'sales' => 'Sales', 'purchase' => 'Purchasing', 'bank' => 'Balances', 'cash' => 'Balances', 'payments' => 'Balances', 'payroll' => 'Payroll'];
        $jrnResult = accounting_journal_save($pdo, [
            'name' => $_POST['name'] ?? '',
            'code' => $newCode,
            'type' => $newType,
            'prefix' => $newCode . '-',
            'currency' => 'TZS',
            'linked_module' => $moduleByType[$newType] ?? 'General',
            'description' => $_POST['description'] ?? '',
            'status' => 'active',
            'allow_manual' => !empty($_POST['allow_manual']),
            'allow_posting' => true,
            'allow_reverse' => true,
            'auto_numbering' => true,
            'affects_cash' => in_array($newType, ['bank', 'cash'], true),
        ], 'active', null);
    }
    $_SESSION['journal_config_flash'] = ['message' => $jrnResult['ok'] ? 'Journal created.' : $jrnResult['error'], 'error' => !$jrnResult['ok']];
    header('Location: settings.php?module=' . rawurlencode((string) ($_GET['module'] ?? 'accounting')) . '#as-journals');
    exit;
}$jrnFlash = $_SESSION['journal_config_flash'] ?? null;
unset($_SESSION['journal_config_flash']);
$jrnTypes = accounting_journal_types();
$jrnList = accounting_journals_get($pdo);
$jrnAccounts = accounting_journal_account_options($pdo);
$jrnCounts = accounting_journal_entry_counts($pdo);
$jrnLog = accounting_journal_log_by_code($pdo);
$glRoles = ['revenue' => [
    'label' => 'Sales Revenue',
    'help' => 'Credited with the invoice amount before VAT. Use another account to split revenue (e.g. Domestic, Export).',
    'type' => 'revenue',
]] + accounting_gl_roles();

$roleGroups = [
    ['title' => 'Sales & receivables', 'sub' => 'Accounts used on an invoice.', 'icon' => 'fa-file-invoice-dollar', 'roles' => ['revenue', 'receivable', 'output_tax']],
    ['title' => 'Stock & cost of sales', 'sub' => 'Accounts for the cost of stock sold.', 'icon' => 'fa-boxes-stacked', 'roles' => ['cogs', 'inventory']],
    ['title' => 'Customer receipts', 'sub' => 'Where payments go if no bank or cash account is picked.', 'icon' => 'fa-building-columns', 'roles' => ['payment_bank']],
    ['title' => 'Automatic accounts', 'sub' => 'The ledger handles these for you. Change them only if your accountant asks.', 'icon' => 'fa-gears', 'roles' => ['accounts_payable', 'opening_equity', 'retained_earnings', 'fx_gain_loss', 'transfer_clearing', 'suspense']],
];

$roleConfigured = static function (string $role) use ($pdo): ?int {
    return $role === 'revenue'
        ? accounting_get_default_sales_revenue_gl_account_id($pdo)
        : accounting_get_gl_role_account_id($pdo, $role);
};
$roleResolved = static function (string $role) use ($pdo): ?int {
    return $role === 'revenue'
        ? accounting_resolve_default_sales_revenue_gl_account_id($pdo)
        : accounting_resolve_gl_role_account_id($pdo, $role);
};

$currencyChoices = [
    'TZS' => 'Tanzanian Shilling', 'USD' => 'US Dollar', 'EUR' => 'Euro', 'GBP' => 'British Pound', 'KES' => 'Kenyan Shilling',
    'UGX' => 'Ugandan Shilling', 'RWF' => 'Rwandan Franc', 'BIF' => 'Burundian Franc', 'ZMW' => 'Zambian Kwacha',
    'MWK' => 'Malawian Kwacha', 'MZN' => 'Mozambican Metical', 'ZAR' => 'South African Rand', 'BWP' => 'Botswana Pula',
    'NGN' => 'Nigerian Naira', 'GHS' => 'Ghanaian Cedi', 'EGP' => 'Egyptian Pound', 'AED' => 'UAE Dirham',
    'SAR' => 'Saudi Riyal', 'QAR' => 'Qatari Riyal', 'KWD' => 'Kuwaiti Dinar', 'OMR' => 'Omani Rial', 'BHD' => 'Bahraini Dinar',
    'CNY' => 'Chinese Yuan', 'INR' => 'Indian Rupee', 'JPY' => 'Japanese Yen', 'KRW' => 'South Korean Won',
    'SGD' => 'Singapore Dollar', 'HKD' => 'Hong Kong Dollar', 'MYR' => 'Malaysian Ringgit', 'PKR' => 'Pakistani Rupee',
    'TRY' => 'Turkish Lira', 'CHF' => 'Swiss Franc', 'CAD' => 'Canadian Dollar', 'AUD' => 'Australian Dollar',
    'NZD' => 'New Zealand Dollar', 'SEK' => 'Swedish Krona', 'NOK' => 'Norwegian Krone', 'DKK' => 'Danish Krone',
    'PLN' => 'Polish Zloty', 'BRL' => 'Brazilian Real', 'MXN' => 'Mexican Peso',
];
$currencyFlagCodes = [
    'TZS' => 'tz', 'USD' => 'us', 'EUR' => 'eu', 'GBP' => 'gb', 'KES' => 'ke', 'UGX' => 'ug', 'RWF' => 'rw', 'BIF' => 'bi',
    'ZMW' => 'zm', 'MWK' => 'mw', 'MZN' => 'mz', 'ZAR' => 'za', 'BWP' => 'bw', 'NGN' => 'ng', 'GHS' => 'gh', 'EGP' => 'eg',
    'AED' => 'ae', 'SAR' => 'sa', 'QAR' => 'qa', 'KWD' => 'kw', 'OMR' => 'om', 'BHD' => 'bh', 'CNY' => 'cn', 'INR' => 'in',
    'JPY' => 'jp', 'KRW' => 'kr', 'SGD' => 'sg', 'HKD' => 'hk', 'MYR' => 'my', 'PKR' => 'pk', 'TRY' => 'tr', 'CHF' => 'ch',
    'CAD' => 'ca', 'AUD' => 'au', 'NZD' => 'nz', 'SEK' => 'se', 'NOK' => 'no', 'DKK' => 'dk', 'PLN' => 'pl', 'BRL' => 'br', 'MXN' => 'mx',
];
$curFlag = static function (string $code) use ($currencyFlagCodes): string {
    return isset($currencyFlagCodes[$code])
        ? 'https://flagcdn.com/w40/' . $currencyFlagCodes[$code] . '.png'
        : 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#e2e8f0"/><path d="M8 12h8M12 8v8" stroke="#64748b" stroke-width="1.5" stroke-linecap="round"/></svg>');
};
$glReady = gl_ensure_schema($pdo);
$ledgerHasEntries = false;
if ($glReady) {
    try {
        $ledgerHasEntries = (int) $pdo->query('SELECT COUNT(*) FROM gl_journal_entries')->fetchColumn() > 0;
    } catch (Throwable $e) {
        $ledgerHasEntries = true;
    }
}

$fxRowsLoad = static function (PDO $pdo): array {
    return $pdo->query('SELECT id, currency, rate_date, rate, note FROM gl_exchange_rates ORDER BY rate_date DESC, currency LIMIT 60')->fetchAll(PDO::FETCH_ASSOC);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_pdf'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Session expired.');
    }
    $content = (string) ($_POST['html'] ?? '');
    $convTitle = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
    $content = preg_replace('#<(script|style|iframe|object|embed|link)\b[^>]*>.*?</\1>#is', '', $content);
    $content = preg_replace('#<(script|iframe|object|embed|link)\b[^>]*>#i', '', $content);
    $content = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\')#i', '', $content);
    $content = preg_replace('#<img\b[^>]*>#i', '', $content);

    $toLocal = static function (string $url): string {
        if ($url === '' || str_starts_with($url, 'data:')) {
            return $url;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
        $parts = explode('/', ltrim($path, '/'));
        while ($parts) {
            $cand = $root . '/' . implode('/', $parts);
            if (is_file($cand)) {
                return $cand;
            }
            array_shift($parts);
        }
        return $url;
    };
    $companyLogo = '';
    try {
        $companyLogo = $toLocal((string) getCompanyLogoUrl());
    } catch (Throwable $e) {
    }
    $companyName = trim((string) getCompanySetting('company_name', ''));
    $brandLogo = dirname(__DIR__) . '/assets/brand/ultitech-logo-512.png';

    $head = '<table width="100%" style="border-bottom:1px solid #d1d5db;margin-bottom:14px"><tr><td style="padding-bottom:8px">'
        . ($companyLogo !== '' ? '<img src="' . htmlspecialchars($companyLogo, ENT_QUOTES) . '" style="height:48px">' : '')
        . '</td><td align="right" style="font-size:13pt;font-weight:bold;color:#111827">' . htmlspecialchars($companyName) . '</td></tr></table>';
    $foot = '<div style="text-align:center;font-size:9pt;color:#6b7280;border-top:1px solid #d1d5db;padding-top:6px">'
        . 'Powered by Ultitech '
        . (is_file($brandLogo) ? '<img src="' . htmlspecialchars($brandLogo, ENT_QUOTES) . '" style="height:14px;vertical-align:middle">' : '')
        . '</div>';

    try {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mpdf_ai';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 15,
            'margin_bottom' => 22,
            'tempDir' => $tempDir,
            'default_font' => 'dejavusans',
        ]);
        $mpdf->SetTitle('AI response');
        $mpdf->SetHTMLFooter($foot);
        $mpdf->WriteHTML('<style>body{font-family:dejavusans;font-size:10.5pt;line-height:1.5;color:#111827}table.t{border-collapse:collapse}.c td,.c th{border:1px solid #d1d5db;padding:4px 6px}</style>' . $head . '<div class="c">' . ($convTitle !== '' ? '<h2 style="font-size:15pt;margin:0 0 10px 0;color:#111827">' . htmlspecialchars($convTitle) . '</h2>' : '') . $content . '</div>');
        $mpdf->Output('ai-response-' . date('Y-m-d-His') . '.pdf', 'D');
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Could not create the PDF.';
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fx_ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $reply = static function (bool $ok, string $msg, PDO $pdo, bool $ready) use ($fxRowsLoad): void {
        echo json_encode([
            'ok' => $ok,
            'message' => $msg,
            'rows' => $ready ? $fxRowsLoad($pdo) : [],
            'count' => $ready ? count(gl_latest_exchange_rates($pdo)) : 0,
        ]);
        exit;
    };
    if (!$glReady) {
        $reply(false, 'The ledger is not ready.', $pdo, false);
    }
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $reply(false, 'Your session expired. Reload the page and try again.', $pdo, true);
    }
    $act = (string) $_POST['fx_ajax'];
    $base = gl_base_currency($pdo);
    if ($act === 'add') {
        $cur = gl_normalize_currency($_POST['currency'] ?? '');
        $date = (string) ($_POST['rate_date'] ?? '');
        $rate = (float) str_replace([',', ' '], '', (string) ($_POST['rate'] ?? ''));
        if ($cur === '' || $cur === $base) {
            $reply(false, 'Choose a currency other than ' . $base . '.', $pdo, true);
        }
        if (!strtotime($date)) {
            $reply(false, 'Enter a valid date.', $pdo, true);
        }
        if ($rate <= 0) {
            $reply(false, 'The rate must be greater than zero.', $pdo, true);
        }
        $pdo->prepare('INSERT INTO gl_exchange_rates (currency, rate_date, rate, note, created_by) VALUES (?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE rate = VALUES(rate), note = VALUES(note), created_by = VALUES(created_by)')
            ->execute([$cur, date('Y-m-d', strtotime($date)), $rate, 'Manual (Accounting Settings)', $_SESSION['user_id'] ?? null]);
        if (gl_meta_get($pdo, 'autosync') === '1') {
            require_once __DIR__ . '/../includes/gl/gl_bridge.php';
            gl_bridge_sync_all($pdo);
        }
        $reply(true, "Saved: 1 {$cur} = " . number_format($rate, 2) . " {$base}.", $pdo, true);
    }
    if ($act === 'delete') {
        $pdo->prepare('DELETE FROM gl_exchange_rates WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        $reply(true, 'Rate removed. Entries already posted keep the rate they were posted with.', $pdo, true);
    }
    if ($act === 'lookup') {
        $cur = gl_normalize_currency($_POST['currency'] ?? '');
        $hit = ($cur !== '' && $cur !== $base) ? gl_fx_lookup_rate($pdo, $cur) : null;
        echo json_encode($hit
            ? ['ok' => true, 'currency' => $cur, 'rate' => $hit['rate'], 'source' => $hit['source']]
            : ['ok' => false, 'currency' => $cur, 'message' => 'Could not get the BOT rate for ' . $cur . '. Enter it manually.']);
        exit;
    }
    if ($act === 'list') {
        $reply(true, '', $pdo, true);
    }
    $reply(false, 'Unknown action.', $pdo, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_rates'])) {
    if (!$glReady) {
        $error = 'The ledger is not ready.';
    } else {
        gl_meta_set($pdo, 'fx_mode', ($_POST['fx_mode'] ?? '') === 'auto' ? 'auto' : 'manual');
        $res = gl_fx_fetch_today($pdo, $_SESSION['user_id'] ?? null);
        if (!$res['saved'] && !$res['failed']) {
            $error = 'No foreign-currency accounts found, so there is nothing to fetch.';
            $fetchInfo = true;
        } elseif ($res['failed']) {
            $error = 'Could not get a rate for ' . implode(', ', $res['failed']) . '. Enter it manually under Manage Rates.'
                . ($res['saved'] ? ' Updated: ' . implode(', ', $res['saved']) . '.' : '');
        } else {
            $success = "Today's rates updated for " . implode(', ', $res['saved']) . '.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['year_close'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Reload the page and try again.';
    } elseif (!$glReady) {
        $error = 'The ledger is not ready.';
    } else {
        $res = gl_year_close($pdo, (string) ($_POST['yc_start'] ?? ''), (string) ($_POST['yc_end'] ?? ''));
        if ($res['ok']) {
            $success = 'Year closed. ' . ($res['net'] >= 0 ? 'Profit' : 'Loss') . ' of ' . number_format(abs($res['net']), 2) . ' moved to Retained Earnings.';
        } else {
            $error = (string) $res['error'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_general'])) {
    $problems = [];
    $companyId = accounting_settings_company_id();

    if ($glReady) {
        gl_meta_set($pdo, 'fx_mode', ($_POST['fx_mode'] ?? '') === 'auto' ? 'auto' : 'manual');
    }

    $fyMonth = (int) ($_POST['fiscal_year_start_month'] ?? 0);
    if ($glReady && $fyMonth >= 1 && $fyMonth <= 12) {
        gl_meta_set($pdo, 'fiscal_year_start_month', (string) $fyMonth);
    }

    $lockDate = trim((string) ($_POST['locked_through'] ?? ''));
    $currentLock = $glReady ? gl_locked_through($pdo) : null;
    if ($lockDate !== '' && $lockDate !== $currentLock) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lockDate) || !strtotime($lockDate)) {
            $problems[] = 'Books closed through is not a valid date.';
        } elseif ($currentLock !== null && $lockDate < $currentLock) {
            $problems[] = 'A closed period cannot be reopened. Pick a date after ' . date('d M Y', strtotime($currentLock)) . '.';
        } elseif (!$glReady || !gl_lock_period_through($pdo, $lockDate, 'Set from Accounting Settings')) {
            $problems[] = 'Could not close the books through that date.';
        }
    }

    $newBase = strtoupper(trim((string) ($_POST['base_currency'] ?? '')));
    $oldBase = $glReady ? gl_base_currency($pdo) : '';
    if ($newBase !== '' && $newBase !== $oldBase) {
        if (!preg_match('/^[A-Z]{3}$/', $newBase)) {
            $problems[] = 'Base currency must be a 3-letter code.';
        } elseif ($ledgerHasEntries) {
            $problems[] = 'Base currency cannot change once the ledger has entries.';
        } elseif ($glReady) {
            gl_meta_set($pdo, 'base_currency', $newBase);
        }
    }

    $vrn = trim((string) ($_POST['company_vat'] ?? ''));
    $mode = (string) ($_POST['tax_calculation_mode'] ?? '');
    if (!in_array($mode, ['inclusive', 'exclusive'], true)) {
        $problems[] = 'Choose whether prices include VAT.';
    } elseif ($companyId <= 0
        || !saveCompanySettingValue($pdo, $companyId, 'company_vat', $vrn)
        || !saveCompanySettingValue($pdo, $companyId, 'tax_calculation_mode', $mode)) {
        $problems[] = 'Could not save the VAT settings.';
    }

    if ($problems) {
        $error = implode(' ', $problems);
    } else {
        $success = 'Periods, tax and currency settings saved.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['save_general']) && !isset($_POST['fetch_rates']) && !isset($_POST['year_close'])) {
    $posted = (array) ($_POST['gl'] ?? []);
    $failed = [];
    $enableStockGuard = !empty($_POST['invoice_stock_mapping_guard']);
    if ($enableStockGuard) {
        foreach (['cogs', 'inventory'] as $stockRole) {
            $options = accounting_gl_role_options($pdo, $stockRole);
            $selected = (int) ($posted[$stockRole] ?? 0);
            if (!$options['filtered'] || !in_array($selected, array_column($options['options'], 'id'), true)) {
                $failed[] = $glRoles[$stockRole]['label'] . ' requires a dedicated account';
            }
        }
    }
    if (!$failed) {
        foreach ($glRoles as $role => $def) {
            $selected = (int) ($posted[$role] ?? 0);
            if ($selected <= 0) {
                if (!empty($def['optional'])) {
                    accounting_clear_gl_role_account_id($pdo, $role);
                } else {
                    $failed[] = $def['label'];
                }
                continue;
            }
            $ok = $role === 'revenue'
                ? accounting_set_default_sales_revenue_gl_account_id($pdo, $selected)
                : accounting_set_gl_role_account_id($pdo, $role, $selected);
            if (!$ok) {
                $failed[] = $def['label'];
            }
        }
    }
    if (!$failed && !accounting_settings_set($pdo, 'invoice_stock_mapping_guard', $enableStockGuard ? '1' : '0')) {
        $failed[] = 'invoice stock mapping protection';
    }
    if ($failed) {
        $error = 'Could not save: ' . implode(', ', $failed) . '. Pick one of the accounts listed for it.';
    } else {
        $success = 'Accounting settings saved.';
    }
}

$roleOptions = [];
$roleState = [];
foreach ($glRoles as $role => $def) {
    $list = $role === 'revenue'
        ? ['options' => accounting_settings_revenue_gl_options($pdo), 'filtered' => false]
        : accounting_gl_role_options($pdo, $role);
    $configured = $roleConfigured($role);
    $selected = $configured ?: $roleResolved($role);
    $allowedIds = array_column($list['options'], 'id');
    if ($selected && !in_array((int) $selected, $allowedIds, true)) {
        if ($configured) {
            $st = $pdo->prepare('SELECT id, code, name FROM erp_accounts WHERE id = ?');
            $st->execute([(int) $selected]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                array_unshift($list['options'], [
                    'id' => (int) $row['id'],
                    'label' => accounting_settings_format_gl_account_label($row) . ' (not suitable, please change)',
                    'invalid' => true,
                ]);
            }
        } else {
            $selected = null;
        }
    }
    $roleOptions[$role] = $list;
    $roleState[$role] = ['selected' => $selected, 'auto' => !$configured];
}
$hasAuto = (bool) array_filter($roleState, static fn ($s) => $s['auto']);

$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$accountingUrl = app_url('/modules/accounting/index.php?module=' . urlencode($module));
$coaUrl = app_url('/modules/balances/accounts.php?module=balances&from=accounting-settings');

$page_title = 'Accounting Settings';
$hideErpBreadcrumbs = true;
$hideHeaderCompanyBranding = true;
$employeeHeaderTitle = 'Accounting Settings';
$employeeHeaderSubtitle = '<span class="ps-crumbs"><a href="' . $esc(company_url('select-module')) . '">Home</a> <span class="ps-crumb-sep">/</span> <a href="' . $esc($accountingUrl) . '">Accounting</a> <span class="ps-crumb-sep">/</span> <span class="ps-crumb-current">Settings</span></span>';
$employeeHeaderRightHtml = '<div class="ps-head-tools">'
    . '<label class="ps-head-search"><i class="fas fa-magnifying-glass" aria-hidden="true"></i>'
    . '<input type="search" id="psHeadSearch" placeholder="Search settings..." autocomplete="off" aria-label="Search accounting settings"></label>'
    . '</div>';

$lockedThrough = $glReady ? gl_locked_through($pdo) : null;
$aiFrameUrl = company_url(isAdmin() ? 'admin/ai_assistant.php' : 'employee/ai_assistant.php') . '?module=accounting';
$fyMonth = $glReady ? gl_fiscal_year_start_month($pdo) : 1;
[$fyStart, $fyEnd] = $glReady ? gl_fiscal_year_bounds($pdo) : [date('Y-01-01'), date('Y-12-31')];
$yc = null;
$ycNet = 0.0;
if ($glReady) {
    try {
        $yc = gl_year_close_candidate($pdo);
        $ycNet = gl_year_close_lines($pdo, $yc['start'], $yc['end'])['net'];
    } catch (Throwable $e) {
        $yc = null;
    }
}
$baseCurrency = $glReady ? strtoupper(trim((string) (gl_meta_get($pdo, 'base_currency') ?? ''))) : '';
if (!preg_match('/^[A-Z]{3}$/', $baseCurrency)) {
    $baseCurrency = 'TZS';
}
if (!isset($currencyChoices[$baseCurrency])) {
    $currencyChoices[$baseCurrency] = $baseCurrency;
}
$fxAutoNote = '';
if ($glReady && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $auto = gl_fx_auto_refresh_if_due($pdo, $_SESSION['user_id'] ?? null);
    if ($auto && $auto['saved']) {
        $fxAutoNote = "Today's rates were updated automatically for " . implode(', ', $auto['saved']) . '.';
    }
}
$fxMode = $glReady ? gl_fx_mode($pdo) : 'manual';
$fxDefaultCur = 'USD';
if ($fxDefaultCur === $baseCurrency || !isset($currencyChoices[$fxDefaultCur])) {
    foreach (array_keys($currencyChoices) as $c) {
        if ($c !== $baseCurrency) { $fxDefaultCur = $c; break; }
    }
}
$fxLastAuto = $glReady ? gl_meta_get($pdo, 'fx_last_auto') : null;
$fxCount = $glReady ? count(gl_latest_exchange_rates($pdo)) : 0;
$companyVat = function_exists('getCompanySetting') ? trim((string) getCompanySetting('company_vat', '')) : '';
$taxMode = function_exists('getCompanySetting') ? strtolower(trim((string) getCompanySetting('tax_calculation_mode', ''))) : '';
$glUrl = static fn (string $page) => app_url('/accounting/' . $page . '?module=' . urlencode($module));
$generalGroups = [
    ['id' => 'as-period', 'tab' => 'Period', 'general' => 'period', 'title' => 'Accounting Period', 'sub' => 'Close past periods.', 'icon' => 'fa-lock'],
    ['id' => 'as-currency', 'tab' => 'Currency', 'general' => 'currency', 'title' => 'Currency', 'sub' => 'Base currency used in all reports.', 'icon' => 'fa-coins'],
    ['id' => 'as-rates', 'tab' => 'Exchange rates', 'general' => 'rates', 'title' => 'Exchange Rates', 'sub' => 'Currency conversion rates.', 'icon' => 'fa-right-left'],
    ['id' => 'as-tax', 'tab' => 'VAT / Tax', 'general' => 'tax', 'title' => 'VAT / Tax', 'sub' => 'VAT number and how prices are entered.', 'icon' => 'fa-percent'],
];
foreach (['as-sales' => 'Sales', 'as-stock' => 'Stock', 'as-receipts' => 'Receipts', 'as-system' => 'Automatic'] as $gid => $gtab) {
    $gi = array_search($gid, ['as-sales', 'as-stock', 'as-receipts', 'as-system'], true);
    $roleGroups[$gi]['id'] = $gid;
    $roleGroups[$gi]['tab'] = $gtab;
}
$sections = array_merge($roleGroups, $generalGroups);
$roleIcons = [
    'accounts_payable' => 'fa-file-invoice', 'opening_equity' => 'fa-scale-balanced', 'retained_earnings' => 'fa-piggy-bank', 'fx_gain_loss' => 'fa-coins',
    'transfer_clearing' => 'fa-right-left', 'suspense' => 'fa-hourglass-half',
    'revenue' => 'fa-chart-line', 'receivable' => 'fa-hand-holding-dollar', 'output_tax' => 'fa-percent',
    'cogs' => 'fa-dolly', 'inventory' => 'fa-boxes-stacked', 'payment_bank' => 'fa-building-columns',
];
$selectArrow = "background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http://www.w3.org/2000/svg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C/polyline%3E%3C/svg%3E'); background-size: 1.25rem; background-repeat: no-repeat; background-position: right 12px center;";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $esc($page_title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= $esc(app_url('/assets/css/style.css')) ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php if (function_exists('renderSystemFontHeadMarkup')) { renderSystemFontHeadMarkup(); } ?>
    <style>
        .as-toast { position: fixed; top: 1rem; right: 1rem; z-index: 100000; display: flex; align-items: center; gap: .6rem; max-width: 360px; padding: .65rem .8rem; border-radius: 10px; background: #fff; color: #1f2937; border-left: 4px solid #dc2626; box-shadow: 0 8px 24px rgba(0,0,0,.18); font-size: .85rem; opacity: 0; transform: translateX(20px); transition: opacity .2s, transform .2s; }
        .as-toast.in { opacity: 1; transform: none; }
        .as-toast-success { border-left-color: #16a34a; } .as-toast-success i { color: #16a34a; }
        .as-toast-info { border-left-color: #2563eb; } .as-toast-info i { color: #2563eb; }
        .as-toast-error i { color: #dc2626; }
        .as-toast button { margin-left: auto; border: 0; background: none; font-size: 1.2rem; line-height: 1; color: #9ca3af; cursor: pointer; }
        html[data-theme="dark"] .as-toast { background: #202c33; color: #e9edef; }
    </style>
    <link rel="stylesheet" href="<?= $esc(app_url('/assets/css/profile-shell.css')) ?>?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/profile-shell.css') ?>">
    <style>
        .as-hero-icon { font-size: 2rem; }
        .as-hero-icon { font-size: 2rem; }
        .editor-shell .ps-hero {
            background:
                radial-gradient(ellipse 45% 140% at 80% 0%, rgba(209, 250, 229, 0.55), transparent 60%),
                linear-gradient(100deg, #065f46 0%, #059669 40%, #34d399 75%, #6ee7b7 100%);
            border: 0; box-shadow: 0 4px 14px rgba(6, 95, 70, 0.25); overflow: hidden;
        }
        .editor-shell .ps-hero::after {
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 800 200' preserveAspectRatio='none'%3E%3Cpath d='M0 200 C120 120 240 60 380 110 C520 160 650 90 800 60 L800 200 Z' fill='rgba(255,255,255,0.10)'/%3E%3Cpath d='M200 200 C320 150 440 90 580 120 C680 142 750 110 800 90 L800 200 Z' fill='rgba(209, 250, 229,0.16)'/%3E%3Cpath d='M0 150 C140 90 260 110 400 70 C540 30 680 60 800 20' fill='none' stroke='rgba(255,255,255,0.35)' stroke-width='2'/%3E%3Cpath d='M0 180 C160 130 300 150 440 110 C580 70 700 100 800 60' fill='none' stroke='rgba(255,255,255,0.22)' stroke-width='2'/%3E%3C/svg%3E") center / 100% 100% no-repeat;
        }
        .editor-shell .ps-hero-copy h2 { color: #fff; font-size: 1.25rem; text-shadow: 0 1px 3px rgba(2, 44, 34, 0.35); }
        .editor-shell .ps-hero-email { font-size: 0.72rem; color: rgba(255, 255, 255, 0.92); text-shadow: 0 1px 2px rgba(2, 44, 34, 0.3); }
        .editor-shell .ps-hero .account-avatar { background: #ecfdf5 !important; }
        .editor-shell .ps-hero .account-avatar .as-hero-icon,
        .editor-shell .ps-hero .account-avatar .as-hero-icon i { color: #059669 !important; background: transparent !important; }
        .editor-shell .ps-hero .ps-logout { background: rgba(255, 255, 255, 0.18); border-color: rgba(255, 255, 255, 0.7); color: #fff; border-radius: 9999px; }
        .editor-shell .ps-hero .ps-logout:hover { background: rgba(255, 255, 255, 0.32); }
        .editor-shell .section-nav a.is-active { color: #059669; border-bottom-color: #059669; }
        .editor-shell .btn-save, .editor-shell .ps-details-save { background: #059669 !important; border-color: #059669 !important; color: #fff !important; }
        .editor-shell .btn-save:hover, .editor-shell .ps-details-save:hover { background: #065f46 !important; border-color: #065f46 !important; }
        .editor-shell .form-input:focus, .editor-shell select:focus, .editor-shell input:focus { border-color: #059669 !important; box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.18) !important; outline: 0; }
        .editor-shell .section-nav a:hover, .editor-shell .section-nav a.is-active i { color: #059669; }
        html:not([data-theme="dark"]) .editor-shell .ps-pref-menu a:hover, html:not([data-theme="dark"]) .editor-shell .ps-pref-menu a:focus-visible { background: #ecfdf5; }
        html:not([data-theme="dark"]) .editor-shell .ps-pref-menu a > i { color: #059669; }
        .ps-pref-menu a:hover, .ps-pref-menu a:focus-visible { background: #ecfdf5; }
        .ps-pref-menu a > i { color: #059669; }
        .ps-pref-menu a.is-current strong { color: #065f46; }
        html:not([data-theme="dark"]) .editor-shell .ps-sec-icon { background: #ecfdf5; color: #059669; }
        html:not([data-theme="dark"]) .editor-shell .ps-status-card h3 i { color: #059669; }
        html:not([data-theme="dark"]) .editor-shell .section-nav .ps-pref-toggle[aria-expanded="true"],
        html:not([data-theme="dark"]) .editor-shell .section-nav .ps-pref-toggle[aria-expanded="true"] i { color: #059669; }

        body.ps-page .employee-header { background: transparent !important; position: relative !important; z-index: 3; padding-top: 1.6rem !important; }
        body.ps-page .employee-header .employee-header-page-title { font-size: 20px !important; color: #fff !important; text-shadow: 0 1px 3px rgba(2, 44, 34, 0.35); }
        body.ps-page .employee-header .ps-crumbs { font-size: 12px; gap: 6px; margin-top: 2px; }
        body.ps-page .employee-header .ps-crumbs a, body.ps-page .employee-header .ps-crumb-current, body.ps-page .employee-header .ps-crumb-sep { color: rgba(255, 255, 255, 0.92); }
        body.ps-page .employee-header .ps-head-search { background: rgba(255, 255, 255, 0.92); border-color: rgba(255, 255, 255, 0.6); }
        body.ps-page .employee-header .header-right { top: 2.3rem !important; }
        .editor-shell .ps-hero-copy h2 { display: none; }
        .editor-shell .ps-hero-main { align-items: center; }
        .ps-hero-copy h2 + .ps-hero-email { margin-top: 0.45rem; }
        .as-map-current { font-weight: 500; opacity: 0.75; }
        .ps-pref-menu a.is-current { background: #ecfdf5; }
        .ps-pref-menu a.is-current strong { color: #059669; }
        html[data-theme="dark"] .ps-pref-menu a.is-current { background: #202c33; }
        html[data-theme="dark"] .ps-pref-menu a.is-current strong { color: #00a884; }
        html[data-theme="dark"] .account-avatar .as-hero-icon { background: #202c33 !important; color: #a5b4fc !important; }
        html[data-theme="dark"] .account-avatar .as-hero-icon i { color: #a5b4fc !important; }
        #asForm .ps-details-grid { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        .as-journal td:not(:first-child) { white-space: nowrap; }
        .as-journal th:not(:first-child), .as-journal td:not(:first-child) { width: 46px; }
        .as-field select.form-input { appearance: none; -webkit-appearance: none; padding-right: 2.5rem !important; cursor: pointer; }
        .editor-section:not(.as-gen) .ps-details-grid { gap: 0.6rem 1rem; }
        .editor-section:not(.as-gen) .as-banner { padding: 0.4rem 0.7rem; margin-bottom: 0.6rem; font-size: 0.76rem; }
        .editor-section:not(.as-gen) .as-field .form-label { margin-bottom: 0.2rem; }
        .editor-section:not(.as-gen) .as-field .form-input { padding-top: 0.4rem; padding-bottom: 0.4rem; }
        .editor-section:not(.as-gen) .ps-details-actions { margin-top: 0.6rem; padding-top: 0; }
        .as-field { position: relative; }
        .as-field > .form-label { display: inline-block; }
        .as-tip { display: inline-flex; vertical-align: middle; margin-left: 0.35rem; color: #9ca3af; font-size: 0.8rem; cursor: help; position: relative; }
        .as-tip:hover, .as-tip:focus { color: #059669; outline: none; }
        .as-tip::after { content: attr(data-tip); position: absolute; left: 50%; bottom: calc(100% + 6px); transform: translateX(-30%); width: max-content; max-width: 240px; padding: 0.4rem 0.6rem; border-radius: 8px; background: #1f2937; color: #fff; font-size: 0.72rem; font-weight: 400; line-height: 1.35; white-space: normal; box-shadow: 0 6px 18px rgba(0,0,0,.2); opacity: 0; visibility: hidden; transition: opacity .12s; z-index: 40; pointer-events: none; }
        .as-tip:hover::after, .as-tip:focus::after { opacity: 1; visibility: visible; }
        html[data-theme="dark"] .as-tip::after { background: #111b21; border: 1px solid #2a3942; }
        .as-field-help { margin: 0.2rem 0 0; font-size: 0.7rem; color: #6b7280; line-height: 1.35; }
        .as-field-warn { margin: 0.35rem 0 0; font-size: 0.72rem; color: #b45309; line-height: 1.45; }
        html[data-theme="dark"] .as-field-warn { color: #fbbf24; }
        .as-tag { display: inline-block; margin-left: 0.35rem; padding: 0.05rem 0.45rem; border-radius: 999px; font-size: 0.62rem; font-weight: 600; vertical-align: middle; background: #fef3c7; color: #92400e; }
        .as-banner { display: flex; gap: 0.6rem; align-items: flex-start; padding: 0.7rem 0.9rem; margin-bottom: 1rem; border-radius: 10px; background: #ecfdf5; border: 1px solid #a7f3d0; color: #064e3b; font-size: 0.78rem; line-height: 1.5; }
        .as-accept { margin-left: auto; border: 0; background: #059669; color: #fff; border-radius: 8px; padding: 0.3rem 0.7rem; font-size: 0.74rem; cursor: pointer; white-space: nowrap; }
        .as-accept:hover { background: #065f46; }
        .as-auto-note { margin: 0 0 0.6rem; font-size: 0.8rem; color: #047857; }
        .as-adv { margin-bottom: 0.8rem; }
        .as-adv > summary { cursor: pointer; font-size: 0.78rem; color: #6b7280; padding: 0.3rem 0; }
        .as-adv[open] > summary { margin-bottom: 0.6rem; }
        .as-banner i { margin-top: 0.15rem; color: #059669; }
        .as-journal { width: 100%; border-collapse: collapse; font-size: 0.74rem; }
        .as-journal th { text-align: left; font-size: 0.64rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #9ca3af; padding: 0 0 0.35rem; }
        .as-journal th:not(:first-child), .as-journal td:not(:first-child) { text-align: right; width: 40px; }
        .as-journal td { padding: 0.4rem 0; border-top: 1px solid #f1f2f7; color: #6b7280; }
        .as-journal td:first-child { color: #111827; padding-right: 0.4rem; }
        .as-journal tr.as-journal-sep td { border-top: 1px dashed #e5e7eb; }
        .as-journal-foot { margin: 0.6rem 0 0; font-size: 0.72rem; color: #6b7280; line-height: 1.5; }
        .as-journal-foot b { color: #374151; font-weight: 600; }
        html[data-theme="dark"] .as-field-help, html[data-theme="dark"] .as-journal td, html[data-theme="dark"] .as-journal-foot { color: #8696a0; }
        html[data-theme="dark"] .as-journal td:first-child, html[data-theme="dark"] .as-journal-foot b { color: #e9edef; }
        html[data-theme="dark"] .as-journal td { border-color: #2a3942; }
        html[data-theme="dark"] .as-tag { background: rgba(245, 158, 11, 0.16); color: #fbbf24; }
        html[data-theme="dark"] .as-banner { background: rgba(0, 168, 132, 0.08); border-color: rgba(0, 168, 132, 0.35); color: #d1d7db; }
        html[data-theme="dark"] .as-banner i { color: #00a884; }
        .as-row { display: grid; grid-template-columns: minmax(180px, 1fr) minmax(220px, 1.2fr); gap: 0.6rem 1rem; align-items: center; padding: 0.45rem 0.75rem; margin-bottom: 0.4rem; border: 1px solid #eceef5; border-radius: 10px; }
        .as-row-head { display: flex; gap: 0.6rem; align-items: center; }
        .as-row-icon { flex: 0 0 auto; width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; background: transparent; color: #6b7280; font-size: 0.95rem; }
        .as-row-title { margin: 0; font-size: 0.84rem; font-weight: 700; line-height: 1.2; }
        .as-row-sub { margin: 0; font-size: 0.7rem; color: #6b7280; line-height: 1.25; }
        .as-row-ctl { display: flex; gap: 0.6rem; align-items: center; }
        .as-row-ctl label.form-label, .as-row-ctl > .form-label { margin: 0; white-space: nowrap; font-size: 0.74rem; }
        .as-row-ctl .form-input { flex: 1; min-width: 0; padding-top: 0.35rem; padding-bottom: 0.35rem; }
        .as-row-ctl .as-yc-btn { flex: 0 0 auto; width: auto; margin: 0; padding: 0.35rem 0.8rem; font-size: 0.78rem; white-space: nowrap; display: inline-flex; align-items: center; gap: 0.4rem; }
        .as-man { display: grid; gap: 0.7rem; }
        .as-man p { margin: 0; }
        body.ps-page[data-ps-tab="as-journals"] .ps-body { grid-template-columns: minmax(0, 560px) minmax(280px, 380px); justify-content: start; }
        body.ps-page[data-ps-tab="as-manual"] .ps-body { grid-template-columns: minmax(0, 1fr); }
        body.ps-page[data-ps-tab="as-manual"] .acct-aside { display: none; }
        .as-jrn-ctl { justify-content: flex-end; flex-wrap: wrap; }
        .as-jrn-toggle { margin: 0; }
        .as-jrn-st { font-size: 0.72rem; color: #9ca3af; }
        .as-jrn-st.is-on { color: #059669; }
        .as-jrn-ico { width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; border: 0 !important; border-radius: 6px; background: transparent !important; box-shadow: none !important; padding: 0; color: #6b7280; font-size: 0.8rem; cursor: pointer; text-decoration: none; }
        .as-jrn-ico:hover:not([disabled]) { color: #059669; }
        .as-jrn-ico[disabled] { opacity: .35; cursor: not-allowed; }
        .as-jrn-off .as-row-title { opacity: .55; }
        .as-jrn-edit-form { display: grid; gap: 0.5rem; }
        .as-jrn-edit-form label { display: grid; gap: 0.15rem; font-size: 0.72rem; color: #6b7280; }
        .as-jrn-edit-form input[type="text"], .as-jrn-edit-form select, .as-jrn-edit-form textarea { width: 100%; padding: 0.3rem 0.45rem; font-size: 0.78rem; border: 1px solid #e5e7eb; border-radius: 8px; background: transparent; color: inherit; }
        #as-journals .ps-sec-head { display: flex; align-items: center; border-bottom: 0 !important; box-shadow: none !important; padding-bottom: 0 !important; margin-bottom: 0.6rem !important; }
        #as-journals .ps-sec-head::after { display: none !important; }
        #as-journals .as-jrn-new { margin-left: auto; }
        .as-jrn-lock { margin: 0; font-size: 0.74rem; color: #6b7280; }
        .as-jrn-lock small { margin-left: .3rem; opacity: .8; }
        .as-jrn-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
        .as-jrn-checks { display: grid; grid-template-columns: 1fr 1fr; gap: 0.25rem 0.5rem; }
        .as-jrn-edit-form .as-jrn-checks label { display: flex; align-items: center; gap: 0.35rem; }
        .as-jrn-edit-actions { display: flex; justify-content: flex-end; gap: 0.5rem; }
        html[data-theme="dark"] .as-jrn-edit-form input[type="text"], html[data-theme="dark"] .as-jrn-edit-form select, html[data-theme="dark"] .as-jrn-edit-form textarea { border-color: #2a3942; }
        .as-jrn-row { cursor: pointer; }
        .as-jrn-row.is-picked { border-color: #059669; background: rgba(5, 150, 105, 0.05); }
        .as-journals .as-row { padding: 0.2rem 0.65rem; margin-bottom: 0.25rem; }
        .as-journals .as-row-icon { width: 26px; height: 26px; }
        .as-journals .as-jrn-ico { width: 26px; height: 26px; font-size: 0.74rem; }
        .as-journals .as-row-title { font-weight: 500; }
        .as-journals .as-row-title small { font-weight: 400 !important; }
        .as-btn-outline[disabled] { opacity: .4; cursor: not-allowed; }
        .as-man { max-width: none; position: relative; overflow: clip; }
        .section-nav .ps-nav-link { font-size: 12.5px !important; padding-top: 7px; padding-bottom: 7px; }
        .section-nav .ps-nav-link i { font-size: 12px; }
        body.ps-page[data-ps-tab="as-manual"] .ps-body, body.ps-page[data-ps-tab="as-manual"] .main-content-wrapper { min-height: 0; height: auto; }
        .as-man-cards { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .as-man-faq { grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; }
        @media (max-width: 900px) { .as-man-cards, .as-man-faq { grid-template-columns: 1fr; } }
        section.as-manual { background: transparent; border: 0; box-shadow: none; padding: 0; }
        section.as-manual > .section-header { padding: 0 0.2rem; }
        .as-man-top { padding: 0.8rem 1rem !important; margin-bottom: 0.7rem; border: 1px solid #eceef5; border-radius: 14px; background: linear-gradient(135deg, #ecfdf5, #eef2ff); }
        .as-man-intro-in { margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px dashed #c4b5fd; }
        .as-man-intro-in h3 { margin: 0; font-size: 0.98rem; font-weight: 600; }
        .as-man-intro-in p { margin: 0.15rem 0 0; font-size: 0.78rem; color: #4b5563; }
        html[data-theme="dark"] .as-man-top { background: #202c33; border-color: #2a3942; }
        html[data-theme="dark"] .as-man-intro-in { border-color: #2a3942; }
        html[data-theme="dark"] .as-man-intro-in p { color: #aebac1; }
        .as-man-panel { display: grid; gap: 0.7rem; padding: 0.8rem; border: 1px solid #eceef5; border-radius: 14px; background: #fff; box-shadow: 0 1px 3px rgba(15,23,42,.05); }
        html[data-theme="dark"] .as-man-panel { background: #111b21; border-color: #2a3942; box-shadow: none; }
        .as-man-hero { display: flex; gap: 0.8rem; align-items: center; padding: 0.8rem 1rem; border-radius: 14px; background: linear-gradient(135deg, #ecfdf5, #eef2ff); }
        .as-man-hero-ico { width: 40px; height: 40px; border-radius: 12px; background: #059669; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 1.05rem; flex: 0 0 auto; }
        .as-man-hero h3 { margin: 0; font-size: 0.98rem; font-weight: 600; }
        .as-man-hero p { font-size: 0.78rem; color: #4b5563; margin-top: 0.15rem; }
        .as-man-track { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.4rem; position: relative; }
        .as-man-track li { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 0.15rem; position: relative; }
        .as-man-track li:not(:last-child)::after { content: ""; position: absolute; top: 15px; left: calc(50% + 20px); width: calc(100% - 40px); border-top: 2px dashed #d9d4f7; }
        .as-man-dot { width: 30px; height: 30px; border-radius: 50%; background: #fff; border: 2px solid #059669; color: #059669; display: inline-flex; align-items: center; justify-content: center; font-size: 0.72rem; }
        .as-man-track b { font-size: 0.78rem; margin-top: 0.2rem; }
        .as-man-track small { font-size: 0.7rem; color: #6b7280; line-height: 1.3; max-width: 130px; }
        .as-man-go { align-self: flex-start; display: inline-flex; gap: 0.35rem; align-items: center; font-size: 0.74rem; font-weight: 600; color: #059669; text-decoration: none; padding: 0.2rem 0.55rem; border-radius: 8px; background: #ecfdf5; transition: background .15s, transform .15s; }
        .as-man-go:hover { background: #ebe7ff; transform: translateX(2px); }
        .as-man-head-row { display: flex; justify-content: space-between; align-items: center; gap: 0.6rem; flex-wrap: wrap; margin-top: 0; }
        .as-man-head-row .as-man-head { margin: 0; }
        .as-man-prog { font-size: 0.74rem; color: #6b7280; }
        .as-man-link { border: 0; background: none; color: #059669; font-size: 0.74rem; cursor: pointer; padding: 0 0 0 0.4rem; }
        .as-man-bar { height: 5px; border-radius: 999px; background: #eceef5; overflow: hidden; margin-top: -0.3rem; }
        .as-man-bar span { display: block; height: 100%; width: 0; background: #16a34a; transition: width .25s; }
        .as-man-steps li { justify-content: space-between; align-items: center; }
        .as-man-check { position: relative; display: flex; gap: 0.7rem; align-items: center; cursor: pointer; flex: 1; min-width: 0; }
        .as-man-check input { position: absolute; opacity: 0; pointer-events: none; }
        .as-man-sico { flex: 0 0 auto; width: 30px; height: 30px; border-radius: 8px; background: #ecfdf5; color: #059669; display: inline-flex; align-items: center; justify-content: center; font-size: 0.85rem; }
        html[data-theme="dark"] .as-man-sico { background: #202c33; color: #00a884; }
        .as-man-steps li:nth-child(1) .as-man-sico { background: #e0f2fe; color: #0284c7; }
        .as-man-steps li:nth-child(2) .as-man-sico { background: #dcfce7; color: #16a34a; }
        .as-man-steps li:nth-child(3) .as-man-sico { background: #ffedd5; color: #ea580c; }
        .as-man-steps li:nth-child(1) .as-man-num { background: #0284c7; }
        .as-man-steps li:nth-child(2) .as-man-num { background: #16a34a; }
        .as-man-steps li:nth-child(3) .as-man-num { background: #ea580c; }
        html[data-theme="dark"] .as-man-steps li:nth-child(1) .as-man-sico { background: #0c2a3d; color: #38bdf8; }
        html[data-theme="dark"] .as-man-steps li:nth-child(2) .as-man-sico { background: #10301c; color: #4ade80; }
        html[data-theme="dark"] .as-man-steps li:nth-child(3) .as-man-sico { background: #3a2210; color: #fb923c; }
        .as-man-check input:checked + .as-man-num { background: #16a34a !important; font-size: 0; }
        .as-man-txt { display: flex; flex-direction: column; gap: 0.1rem; }
        .as-man-txt small { font-size: 0.74rem; color: #4b5563; line-height: 1.35; }
        .as-man-check input:focus-visible + .as-man-num { outline: 2px solid #059669; outline-offset: 2px; }
        .as-man-check input:checked + .as-man-num { background: #16a34a; font-size: 0; }
        .as-man-check input:checked + .as-man-num::before { content: "\2713"; font-size: 0.8rem; }
        .as-man-check input:checked ~ .as-man-txt b { text-decoration: line-through; color: #6b7280; }
        .as-man-faq { display: grid; gap: 0.35rem; }
        .as-man-faq details { border: 1px solid #eceef5; border-radius: 10px; padding: 0.5rem 0.8rem; }
        .as-man-faq summary { cursor: pointer; font-size: 0.8rem; font-weight: 600; list-style: none; display: flex; justify-content: space-between; gap: 0.6rem; }
        .as-man-faq summary::-webkit-details-marker { display: none; }
        .as-man-faq summary::after { content: "+"; color: #059669; font-weight: 700; }
        .as-man-faq details[open] summary::after { content: "\2212"; }
        .as-man-faq details p { margin-top: 0.35rem; font-size: 0.77rem; line-height: 1.45; color: #4b5563; }
        .as-man-card { display: flex; flex-direction: column; gap: 0.35rem; padding: 0.75rem 0.85rem; border: 1px solid #eceef5; border-radius: 12px; transition: box-shadow .15s, transform .15s; }
        .as-man-card:hover { box-shadow: 0 6px 18px rgba(5, 150, 105,.14); transform: translateY(-2px); }
        .as-man-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 0.6rem; }
        @media (max-width: 640px) { .as-man-track { grid-template-columns: 1fr 1fr; row-gap: 0.8rem; } .as-man-track li::after { display: none; } }
        html[data-theme="dark"] .as-man-hero { background: #202c33; }
        html[data-theme="dark"] .as-man-hero p, html[data-theme="dark"] .as-man-txt small, html[data-theme="dark"] .as-man-faq details p { color: #aebac1; }
        html[data-theme="dark"] .as-man-faq details, html[data-theme="dark"] .as-man-bar { border-color: #2a3942; }
        html[data-theme="dark"] .as-man-dot { background: #111b21; }
        html[data-theme="dark"] .as-man-go { background: #202c33; color: #00a884; }
        .as-man-ico { width: 30px; height: 30px; border-radius: 8px;         background: transparent; color: #059669; display: inline-flex; align-items: center; justify-content: center; font-size: 1.15rem; }
                .as-man-card:nth-child(1) .as-man-ico { color: #0284c7; }
                .as-man-card:nth-child(2) .as-man-ico { color: #16a34a; }
                .as-man-card:nth-child(3) .as-man-ico { color: #ea580c; }
                .as-man-card h3 { margin: 0.1rem 0 0; font-size: 0.9rem; font-weight: 600; }
        .as-man-when { align-self: flex-start; font-size: 0.68rem; color: #059669; background: #ecfdf5; padding: 0.12rem 0.5rem; border-radius: 999px; }
        .as-man-card p { font-size: 0.78rem; line-height: 1.45; color: #4b5563; }
        .as-man-card .as-man-ex { margin-top: auto; padding-top: 0.35rem; border-top: 1px dashed #e5e7eb; font-size: 0.72rem; color: #6b7280; }
        .as-man-card { display: grid; grid-template-columns: auto 1fr; column-gap: 0.5rem; row-gap: 0.15rem; padding: 0.5rem 0.7rem; align-items: center; }
        .as-man-card > * { grid-column: 1 / -1; }
        .as-man-card > .as-man-ico { grid-column: 1; grid-row: 1; width: 22px; height: 22px; font-size: 1rem; }
        .as-man-card > h3 { grid-column: 2; grid-row: 1; margin: 0; }
        .as-man-card > .as-man-when { padding: 0; background: transparent; }
        .as-man-card p { font-size: 0.75rem; line-height: 1.35; }
        .as-man-card .as-man-ex { padding-top: 0.2rem; }
        .as-man-head:has(.as-expand) { display: flex; align-items: center; gap: 8px; }
        .as-expand { margin-left: auto; border: 0; background: transparent; color: #64748b; cursor: pointer; padding: 4px 8px; border-radius: 8px; }
        .as-expand:hover { background: rgba(100,116,139,.15); }
        #asAiPanel.as-full { position: fixed; inset: 0; z-index: 99999; margin: 0; border-radius: 0; display: flex; flex-direction: column; background: #fff; }
        html[data-theme="dark"] #asAiPanel.as-full { background: #212121; }
        #asAiPanel.as-full .as-chat { flex: 1; min-height: 0; }
        #asAiPanel.as-full .as-chat iframe { height: 100%; }
        .as-chat { border: 0; border-radius: 0; overflow: hidden; background: transparent; }
        .as-chat iframe { display: block; width: 100%; height: 230px; border: 0; background: #fff; }
        html[data-theme="dark"] .as-chat iframe { background: #212121; }
        .as-man-head { margin: 0.3rem 0 0; font-size: 0.88rem; font-weight: 600; }
        .as-man-head i { color: #6b7280; margin-right: 0.4rem; }
        .as-man-steps { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.6rem; }
        @media (max-width: 900px) { .as-man-steps { grid-template-columns: 1fr; } }
        .as-man-steps li { display: flex; gap: 0.7rem; align-items: flex-start; padding: 0.55rem 0.8rem; border: 1px solid #eceef5; border-radius: 10px; }
        .as-man-num { flex: 0 0 auto; width: 24px; height: 24px; border-radius: 50%; background: #059669; color: #fff; font-size: 0.76rem; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; }
        .as-man-steps b { font-size: 0.82rem; }
        .as-man-steps p { font-size: 0.76rem; color: #4b5563; margin-top: 0.1rem; line-height: 1.4; }
        .as-man-warn { display: flex; gap: 0.5rem; align-items: flex-start; padding: 0.5rem 0.75rem; border-radius: 10px; background: #f0fdf4; color: #92400e; font-size: 0.76rem; line-height: 1.4; }
        .as-man-tips { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.35rem; }
        .as-man-tips li { display: flex; gap: 0.6rem; align-items: flex-start; font-size: 0.77rem; line-height: 1.4; color: #4b5563; }
        .as-man-tips li > i { color: #9ca3af; margin-top: 0.15rem; width: 14px; text-align: center; }
        html[data-theme="dark"] .as-man-card, html[data-theme="dark"] .as-man-steps li { border-color: #2a3942; }
        html[data-theme="dark"] .as-man-when { background: #202c33; color: #00a884; }
        html[data-theme="dark"] .as-man-card:nth-child(1) .as-man-ico { color: #38bdf8; }
        html[data-theme="dark"] .as-man-card:nth-child(2) .as-man-ico { color: #4ade80; }
        html[data-theme="dark"] .as-man-card:nth-child(3) .as-man-ico { color: #fb923c; }
        html[data-theme="dark"] .as-man-intro, html[data-theme="dark"] .as-man-card p, html[data-theme="dark"] .as-man-steps p, html[data-theme="dark"] .as-man-tips li { color: #aebac1; }
        html[data-theme="dark"] .as-man-warn { background: #2b2410; color: #f5c26b; }
        .as-yc { justify-content: space-between; flex-wrap: wrap; }
        .as-yc-info { display: flex; flex-direction: column; line-height: 1.25; font-size: 0.74rem; color: #6b7280; }
        .as-yc-info b { font-size: 0.85rem; color: #111827; }
        .as-yc-done { color: #15803d; font-weight: 600; font-size: 0.8rem; white-space: nowrap; }
        html[data-theme="dark"] .as-yc-info b { color: #e9edef; }
        .as-row-ctl .as-req { color: #dc2626; }
        .as-pill { display: inline-block; padding: 0.15rem 0.6rem; border-radius: 8px; background: #f1efff; color: #4c3bb5; font-size: 0.76rem; font-weight: 600; }
        .as-ctl-line { display: flex; gap: 0.6rem; align-items: center; justify-content: space-between; flex-wrap: wrap; width: 100%; }
        #asGenMenu { min-width: 0; width: max-content; padding: 0.3rem; }
        #asGenMenu a { padding: 0.4rem 0.75rem; gap: 0.6rem; }
        #asGenMenu a > i { color: #6b7280; font-size: 0.85rem; }
        #asGenMenu small { display: none; }
        #asGenMenu strong { font-weight: 400; font-size: 0.84rem; }
        #asGenMenu a.is-current strong { font-weight: 600; }
        #asGenMenu a.is-current > i { color: #059669; }
        .acct-aside[hidden], .ps-status-card[hidden] { display: none; }
        .as-sum-list { list-style: none; margin: .5rem 0 0; padding: 0; }
        .as-sum-list li { display: flex; align-items: center; gap: .55rem; padding: .4rem 0; border-top: 1px solid #eceef5; font-size: .8rem; }
        .as-sum-list li:first-child { border-top: 0; }
        .as-sum-list i { width: 1rem; text-align: center; color: #6b7280; }
        .as-sum-list span { flex: 1; color: #6b7280; }
        .as-sum-list b { font-weight: 500; text-align: right; word-break: break-all; }
        html[data-theme="dark"] .as-sum-list li { border-color: #2a3942; }
        .as-radio-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; flex: 1; }
        .as-radio { display: flex; gap: 0.4rem; align-items: center; padding: 0.3rem 0.6rem; border: 1px solid #e0e3ee; border-radius: 8px; cursor: pointer; font-size: 0.76rem; font-weight: 600; }
        .as-radio small { display: block; font-weight: 400; font-size: 0.64rem; color: #6b7280; line-height: 1.2; }
        .as-radio:has(input:checked) { border-color: #059669; background: #f0fdf4; }
        #as-rates .as-pill, #as-rates .as-btn-outline { background: transparent; }
        #as-rates .as-btn-outline { border: 0; padding: 0.3rem 0.4rem; white-space: nowrap; flex: none; cursor: pointer; }
        #as-rates .as-btn-outline:hover { text-decoration: underline; }
        #as-rates .as-pill { padding: 0; border-radius: 0; color: inherit; }
        #as-rates .as-row { border: 0; padding-left: 0; padding-right: 0; border-radius: 0; }
        #as-rates .as-row + .as-row { border-top: 1px solid #eceef5; padding-top: 0.6rem; }
        #as-rates .as-ctl-line { flex-wrap: nowrap; justify-content: flex-start; gap: 0.75rem; }
        #as-rates .as-ctl-line > div { display: flex; align-items: center; gap: 0.5rem; white-space: nowrap; }
        #as-rates .as-fx { border-top: 0; padding-top: 0; }
        .as-fx { grid-column: 1 / -1; margin-top: .1rem; padding-top: .5rem; border-top: 1px dashed #e0e3ee; }
        .as-fx-add { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        .as-fx-add .form-input { width: auto; min-width: 0; padding: .35rem .55rem; font-size: .8rem; height: auto; }
        #fxCurBox { flex: 1 1 220px; max-width: 300px; }
        #fxRate { flex: 0 1 140px; width: 140px; }
        #fxDate { flex: 0 0 auto; }
        .as-fx-eq { font-size: .8rem; color: #6b7280; }
        .as-fx-list { max-height: 190px; overflow: auto; margin-top: .4rem; }
        .as-fx-list table { width: 100%; border-collapse: collapse; font-size: .78rem; }
        .as-fx-list td { padding: .25rem .4rem; border-top: 1px solid #eceef5; }
        .as-fx-list td.n { text-align: right; font-variant-numeric: tabular-nums; }
        .as-fx-list button { border: 0; background: none; color: #9ca3af; cursor: pointer; }
        .as-fx-list button:hover { color: #dc2626; }
        .as-fx-empty { color: #6b7280; text-align: center; padding: .6rem; }
        .as-switch { display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; }
        .as-switch input { position: absolute; opacity: 0; pointer-events: none; }
        .as-switch-track { position: relative; width: 34px; height: 18px; border-radius: 9px; background: #c4c9d6; transition: background .15s; flex: none; }
        .as-switch-track::after { content: ''; position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; border-radius: 50%; background: #fff; transition: transform .15s; }
        .as-switch input:checked + .as-switch-track { background: #16a34a; }
        .as-switch input:checked + .as-switch-track::after { transform: translateX(16px); }
        .as-switch input:focus-visible + .as-switch-track { outline: 2px solid #059669; outline-offset: 2px; }
        .as-switch small { font-size: 0.68rem; color: #6b7280; }
        .as-btn-outline { display: inline-flex; gap: 0.4rem; align-items: center; padding: 0.3rem 0.8rem; border: 1px solid #059669; border-radius: 8px; color: #059669; background: #fff; font-size: 0.76rem; font-weight: 600; text-decoration: none; }
        .as-btn-reset { display: inline-flex; gap: 0.4rem; align-items: center; padding: 0.4rem 0.9rem; border: 1px solid #d9dce8; border-radius: 8px; background: #fff; color: #374151; font-size: 0.8rem; font-weight: 600; cursor: pointer; }
        .as-info { color: #9ca3af; cursor: help; font-size: 0.8rem; }
        .as-info a { color: inherit; }
        .as-gen .ps-details-actions { margin-top: 0.5rem; padding-top: 0; }
        .as-gen .as-row-title, .as-gen .form-label, .as-gen .as-pill, .as-gen .as-radio, .as-gen .as-btn-outline, .as-gen .as-btn-reset, .as-gen .btn-save { font-weight: 400; }
        .as-gen .as-row-title { font-size: 0.86rem; }
        .as-cur { position: relative; flex: 1; min-width: 0; }
        .as-cur-btn { display: flex; align-items: center; gap: 0.5rem; width: 100%; text-align: left; cursor: pointer; background: #fff; }
        .as-cur-btn span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .as-cur-caret { color: #94a3b8; font-size: 0.7rem; }
        .as-cur.is-disabled .as-cur-btn { opacity: 0.6; cursor: not-allowed; }
        .as-flag { width: 20px; height: 14px; object-fit: cover; border-radius: 2px; box-shadow: 0 0 0 1px rgba(0,0,0,0.12); flex: 0 0 auto; }
        .as-cur-menu { position: absolute; z-index: 30; left: 0; right: 0; top: calc(100% + 4px); background: #fff; border: 1px solid #e0e3ee; border-radius: 10px; box-shadow: 0 10px 28px rgba(15,23,42,0.14); padding: 0.4rem; }
        .as-cur-search { width: 100%; padding: 0.35rem 0.6rem; margin-bottom: 0.3rem; border: 1px solid #e0e3ee; border-radius: 8px; font-size: 0.78rem; }
        .as-cur-menu ul { list-style: none; margin: 0; padding: 0; max-height: 240px; overflow-y: auto; }
        .as-cur-menu li { display: flex; align-items: center; gap: 0.55rem; padding: 0.35rem 0.5rem; border-radius: 7px; cursor: pointer; font-size: 0.78rem; }
        .as-cur-menu li b { font-weight: 600; min-width: 2.4rem; }
        .as-cur-menu li span { color: #6b7280; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .as-cur-menu li:hover, .as-cur-menu li.is-active { background: #f1f5f9; }
        .as-cur-menu li[aria-selected="true"] { background: #f0fdf4; }
        html[data-theme="dark"] .as-cur-btn, html[data-theme="dark"] .as-cur-menu { background: #202c33; border-color: #2a3942; }
        html[data-theme="dark"] .as-cur-search { background: #111b21; border-color: #2a3942; color: #e9edef; }
        html[data-theme="dark"] .as-cur-menu li:hover, html[data-theme="dark"] .as-cur-menu li.is-active { background: #2a3942; }
        html[data-theme="dark"] .as-cur-menu li[aria-selected="true"] { background: rgba(0,168,132,0.12); }
        .as-gen .as-cur-menu li b { font-weight: 600; }
        @media (max-width: 700px) { .as-row { grid-template-columns: 1fr; } .as-row-ctl { flex-wrap: wrap; } }
        html[data-theme="dark"] .as-row { border-color: #2a3942; }
        html[data-theme="dark"] .as-row-icon { background: transparent; color: #8696a0; }
        html[data-theme="dark"] .as-pill { background: rgba(0, 168, 132, 0.12); color: #00a884; }
        html[data-theme="dark"] .as-radio { border-color: #2a3942; }
        html[data-theme="dark"] .as-radio:has(input:checked) { background: rgba(0, 168, 132, 0.08); border-color: #00a884; }
        html[data-theme="dark"] .as-btn-outline, html[data-theme="dark"] .as-btn-reset { background: transparent; color: #e9edef; border-color: #2a3942; }
    </style>
</head>
<body class="dashboard ps-page">
<?php require_once __DIR__ . '/../includes/header_employee.php'; ?>

<div class="main-content-wrapper">
        <div class="page-shell editor-shell">
            <section class="ps-hero">
                <div class="ps-hero-main">
                    <div class="account-avatar-wrap">
                        <div class="account-avatar">
                            <span class="account-avatar-fallback as-hero-icon"><i class="fas fa-money-bill-wave"></i></span>
                        </div>
                    </div>
                    <div class="ps-hero-copy">
                        <h2>Accounting Settings</h2>
                        <p class="ps-hero-email"><i class="fas fa-book"></i> Default general ledger accounts used when invoices and payments are posted.</p>
                    </div>
                </div>
                <a href="<?= $esc($accountingUrl) ?>" class="ps-logout"><i class="fas fa-arrow-left"></i> Back to Accounting</a>
            </section>
            <script>
            (function () {
                var hero = document.querySelector('.editor-shell .ps-hero');
                var head = document.querySelector('.employee-header');
                if (!hero || !head) { return; }
                function fit() {
                    var h = head.offsetHeight;
                    var gap = 14;
                    hero.style.marginTop = (gap - h) + 'px';
                    hero.style.paddingTop = (h - gap + 12) + 'px';
                }
                fit();
                window.addEventListener('resize', fit);
                window.addEventListener('load', fit);
            })();
            </script>

            <nav class="section-nav" aria-label="Accounting settings sections">
                <ul>
                    <li><a href="javascript:void(0)" role="button" class="ps-nav-link ps-pref-toggle is-active" id="asMapToggle" aria-haspopup="menu" aria-expanded="false" aria-controls="asMapMenu"><i class="fas fa-diagram-project"></i> GL Mapping <span id="asMapCurrent" class="as-map-current"></span> <i class="fas fa-chevron-down ps-pref-caret"></i></a></li>
                    <li><a href="javascript:void(0)" role="button" class="ps-nav-link ps-pref-toggle" id="asGenToggle" aria-haspopup="menu" aria-expanded="false" aria-controls="asGenMenu"><i class="fas fa-calendar-check"></i> Periods &amp; tax <span id="asGenCurrent" class="as-map-current"></span> <i class="fas fa-chevron-down ps-pref-caret"></i></a></li>
                    <li><a href="#as-manual" role="button" class="ps-nav-link" id="asManualToggle"><i class="fas fa-book-open"></i> Manual</a></li>
                    <li><a href="#as-journals" role="button" class="ps-nav-link" id="asJournalsToggle"><i class="fas fa-book"></i> Journals</a></li>
                </ul>
                <div class="ps-pref-menu" id="asGenMenu" role="menu" hidden>
                    <?php foreach ($generalGroups as $group): ?>
                    <a href="#<?= $esc($group['id']) ?>" role="menuitem" data-as-section="<?= $esc($group['id']) ?>" data-as-tab="<?= $esc($group['tab']) ?>"><i class="fas <?= $esc($group['icon']) ?>"></i><span><strong><?= $esc($group['tab']) ?></strong><small><?= $esc($group['sub']) ?></small></span></a>
                    <?php endforeach; ?>
                </div>
                <div class="ps-pref-menu" id="asMapMenu" role="menu" hidden>
                    <?php foreach ($roleGroups as $group): ?>
                    <a href="#<?= $esc($group['id']) ?>" role="menuitem" data-as-section="<?= $esc($group['id']) ?>" data-as-tab="<?= $esc($group['tab']) ?>"><i class="fas <?= $esc($group['icon']) ?>"></i><span><strong><?= $esc($group['tab']) ?></strong><small><?= $esc($group['title']) ?></small></span></a>
                    <?php endforeach; ?>
                    <a href="<?= $esc($coaUrl) ?>" role="menuitem"><i class="fas fa-sitemap"></i><span><strong>Chart of Accounts</strong><small>Add or rename GL accounts</small></span></a>
                </div>
            </nav>

            <div class="ps-body">
                <form method="post" class="editor-main" id="asForm">
                    <?php foreach ($sections as $i => $group): ?>
                    <section class="editor-section<?= !empty($group['general']) ? ' as-gen' : '' ?>" id="<?= $esc($group['id']) ?>"<?= $i === 0 ? '' : ' hidden' ?>>
                        <div class="section-header ps-sec-head">
                            <span class="ps-sec-icon"><i class="fas <?= $esc($group['icon']) ?>"></i></span>
                            <div>
                                <h2 class="section-title"><?= $esc($group['title']) ?></h2>
                                <p class="section-subtitle"><?= $esc($group['sub']) ?></p>
                            </div>
                        </div>
                        <?php if (!empty($group['general'])): ?>
                        <?php if ($group['general'] === 'period'): ?>
                        <div class="as-row">
                            <div class="as-row-head"><span class="as-row-icon"><i class="fas fa-lock"></i></span>
                                <div><h3 class="as-row-title" title="Close past periods.">Accounting Period</h3></div></div>
                            <div class="as-row-ctl">
                                <label class="form-label" for="gen_locked">Books closed through</label>
                                <input type="date" class="form-input" id="gen_locked" name="locked_through" value="<?= $esc($lockedThrough ?? '') ?>"<?= $lockedThrough ? ' min="' . $esc($lockedThrough) . '"' : '' ?>>
                                <a class="as-info" href="<?= $esc($glUrl('gl-reconciliation.php')) ?>" title="Entries dated on or before this day are moved to the first open day. A closed period cannot be reopened, so choose carefully. Click to open Reconciliation."><i class="fas fa-circle-info"></i></a>
                            </div>
                        </div>
                        <div class="as-row">
                            <div class="as-row-head"><span class="as-row-icon"><i class="fas fa-calendar-days"></i></span>
                                <div><h3 class="as-row-title" title="Used for year-to-date reports and year-end closing.">Financial Year</h3></div></div>
                            <div class="as-row-ctl">
                                <label class="form-label" for="gen_fy">Year starts in</label>
                                <select class="form-input" id="gen_fy" name="fiscal_year_start_month">
                                    <?php for ($mo = 1; $mo <= 12; $mo++): ?>
                                    <option value="<?= $mo ?>"<?= $mo === $fyMonth ? ' selected' : '' ?>><?= $esc(date('F', mktime(0, 0, 0, $mo, 1))) ?></option>
                                    <?php endfor; ?>
                                </select>
                                <span class="as-tip" tabindex="0" data-tip="Current year: <?= $esc(date('d M Y', strtotime($fyStart)) . ' to ' . date('d M Y', strtotime($fyEnd))) ?>"><i class="fas fa-circle-info"></i></span>
                            </div>
                        </div>
                        <?php if ($yc && !$yc['closed']): ?>
                        <div class="as-row">
                            <div class="as-row-head"><span class="as-row-icon"><i class="fas fa-flag-checkered"></i></span>
                                <div><h3 class="as-row-title" title="Moves the year's profit or loss into Retained Earnings.">Year-End Close</h3></div></div>
                            <div class="as-row-ctl as-yc">
                                <div class="as-yc-info">
                                    <span><?= $esc(date('d M Y', strtotime($yc['start'])) . ' - ' . date('d M Y', strtotime($yc['end']))) ?></span>
                                    <?php if (!$yc['closed']): ?><span><?= $ycNet >= 0 ? 'Profit' : 'Loss' ?>: <b><?= $esc(number_format(abs($ycNet), 2) . ' ' . $baseCurrency) ?></b></span><?php endif; ?>
                                </div>
                                <?php if ($yc['closed']): ?>
                                    <span class="as-yc-done"><i class="fas fa-circle-check"></i> Closed</span>
                                <?php else: ?>
                                    <input type="hidden" name="csrf_token" value="<?= $esc(csrf_token()) ?>">
                                    <input type="hidden" name="yc_start" value="<?= $esc($yc['start']) ?>">
                                    <input type="hidden" name="yc_end" value="<?= $esc($yc['end']) ?>">
                                    <button type="submit" name="year_close" value="1" formnovalidate class="btn-save as-yc-btn" onclick="return confirm('Close this financial year? The profit or loss will be posted to Retained Earnings.');"><i class="fas fa-flag-checkered"></i> Close year</button>
                                    <span class="as-tip" tabindex="0" data-tip="Posts one entry dated the last day of the year. Do this before locking the period. You can reverse it from the Journal."><i class="fas fa-circle-info"></i></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php elseif ($group['general'] === 'currency'): ?>
                        <div class="as-row">
                            <div class="as-row-head"><span class="as-row-icon"><i class="fas fa-coins"></i></span>
                                <div><h3 class="as-row-title" title="Used in all reports.">Currency</h3></div></div>
                            <div class="as-row-ctl">
                                <label class="form-label" id="gen_base_lbl">Base currency <span class="as-req">*</span></label>
                                <div class="as-cur<?= $ledgerHasEntries ? ' is-disabled' : '' ?>" id="asCur">
                                    <input type="hidden" name="base_currency" id="gen_base" value="<?= $esc($baseCurrency) ?>">
                                    <button type="button" class="form-input as-cur-btn" id="asCurBtn" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="gen_base_lbl"<?= $ledgerHasEntries ? ' disabled' : '' ?>>
                                        <img class="as-flag" id="asCurFlag" src="<?= $esc($curFlag($baseCurrency)) ?>" alt="">
                                        <span id="asCurText"><?= $esc($baseCurrency . ' - ' . $currencyChoices[$baseCurrency]) ?></span>
                                        <i class="fas fa-chevron-down as-cur-caret"></i>
                                    </button>
                                    <div class="as-cur-menu" id="asCurMenu" hidden>
                                        <input type="search" class="as-cur-search" id="asCurSearch" placeholder="Search currency..." autocomplete="off" aria-label="Search currency">
                                        <ul role="listbox">
                                            <?php foreach ($currencyChoices as $code => $cname): ?>
                                            <li role="option" data-code="<?= $esc($code) ?>" data-name="<?= $esc($cname) ?>" data-flag="<?= $esc($curFlag($code)) ?>" data-q="<?= $esc(strtolower($code . ' ' . $cname)) ?>"<?= $code === $baseCurrency ? ' aria-selected="true"' : '' ?>>
                                                <img class="as-flag" src="<?= $esc($curFlag($code)) ?>" alt="" loading="lazy"><b><?= $esc($code) ?></b><span><?= $esc($cname) ?></span>
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                                <?php if ($ledgerHasEntries): ?><span class="as-info" title="Locked because the ledger already has entries."><i class="fas fa-lock"></i></span><?php endif; ?>
                            </div>
                        </div>
                        <?php elseif ($group['general'] === 'rates'): ?>
                        <div class="as-row">
                            <div class="as-row-head"><span class="as-row-icon"><i class="fas fa-right-left"></i></span>
                                <div><h3 class="as-row-title" title="Currency conversion rates.">Exchange Rates</h3></div></div>
                            <div class="as-row-ctl">
                                <div class="as-ctl-line">
                                    <button type="button" class="as-btn-outline" id="fxToggle" aria-expanded="false"><i class="fas fa-plus"></i> Add Rates</button>
                                </div>
                            </div>
                            <div class="as-fx" id="fxPanel" hidden data-csrf="<?= $esc(csrf_token()) ?>">
                                <div class="as-fx-add">
                                    <span class="as-fx-eq">1</span>
                                    <div class="as-cur" id="fxCurBox">
                                        <input type="hidden" id="fxCur" value="<?= $esc($fxDefaultCur) ?>">
                                        <button type="button" class="form-input as-cur-btn" id="fxCurBtn" aria-haspopup="listbox" aria-expanded="false" aria-label="Currency">
                                            <img class="as-flag" id="fxCurFlag" src="<?= $esc($curFlag($fxDefaultCur)) ?>" alt="">
                                            <span id="fxCurText"><?= $esc($fxDefaultCur . ' - ' . $currencyChoices[$fxDefaultCur]) ?></span>
                                            <i class="fas fa-chevron-down as-cur-caret"></i>
                                        </button>
                                        <div class="as-cur-menu" id="fxCurMenu" hidden>
                                            <input type="search" class="as-cur-search" id="fxCurSearch" placeholder="Search currency..." autocomplete="off" aria-label="Search currency">
                                            <ul role="listbox">
                                                <?php foreach ($currencyChoices as $code => $cname): if ($code === $baseCurrency) continue; ?>
                                                <li role="option" data-code="<?= $esc($code) ?>" data-name="<?= $esc($cname) ?>" data-flag="<?= $esc($curFlag($code)) ?>" data-q="<?= $esc(strtolower($code . ' ' . $cname)) ?>"<?= $code === $fxDefaultCur ? ' aria-selected="true"' : '' ?>>
                                                    <img class="as-flag" src="<?= $esc($curFlag($code)) ?>" alt="" loading="lazy"><b><?= $esc($code) ?></b><span><?= $esc($cname) ?></span>
                                                </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </div>
                                    <span class="as-fx-eq">=</span>
                                    <input type="text" id="fxRate" class="form-input" inputmode="decimal" placeholder="Rate e.g. 2,650" aria-label="Rate" autocomplete="off">
                                    <span class="as-fx-eq"><?= $esc($baseCurrency) ?></span>
                                    <input type="date" id="fxDate" class="form-input" value="<?= date('Y-m-d') ?>" aria-label="Rate date">
                                    <button type="button" class="as-btn-outline" id="fxAdd"><i class="fas fa-plus"></i> Add</button>
                                </div>
                                <div class="as-fx-list"><table><tbody id="fxBody"></tbody></table></div>
                            </div>
                        </div>
                        <div class="as-row">
                            <div class="as-row-head"><span class="as-row-icon"><i class="fas fa-robot"></i></span>
                                <div><h3 class="as-row-title" title="Where daily rates come from.">Auto exchange rate</h3></div></div>
                            <div class="as-row-ctl">
                                <label class="as-switch" title="Off = I enter rates myself. On = BOT rate via AI assistant, daily.">
                                    <input type="checkbox" name="fx_mode" value="auto"<?= $fxMode === 'auto' ? ' checked' : '' ?>>
                                    <span class="as-switch-track"></span>
                                    <span class="form-label">Automatic daily rate <small>(BOT via AI assistant)</small></span>
                                </label>
                                <button type="submit" name="fetch_rates" value="1" formnovalidate class="as-btn-outline" hidden tabindex="-1"><i class="fas fa-wand-magic-sparkles"></i> Fetch today's rates</button>
                            </div>
                            <?php if ($fxLastAuto): ?><div class="as-row-note" style="font-size:.7rem;color:#6b7280;margin:.2rem 0 0 2.2rem">Last automatic update: <?= $esc(date('d M Y', strtotime($fxLastAuto))) ?>. <?= $esc($fxAutoNote) ?></div><?php endif; ?>
                        </div>
                        <?php elseif ($group['general'] === 'tax'): ?>
                        <div class="as-row">
                            <div class="as-row-head"><span class="as-row-icon"><i class="fas fa-file-invoice"></i></span>
                                <div><h3 class="as-row-title" title="Your VAT number.">VAT / Tax</h3></div></div>
                            <div class="as-row-ctl">
                                <label class="form-label" for="gen_vrn">VAT registration (VRN)</label>
                                <input type="text" class="form-input" id="gen_vrn" name="company_vat" maxlength="50" value="<?= $esc($companyVat) ?>" autocomplete="off">
                            </div>
                        </div>
                        <div class="as-row">
                            <div class="as-row-head"><span class="as-row-icon"><i class="fas fa-percent"></i></span>
                                <div><h3 class="as-row-title" title="How prices are entered.">Sales Tax Calculation</h3></div></div>
                            <div class="as-row-ctl">
                                <span class="form-label">Prices include VAT <span class="as-req">*</span></span>
                                <div class="as-radio-pair">
                                    <label class="as-radio"><input type="radio" name="tax_calculation_mode" value="inclusive"<?= $taxMode === 'inclusive' ? ' checked' : '' ?>>                                    <span>Inclusive<small>VAT in price</small></span></label>
                                                                        <label class="as-radio"><input type="radio" name="tax_calculation_mode" value="exclusive"<?= $taxMode === 'inclusive' ? '' : ' checked' ?>><span>Exclusive<small>VAT added on top</small></span></label>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="ps-details-actions as-gen-actions" style="gap:0.6rem;">
                            <button type="button" class="as-btn-reset as-gen-reset"><i class="fas fa-rotate-left"></i> Reset</button>
                            <button type="submit" name="save_general" value="1" formnovalidate class="btn-save ps-details-save as-gen-save"><i class="fas fa-floppy-disk"></i> Save changes</button>
                        </div>
                        </section>
                        <?php continue; endif; ?>
                        <?php if ($hasAuto): ?>
                        <div class="as-banner">
                            <i class="fas fa-wand-magic-sparkles"></i>
                            <span>Accounts marked <b>Auto-detected</b> were picked for you.</span>
                        </div>
                        <?php endif; ?>
                        <?php $isAuto = $group['id'] === 'as-system'; $wrapAdv = $hasAuto && !$isAuto; ?>
                        <?php if ($wrapAdv): ?><details class="as-adv"><summary>Advanced options: review or change accounts</summary><?php endif; ?>
                        <?php if ($isAuto): ?>
                        <p class="as-auto-note"><i class="fas fa-circle-check"></i> Handled automatically. Nothing to do here.</p>
                        <details class="as-adv"><summary>Advanced: choose these accounts yourself</summary>
                        <?php endif; ?>
                        <div class="ps-details-grid">
                            <?php foreach ($group['roles'] as $role):
                                $def = $glRoles[$role];
                                $opts = $roleOptions[$role]['options'];
                                $state = $roleState[$role];
                                $fieldId = 'gl_' . $role;
                            ?>
                            <div class="as-field">
                                <?php $optional = !empty($def['optional']); ?>
                                <label class="form-label" for="<?= $esc($fieldId) ?>"><?= $esc($def['label']) ?><?php if ($state['auto'] && $state['selected']): ?><span class="as-tag">Auto-detected</span><?php endif; ?></label><span class="as-tip" tabindex="0" role="note" aria-label="<?= $esc($def['help']) ?>" data-tip="<?= $esc($def['help']) ?>"><i class="fas fa-circle-info"></i></span>
                                <div class="ps-icon-field">
                                    <i class="fas <?= $esc($roleIcons[$role] ?? 'fa-book') ?>"></i>
                                    <select name="gl[<?= $esc($role) ?>]" id="<?= $esc($fieldId) ?>" data-role="<?= $esc($role) ?>" class="form-input" style="<?= $selectArrow ?>"<?= $optional ? ' data-optional="1"' : ' required' ?>>
                                        <?php if ($optional): ?>
                                            <option value=""<?= $state['selected'] ? '' : ' selected' ?>>System default: <?= $esc($def['default'][0] . ' - ' . $def['default'][1]) ?> (created when first needed)</option>
                                            <?php foreach ($opts as $opt): if (!$roleOptions[$role]['filtered'] && empty($opt['invalid'])) { continue; } ?>
                                                <option value="<?= (int) $opt['id'] ?>"<?= (int) $opt['id'] === (int) $state['selected'] ? ' selected' : '' ?><?= !empty($opt['invalid']) ? ' data-invalid="1"' : '' ?>><?= $esc($opt['label']) ?></option>
                                            <?php endforeach; ?>
                                        <?php elseif ($opts === []): ?>
                                            <option value="">No <?= $esc($def['type']) ?> accounts found</option>
                                        <?php else: ?>
                                            <?php if (!$state['selected']): ?><option value="">Select an account&hellip;</option><?php endif; ?>
                                            <?php foreach ($opts as $opt): ?>
                                                <option value="<?= (int) $opt['id'] ?>"<?= (int) $opt['id'] === (int) $state['selected'] ? ' selected' : '' ?><?= !empty($opt['invalid']) ? ' data-invalid="1"' : '' ?>><?= $esc($opt['label']) ?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <?php if ($role !== 'revenue' && !$optional && !$roleOptions[$role]['filtered'] && $opts !== []): ?>
                                <p class="as-field-warn"><i class="fas fa-triangle-exclamation"></i> No <?= $esc(strtolower($def['label'])) ?> account found in your chart, so all <?= $esc($def['type']) ?> accounts are listed. Consider adding a dedicated one.</p>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($group['id'] === 'as-stock'): ?>
                        <label class="as-switch">
                            <input type="checkbox" name="invoice_stock_mapping_guard" value="1" id="invoiceStockMappingGuard"<?= accounting_settings_get($pdo, 'invoice_stock_mapping_guard') === '1' ? ' checked' : '' ?>>
                            <span>Require dedicated cost of goods sold and inventory accounts before creating invoices</span>
                        </label>
                        <?php endif; ?>
                        <?php if ($isAuto || $wrapAdv): ?></details><?php endif; ?>
                        <div class="ps-details-actions">
                            <button type="submit" class="btn-save ps-details-save as-save"<?= $hasAuto ? '' : ' disabled' ?>><i class="fas fa-floppy-disk"></i> Save changes</button>
                        </div>
                    </section>
                    <?php endforeach; ?>

                    <section class="editor-section as-journals" id="as-journals" hidden>
                        <div class="section-header ps-sec-head">
                            <span class="ps-sec-icon"><i class="fas fa-book"></i></span>
                            <div>
                                <h2 class="section-title">Journals</h2>
                                <p class="section-subtitle">Set up the journals used to post entries.</p>
                            </div>
                            <button type="button" class="btn-save ps-details-save as-jrn-new" id="jrnNewBtn" title="New journal" aria-label="New journal"><i class="fas fa-plus"></i> New</button>
                        </div>
                        
                        <?php if ($jrnFlash): ?>
                        <div class="as-banner" role="status"<?= !empty($jrnFlash['error']) ? ' style="background:#fef2f2;border-color:#fecaca;color:#b91c1c"' : '' ?>><i class="fas fa-circle-info"></i><span><?= $esc($jrnFlash['message'] ?? '') ?></span></div>
                        <?php endif; ?>
                        <?php
                        $jrnIcons = ['general' => 'fa-book', 'sales' => 'fa-cart-shopping', 'purchase' => 'fa-bag-shopping', 'bank' => 'fa-building-columns', 'cash' => 'fa-money-bill-wave', 'payments' => 'fa-credit-card', 'payroll' => 'fa-users', 'opening' => 'fa-flag-checkered'];
                        foreach ($jrnList as $j):
                            $jActive = ($j['status'] ?? '') === 'active';
                            $jDefault = !empty($j['is_default']);
                            $jCore = accounting_journal_is_core($j);
                            $jLocked = $jActive && ($jDefault || $jCore);
                            $jTypeLabel = $jrnTypes[$j['type'] ?? ''] ?? 'General';
                        ?>
                        <div class="as-row as-jrn-row<?= $jActive ? '' : ' as-jrn-off' ?>" tabindex="0" data-jrn="<?= $esc(json_encode(['id' => (string) $j['id'], 'name' => $j['name'], 'code' => $j['code'], 'type' => $jTypeLabel, 'active' => $jActive, 'default' => $jDefault, 'desc' => (string) ($j['description'] ?? ''), 'cur' => (string) ($j['currency'] ?? 'TZS'), 'seq' => (string) ($j['posting_sequence'] ?? ''), 'typeKey' => (string) ($j['type'] ?? 'general'), 'mod' => (string) ($j['linked_module'] ?? ''), 'manual' => !empty($j['allow_manual']), 'post' => !empty($j['allow_posting']), 'rev' => !empty($j['allow_reverse']), 'appr' => !empty($j['require_approval']), 'auto' => !empty($j['auto_numbering']), 'cash' => !empty($j['affects_cash']), 'core' => $jCore, 'log' => $jrnLog[(string) $j['code']] ?? [], 'entries' => (int) ($jrnCounts[strtoupper((string) $j['code'])] ?? 0), 'acct' => (int) ($j['default_account_id'] ?? 0), 'acctName' => $jrnAccounts[(int) ($j['default_account_id'] ?? 0)] ?? '', 'upd' => isset($j['updated_by']) ? $j['updated_by'] . ', ' . ($j['updated_at'] ?? '') : ''])) ?>">
                            <div class="as-row-head"><span class="as-row-icon" title="<?= $esc($jTypeLabel) ?>"><i class="fas <?= $esc($jrnIcons[$j['type'] ?? ''] ?? 'fa-book') ?>"></i></span>
                                <h3 class="as-row-title"><?= $esc($j['name']) ?> <small style="color:#6b7280;font-weight:600"><?= $esc($j['code']) ?></small><?php if ($jDefault): ?> <i class="fas fa-star" style="color:#f59e0b;font-size:.75rem" title="Default"></i><?php endif; ?></h3></div>
                            <div class="as-row-ctl as-jrn-ctl">
                                <span class="as-jrn-st<?= $jActive ? ' is-on' : '' ?>"><?= $jActive ? 'Active' : 'Inactive' ?></span>
                                <a class="as-jrn-ico as-jrn-edit" href="<?= $esc($glUrl('journal-create.php') . '&id=' . rawurlencode((string) $j['id'])) ?>" title="Edit" aria-label="Edit"><i class="fas fa-pen"></i></a>
                                <button type="submit" form="asJrnToggleForm" name="id" value="<?= $esc($j['id']) ?>" class="as-jrn-ico"<?= $jLocked ? ' disabled title="' . ($jCore ? 'Used by automatic posting' : 'Default journal cannot be deactivated') . '"' : ' title="' . ($jActive ? 'Deactivate' : 'Activate') . '"' ?> aria-label="<?= $jActive ? 'Deactivate' : 'Activate' ?>"><i class="fas fa-power-off"></i></button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                    </section>

                    <section class="editor-section as-manual" id="as-manual" hidden>
                        <div class="as-man">
                            <div class="as-man-panel">

                            <h3 class="as-man-head"><i class="fas fa-route"></i> A year at a glance</h3>
                            <ol class="as-man-track">
                                <li><span class="as-man-dot"><i class="fas fa-play"></i></span><b>Start</b><small>Pick the month your year begins</small></li>
                                <li><span class="as-man-dot"><i class="fas fa-calendar-check"></i></span><b>Every month</b><small>Check records, then lock the month</small></li>
                                <li><span class="as-man-dot"><i class="fas fa-flag-checkered"></i></span><b>Year ends</b><small>Close the year</small></li>
                                <li><span class="as-man-dot"><i class="fas fa-lock"></i></span><b>Then</b><small>Lock the whole year</small></li>
                            </ol>
                            </div>

                            <div class="as-man-panel">
                            <h3 class="as-man-head"><i class="fas fa-sliders"></i> The settings</h3>
                            <div class="as-man-cards">
                                <div class="as-man-card">
                                    <span class="as-man-ico"><i class="fas fa-calendar-days"></i></span>
                                    <h3>Financial Year</h3>
                                    <span class="as-man-when"><i class="fas fa-clock"></i> Once, when you start</span>
                                    <p>Choose the month your business year begins. For most companies this is January.</p>
                                    <p class="as-man-ex">Starts in April &rarr; the year runs 1 April to 31 March.</p>
                                    <a href="#as-period" class="as-man-go" data-go="as-period">Open setting <i class="fas fa-arrow-right"></i></a>
                                </div>
                                <div class="as-man-card">
                                    <span class="as-man-ico"><i class="fas fa-flag-checkered"></i></span>
                                    <h3>Year-End Close</h3>
                                    <span class="as-man-when"><i class="fas fa-clock"></i> Once a year</span>
                                    <p>Wraps up the year. The profit or loss moves into <b>Retained Earnings</b>, so the new year starts clean.</p>
                                    <p class="as-man-ex">Do it after the year is over and your records are checked.</p>
                                    <a href="#as-period" class="as-man-go" data-go="as-period">Open setting <i class="fas fa-arrow-right"></i></a>
                                </div>
                                <div class="as-man-card">
                                    <span class="as-man-ico"><i class="fas fa-lock"></i></span>
                                    <h3>Books closed through</h3>
                                    <span class="as-man-when"><i class="fas fa-clock"></i> After each check-up</span>
                                    <p>Locks old dates so nobody can change past records by mistake. Many companies lock every month.</p>
                                    <p class="as-man-ex">A lock cannot be undone, so be sure first.</p>
                                    <a href="#as-period" class="as-man-go" data-go="as-period">Open setting <i class="fas fa-arrow-right"></i></a>
                                </div>
                            </div>
                            </div>

                            <div class="as-man-panel">
                            <div class="as-man-head-row">
                                <h3 class="as-man-head"><i class="fas fa-list-check"></i> Closing the year: your checklist</h3>
                                <span class="as-man-prog"><b id="asManDone">0</b> of 3 done <button type="button" id="asManReset" class="as-man-link">Start over</button></span>
                            </div>
                            <div class="as-man-bar"><span id="asManBar"></span></div>
                            <ol class="as-man-steps">
                                <li>
                                    <label class="as-man-check"><input type="checkbox" data-step="1"><span class="as-man-num">1</span><span class="as-man-sico"><i class="fas fa-clipboard-check"></i></span>
                                        <span class="as-man-txt"><b>Check everything</b><small>All invoices, expenses and payments for the year are entered and correct.</small></span></label>
                                </li>
                                <li>
                                    <label class="as-man-check"><input type="checkbox" data-step="2"><span class="as-man-num">2</span><span class="as-man-sico"><i class="fas fa-flag-checkered"></i></span>
                                        <span class="as-man-txt"><b>Press &ldquo;Close year&rdquo;</b><small>It happens straight away. You do not need to press Save.</small></span></label>
                                    <a href="#as-period" class="as-man-go" data-go="as-period">Go <i class="fas fa-arrow-right"></i></a>
                                </li>
                                <li>
                                    <label class="as-man-check"><input type="checkbox" data-step="3"><span class="as-man-num">3</span><span class="as-man-sico"><i class="fas fa-lock"></i></span>
                                        <span class="as-man-txt"><b>Lock the year</b><small>Set &ldquo;Books closed through&rdquo; to the last day of the year, then press Save changes.</small></span></label>
                                    <a href="#as-period" class="as-man-go" data-go="as-period">Go <i class="fas fa-arrow-right"></i></a>
                                </li>
                            </ol>
                            </div>
                            <p class="as-man-warn"><i class="fas fa-triangle-exclamation"></i><span><b>Order matters.</b> Close the year <u>before</u> you lock it. If the date is already locked, the close will not work.</span></p>

                            <div class="as-man-panel" id="asAiPanel" data-csrf="<?= $esc(csrf_token()) ?>">
                                <h3 class="as-man-head"><i class="fas fa-robot"></i> Ask the AI assistant
                                    <button type="button" class="as-expand" id="asExpandBtn" title="Expand" aria-label="Expand"><i class="fas fa-expand"></i></button>
                                </h3>
                                <div class="as-chat">
                                    <iframe id="asAiFrame" title="AI Assistant" loading="lazy" data-src="<?= $esc($aiFrameUrl) ?>"></iframe>
                                </div>
                            </div>
                        </div>
                    </section>
                </form>
                

                <aside class="acct-aside" id="asAside">
                    <div class="ps-status-card as-sum" id="asSumCard" hidden>
                        <h3><i class="fas fa-list-check"></i> Current settings</h3>
                        <ul class="as-sum-list">
                            <li><i class="fas fa-lock"></i><span>Books closed through</span><b><?= $lockedThrough ? $esc(date('d M Y', strtotime($lockedThrough))) : 'Not closed' ?></b></li>
                            <li><i class="fas fa-coins"></i><span>Base currency</span><b><?= $esc($baseCurrency) ?></b></li>
                            <li><i class="fas fa-right-left"></i><span>Rates entered</span><b id="asSumRates"><?= (int) $fxCount ?></b></li>
                            <li><i class="fas fa-robot"></i><span>Auto exchange rate</span><b><?= $fxMode === 'auto' ? 'On' : 'Off' ?></b></li>
                            <li><i class="fas fa-file-invoice"></i><span>VAT number</span><b><?= $companyVat !== '' ? $esc($companyVat) : 'Not set' ?></b></li>
                            <li><i class="fas fa-percent"></i><span>Prices</span><b><?= $taxMode === 'inclusive' ? 'Include VAT' : 'Exclude VAT' ?></b></li>
                        </ul>
                    </div>
                    <div class="ps-status-card as-sum" id="asJrnDetail" hidden>
                        <h3><i class="fas fa-book"></i> <span id="jdTitle">Journal</span></h3>
                        <div id="jdView"><ul class="as-sum-list">
                            <li><i class="fas fa-hashtag"></i><span>Code</span><b id="jdCode"></b></li>
                            <li><i class="fas fa-tag"></i><span>Type</span><b id="jdType"></b></li>
                            <li><i class="fas fa-circle-dot"></i><span>Status</span><b id="jdStatus"></b></li>
                            <li><i class="fas fa-coins"></i><span>Currency</span><b id="jdCur"></b></li>
                            <li><i class="fas fa-list-ol"></i><span>Number prefix</span><b id="jdSeq"></b></li>
                            <li><i class="fas fa-plug"></i><span>Linked to</span><b id="jdMod"></b></li>
                            <li><i class="fas fa-pen-to-square"></i><span>Manual entries</span><b id="jdManual"></b></li>
                            <li><i class="fas fa-paper-plane"></i><span>Posting</span><b id="jdPost"></b></li>
                            <li><i class="fas fa-rotate-left"></i><span>Reversal</span><b id="jdRev"></b></li>
                            <li><i class="fas fa-user-check"></i><span>Approval</span><b id="jdAppr"></b></li>
                            <li><i class="fas fa-money-bill-wave"></i><span>Affects cash</span><b id="jdCash"></b></li>
                            <li><i class="fas fa-file-lines"></i><span>Posted entries</span><b id="jdEntries"></b></li>
                            <li id="jdAcctWrap"><i class="fas fa-building-columns"></i><span>Posts to account</span><b id="jdAcct"></b></li>
                        </ul>
                        <p class="as-row-sub" id="jdDesc" style="margin-top:.6rem"></p>
                        <div id="jdLogWrap" hidden style="margin-top:.7rem"><b style="font-size:.78rem;color:#6b7280">Change log</b><ul id="jdLog" class="as-row-sub" style="margin:.25rem 0 0;padding-left:1rem"></ul></div></div>
                        <form method="post" id="jdEdit" class="as-jrn-edit-form" action="settings.php?module=<?= $esc(rawurlencode($module)) ?>" hidden>
                            <input type="hidden" name="csrf_token" value="<?= $esc(csrf_token()) ?>">
                            <input type="hidden" name="action" value="save_journal">
                            <input type="hidden" name="id" id="jeId">
                            <label><span>Name</span><input type="text" name="name" id="jeName" maxlength="100" required></label>
                            <p class="as-jrn-lock"><i class="fas fa-lock"></i> <b id="jeCodeTxt"></b> &middot; <span id="jeTypeTxt"></span> &middot; <span id="jePrefixTxt"></span> <small>Fixed after creation</small></p>
                            <label><span>Status</span><select name="status" id="jeStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                            <div class="as-jrn-checks">
                                <label><input type="checkbox" name="is_default" value="1" id="jeDefault"> Default journal</label>
                                <label id="jeManualWrap"><input type="checkbox" name="allow_manual" value="1" id="jeManual"> Allow manual entries</label>
                            </div>
                            <label id="jeAcctWrap"><span>Default account</span><select name="default_account_id" id="jeAcct"><option value="0">Not set</option><?php foreach ($jrnAccounts as $aid => $albl): ?><option value="<?= (int) $aid ?>"><?= $esc($albl) ?></option><?php endforeach; ?></select></label>
                            <p class="as-row-sub" id="jeUsedBy"></p>
                            <label><span>Description</span><textarea name="description" id="jeDesc" rows="2" maxlength="1000"></textarea></label>
                            <p class="as-row-sub" id="jeAudit"></p>
                            <div class="as-jrn-edit-actions">
                                <button type="button" class="as-btn-reset" id="jeCancel"><i class="fas fa-xmark"></i> Cancel</button>
                                <button type="submit" class="btn-save ps-details-save"><i class="fas fa-floppy-disk"></i> Save</button>
                            </div>
                        </form>
                        <form method="post" id="jdNew" class="as-jrn-edit-form" action="settings.php?module=<?= $esc(rawurlencode($module)) ?>" hidden>
                            <input type="hidden" name="csrf_token" value="<?= $esc(csrf_token()) ?>">
                            <input type="hidden" name="action" value="create_journal">
                            <label><span>Name</span><input type="text" name="name" id="jnName" maxlength="100" required></label>
                            <div class="as-jrn-2">
                                <label><span>Code</span><input type="text" name="code" id="jnCode" maxlength="8" pattern="[A-Za-z0-9]{2,8}" placeholder="e.g. INV" required style="text-transform:uppercase"></label>
                                <label><span>Type</span><select name="type" id="jnType"><?php foreach ($jrnTypes as $k => $v): ?><option value="<?= $esc($k) ?>"><?= $esc($v) ?></option><?php endforeach; ?></select></label>
                            </div>
                            <label class="as-jrn-chk"><input type="checkbox" name="allow_manual" value="1" id="jnManual"> Allow manual entries</label>
                            <label><span>Description</span><textarea name="description" maxlength="1000" rows="2"></textarea></label>
                            <p class="as-row-sub">Number prefix, linked module and posting rules are set automatically. Code and type can't be changed later.</p>
                            <div class="as-jrn-edit-actions">
                                <button type="button" class="as-btn-reset" id="jnCancel"><i class="fas fa-xmark"></i> Cancel</button>
                                <button type="submit" class="btn-save ps-details-save"><i class="fas fa-floppy-disk"></i> Create</button>
                            </div>
                        </form>
                    </div></aside>
                <form hidden method="post" id="asJrnToggleForm" action="settings.php?module=<?= $esc(rawurlencode($module)) ?>">
                    <input type="hidden" name="csrf_token" value="<?= $esc(csrf_token()) ?>">
                    <input type="hidden" name="action" value="toggle_journal">
                </form>
            </div>
        </div>
</div>

<script>
(function () {
    var navs = [
        { toggle: document.getElementById('asMapToggle'), menu: document.getElementById('asMapMenu'), current: document.getElementById('asMapCurrent') },
        { toggle: document.getElementById('asGenToggle'), menu: document.getElementById('asGenMenu'), current: document.getElementById('asGenCurrent') }
    ];
    var sections = Array.prototype.slice.call(document.querySelectorAll('.editor-section[id]'));
    function show(id, remember) {
        if (!document.getElementById(id)) return;
        sections.forEach(function (s) { s.hidden = s.id !== id; });
        navs.forEach(function (n) {
            var owns = false;
            n.current.textContent = '';
            n.items.forEach(function (a) {
                var on = a.getAttribute('data-as-section') === id;
                a.classList.toggle('is-current', on);
                if (on) { owns = true; n.current.textContent = '\u00B7 ' + a.getAttribute('data-as-tab'); }
            });
            n.toggle.classList.toggle('is-active', owns);
        });
        document.body.setAttribute('data-ps-tab', id);
        var aside = document.getElementById('asAside');
        var shown = document.getElementById(id);
        var manualBtn = document.getElementById('asManualToggle');
        if (manualBtn) manualBtn.classList.toggle('is-active', id === 'as-manual');
        var jrnBtn = document.getElementById('asJournalsToggle');
        if (jrnBtn) jrnBtn.classList.toggle('is-active', id === 'as-journals');
        if (aside && shown) {
            var isGen = shown.classList.contains('as-gen');
            var isManual = id === 'as-manual';
            document.getElementById('asSumCard').hidden = !isGen;
            document.getElementById('asJrnDetail').hidden = id !== 'as-journals';
            if (id === 'as-journals' && window.asJrnPick) window.asJrnPick();
            
        }
        if (remember) {
            try { sessionStorage.setItem('asActiveTab', id); } catch (e) {}
            if (history.replaceState) history.replaceState(null, '', '#' + id);
        }
    }

    function closeAll(except) { navs.forEach(function (n) { if (n !== except) setOpen(n, false); }); }
    function place(n) {
        var r = n.toggle.getBoundingClientRect();
        var w = n.menu.offsetWidth;
        n.menu.style.top = (r.bottom + 6) + 'px';
        n.menu.style.left = Math.min(Math.max(8, r.left), window.innerWidth - w - 8) + 'px';
    }
    function setOpen(n, open) {
        n.menu.hidden = !open;
        n.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) place(n);
    }
    navs.forEach(function (n) {
        n.items = Array.prototype.slice.call(n.menu.querySelectorAll('[data-as-section]'));
        document.body.appendChild(n.menu);
        n.toggle.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); closeAll(n); setOpen(n, n.menu.hidden); });
        n.menu.addEventListener('click', function (e) {
            var item = e.target.closest('[data-as-section]');
            if (!item) return;
            e.preventDefault();
            setOpen(n, false);
            show(item.getAttribute('data-as-section'), true);
        });
        document.addEventListener('click', function (e) { if (!n.menu.hidden && !n.menu.contains(e.target)) setOpen(n, false); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !n.menu.hidden) { setOpen(n, false); n.toggle.focus(); } });
        window.addEventListener('resize', function () { if (!n.menu.hidden) place(n); });
        window.addEventListener('scroll', function () { if (!n.menu.hidden) place(n); }, true);
    });

    Array.prototype.forEach.call(document.querySelectorAll('.as-man-go'), function (a) {
        a.addEventListener('click', function (e) { e.preventDefault(); show(a.getAttribute('data-go'), true); window.scrollTo({ top: 0, behavior: 'smooth' }); });
    });
    var manSteps = Array.prototype.slice.call(document.querySelectorAll('.as-man-check input'));
    function manSave() {
        var done = manSteps.filter(function (c) { return c.checked; });
        document.getElementById('asManDone').textContent = done.length;
        document.getElementById('asManBar').style.width = Math.round(done.length / manSteps.length * 100) + '%';
        try { localStorage.setItem('asManualSteps', JSON.stringify(manSteps.map(function (c) { return c.checked; }))); } catch (e) {}
    }
    try {
        var savedSteps = JSON.parse(localStorage.getItem('asManualSteps') || '[]');
        manSteps.forEach(function (c, i) { c.checked = !!savedSteps[i]; });
    } catch (e) {}
    manSteps.forEach(function (c) { c.addEventListener('change', manSave); });
    document.getElementById('asManReset').addEventListener('click', function () { manSteps.forEach(function (c) { c.checked = false; }); manSave(); });
    manSave();

    var manualLink = document.getElementById('asManualToggle');
    if (manualLink) manualLink.addEventListener('click', function (e) { e.preventDefault(); closeAll(null); show('as-manual', true); });
    var jrnLink = document.getElementById('asJournalsToggle');
    if (jrnLink) jrnLink.addEventListener('click', function (e) { e.preventDefault(); closeAll(null); show('as-journals', true); });

    var start = (location.hash || '').slice(1);
    if (!start) { try { start = sessionStorage.getItem('asActiveTab') || ''; } catch (e) {} }
    show(start && document.getElementById(start) ? start : sections[0].id, false);

    var search = document.getElementById('psHeadSearch');
    if (search) {
        search.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            var q = search.value.trim().toLowerCase();
            if (!q) return;
            var hit = sections.find(function (s) { return s.textContent.toLowerCase().indexOf(q) !== -1; });
            if (hit) show(hit.id, true);
        });
    }

    var form = document.getElementById('asForm');
    form.addEventListener('invalid', function (e) {
        var d = e.target.closest && e.target.closest('details.as-adv');
        if (d) { d.open = true; }
    }, true);
    var saves = Array.prototype.slice.call(document.querySelectorAll('.as-save'));
    var selects = Array.prototype.slice.call(form.querySelectorAll('select[data-role]'));
    var original = selects.map(function (s) { return s.value; }).join('|');
    var stockGuard = document.getElementById('invoiceStockMappingGuard');
    var originalStockGuard = stockGuard ? stockGuard.checked : false;
    var hasAuto = <?= $hasAuto ? 'true' : 'false' ?>;
    function labelFor(role) {
        var s = form.querySelector('select[data-role="' + role + '"]');
        var opt = s && s.options[s.selectedIndex];
        return opt && opt.value ? opt.textContent.trim() : '\u2014';
    }
    function sync() {
        document.querySelectorAll('[data-show]').forEach(function (el) { el.textContent = labelFor(el.getAttribute('data-show')); });
        var now = selects.map(function (s) { return s.value; });
        var invalid = selects.some(function (s) { var o = s.options[s.selectedIndex]; return o && o.getAttribute('data-invalid') === '1'; });
        var off = invalid || selects.some(function (s) { return s.value === '' && !s.hasAttribute('data-optional'); }) || (!hasAuto && now.join('|') === original && (!stockGuard || stockGuard.checked === originalStockGuard));
        saves.forEach(function (b) { b.disabled = off; });
    }
    selects.forEach(function (s) { s.addEventListener('change', sync); });
    if (stockGuard) stockGuard.addEventListener('change', sync);
    sync();

    var curBox = document.getElementById('asCur');
    var curBtn = document.getElementById('asCurBtn');
    var curMenu = document.getElementById('asCurMenu');
    var curSearch = document.getElementById('asCurSearch');
    var curInput = document.getElementById('gen_base');
    var curOpts = curMenu ? Array.prototype.slice.call(curMenu.querySelectorAll('li')) : [];
    function pickCurrency(code) {
        curOpts.forEach(function (li) {
            var on = li.getAttribute('data-code') === code;
            if (on) {
                li.setAttribute('aria-selected', 'true');
                curInput.value = code;
                document.getElementById('asCurFlag').src = li.getAttribute('data-flag');
                document.getElementById('asCurText').textContent = code + ' - ' + li.getAttribute('data-name');
            } else {
                li.removeAttribute('aria-selected');
            }
        });
    }
    function curOpen(open) {
        curMenu.hidden = !open;
        curBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            curSearch.value = '';
            curOpts.forEach(function (li) { li.hidden = false; });
            curSearch.focus();
            var sel = curMenu.querySelector('[aria-selected="true"]');
            if (sel) sel.scrollIntoView({ block: 'nearest' });
        }
    }
    if (curBtn && !curBtn.disabled) {
        curBtn.addEventListener('click', function () { curOpen(curMenu.hidden); });
        curSearch.addEventListener('input', function () {
            var q = curSearch.value.trim().toLowerCase();
            curOpts.forEach(function (li) { li.hidden = q !== '' && li.getAttribute('data-q').indexOf(q) === -1; });
        });
        curSearch.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var first = curOpts.filter(function (li) { return !li.hidden; })[0];
                if (first) { pickCurrency(first.getAttribute('data-code')); curOpen(false); curBtn.focus(); }
            } else if (e.key === 'Escape') {
                e.stopPropagation();
                curOpen(false);
                curBtn.focus();
            }
        });
        curMenu.addEventListener('click', function (e) {
            var li = e.target.closest('li[data-code]');
            if (!li) return;
            pickCurrency(li.getAttribute('data-code'));
            curOpen(false);
            curBtn.focus();
        });
        document.addEventListener('click', function (e) { if (!curMenu.hidden && !curBox.contains(e.target)) curOpen(false); });
    }

    var fxSwitch = form.querySelector('input[name="fx_mode"]');
    var fxFetch = form.querySelector('button[name="fetch_rates"]');
    if (fxSwitch && fxFetch) {
        fxSwitch.addEventListener('change', function () { if (fxSwitch.checked) fxFetch.click(); });
    }
    var genLock = document.getElementById('gen_locked');
    Array.prototype.forEach.call(document.querySelectorAll('.as-gen-reset'), function (btn) {
        btn.addEventListener('click', function () {
            form.querySelectorAll('.as-gen input, .as-gen select').forEach(function (el) {
                if (el.type === 'radio' || el.type === 'checkbox') el.checked = el.defaultChecked;
                else if (el.tagName === 'SELECT') Array.prototype.forEach.call(el.options, function (o) { o.selected = o.defaultSelected; });
                else if (el !== curSearch && el.id !== 'fxCur' && el.id !== 'fxCurSearch') el.value = el.defaultValue;
            });
            if (curInput) pickCurrency(curInput.defaultValue);
        });
    });
    Array.prototype.forEach.call(document.querySelectorAll('.as-gen-save'), function (btn) {
        btn.addEventListener('click', function (e) {
            if (genLock && genLock.value && genLock.value !== genLock.defaultValue &&
                !window.confirm('Close the books through ' + genLock.value + '? A closed period cannot be reopened.')) {
                e.preventDefault();
            }
        });
    });

    function showToast(msg, type) {
        var box = document.createElement('div');
        box.className = 'as-toast as-toast-' + type;
        box.setAttribute('role', 'status');
        var ico = document.createElement('i');
        ico.className = 'fas ' + (type === 'success' ? 'fa-circle-check' : type === 'info' ? 'fa-circle-info' : 'fa-circle-exclamation');
        var txt = document.createElement('span');
        txt.textContent = msg;
        var x = document.createElement('button');
        x.type = 'button';
        x.setAttribute('aria-label', 'Close');
        x.innerHTML = '&times;';
        box.appendChild(ico); box.appendChild(txt); box.appendChild(x);
        document.body.appendChild(box);
        var kill = function () { box.classList.remove('in'); setTimeout(function () { box.remove(); }, 250); };
        x.addEventListener('click', kill);
        requestAnimationFrame(function () { box.classList.add('in'); });
        setTimeout(kill, 5000);
    }

    var fxPanel = document.getElementById('fxPanel');
    if (fxPanel) {
        var fxBody = document.getElementById('fxBody');
        var fxRate = document.getElementById('fxRate');
        var fxToggle = document.getElementById('fxToggle');
        var fxPill = document.getElementById('fxCount');
        var renderRates = function (rows, count) {
            fxBody.textContent = '';
            if (!rows.length) {
                var tr0 = fxBody.insertRow(), td0 = tr0.insertCell();
                td0.colSpan = 4; td0.className = 'as-fx-empty'; td0.textContent = 'No rates entered yet.';
            }
            rows.forEach(function (r) {
                var tr = fxBody.insertRow();
                tr.insertCell().textContent = r.currency;
                var c2 = tr.insertCell(); c2.className = 'n'; c2.textContent = Number(r.rate).toLocaleString(undefined, { maximumFractionDigits: 6 });
                tr.insertCell().textContent = r.rate_date;
                var c4 = tr.insertCell(); c4.className = 'n';
                var del = document.createElement('button');
                del.type = 'button'; del.title = 'Remove'; del.setAttribute('aria-label', 'Remove rate');
                del.innerHTML = '<i class="fas fa-trash"></i>';
                del.addEventListener('click', function () {
                    if (window.confirm('Remove this rate?')) fxSend({ fx_ajax: 'delete', id: r.id });
                });
                c4.appendChild(del);
            });
            if (fxPill && typeof count === 'number') fxPill.textContent = count + (count === 1 ? ' currency' : ' currencies');
            var sumRates = document.getElementById('asSumRates');
            if (sumRates && typeof count === 'number') sumRates.textContent = count;
        };
        var fxSend = function (data) {
            var fd = new FormData();
            Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
            fd.append('csrf_token', fxPanel.getAttribute('data-csrf'));
            return fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j.message) showToast(j.message, j.ok ? 'success' : 'error');
                    renderRates(j.rows || [], j.count);
                    return j;
                })
                .catch(function () { showToast('Could not reach the server.', 'error'); });
        };
        var fxLoaded = false;
        fxToggle.addEventListener('click', function () {
            var open = fxPanel.hidden;
            fxPanel.hidden = !open;
            fxToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open && !fxLoaded) { fxLoaded = true; fxSend({ fx_ajax: 'list' }).then(function () {}); }
            if (open && fxLookup) fxLookup();
        });
        document.getElementById('fxAdd').addEventListener('click', function () {
            fxSend({ fx_ajax: 'add', currency: document.getElementById('fxCur').value, rate: fxRate.value, rate_date: document.getElementById('fxDate').value })
                .then(function (j) { if (j && j.ok) { fxRate.value = ''; fxRate.focus(); } });
        });
        fxPanel.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.id !== 'fxCurSearch') { e.preventDefault(); document.getElementById('fxAdd').click(); }
        });

        var fxBox = document.getElementById('fxCurBox');
        var fxBtn = document.getElementById('fxCurBtn');
        var fxMenu = document.getElementById('fxCurMenu');
        var fxSearch = document.getElementById('fxCurSearch');
        var fxCurIn = document.getElementById('fxCur');
        var fxOpts = Array.prototype.slice.call(fxMenu.querySelectorAll('li'));
        var fxLookup = function () {
            var sw = form.querySelector('input[name="fx_mode"]');
            if (!sw || !sw.checked) return;
            var code = fxCurIn.value;
            fxRate.placeholder = 'Fetching BOT rate...';
            fxRate.value = '';
            var fd = new FormData();
            fd.append('fx_ajax', 'lookup');
            fd.append('currency', code);
            fd.append('csrf_token', fxPanel.getAttribute('data-csrf'));
            fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (fxCurIn.value !== code) return;
                    fxRate.placeholder = 'Rate e.g. 2,650';
                    if (j.ok) { fxRate.value = String(j.rate); fxRate.title = 'Source: ' + j.source; }
                    else showToast(j.message || 'Could not get the BOT rate.', 'error');
                })
                .catch(function () { fxRate.placeholder = 'Rate e.g. 2,650'; showToast('Could not reach the server.', 'error'); });
        };
        var fxPick = function (code) {
            var changed = fxCurIn.value !== code;
            fxOpts.forEach(function (li) {
                if (li.getAttribute('data-code') === code) {
                    li.setAttribute('aria-selected', 'true');
                    fxCurIn.value = code;
                    document.getElementById('fxCurFlag').src = li.getAttribute('data-flag');
                    document.getElementById('fxCurText').textContent = code + ' - ' + li.getAttribute('data-name');
                } else {
                    li.removeAttribute('aria-selected');
                }
            });
            if (changed) fxLookup();
        };
        var fxOpen = function (open) {
            fxMenu.hidden = !open;
            fxBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                fxSearch.value = '';
                fxOpts.forEach(function (li) { li.hidden = false; });
                fxSearch.focus();
                var sel = fxMenu.querySelector('[aria-selected="true"]');
                if (sel) sel.scrollIntoView({ block: 'nearest' });
            }
        };
        fxBtn.addEventListener('click', function () { fxOpen(fxMenu.hidden); });
        fxSearch.addEventListener('input', function () {
            var q = fxSearch.value.trim().toLowerCase();
            fxOpts.forEach(function (li) { li.hidden = q !== '' && li.getAttribute('data-q').indexOf(q) === -1; });
        });
        fxSearch.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var first = fxOpts.filter(function (li) { return !li.hidden; })[0];
                if (first) { fxPick(first.getAttribute('data-code')); fxOpen(false); fxRate.focus(); }
            } else if (e.key === 'Escape') {
                e.stopPropagation();
                fxOpen(false);
                fxBtn.focus();
            }
        });
        fxMenu.addEventListener('click', function (e) {
            var li = e.target.closest('li[data-code]');
            if (!li) return;
            fxPick(li.getAttribute('data-code'));
            fxOpen(false);
            fxRate.focus();
        });
        document.addEventListener('click', function (e) { if (!fxMenu.hidden && !fxBox.contains(e.target)) fxOpen(false); });
    }

    var msg = <?= json_encode($success !== '' ? $success : $error) ?>;
    var type = <?= json_encode($success !== '' ? 'success' : ($fetchInfo ? 'info' : 'error')) ?>;
    if (msg) showToast(msg, type);
})();
</script>
<script>
window.asAiPdf = function (html, title) {
    var panel = document.getElementById('asAiPanel');
    var fd = new FormData();
    fd.append('ai_pdf', '1');
    fd.append('csrf_token', panel.getAttribute('data-csrf'));
    fd.append('html', html);
    fd.append('title', title || '');
    fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
            var ct = r.headers.get('content-type') || '';
            if (!r.ok || ct.indexOf('pdf') === -1) throw new Error('PDF failed');
            return r.blob();
        })
        .then(function (blob) {
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'ai-response-' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.pdf';
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
        })
        .catch(function () { window.alert('Could not create the PDF. Please try again.'); });
};
(function () {
    var btn = document.getElementById('asExpandBtn'), panel = document.getElementById('asAiPanel');
    if (!btn || !panel) return;
    function set(on) {
        panel.classList.toggle('as-full', on);
        document.body.style.overflow = on ? 'hidden' : '';
        btn.title = on ? 'Exit full screen' : 'Expand';
        btn.firstElementChild.className = on ? 'fas fa-compress' : 'fas fa-expand';
    }
    btn.addEventListener('click', function () { set(!panel.classList.contains('as-full')); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') set(false); });
})();
</script>
<script>
(function () {
    var frame = document.getElementById('asAiFrame');
    if (!frame) return;
    var css = '#native-sidebar,.employee-header,.layout-main-wrapper>.flex-grow-1>header,#erp-auto-breadcrumb,.erp-auto-breadcrumb,.ps-crumbs{display:none!important}'
        + 'body.page-ai-assistant .layout-main-wrapper,body.page-ai-assistant .layout-main-wrapper>.flex-grow-1{height:100vh!important}'
        + 'body.page-ai-assistant main.main-content.ai-assistant-react-root{padding-bottom:0!important}';
    frame.addEventListener('load', function () {
        try {
            var doc = frame.contentDocument;
            var st = doc.createElement('style');
            st.textContent = css;
            doc.head.appendChild(st);
            var hideFab = function () {
                doc.querySelectorAll('body *').forEach(function (el) {
                    if (el.closest('#root')) return;
                    var cs = doc.defaultView.getComputedStyle(el);
                    if (cs.position !== 'fixed' && cs.position !== 'absolute') return;
                    var r = el.getBoundingClientRect();
                    if (r.width > 0 && r.width <= 70 && r.height <= 70 && el.querySelector('svg,i,img')) el.style.setProperty('display', 'none', 'important');
                });
            };
            hideFab();
            setTimeout(hideFab, 800);
            setTimeout(hideFab, 2500);
            var dst = doc.createElement('style');
            dst.textContent = '.gpt-assistant{flex-wrap:wrap}.as-dls{margin:4px 0 0 40px;display:inline-flex;gap:2px}.as-dl{margin:0;border:0;background:transparent;color:#6b7280;font:inherit;font-size:12px;cursor:pointer;padding:2px 6px;border-radius:6px;display:inline-flex;gap:5px;align-items:center}.as-dl:hover{background:rgba(100,116,139,.15)}.as-opt{display:none}.as-menu{position:fixed;z-index:2147483647;background:#fff;color:#111;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.15);padding:4px;min-width:130px;display:flex;flex-direction:column}.as-menu button{border:0;background:transparent;font:inherit;font-size:13px;text-align:left;padding:7px 10px;border-radius:8px;cursor:pointer;display:flex;align-items:center;gap:8px}.as-menu button:hover{background:#f3f4f6}.as-menu i{font-style:normal;font-weight:700;width:16px;text-align:center}';
            doc.head.appendChild(dst);
            var addDl = function () {
                doc.querySelectorAll('.gpt-assistant').forEach(function (box) {
                    var body = box.querySelector('.gpt-body');
                    var text = body ? body.innerText.trim() : '';
                    var btn = box.querySelector('.as-dls');
                    if (!text || body.querySelector('.gpt-typing,.gpt-pending')) { if (btn) btn.remove(); return; }
                    if (btn) return;
                    btn = doc.createElement('span');
                    btn.className = 'as-dls';
                    btn.style.cursor = 'default';
                    var esc = function (s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
                    var stamp = function () { return new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-'); };
                    var save = function (blob, name) {
                        var a = doc.createElement('a');
                        a.href = URL.createObjectURL(blob);
                        a.download = name;
                        doc.body.appendChild(a);
                        a.click();
                        a.remove();
                        setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
                    };
                    var title = (function () {
                        var row = box.closest('.gpt-row'), p = row && row.previousElementSibling;
                        while (p && !p.querySelector('.gpt-user')) p = p.previousElementSibling;
                        var t = p ? p.querySelector('.gpt-user').innerText.trim() : '';
                        return t.length > 150 ? t.slice(0, 147) + '...' : t;
                    })();
                    var exportBody = function () {
                        var c = body.cloneNode(true);
                        c.querySelectorAll('.gpt-chart').forEach(function (ch) {
                            var t = doc.createElement('table');
                            t.setAttribute('width', '100%');
                            t.style.borderCollapse = 'collapse';
                            var ttl = ch.querySelector('.gpt-chart-title');
                            var out = (ttl ? '<tr><td colspan="3" style="border:0"><b>' + esc(ttl.textContent) + '</b></td></tr>' : '');
                            ch.querySelectorAll('.gpt-bar-row').forEach(function (r) {
                                var w = (r.querySelector('.gpt-bar') || {}).style ? r.querySelector('.gpt-bar').style.width : '2%';
                                out += '<tr><td style="border:0;font-size:9pt" width="30%">' + esc(r.querySelector('.gpt-bar-label').textContent) + '</td>'
                                    + '<td style="border:0" width="50%"><div style="background:#059669;height:10px;width:' + w + '"></div></td>'
                                    + '<td style="border:0;font-size:9pt" align="right">' + esc(r.querySelector('.gpt-bar-value').textContent) + '</td></tr>';
                            });
                            t.innerHTML = out;
                            ch.parentNode.replaceChild(t, ch);
                        });
                        c.querySelectorAll('.gpt-table').forEach(function (t) { t.setAttribute('border', '1'); t.setAttribute('cellpadding', '4'); t.setAttribute('width', '100%'); t.style.borderCollapse = 'collapse'; });
                        return c.innerHTML;
                    };
                    var html = function () {
                        return '<html><head><meta charset="utf-8"><title>AI response</title><style>body{font-family:Arial,sans-serif;font-size:12pt;line-height:1.5}</style></head><body>' + (title ? '<h2>' + esc(title) + '</h2>' : '') + exportBody() + '</body></html>';
                    };
                    var fmts = [
                        ['PDF', 'Print or save as PDF', 'fa-file-pdf', '#dc2626', 1, '<path d="M8.5 17v-4h1.4a1.2 1.2 0 0 1 0 2.4H8.5M13 17v-4h.9a2 2 0 0 1 0 4zM17 13h-1.5v4M15.5 15h1.3" stroke-width="1.2"/>'],
                        ['Word', 'Editable .doc file', 'fa-file-word', '#2563eb', 2, '<path d="M8 12.5l1.2 4.5 1.3-3.5 1.3 3.5 1.2-4.5" stroke-width="1.3"/>'],
                        ['TXT', 'Plain text file', 'fa-file-lines', '#64748b', 0, '<path d="M8.5 12.5h7M8.5 15h7M8.5 17.5h4" stroke-width="1.4"/>']
                    ];
                    var actions = [
                        function () { save(new Blob([(title ? title + '\n\n' : '') + body.innerText.trim()], { type: 'text/plain;charset=utf-8' }), 'ai-response-' + stamp() + '.txt'); },
                        function () {
                            if (window.parent && window.parent.asAiPdf) window.parent.asAiPdf(exportBody(), title);
                        },
                        function () { save(new Blob(['\ufeff' + html()], { type: 'application/msword' }), 'ai-response-' + stamp() + '.doc'); }
                    ];
                    var closeMenu = function () { var m = doc.querySelector('.as-menu'); if (m) m.remove(); };
                    var openPopup = function (e) {
                        e.stopPropagation();
                        if (doc.querySelector('.as-menu')) { closeMenu(); return; }
                        var menu = doc.createElement('div');
                        menu.className = 'as-menu';
                        fmts.forEach(function (f) {
                            var o = doc.createElement('button');
                            o.type = 'button';
                            o.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="' + f[3] + '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>' + f[5] + '</svg>' + f[0];
                            o.addEventListener('click', function () { actions[f[4]](); closeMenu(); });
                            menu.appendChild(o);
                        });
                        doc.body.appendChild(menu);
                        var r = main.getBoundingClientRect(), h = menu.offsetHeight;
                        menu.style.left = Math.max(4, r.left) + 'px';
                        menu.style.top = (r.bottom + h + 8 > doc.defaultView.innerHeight ? Math.max(4, r.top - h - 4) : r.bottom + 4) + 'px';
                    };
                    doc.addEventListener('click', closeMenu);
                    var main = doc.createElement('button');
                    main.type = 'button';
                    main.className = 'as-dl as-main';
                    main.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 11 5 5 5-5"/><path d="M5 21h14"/></svg> Download';
                    main.addEventListener('click', openPopup);
                    btn.appendChild(main);
                    box.appendChild(btn);
                });
            };
            new MutationObserver(addDl).observe(doc.body, { childList: true, subtree: true, characterData: true });
            addDl();
        } catch (e) {}
    });
    var loaded = false;
    function load() {
        if (loaded) return;
        loaded = true;
        frame.src = frame.getAttribute('data-src');
    }
    var tab = document.getElementById('asManualToggle');
    if (tab) tab.addEventListener('click', load);
    if (window.location.hash === '#as-manual') load();
})();
(function () {
    var rows = Array.prototype.slice.call(document.querySelectorAll('.as-jrn-row'));
    if (!rows.length) return;
    var yn = function (v) { return v ? 'Yes' : 'No'; };
    function set(id, v) { document.getElementById(id).textContent = v || '\u2014'; }
    var editRow = null;
    function pick(row) {
        row = row || document.querySelector('.as-jrn-row.is-picked') || rows[0];
        var d; try { d = JSON.parse(row.getAttribute('data-jrn')); } catch (e) { return; }
        rows.forEach(function (r) { r.classList.toggle('is-picked', r === row); });
        if (form && ((!form.hidden && row !== editRow) || !nform.hidden)) showMode('view');
        set('jdTitle', d.name); set('jdCode', d.code); set('jdType', d.type + (d.default ? ' (default)' : ''));
        set('jdStatus', d.active ? 'Active' : 'Inactive'); set('jdCur', d.cur); set('jdSeq', d.seq ? d.seq + '#####' : '');
        set('jdMod', d.mod); set('jdManual', yn(d.manual)); set('jdPost', yn(d.post)); set('jdRev', yn(d.rev));
        set('jdAppr', yn(d.appr)); set('jdCash', yn(d.cash));
        document.getElementById('jdEntries').textContent = String(d.entries || 0);
        document.getElementById('jdAcctWrap').style.display = d.cash ? '' : 'none'; set('jdAcct', d.acctName);
        var lg = document.getElementById('jdLog'); lg.innerHTML = '';
        (d.log || []).forEach(function (l) { var li = document.createElement('li'); li.textContent = l.text + ' \u2013 ' + l.by + ', ' + l.at; lg.appendChild(li); });
        document.getElementById('jdLogWrap').hidden = !(d.log && d.log.length);
        document.getElementById('jdDesc').textContent = d.desc || '';
    }
    var view = document.getElementById('jdView'), form = document.getElementById('jdEdit');
    function fillEdit(row) {
        var d; try { d = JSON.parse(row.getAttribute('data-jrn')); } catch (e) { return; }
        var v = function (id, x) { document.getElementById(id).value = x; };
        var c = function (id, x) { document.getElementById(id).checked = !!x; };
        v('jeId', d.id); v('jeName', d.name); v('jeStatus', d.active ? 'active' : 'inactive'); v('jeDesc', d.desc);
        c('jeDefault', d.default); c('jeManual', d.manual);
        document.getElementById('jeDefault').disabled = !!d.default;
        document.getElementById('jeStatus').disabled = !!(d.core && d.active);
        document.getElementById('jeAcctWrap').style.display = d.cash ? '' : 'none'; v('jeAcct', d.acct || 0);
        document.getElementById('jeCodeTxt').textContent = d.code;
        document.getElementById('jeTypeTxt').textContent = d.type;
        document.getElementById('jePrefixTxt').textContent = d.seq ? d.seq + '#####' : '';
        document.getElementById('jeUsedBy').textContent = d.mod && d.mod !== 'General' ? 'Entries are posted here automatically by ' + d.mod + '. Keep manual entries off.' : '';
        document.getElementById('jeAudit').textContent = d.upd ? 'Last changed by ' + d.upd : '';
    }
    var nform = document.getElementById('jdNew');
    function showMode(m) { view.hidden = m !== 'view'; form.hidden = m !== 'edit'; nform.hidden = m !== 'new'; }
    function editMode(on) { showMode(on ? 'edit' : 'view'); }
    document.getElementById('jrnNewBtn').addEventListener('click', function () {
        nform.reset();
        document.getElementById('jdTitle').textContent = 'New journal';
        showMode('new');
        document.getElementById('jnName').focus();
    });
    document.getElementById('jnCancel').addEventListener('click', function () { pick(); showMode('view'); });
    document.querySelectorAll('.as-jrn-edit').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            var row = a.closest('.as-jrn-row');
            editRow = row; pick(row); fillEdit(row); editMode(true);
        });
    });
    document.getElementById('jeCancel').addEventListener('click', function () { editMode(false); });    window.asJrnPick = function () { pick(); };
    rows.forEach(function (r) {
        r.addEventListener('click', function (e) { if (!e.target.closest('a,button')) pick(r); });
        r.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target === r) { e.preventDefault(); pick(r); } });
    });
    if (document.body.getAttribute('data-ps-tab') === 'as-journals') pick();
})();</script>
</body>
</html>
