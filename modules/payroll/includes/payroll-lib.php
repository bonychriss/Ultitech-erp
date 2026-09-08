<?php

declare(strict_types=1);

function payrollDeskBootstrap(): PDO
{
    static $booted = false;
    if (!$booted) {
        require_once __DIR__ . '/../config/database.php';
        $booted = true;
    }

    global $pdo;
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }

    return $pdo;
}

function payrollDeskRequireAccess(): void
{
    payrollDeskBootstrap();
    requireLogin();

    if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
        $_GET['module'] = 'payroll';
    }
    $_SESSION['active_module'] = 'payroll';
}

function payrollDeskRequireFinanceOrAdmin(): void
{
    payrollDeskRequireAccess();

    if (!isFinanceOrAdmin()) {
        $q = array_merge($_GET ?: [], ['module' => 'payroll']);
        header('Location: my_payslips.php?' . http_build_query($q));
        exit;
    }
}

function payrollDeskWebBasePath(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        $base = rtrim(dirname($script), '/');
        // API handlers (api/*.php) - page assets and links live in the module root.
        if (str_ends_with($base, '/api')) {
            $base = rtrim(dirname($base), '/');
        }

        return $base;
    }

    if (function_exists('app_url')) {
        return app_url('/modules/payroll');
    }

    return rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
}

function payrollDeskPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $base = payrollDeskWebBasePath();

    return $base . '/' . $relativePath;
}

function payrollDeskQueryString(array $extra = []): string
{
    return '?' . http_build_query(array_merge($_GET ?: [], $extra));
}

