<?php
// modules/payroll/edit_payslip.php
require_once __DIR__ . '/config/database.php';
requireFinanceOrAdmin();

if (!isset($_GET['id']) || (int) $_GET['id'] <= 0) {
    header('Location: index.php');
    exit;
}

$payslip_id = (int) $_GET['id'];

// Fetch current payslip data
$stmt = $pdo->prepare('
    SELECT p.*, pr.status as run_status, u.full_name, pr.month, pr.year
    FROM ' . payroll_table('payslips') . ' p
    JOIN ' . payroll_table('payroll_runs') . ' pr ON p.payroll_run_id = pr.id
    JOIN users u ON p.user_id = u.id
    WHERE p.id = ?
');
$stmt->execute([$payslip_id]);
$slip = $stmt->fetch();

if (!$slip) {
    die("Payslip not found.");
}

if ($slip['run_status'] !== 'draft') {
    die("Cannot edit a payslip in a non-draft run.");
}

if (!isFinance()) {
    die("Access denied: Only administrators and finance staff can edit payroll values.");
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $basic = floatval($_POST['basic_salary']);
    $allowances = floatval($_POST['total_allowances']);
    $adjustment = floatval($_POST['monthly_adjustment']);
    $nssf = floatval($_POST['nssf_deduction']);
    $tax = floatval($_POST['tax_deduction']);
    $other = floatval($_POST['other_deductions']);
    $remarks = $_POST['remarks'] ?? '';
    
    $gross = $basic + $allowances + $adjustment;
    $net = $gross - $nssf - $tax - $other;

    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare('
            UPDATE ' . payroll_table('payslips') . ' 
            SET basic_salary = ?, total_allowances = ?, monthly_adjustment = ?, 
                gross_salary = ?, nssf_deduction = ?, tax_deduction = ?, 
                other_deductions = ?, net_salary = ?, remarks = ?
            WHERE id = ?
        ');
        $stmt->execute([$basic, $allowances, $adjustment, $gross, $nssf, $tax, $other, $net, $remarks, $payslip_id]);

        // Recalculate run total
        $stmt = $pdo->prepare('
            UPDATE ' . payroll_table('payroll_runs') . ' 
            SET total_payout = (SELECT SUM(net_salary) FROM ' . payroll_table('payslips') . ' WHERE payroll_run_id = ?)
            WHERE id = ?
        ');
        $stmt->execute([$slip['payroll_run_id'], $slip['payroll_run_id']]);

        $pdo->commit();
        $success = "Payslip updated successfully.";
        
        // Refresh data
        $stmt = $pdo->prepare('SELECT p.*, pr.status as run_status, u.full_name, pr.month, pr.year FROM ' . payroll_table('payslips') . ' p JOIN ' . payroll_table('payroll_runs') . ' pr ON p.payroll_run_id = pr.id JOIN users u ON p.user_id = u.id WHERE p.id = ?');
        $stmt->execute([$payslip_id]);
        $slip = $stmt->fetch();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Update failed: " . $e->getMessage();
    }
}

$period = date('F Y', strtotime($slip['year'] . '-' . $slip['month'] . '-01'));
$page_title = 'Edit Payslip - ' . $slip['full_name'];

include __DIR__ . '/includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { corePlugins: { preflight: false } };
</script>
<style>
    .pay-shell { font-family: 'Outfit', sans-serif; font-size: 16px; color: #374151; }
    .form-card { background: #fff; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.05); padding: 2rem; }
    .form-label { font-weight: 600; font-size: 0.9rem; color: #4b5563; }
    .section-title { font-size: 1.1rem; font-weight: 700; color: #111827; border-bottom: 1px solid #e5e7eb; padding-bottom: 0.5rem; margin-bottom: 1.5rem; }
    .net-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 1rem; }
</style>

<main class="main-content pay-shell bg-[#F9F9F9] min-h-[50vh] pb-8">
    <div class="max-w-4xl mx-auto px-4 pt-6">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="index.php">Payroll</a></li>
                        <li class="breadcrumb-item"><a href="view_run.php?id=<?= $slip['payroll_run_id'] ?>">Run Details</a></li>
                        <li class="breadcrumb-item active">Edit Payslip</li>
                    </ol>
                </nav>
                <h1 class="h3 font-bold text-gray-900 m-0"><?= htmlspecialchars($slip['full_name']) ?></h1>
                <p class="text-muted m-0"><?= htmlspecialchars($period) ?></p>
            </div>
            <a href="view_run.php?id=<?= $slip['payroll_run_id'] ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger border-0 shadow-sm mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="alert alert-success border-0 shadow-sm mb-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST" id="editForm">
                <h4 class="section-title">Earnings</h4>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label">Basic Salary</label>
                        <input type="number" step="0.01" class="form-control" name="basic_salary" value="<?= $slip['basic_salary'] ?>" oninput="calcNet()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Allowances</label>
                        <input type="number" step="0.01" class="form-control" name="total_allowances" value="<?= $slip['total_allowances'] ?>" oninput="calcNet()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Adjustments</label>
                        <input type="number" step="0.01" class="form-control" name="monthly_adjustment" value="<?= $slip['monthly_adjustment'] ?>" oninput="calcNet()">
                    </div>
                </div>

                <h4 class="section-title">Deductions</h4>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label">NSSF (Employee)</label>
                        <input type="number" step="0.01" class="form-control" name="nssf_deduction" value="<?= $slip['nssf_deduction'] ?>" oninput="calcNet()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">PAYE (Tax)</label>
                        <input type="number" step="0.01" class="form-control" name="tax_deduction" value="<?= $slip['tax_deduction'] ?>" oninput="calcNet()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Other Deductions</label>
                        <input type="number" step="0.01" class="form-control" name="other_deductions" value="<?= $slip['other_deductions'] ?>" oninput="calcNet()">
                    </div>
                </div>

                <h4 class="section-title">Additional Info</h4>
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <label class="form-label">Remarks / Notes</label>
                        <textarea class="form-control" name="remarks" rows="3" placeholder="Add any special notes or reasons for adjustments..."><?= htmlspecialchars($slip['remarks'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="row items-center mt-6">
                    <div class="col-md-6">
                        <div class="net-box">
                            <div class="small text-green-700 font-semibold uppercase">Estimated Net Salary</div>
                            <div class="h3 font-bold text-green-900 m-0">TZS <span id="netDisplay"><?= number_format($slip['net_salary'], 2) ?></span></div>
                        </div>
                    </div>
                    <div class="col-md-6 text-end">
                        <button type="submit" class="btn btn-primary px-5 py-2 font-semibold">
                            <i class="bi bi-save me-1"></i> Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
    function calcNet() {
        const basic = parseFloat(document.getElementsByName('basic_salary')[0].value || 0);
        const allowances = parseFloat(document.getElementsByName('total_allowances')[0].value || 0);
        const adj = parseFloat(document.getElementsByName('monthly_adjustment')[0].value || 0);
        const nssf = parseFloat(document.getElementsByName('nssf_deduction')[0].value || 0);
        const tax = parseFloat(document.getElementsByName('tax_deduction')[0].value || 0);
        const other = parseFloat(document.getElementsByName('other_deductions')[0].value || 0);

        const gross = basic + allowances + adj;
        const net = gross - nssf - tax - other;
        
        document.getElementById('netDisplay').textContent = net.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
