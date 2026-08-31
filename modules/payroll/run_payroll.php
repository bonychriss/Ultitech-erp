<?php
require_once __DIR__ . '/config/database.php';
requireFinanceOrAdmin();

$current_user_id = (int) ($_SESSION['user_id'] ?? 0);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $month = (int) $_POST['month'];
    $year = (int) $_POST['year'];

    $stmt = $pdo->prepare('SELECT id FROM ' . payroll_table('payroll_runs') . ' WHERE month = ? AND year = ?');
    $stmt->execute([$month, $year]);
    if ($stmt->fetch()) {
        $error = 'Payroll for this period already exists.';
    } else {
        $settings = [];
        $st = $pdo->query('SELECT * FROM ' . payroll_table('payroll_settings'));
        while ($r = $st->fetch()) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }

        $nssf_rate = floatval($settings['social_security_rate'] ?? 10) / 100;
        $tax_rate = floatval($settings['tax_rate'] ?? 0) / 100;

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO ' . payroll_table('payroll_runs') . " (month, year, run_date, run_by, status, total_payout) VALUES (?, ?, CURDATE(), ?, 'draft', 0)");
            $stmt->execute([$month, $year, $current_user_id]);
            $run_id = $pdo->lastInsertId();

            $users = $pdo->query('
                SELECT u.id, es.basic_salary, es.house_allowance, es.transport_allowance, es.other_deductions, es.monthly_adjustment
                FROM users u
                JOIN ' . payroll_table('employee_salary') . ' es ON u.id = es.user_id
                WHERE u.is_active = 1
            ')->fetchAll();

            $total_payout = 0;

            $active_rules = $pdo->query('SELECT * FROM ' . payroll_table('erp_payroll_settings') . ' WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);

            $tax_bands = $pdo->query('SELECT * FROM ' . payroll_table('payroll_tax_bands') . ' WHERE is_active = 1 ORDER BY min_salary ASC')->fetchAll(PDO::FETCH_ASSOC);

            foreach ($users as $user) {
                $basic = floatval($user['basic_salary']);
                $allowances = floatval($user['house_allowance']) + floatval($user['transport_allowance']);

                $dynamic_allowances = 0;
                $dynamic_deductions = 0;

                foreach ($active_rules as $rule) {
                    $val = ($rule['is_percentage']) ? ($basic * ($rule['value'] / 100)) : $rule['value'];

                    if ($rule['type'] === 'allowance') {
                        $dynamic_allowances += $val;
                    } elseif ($rule['type'] === 'deduction') {
                        $dynamic_deductions += $val;
                    }
                }

                $allowances += $dynamic_allowances;

                $adj = floatval($user['monthly_adjustment']);
                $gross = $basic + $allowances + $adj;

                $nssf = $gross * $nssf_rate;
                $taxable_income = $gross - $nssf;

                $tax = 0;
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

                $stmt = $pdo->prepare('INSERT INTO ' . payroll_table('payslips') . '
                    (payroll_run_id, user_id, basic_salary, total_allowances, monthly_adjustment, gross_salary, tax_deduction, nssf_deduction, other_deductions, net_salary)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$run_id, $user['id'], $basic, $allowances, $adj, $gross, $tax, $nssf, $other_deductions, $net]);

                $total_payout += $net;
            }

            $pdo->prepare('UPDATE ' . payroll_table('payroll_runs') . ' SET total_payout = ? WHERE id = ?')->execute([$total_payout, $run_id]);

            $pdo->commit();
            $success = 'Payroll generated successfully! Total payout: TSh ' . number_format($total_payout, 2);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to generate payroll: ' . $e->getMessage();
        }
    }
}

$payrollQs = function (array $extra = []) {
    return '?' . http_build_query(array_merge($_GET ?: [], $extra));
};

$page_title = 'Run payroll';

include __DIR__ . '/includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .pay-shell {
        font-family: 'Outfit', system-ui, -apple-system, sans-serif;
        font-size: 16px;
        color: #374151;
    }
    .dash-card {
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        overflow: hidden;
    }
</style>

<main class="main-content pay-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-full mx-auto px-0">
        <div class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-sm">
            <div class="px-4 py-3 flex flex-wrap items-center gap-2 sm:gap-3 border-b border-gray-100">
                <h1 class="text-xl font-bold text-gray-900 truncate m-0 inline-flex items-center gap-2">
                    <i class="fas fa-play-circle text-[#2563EB]"></i><span>Run payroll</span>
                </h1>
                <div class="flex-1 min-w-[8px]"></div>
                <a href="index.php<?= htmlspecialchars($payrollQs()) ?>" class="btn btn-outline-secondary btn-sm rounded-2">
                    <i class="bi bi-arrow-left me-1"></i><span class="d-none d-sm-inline">Dashboard</span>
                </a>
                <a href="<?= htmlspecialchars(app_url('/select-module.php')) ?>" class="text-base font-medium text-gray-600 hover:text-[#2563EB] border border-gray-200 rounded-md px-3 py-2 bg-white inline-flex items-center gap-2 no-underline">
                    <i class="fas fa-th-large text-sm"></i> Modules
                </a>
            </div>
            <div class="px-4 py-2 flex flex-wrap items-center gap-2 text-sm bg-gray-50/80 border-b border-gray-100 text-gray-600">
                <span><i class="fas fa-calendar text-gray-400 me-1"></i><?php echo date('l, d M Y'); ?></span>
                <span class="text-gray-300 hidden sm:inline">|</span>
                <span>Generate draft payroll for a period<?php if (defined('COMPANY_NAME')): ?> · <?= htmlspecialchars(COMPANY_NAME); ?><?php endif; ?></span>
            </div>
        </div>

        <div class="px-4 pt-4 pb-3">
            <div class="row justify-content-center">
                <div class="col-12 col-lg-8 col-xl-6">
                    <div class="dash-card">
                        <div class="px-4 py-3 border-bottom border-gray-100 fw-bold text-gray-800">
                            <i class="fas fa-calculator text-[#2563EB] me-2"></i>Generate monthly payroll
                        </div>
                        <div class="p-4 p-md-4">
                            <?php if ($error !== ''): ?>
                                <div class="alert alert-danger border-0 shadow-sm"><?= htmlspecialchars($error) ?></div>
                            <?php endif; ?>
                            <?php if ($success !== ''): ?>
                                <div class="alert alert-success border-0 shadow-sm">
                                    <?= htmlspecialchars($success) ?>
                                    <div class="mt-2">
                                        <a href="index.php<?= htmlspecialchars($payrollQs()) ?>" class="btn btn-success btn-sm rounded-2">Go to payroll</a>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <form method="POST">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-secondary">Month</label>
                                        <select name="month" class="form-select" required>
                                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                                <option value="<?= $m ?>" <?= (int) date('n') === $m ? 'selected' : '' ?>>
                                                    <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-secondary">Year</label>
                                        <input type="number" name="year" class="form-control" value="<?= (int) date('Y') ?>" required min="2000" max="2100">
                                    </div>
                                    <div class="col-12 mt-2">
                                        <div class="alert alert-info small border-0 mb-3 py-2">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Calculates salaries for all active employees with salary records using current tax and settings.
                                        </div>
                                        <button type="submit" class="btn btn-primary w-100 rounded-2 py-2 fw-semibold">
                                            <i class="bi bi-lightning-charge me-1"></i> Generate draft payroll
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