function payrollDeskCurrentUser(): array
{
    $name = trim((string) ($_SESSION['full_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($_SESSION['username'] ?? 'User'));
    }

    return [
        'id' => (int) ($_SESSION['user_id'] ?? 0),
        'name' => $name !== '' ? $name : 'User',
        'username' => (string) ($_SESSION['username'] ?? ''),
        'isAdmin' => function_exists('isAdmin') && isAdmin(),
    ];
}

/**
 * @return array{missingTables:array<int,string>,totalSalariedStaff:int,lastRun:?array,runs:array<int,array>,totalRuns:int}
 */
function payrollDeskDashboardData(PDO $pdo): array
{
    $requiredPayrollTables = ['employee_salary', 'payroll_runs', 'payslips'];
    $missingPayrollTables = [];
    foreach ($requiredPayrollTables as $tableName) {
        if (!function_exists('payroll_table_exists') || !payroll_table_exists($tableName)) {
            $missingPayrollTables[] = $tableName;
        }
    }

    $totalSalariedStaff = 0;
    $lastRun = null;
    $runs = [];
    $totalRuns = 0;

    if ($missingPayrollTables === []) {
        try {
            $stmt = $pdo->query('SELECT COUNT(*) FROM ' . payroll_table('employee_salary'));
            $totalSalariedStaff = (int) $stmt->fetchColumn();

            $stmt = $pdo->query('SELECT * FROM ' . payroll_table('payroll_runs') . ' ORDER BY id DESC LIMIT 1');
            $lastRun = $stmt->fetch() ?: null;

            $totalRuns = (int) ($pdo->query('SELECT COUNT(*) FROM ' . payroll_table('payroll_runs'))->fetchColumn() ?: 0);
            $runs = $pdo->query('SELECT * FROM ' . payroll_table('payroll_runs') . ' ORDER BY id DESC LIMIT 10')->fetchAll();
        } catch (Throwable $e) {
            $missingPayrollTables[] = 'query_failed';
        }
    }

    return [
        'missingTables' => $missingPayrollTables,
        'totalSalariedStaff' => $totalSalariedStaff,
        'lastRun' => is_array($lastRun) ? payrollDeskFormatRun($lastRun) : null,
        'runs' => array_map('payrollDeskFormatRun', $runs),
        'totalRuns' => $totalRuns,
    ];
}

/**
 * @param array<string,mixed> $run
 * @return array<string,mixed>
 */
function payrollDeskFormatRun(array $run): array
{
    $year = (int) ($run['year'] ?? 0);
    $month = (int) ($run['month'] ?? 0);
    $periodDate = sprintf('%04d-%02d-01', $year, max(1, $month));

    return [
        'id' => (int) ($run['id'] ?? 0),
        'year' => $year,
        'month' => $month,
        'periodLabel' => date('F Y', strtotime($periodDate)),
        'runDate' => (string) ($run['run_date'] ?? ''),
        'runDateLabel' => !empty($run['run_date']) ? date('M j, Y', strtotime((string) $run['run_date'])) : '',
        'totalPayout' => (float) ($run['total_payout'] ?? 0),
        'status' => (string) ($run['status'] ?? 'draft'),
    ];
}

/**
 * @return array<string,mixed>
 */
function payrollDeskDeskInitPayload(PDO $pdo): array
{
    $companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : (int) ($_SESSION['company_id'] ?? 0);
    $company = function_exists('getCurrentCompany') ? (getCurrentCompany() ?: []) : [];
    $dashboard = payrollDeskDashboardData($pdo);

    return [
        'company' => [
            'id' => $companyId,
            'name' => (string) ($company['company_name'] ?? ($_SESSION['company_name'] ?? (defined('COMPANY_NAME') ? COMPANY_NAME : 'Company'))),
        ],
        'user' => payrollDeskCurrentUser(),
        'stats' => [
            'total_salaried_staff' => $dashboard['totalSalariedStaff'],
            'total_runs' => $dashboard['totalRuns'],
            'last_run_period' => $dashboard['lastRun']['periodLabel'] ?? null,
            'last_run_payout' => $dashboard['lastRun']['totalPayout'] ?? 0,
            'listed_now' => count($dashboard['runs']),
        ],
        'missingTables' => $dashboard['missingTables'],
        'runs' => $dashboard['runs'],
        'links' => [
            'help' => payrollDeskPublicUrl('help.php') . payrollDeskQueryString(),
            'setup' => payrollDeskPublicUrl('setup.php') . payrollDeskQueryString(),
            'runPayroll' => payrollDeskPublicUrl('run_payroll.php') . payrollDeskQueryString(),
            'salaries' => payrollDeskPublicUrl('salaries.php') . payrollDeskQueryString(),
            'settings' => payrollDeskPublicUrl('settings.php') . payrollDeskQueryString(),
            'viewRunBase' => payrollDeskPublicUrl('view_run.php') . payrollDeskQueryString(),
        ],
    ];
}

/**
 * @return array{distHtml:string,assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function payrollDeskLoadReactAssets(): ?array
{
    $uiDir = dirname(__DIR__) . '/frontend';
    $distIndex = $uiDir . '/dist/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '' || $cssFile === '') {
        return null;
    }

    $assetBase = payrollDeskPublicUrl('frontend/dist/assets/');
    $apiUrl = payrollDeskPublicUrl('api');
    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    return [
        'distHtml' => $distHtml,
        'assetBase' => $assetBase,
        'apiUrl' => $apiUrl,
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

function payrollDeskShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
    ];

    if (function_exists('app_url')) {
        $erpStylePath = dirname(__DIR__, 3) . '/assets/css/style.css';
        $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
        if (function_exists('erp_dark_theme_css_url')) {
            $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
        }
    }

    return implode("\n    ", $parts);
}

function payrollDeskJsonResponse(bool $success, mixed $data = null, ?string $message = null, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'error' => $success ? null : ($message ?? 'Request failed'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function payrollDeskApproveRun(PDO $pdo, int $runId): void
{
    if (!isAdmin()) {
        payrollDeskJsonResponse(false, null, 'Unauthorized.', 403);
    }
    if ($runId <= 0) {
        payrollDeskJsonResponse(false, null, 'Run id is required.', 422);
    }

    $stmt = $pdo->prepare('UPDATE ' . payroll_table('payroll_runs') . " SET status = 'approved' WHERE id = ?");
    $stmt->execute([$runId]);
    payrollDeskJsonResponse(true, payrollDeskDeskInitPayload($pdo), 'Payroll run approved successfully.');
}

function payrollDeskDeleteRun(PDO $pdo, int $runId): void
{
    if (!isAdmin()) {
        payrollDeskJsonResponse(false, null, 'Unauthorized.', 403);
    }
    if ($runId <= 0) {
        payrollDeskJsonResponse(false, null, 'Run id is required.', 422);
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM ' . payroll_table('payslips') . ' WHERE payroll_run_id = ?')->execute([$runId]);
        $pdo->prepare('DELETE FROM ' . payroll_table('payroll_runs') . ' WHERE id = ?')->execute([$runId]);
        $pdo->commit();
        payrollDeskJsonResponse(true, payrollDeskDeskInitPayload($pdo), 'Draft payroll run deleted.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        payrollDeskJsonResponse(false, null, 'Could not delete payroll run.', 500);
    }
}

/**
 * @return array<string, mixed>
 */
function payrollDeskGetRunPayload(PDO $pdo, int $runId): array
{
    if ($runId <= 0) {
        throw new RuntimeException('Run id is required.');
    }

    $stmt = $pdo->prepare('SELECT * FROM ' . payroll_table('payroll_runs') . ' WHERE id = ? LIMIT 1');
    $stmt->execute([$runId]);
    $run = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$run) {
        throw new RuntimeException('Payroll run not found.');
    }

    $stmt = $pdo->prepare(
        'SELECT p.*, u.full_name, u.department
         FROM ' . payroll_table('payslips') . ' p
         JOIN users u ON p.user_id = u.id
         WHERE p.payroll_run_id = ?
         ORDER BY u.full_name ASC'
    );
    $stmt->execute([$runId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totals = [
        'basic' => 0.0,
        'allowances' => 0.0,
        'gross' => 0.0,
        'tax' => 0.0,
        'nssf' => 0.0,
        'net' => 0.0,
    ];
    $slips = [];
    foreach ($rows as $row) {
        $basic = (float) ($row['basic_salary'] ?? 0);
        $allowances = (float) ($row['total_allowances'] ?? 0);
        $gross = (float) ($row['gross_salary'] ?? 0);
        $tax = (float) ($row['tax_deduction'] ?? 0);
        $nssf = (float) ($row['nssf_deduction'] ?? 0);
        $net = (float) ($row['net_salary'] ?? 0);
        $totals['basic'] += $basic;
        $totals['allowances'] += $allowances;
        $totals['gross'] += $gross;
        $totals['tax'] += $tax;
        $totals['nssf'] += $nssf;
        $totals['net'] += $net;

        $name = (string) ($row['full_name'] ?? '');
        $slips[] = [
            'id' => (int) ($row['id'] ?? 0),
            'userId' => (int) ($row['user_id'] ?? 0),
            'fullName' => $name,
            'department' => (string) ($row['department'] ?? ''),
            'basicSalary' => $basic,
            'allowances' => $allowances,
            'grossSalary' => $gross,
            'taxDeduction' => $tax,
            'nssfDeduction' => $nssf,
            'netSalary' => $net,
            'remarks' => (string) ($row['remarks'] ?? ''),
            'isPublished' => !empty($row['is_published']),
            'initials' => $name !== '' ? strtoupper(substr($name, 0, 1)) : '?',
        ];
    }

    $year = (int) ($run['year'] ?? 0);
    $month = (int) ($run['month'] ?? 0);
    $periodDate = sprintf('%04d-%02d-01', $year, $month);
    $runDate = (string) ($run['run_date'] ?? '');
    $status = (string) ($run['status'] ?? 'draft');

    $qs = payrollDeskQueryString(['id' => $runId]);

    return [
        'run' => [
            'id' => $runId,
            'year' => $year,
            'month' => $month,
            'periodLabel' => date('F Y', strtotime($periodDate)),
            'runDate' => $runDate,
            'runDateLabel' => $runDate !== '' ? date('d M Y', strtotime($runDate)) : '-',
            'status' => $status,
            'totalPayout' => (float) ($run['total_payout'] ?? 0),
            'isPublished' => !empty($run['is_published']),
        ],
        'slips' => $slips,
        'totals' => $totals,
        'user' => payrollDeskCurrentUser(),
        'can' => [
            'approve' => function_exists('isAdmin') && isAdmin() && $status === 'draft',
            'delete' => function_exists('isAdmin') && isAdmin() && $status === 'draft',
            'revert' => function_exists('isAdmin') && isAdmin() && $status === 'approved',
            'markPaid' => $status === 'approved',
            'sendAll' => function_exists('isAdmin') && isAdmin()
                && in_array($status, ['approved', 'paid'], true)
                && empty($run['is_published']),
            'editPayslip' => function_exists('isFinance') && isFinance() && $status === 'draft',
        ],
        'links' => [
            'payroll' => payrollDeskPublicUrl('index.php') . payrollDeskQueryString(),
            'runPayroll' => payrollDeskPublicUrl('run_payroll.php') . payrollDeskQueryString(),
            'exportExcel' => payrollDeskPublicUrl('export_run_xls.php') . $qs,
            'emailAll' => payrollDeskPublicUrl('email_run.php') . $qs,
            'payslipBase' => payrollDeskPublicUrl('payslip.php') . payrollDeskQueryString(),
            'editPayslipBase' => payrollDeskPublicUrl('edit_payslip.php') . payrollDeskQueryString(),
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function payrollDeskRunAction(PDO $pdo, int $runId, string $action, int $payslipId = 0): array
{
    if ($runId <= 0) {
        throw new RuntimeException('Run id is required.');
    }

    $stmt = $pdo->prepare('SELECT * FROM ' . payroll_table('payroll_runs') . ' WHERE id = ? LIMIT 1');
    $stmt->execute([$runId]);
    $run = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$run) {
        throw new RuntimeException('Payroll run not found.');
    }

    $action = trim($action);
    $message = 'Updated.';

    if ($action === 'approve') {
        if (!isAdmin()) {
            throw new RuntimeException('Unauthorized.');
        }
        $pdo->prepare('UPDATE ' . payroll_table('payroll_runs') . " SET status = 'approved' WHERE id = ?")->execute([$runId]);
        $message = 'Payroll run approved.';
    } elseif ($action === 'revert') {
        if (!isAdmin()) {
            throw new RuntimeException('Unauthorized.');
        }
        $pdo->prepare('UPDATE ' . payroll_table('payroll_runs') . " SET status = 'draft' WHERE id = ?")->execute([$runId]);
        $message = 'Payroll run reverted to draft.';
    } elseif ($action === 'mark_paid') {
        require_once __DIR__ . '/accounting.php';
        $schemaSetup = dirname(__DIR__, 3) . '/accounting/setup_schema.php';
        if (is_file($schemaSetup)) {
            require_once $schemaSetup;
        }
        if (function_exists('ensureAccountingSchema')) {
            ensureAccountingSchema();
        }

        $pdo->beginTransaction();
        try {
            $postResult = postPayrollToLedger($runId);
            if ($postResult !== true) {
                throw new RuntimeException('Accounting post failed: ' . (string) $postResult);
            }
            $pdo->prepare('UPDATE ' . payroll_table('payroll_runs') . " SET status = 'paid' WHERE id = ?")->execute([$runId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $message = 'Payroll run marked as paid.';
    } elseif ($action === 'delete') {
        if (!isAdmin()) {
            throw new RuntimeException('Unauthorized.');
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM ' . payroll_table('payslips') . ' WHERE payroll_run_id = ?')->execute([$runId]);
            $pdo->prepare('DELETE FROM ' . payroll_table('payroll_runs') . ' WHERE id = ?')->execute([$runId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('Could not delete payroll run.');
        }

        return [
            'deleted' => true,
            'redirect' => payrollDeskPublicUrl('index.php') . payrollDeskQueryString(['success' => 'deleted']),
            'message' => 'Draft payroll run deleted.',
        ];
    } elseif ($action === 'send_to_account') {
        if (!isAdmin()) {
            throw new RuntimeException('Unauthorized.');
        }
        $pdo->prepare('UPDATE ' . payroll_table('payroll_runs') . ' SET is_published = 1 WHERE id = ?')->execute([$runId]);
        $pdo->prepare('UPDATE ' . payroll_table('payslips') . ' SET is_published = 1 WHERE payroll_run_id = ?')->execute([$runId]);
        $message = 'Payroll slips have been sent to employee accounts.';
    } elseif ($action === 'send_single_to_account') {
        if (!isAdmin()) {
            throw new RuntimeException('Unauthorized.');
        }
        if ($payslipId <= 0) {
            throw new RuntimeException('Payslip id is required.');
        }
        $pdo->prepare('UPDATE ' . payroll_table('payslips') . ' SET is_published = 1 WHERE id = ? AND payroll_run_id = ?')
            ->execute([$payslipId, $runId]);
        $message = 'The payslip has been sent to the employee account.';
    } else {
        throw new RuntimeException('Unknown action.');
    }

    return [
        'deleted' => false,
        'message' => $message,
        'data' => payrollDeskGetRunPayload($pdo, $runId),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function payrollDeskListSalaries(PDO $pdo): array
{
    $query = '
        SELECT u.id, u.full_name, u.email, u.role, u.department,
               es.basic_salary, es.house_allowance, es.transport_allowance,
               es.bank_name, es.account_number, es.tin_number, es.nssf_number
        FROM users u
        LEFT JOIN ' . payroll_table('employee_salary') . ' es ON u.id = es.user_id
        WHERE u.is_active = 1 AND u.role = \'employee\'
        ORDER BY u.full_name ASC
    ';

    $rows = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    $employees = [];

    foreach ($rows as $row) {
        $basic = (float) ($row['basic_salary'] ?? 0);
        $house = (float) ($row['house_allowance'] ?? 0);
        $transport = (float) ($row['transport_allowance'] ?? 0);
        $allowances = $house + $transport;
        $name = (string) ($row['full_name'] ?? '');

        $employees[] = [
            'id' => (int) ($row['id'] ?? 0),
            'fullName' => $name,
            'email' => (string) ($row['email'] ?? ''),
            'department' => (string) ($row['department'] ?? ''),
            'basicSalary' => $basic,
            'houseAllowance' => $house,
            'transportAllowance' => $transport,
            'allowances' => $allowances,
            'grossPay' => $basic + $allowances,
            'bankName' => (string) ($row['bank_name'] ?? ''),
            'accountNumber' => (string) ($row['account_number'] ?? ''),
            'initials' => $name !== '' ? strtoupper(substr($name, 0, 1)) : '?',
            'hasSalary' => $basic > 0 || $allowances > 0 || !empty($row['bank_name']),
        ];
    }

    return $employees;
}

/**
 * @return array<string, mixed>
 */
function payrollDeskSalariesInitPayload(PDO $pdo): array
{
    $employees = payrollDeskListSalaries($pdo);
    $withSalary = 0;
    $totalGross = 0.0;
    foreach ($employees as $emp) {
        if ($emp['hasSalary']) {
            $withSalary++;
        }
        $totalGross += (float) $emp['grossPay'];
    }

    $totalEmployees = count($employees);

    return [
        'employees' => $employees,
        'stats' => [
            'total_employees' => $totalEmployees,
            'with_salary' => $withSalary,
            'without_salary' => max(0, $totalEmployees - $withSalary),
            'total_gross' => $totalGross,
        ],
        'links' => [
            'payroll' => payrollDeskPublicUrl('index.php') . payrollDeskQueryString(),
            'salaries' => payrollDeskPublicUrl('salaries.php') . payrollDeskQueryString(),
            'runPayroll' => payrollDeskPublicUrl('run_payroll.php') . payrollDeskQueryString(),
            'editSalaryBase' => payrollDeskPublicUrl('edit_salary.php') . payrollDeskQueryString(),
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function payrollDeskGetSalaryEmployee(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        throw new RuntimeException('Employee id is required.');
    }

    $stmt = $pdo->prepare('SELECT id, full_name, email, department FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$userId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) {
        throw new RuntimeException('Employee not found.');
    }

    $stmt = $pdo->prepare('SELECT * FROM ' . payroll_table('employee_salary') . ' WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $salary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $name = (string) ($employee['full_name'] ?? '');

    return [
        'employee' => [
            'id' => (int) $employee['id'],
            'fullName' => $name,
            'email' => (string) ($employee['email'] ?? ''),
            'department' => (string) ($employee['department'] ?? ''),
            'initials' => $name !== '' ? strtoupper(substr($name, 0, 1)) : '?',
        ],
        'salary' => [
            'basicSalary' => (float) ($salary['basic_salary'] ?? 0),
            'houseAllowance' => (float) ($salary['house_allowance'] ?? 0),
            'transportAllowance' => (float) ($salary['transport_allowance'] ?? 0),
            'bankName' => (string) ($salary['bank_name'] ?? ''),
            'accountNumber' => (string) ($salary['account_number'] ?? ''),
            'tinNumber' => (string) ($salary['tin_number'] ?? ''),
            'nssfNumber' => (string) ($salary['nssf_number'] ?? ''),
        ],
        'links' => [
            'salaries' => payrollDeskPublicUrl('salaries.php') . payrollDeskQueryString(),
        ],
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function payrollDeskSaveSalary(PDO $pdo, array $payload): void
{
    $userId = (int) ($payload['user_id'] ?? $payload['userId'] ?? 0);
    if ($userId <= 0) {
        payrollDeskJsonResponse(false, null, 'Employee id is required.', 422);
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$userId]);
    if (!$stmt->fetch()) {
        payrollDeskJsonResponse(false, null, 'Employee not found.', 404);
    }

    $basic = (float) ($payload['basic_salary'] ?? $payload['basicSalary'] ?? 0);
    $house = (float) ($payload['house_allowance'] ?? $payload['houseAllowance'] ?? 0);
    $transport = (float) ($payload['transport_allowance'] ?? $payload['transportAllowance'] ?? 0);
    $bank = trim((string) ($payload['bank_name'] ?? $payload['bankName'] ?? ''));
    $account = trim((string) ($payload['account_number'] ?? $payload['accountNumber'] ?? ''));
    $tin = trim((string) ($payload['tin_number'] ?? $payload['tinNumber'] ?? ''));
    $nssf = trim((string) ($payload['nssf_number'] ?? $payload['nssfNumber'] ?? ''));

    $stmt = $pdo->prepare('SELECT id FROM ' . payroll_table('employee_salary') . ' WHERE user_id = ?');
    $stmt->execute([$userId]);
    if ($stmt->fetch()) {
        $sql = 'UPDATE ' . payroll_table('employee_salary') . ' SET basic_salary=?, house_allowance=?, transport_allowance=?,
                bank_name=?, account_number=?, tin_number=?, nssf_number=? WHERE user_id=?';
    } else {
        $sql = 'INSERT INTO ' . payroll_table('employee_salary') . ' (basic_salary, house_allowance, transport_allowance,
                bank_name, account_number, tin_number, nssf_number, user_id) VALUES (?,?,?,?,?,?,?,?)';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$basic, $house, $transport, $bank, $account, $tin, $nssf, $userId]);

    payrollDeskJsonResponse(true, payrollDeskGetSalaryEmployee($pdo, $userId), 'Salary details updated.');
}

/**
 * Employee-facing published payslips for the logged-in user.
 *
 * @return array<string, mixed>
 */
function payrollDeskMyPayslipsPayload(PDO $pdo): array
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $slips = [];

    if ($userId > 0 && function_exists('payroll_table_exists')
        && payroll_table_exists('payslips')
        && payroll_table_exists('payroll_runs')) {
        $stmt = $pdo->prepare(
            'SELECT p.*, pr.month, pr.year, pr.run_date, pr.status AS run_status
             FROM ' . payroll_table('payslips') . ' p
             JOIN ' . payroll_table('payroll_runs') . ' pr ON p.payroll_run_id = pr.id
             WHERE p.user_id = ?
               AND pr.status IN (\'approved\', \'paid\')
               AND p.is_published = 1
             ORDER BY pr.year DESC, pr.month DESC, p.id DESC'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $year = (int) ($row['year'] ?? 0);
            $month = (int) ($row['month'] ?? 0);
            $periodDate = sprintf('%04d-%02d-01', $year, max(1, $month));
            $runStatus = (string) ($row['run_status'] ?? $row['status'] ?? 'approved');
            $slipStatus = strtolower((string) ($row['status'] ?? $runStatus));
            if ($slipStatus !== 'paid' && $runStatus === 'paid') {
                $slipStatus = 'paid';
            }

            $slips[] = [
                'id' => (int) ($row['id'] ?? 0),
                'idLabel' => '#' . str_pad((string) (int) ($row['id'] ?? 0), 5, '0', STR_PAD_LEFT),
                'year' => $year,
                'month' => $month,
                'periodLabel' => date('F Y', strtotime($periodDate)),
                'runDate' => (string) ($row['run_date'] ?? ''),
                'runDateLabel' => !empty($row['run_date']) ? date('d M, Y', strtotime((string) $row['run_date'])) : '',
                'basicSalary' => (float) ($row['basic_salary'] ?? 0),
                'netSalary' => (float) ($row['net_salary'] ?? 0),
                'status' => $slipStatus === 'paid' ? 'paid' : 'approved',
                'statusLabel' => $slipStatus === 'paid' ? 'Paid' : 'Approved',
            ];
        }
    }

    return [
        'user' => payrollDeskCurrentUser(),
        'stats' => [
            'total' => count($slips),
            'paid' => count(array_filter($slips, static fn(array $s): bool => ($s['status'] ?? '') === 'paid')),
            'latestNet' => $slips[0]['netSalary'] ?? 0,
            'latestPeriod' => $slips[0]['periodLabel'] ?? null,
        ],
        'payslips' => $slips,
        'links' => [
            'payslipBase' => payrollDeskPublicUrl('payslip.php') . payrollDeskQueryString(),
        ],
    ];
}

/**
 * @return array<string,mixed>
 */
function payrollDeskRunInitPayload(PDO $pdo): array
{
    $company = function_exists('getCurrentCompany') ? (getCurrentCompany() ?: []) : [];
    $dashboard = payrollDeskDashboardData($pdo);
    $now = new DateTimeImmutable('now');

    return [
        'company' => [
            'name' => (string) ($company['company_name'] ?? ($_SESSION['company_name'] ?? (defined('COMPANY_NAME') ? COMPANY_NAME : 'Company'))),
        ],
        'user' => payrollDeskCurrentUser(),
        'defaults' => [
            'month' => (int) $now->format('n'),
            'year' => (int) $now->format('Y'),
        ],
        'months' => array_map(static function (int $m): array {
            return [
                'value' => $m,
                'label' => date('F', mktime(0, 0, 0, $m, 1)),
            ];
        }, range(1, 12)),
        'stats' => [
            'totalSalariedStaff' => $dashboard['totalSalariedStaff'],
            'lastRun' => $dashboard['lastRun'],
        ],
        'missingTables' => $dashboard['missingTables'],
        'links' => [
            'dashboard' => payrollDeskPublicUrl('index.php') . payrollDeskQueryString(),
            'salaries' => payrollDeskPublicUrl('salaries.php') . payrollDeskQueryString(),
            'setup' => payrollDeskPublicUrl('setup.php') . payrollDeskQueryString(),
            'viewRunBase' => payrollDeskPublicUrl('view_run.php') . payrollDeskQueryString(),
            'modules' => function_exists('app_url') ? app_url('/select-module.php') : '/select-module.php',
        ],
    ];
}

/**
 * Generate a draft payroll run for the given period.
 *
 * @return array{runId:int,totalPayout:float,employeeCount:int,periodLabel:string,viewRunUrl:string}
 */
function payrollDeskGenerateRun(PDO $pdo, int $month, int $year, int $runByUserId): array
{
    if ($month < 1 || $month > 12) {
        throw new InvalidArgumentException('Invalid month.');
    }
    if ($year < 2000 || $year > 2100) {
        throw new InvalidArgumentException('Invalid year.');
    }
    if ($runByUserId <= 0) {
        throw new InvalidArgumentException('Invalid user.');
    }

    $required = ['employee_salary', 'payroll_runs', 'payslips', 'payroll_settings'];
    foreach ($required as $table) {
        if (!function_exists('payroll_table_exists') || !payroll_table_exists($table)) {
            throw new RuntimeException('Payroll setup required. Missing table: ' . $table);
        }
    }

    $stmt = $pdo->prepare('SELECT id FROM ' . payroll_table('payroll_runs') . ' WHERE month = ? AND year = ?');
    $stmt->execute([$month, $year]);
    if ($stmt->fetch()) {
        throw new RuntimeException('Payroll for this period already exists.');
    }

    $settings = [];
    $st = $pdo->query('SELECT * FROM ' . payroll_table('payroll_settings'));
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $settings[(string) $r['setting_key']] = $r['setting_value'];
    }

    $nssf_rate = floatval($settings['social_security_rate'] ?? 10) / 100;

    $active_rules = [];
    if (function_exists('payroll_table_exists') && payroll_table_exists('erp_payroll_settings')) {
        $active_rules = $pdo->query(
            'SELECT * FROM ' . payroll_table('erp_payroll_settings') . ' WHERE is_active = 1'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $tax_bands = [];
    if (function_exists('payroll_table_exists') && payroll_table_exists('payroll_tax_bands')) {
        $tax_bands = $pdo->query(
            'SELECT * FROM ' . payroll_table('payroll_tax_bands') . ' WHERE is_active = 1 ORDER BY min_salary ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $users = $pdo->query('
        SELECT u.id, es.basic_salary, es.house_allowance, es.transport_allowance, es.other_deductions, es.monthly_adjustment
        FROM users u
        JOIN ' . payroll_table('employee_salary') . ' es ON u.id = es.user_id
        WHERE u.is_active = 1
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($users === []) {
        throw new RuntimeException('No active employees with salary records found.');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO ' . payroll_table('payroll_runs')
            . " (month, year, run_date, run_by, status, total_payout) VALUES (?, ?, CURDATE(), ?, 'draft', 0)"
        );
        $stmt->execute([$month, $year, $runByUserId]);
        $runId = (int) $pdo->lastInsertId();

        $total_payout = 0.0;
        $insertSlip = $pdo->prepare(
            'INSERT INTO ' . payroll_table('payslips') . '
            (payroll_run_id, user_id, basic_salary, total_allowances, monthly_adjustment, gross_salary, tax_deduction, nssf_deduction, other_deductions, net_salary)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($users as $user) {
            $basic = floatval($user['basic_salary']);
            $allowances = floatval($user['house_allowance']) + floatval($user['transport_allowance']);

            $dynamic_allowances = 0.0;
            $dynamic_deductions = 0.0;

            foreach ($active_rules as $rule) {
                $val = !empty($rule['is_percentage'])
                    ? ($basic * (floatval($rule['value']) / 100))
                    : floatval($rule['value']);

                if (($rule['type'] ?? '') === 'allowance') {
                    $dynamic_allowances += $val;
                } elseif (($rule['type'] ?? '') === 'deduction') {
                    $dynamic_deductions += $val;
                }
            }

            $allowances += $dynamic_allowances;
            $adj = floatval($user['monthly_adjustment']);
            $gross = $basic + $allowances + $adj;

            $nssf = $gross * $nssf_rate;
            $taxable_income = $gross - $nssf;

            $tax = 0.0;
            foreach ($tax_bands as $band) {
                $max = $band['max_salary'] !== null ? floatval($band['max_salary']) : PHP_FLOAT_MAX;
                $min = floatval($band['min_salary']);

                if ($taxable_income >= $min && $taxable_income <= $max) {
                    $threshold = $min > 0 ? $min - 1 : 0;
                    $excess = $taxable_income - $threshold;
                    $rate = floatval($band['tax_rate']) / 100;
                    $tax = floatval($band['offset_amount']) + ($excess * $rate);
                    break;
                }
            }

            $other_deductions = floatval($user['other_deductions']) + $dynamic_deductions;
            $net = $gross - $tax - $nssf - $other_deductions;

            $insertSlip->execute([
                $runId,
                (int) $user['id'],
                $basic,
                $allowances,
                $adj,
                $gross,
                $tax,
                $nssf,
                $other_deductions,
                $net,
            ]);

            $total_payout += $net;
        }

        $pdo->prepare('UPDATE ' . payroll_table('payroll_runs') . ' SET total_payout = ? WHERE id = ?')
            ->execute([$total_payout, $runId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('Failed to generate payroll: ' . $e->getMessage(), 0, $e);
    }

    $periodDate = sprintf('%04d-%02d-01', $year, $month);
    $viewRunUrl = payrollDeskPublicUrl('view_run.php') . payrollDeskQueryString(['id' => $runId]);

    return [
        'runId' => $runId,
        'totalPayout' => $total_payout,
        'employeeCount' => count($users),
        'periodLabel' => date('F Y', strtotime($periodDate)),
        'viewRunUrl' => $viewRunUrl,
        'dashboardUrl' => payrollDeskPublicUrl('index.php') . payrollDeskQueryString(),
    ];
}

/**
 * @return array<string,mixed>
 */
function payrollDeskGetPayslipEditPayload(PDO $pdo, int $payslipId): array
{
    if ($payslipId <= 0) {
        throw new InvalidArgumentException('Invalid payslip id.');
    }
    if (!function_exists('isFinance') || !isFinance()) {
        throw new RuntimeException('Access denied: Only administrators and finance staff can edit payroll values.');
    }

    $stmt = $pdo->prepare(
        'SELECT p.*, pr.status AS run_status, pr.id AS run_id, u.full_name, u.id AS employee_user_id, pr.month, pr.year
         FROM ' . payroll_table('payslips') . ' p
         JOIN ' . payroll_table('payroll_runs') . ' pr ON p.payroll_run_id = pr.id
         JOIN users u ON p.user_id = u.id
         WHERE p.id = ?'
    );
    $stmt->execute([$payslipId]);
    $slip = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$slip) {
        throw new RuntimeException('Payslip not found.');
    }

    $runStatus = (string) ($slip['run_status'] ?? '');
    if ($runStatus !== 'draft') {
        throw new RuntimeException('Cannot edit a payslip in a non-draft run.');
    }

    $year = (int) ($slip['year'] ?? 0);
    $month = (int) ($slip['month'] ?? 0);
    $periodDate = sprintf('%04d-%02d-01', $year, max(1, $month));
    $runId = (int) ($slip['run_id'] ?? $slip['payroll_run_id'] ?? 0);

    return [
        'payslip' => [
            'id' => (int) ($slip['id'] ?? 0),
            'runId' => $runId,
            'userId' => (int) ($slip['employee_user_id'] ?? $slip['user_id'] ?? 0),
            'fullName' => (string) ($slip['full_name'] ?? ''),
            'periodLabel' => date('F Y', strtotime($periodDate)),
            'month' => $month,
            'year' => $year,
            'runStatus' => $runStatus,
            'basicSalary' => (float) ($slip['basic_salary'] ?? 0),
            'totalAllowances' => (float) ($slip['total_allowances'] ?? 0),
            'monthlyAdjustment' => (float) ($slip['monthly_adjustment'] ?? 0),
            'grossSalary' => (float) ($slip['gross_salary'] ?? 0),
            'nssfDeduction' => (float) ($slip['nssf_deduction'] ?? 0),
            'taxDeduction' => (float) ($slip['tax_deduction'] ?? 0),
            'otherDeductions' => (float) ($slip['other_deductions'] ?? 0),
            'netSalary' => (float) ($slip['net_salary'] ?? 0),
            'remarks' => (string) ($slip['remarks'] ?? ''),
        ],
        'links' => [
            'dashboard' => payrollDeskPublicUrl('index.php') . payrollDeskQueryString(),
            'viewRun' => payrollDeskPublicUrl('view_run.php') . payrollDeskQueryString(['id' => $runId]),
            'editPayslipBase' => payrollDeskPublicUrl('edit_payslip.php') . payrollDeskQueryString(),
        ],
    ];
}

/**
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function payrollDeskSavePayslip(PDO $pdo, int $payslipId, array $payload): array
{
    $current = payrollDeskGetPayslipEditPayload($pdo, $payslipId);
    $runId = (int) ($current['payslip']['runId'] ?? 0);

    $basic = (float) ($payload['basic_salary'] ?? $payload['basicSalary'] ?? 0);
    $allowances = (float) ($payload['total_allowances'] ?? $payload['totalAllowances'] ?? 0);
    $adjustment = (float) ($payload['monthly_adjustment'] ?? $payload['monthlyAdjustment'] ?? 0);
    $nssf = (float) ($payload['nssf_deduction'] ?? $payload['nssfDeduction'] ?? 0);
    $tax = (float) ($payload['tax_deduction'] ?? $payload['taxDeduction'] ?? 0);
    $other = (float) ($payload['other_deductions'] ?? $payload['otherDeductions'] ?? 0);
    $remarks = trim((string) ($payload['remarks'] ?? ''));

    $gross = $basic + $allowances + $adjustment;
    $net = $gross - $nssf - $tax - $other;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'UPDATE ' . payroll_table('payslips') . '
             SET basic_salary = ?, total_allowances = ?, monthly_adjustment = ?,
                 gross_salary = ?, nssf_deduction = ?, tax_deduction = ?,
                 other_deductions = ?, net_salary = ?, remarks = ?
             WHERE id = ?'
        );
        $stmt->execute([$basic, $allowances, $adjustment, $gross, $nssf, $tax, $other, $net, $remarks, $payslipId]);

        $stmt = $pdo->prepare(
            'UPDATE ' . payroll_table('payroll_runs') . '
             SET total_payout = (SELECT SUM(net_salary) FROM ' . payroll_table('payslips') . ' WHERE payroll_run_id = ?)
             WHERE id = ?'
        );
        $stmt->execute([$runId, $runId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('Update failed: ' . $e->getMessage(), 0, $e);
    }

    return payrollDeskGetPayslipEditPayload($pdo, $payslipId);
}

/**
 * @param array<string, mixed> $extraWindowVars
 */
function payrollDeskRenderReactEntry(string $pageTitle, string $headerTitle, string $payrollPage, array $extraWindowVars = []): void
{
    $assets = payrollDeskLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Payroll</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Payroll</h1>';
        echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/payroll/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    $page_title = $pageTitle;
    $employeeHeaderTitle = $headerTitle;
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--pay-desk';
    $bodyExtraClass = 'page-pay-desk';

    $windowScript = 'window.__PAYROLL_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES)
        . ';window.__PAYROLL_PAGE__ = ' . json_encode($payrollPage, JSON_UNESCAPED_SLASHES);
    foreach ($extraWindowVars as $key => $value) {
        $windowScript .= ';window.' . $key . ' = ' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $payrollHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
        . "\n" . '<script>' . $windowScript . ';</script>';

    require __DIR__ . '/payroll-react-shell.php';
    exit;
}

/**
 * @return array{settings:array<string,string>,taxBands:list<array<string,mixed>>,rules:list<array<string,mixed>>,links:array<string,string>}
 */
function payrollDeskGetSettingsPayload(PDO $pdo): array
{
    if (!payroll_table_exists('payroll_settings') || !payroll_table_exists('payroll_tax_bands')) {
        throw new RuntimeException('Payroll settings tables are missing. Run setup first.');
    }

    payrollDeskEnsureTaxBandDescriptionColumn($pdo);

    $settings = [
        'payDay' => '30',
        'socialSecurityRate' => '10',
        'taxRate' => '0',
    ];
    $st = $pdo->query('SELECT * FROM ' . payroll_table('payroll_settings'));
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $key = trim((string) ($row['setting_key'] ?? ''));
        $val = $row['setting_value'] ?? null;
        if ($key === '' || $val === null || $val === '') {
            $key = trim((string) ($row['meta_key'] ?? $key));
            $val = $row['meta_value'] ?? $val;
        }
        $key = (string) $key;
        $val = (string) ($val ?? '');
        if ($key === 'pay_day') {
            $settings['payDay'] = $val;
        } elseif ($key === 'social_security_rate' || $key === 'nssf_rate') {
            // Legacy nssf_rate may be stored as a fraction (0.05); UI expects percent.
            if ($key === 'nssf_rate' && is_numeric($val) && (float) $val > 0 && (float) $val < 1) {
                $val = (string) ((float) $val * 100);
            }
            if ($key === 'social_security_rate' || $settings['socialSecurityRate'] === '10') {
                $settings['socialSecurityRate'] = $val;
            }
        } elseif ($key === 'tax_rate') {
            $settings['taxRate'] = $val;
        }
    }

    $taxBands = [];
    $bands = $pdo->query('SELECT * FROM ' . payroll_table('payroll_tax_bands') . ' ORDER BY min_salary ASC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($bands as $band) {
        $taxBands[] = payrollDeskNormalizeTaxBand($band);
    }

    $rules = [];
    if (payroll_table_exists('erp_payroll_settings')) {
        $ruleRows = $pdo->query('SELECT * FROM ' . payroll_table('erp_payroll_settings') . ' ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ruleRows as $rule) {
            $rules[] = payrollDeskNormalizeRule($rule);
        }
    }

    return [
        'settings' => $settings,
        'taxBands' => $taxBands,
        'rules' => $rules,
        'links' => [
            'dashboard' => payrollDeskPublicUrl('index.php') . payrollDeskQueryString(),
            'help' => payrollDeskPublicUrl('help.php') . payrollDeskQueryString(),
            'setup' => payrollDeskPublicUrl('setup.php') . payrollDeskQueryString(),
        ],
    ];
}

function payrollDeskEnsureTaxBandDescriptionColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM ' . payroll_table('payroll_tax_bands'))->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('description', $cols, true)) {
            $pdo->exec(
                'ALTER TABLE ' . payroll_table('payroll_tax_bands')
                . ' ADD COLUMN `description` varchar(255) DEFAULT NULL AFTER `offset_amount`'
            );
        }
    } catch (Throwable $e) {
        // Keep going; description will fall back to generated text.
    }
}

/**
 * @param array<string,mixed> $band
 */
function payrollDeskBuildTaxBandDescription(array $band): string
{
    $min = (float) ($band['min_salary'] ?? $band['minSalary'] ?? 0);
    $rate = (float) ($band['tax_rate'] ?? $band['taxRate'] ?? 0);
    $offset = (float) ($band['offset_amount'] ?? $band['offsetAmount'] ?? 0);

    if ($rate == 0.0) {
        return '0% (No tax)';
    }

    $base = $offset > 0 ? number_format($offset) . ' + ' : '';

    return $base . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '% of amount above ' . number_format($min);
}

/**
 * @param array<string,mixed> $band
 * @return array<string,mixed>
 */
function payrollDeskNormalizeTaxBand(array $band): array
{
    $min = (float) ($band['min_salary'] ?? 0);
    $maxRaw = $band['max_salary'] ?? null;
    $max = $maxRaw === null || $maxRaw === '' ? null : (float) $maxRaw;
    $rate = (float) ($band['tax_rate'] ?? 0);
    $offset = (float) ($band['offset_amount'] ?? 0);
    $active = (int) ($band['is_active'] ?? 1) === 1;
    $storedDescription = trim((string) ($band['description'] ?? ''));
    $description = $storedDescription !== '' ? $storedDescription : payrollDeskBuildTaxBandDescription([
        'min_salary' => $min,
        'tax_rate' => $rate,
        'offset_amount' => $offset,
    ]);

    if ($max === null) {
        $rangeLabel = 'Above ' . number_format($min);
    } else {
        $rangeLabel = number_format($min) . ' - ' . number_format($max);
    }

    return [
        'id' => (int) ($band['id'] ?? 0),
        'minSalary' => $min,
        'maxSalary' => $max,
        'taxRate' => $rate,
        'offsetAmount' => $offset,
        'isActive' => $active,
        'rangeLabel' => $rangeLabel,
        'description' => $description,
    ];
}

/**
 * @param array<string,mixed> $rule
 * @return array<string,mixed>
 */
function payrollDeskNormalizeRule(array $rule): array
{
    return [
        'id' => (int) ($rule['id'] ?? 0),
        'name' => (string) ($rule['name'] ?? ''),
        'type' => (string) ($rule['type'] ?? 'deduction'),
        'value' => (float) ($rule['value'] ?? 0),
        'isPercentage' => (int) ($rule['is_percentage'] ?? 0) === 1,
        'isActive' => (int) ($rule['is_active'] ?? 0) === 1,
    ];
}

/**
 * @param array<string,mixed> $payload
 * @return array{message:string,data:array<string,mixed>}
 */
function payrollDeskSaveGlobalSettings(PDO $pdo, array $payload): array
{
    $map = [
        'pay_day' => (string) ($payload['payDay'] ?? $payload['pay_day'] ?? '30'),
        'social_security_rate' => (string) ($payload['socialSecurityRate'] ?? $payload['social_security_rate'] ?? '10'),
        'tax_rate' => (string) ($payload['taxRate'] ?? $payload['tax_rate'] ?? '0'),
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO ' . payroll_table('payroll_settings') . ' (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($map as $key => $value) {
        $stmt->execute([$key, $value]);
    }

    return [
        'message' => 'Settings saved.',
        'data' => payrollDeskGetSettingsPayload($pdo),
    ];
}

/**
 * @param array<string,mixed> $payload
 * @return array{message:string,data:array<string,mixed>}
 */
function payrollDeskSaveTaxBand(PDO $pdo, array $payload): array
{
    payrollDeskEnsureTaxBandDescriptionColumn($pdo);

    $id = (int) ($payload['id'] ?? 0);
    $min = (float) ($payload['minSalary'] ?? $payload['min_salary'] ?? 0);
    $maxRaw = $payload['maxSalary'] ?? $payload['max_salary'] ?? null;
    $max = ($maxRaw === null || $maxRaw === '') ? null : (float) $maxRaw;
    $rate = (float) ($payload['taxRate'] ?? $payload['tax_rate'] ?? 0);
    $offset = (float) ($payload['offsetAmount'] ?? $payload['offset_amount'] ?? 0);
    $active = !empty($payload['isActive'] ?? $payload['is_active'] ?? true) ? 1 : 0;
    $description = trim((string) ($payload['description'] ?? ''));
    if ($description === '') {
        $description = payrollDeskBuildTaxBandDescription([
            'min_salary' => $min,
            'tax_rate' => $rate,
            'offset_amount' => $offset,
        ]);
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE ' . payroll_table('payroll_tax_bands')
            . ' SET min_salary = ?, max_salary = ?, tax_rate = ?, offset_amount = ?, description = ?, is_active = ? WHERE id = ?'
        );
        $stmt->execute([$min, $max, $rate, $offset, $description, $active, $id]);
        $message = 'Tax band updated.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO ' . payroll_table('payroll_tax_bands')
            . ' (min_salary, max_salary, tax_rate, offset_amount, description, is_active) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$min, $max, $rate, $offset, $description, $active]);
        $message = 'Tax band added.';
    }

    return [
        'message' => $message,
        'data' => payrollDeskGetSettingsPayload($pdo),
    ];
}

function payrollDeskDeleteTaxBand(PDO $pdo, int $id): array
{
    if ($id <= 0) {
        throw new InvalidArgumentException('Invalid tax band id.');
    }
    $stmt = $pdo->prepare('DELETE FROM ' . payroll_table('payroll_tax_bands') . ' WHERE id = ?');
    $stmt->execute([$id]);

    return [
        'message' => 'Tax band deleted.',
        'data' => payrollDeskGetSettingsPayload($pdo),
    ];
}

/**
 * @param array<string,mixed> $payload
 * @return array{message:string,data:array<string,mixed>}
 */
function payrollDeskSaveRule(PDO $pdo, array $payload): array
{
    if (!payroll_table_exists('erp_payroll_settings')) {
        throw new RuntimeException('Custom rules table is missing.');
    }

    $id = (int) ($payload['id'] ?? 0);
    $name = trim((string) ($payload['name'] ?? ''));
    $type = strtolower(trim((string) ($payload['type'] ?? 'deduction')));
    $value = (float) ($payload['value'] ?? 0);
    $isPct = !empty($payload['isPercentage'] ?? $payload['is_percentage'] ?? false) ? 1 : 0;
    $active = !empty($payload['isActive'] ?? $payload['is_active'] ?? true) ? 1 : 0;

    if ($name === '') {
        throw new InvalidArgumentException('Rule name is required.');
    }
    if (!in_array($type, ['allowance', 'deduction'], true)) {
        $type = 'deduction';
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE ' . payroll_table('erp_payroll_settings')
            . ' SET name = ?, value = ?, is_percentage = ?, type = ?, is_active = ? WHERE id = ?'
        );
        $stmt->execute([$name, $value, $isPct, $type, $active, $id]);
        $message = 'Rule updated.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO ' . payroll_table('erp_payroll_settings')
            . ' (name, value, is_percentage, type, is_active) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $value, $isPct, $type, $active]);
        $message = 'Rule added.';
    }

    return [
        'message' => $message,
        'data' => payrollDeskGetSettingsPayload($pdo),
    ];
}

function payrollDeskDeleteRule(PDO $pdo, int $id): array
{
    if (!payroll_table_exists('erp_payroll_settings')) {
        throw new RuntimeException('Custom rules table is missing.');
    }
    if ($id <= 0) {
        throw new InvalidArgumentException('Invalid rule id.');
    }
    $stmt = $pdo->prepare('DELETE FROM ' . payroll_table('erp_payroll_settings') . ' WHERE id = ?');
    $stmt->execute([$id]);

    return [
        'message' => 'Rule deleted.',
        'data' => payrollDeskGetSettingsPayload($pdo),
    ];
}
