<?php
// modules/payroll/email_run.php
require_once __DIR__ . '/config/database.php';
require_once '../../includes/mailer.php';

// Strict Access Control
requireFinanceOrAdmin();

if (!isset($_GET['id'])) {
    die("Run ID is required.");
}

$run_id = intval($_GET['id']);

// Fetch Run Header
$stmt = $pdo->prepare('SELECT * FROM ' . payroll_table('payroll_runs') . ' WHERE id = ?');
$stmt->execute([$run_id]);
$run = $stmt->fetch();

if (!$run) {
    die("Payroll run not found.");
}

// Handle AJAX Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'get_recipients') {
        // Fetch eligible payslips
        // Fetch Payslips with User Emails
        $stmt = $pdo->prepare("
            SELECT p.id, u.full_name, u.email
            FROM " . payroll_table('payslips') . " p
            JOIN users u ON p.user_id = u.id
            WHERE p.payroll_run_id = ? AND u.email IS NOT NULL AND u.email != ''
        ");
        $stmt->execute([$run_id]);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'recipients' => $recipients]);
        exit;
    }

    if ($_POST['action'] === 'send_single') {
        $payslip_id = intval($_POST['payslip_id']);
        
        // Fetch specific payslip data
        $stmt = $pdo->prepare("
            SELECT p.*, u.full_name, u.email, u.role, u.department,
                   es.bank_name, es.account_number, es.nssf_number, es.tin_number,
                   runner.full_name as runner_name, runner.role as runner_role
            FROM " . payroll_table('payslips') . " p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN " . payroll_table('employee_salary') . " es ON u.id = es.user_id
            LEFT JOIN " . payroll_table('payroll_runs') . " pr ON p.payroll_run_id = pr.id
            LEFT JOIN users runner ON pr.run_by = runner.id
            WHERE p.id = ?
        ");
        $stmt->execute([$payslip_id]);
        $slip = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$slip || empty($slip['email'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid payslip or no email']);
            exit;
        }

        // --- EMAIL GENERATION LOGIC ---
        $month_year = date('F Y', mktime(0, 0, 0, $run['month'], 1, $run['year']));
        $company_name = defined('COMPANY_NAME') ? COMPANY_NAME : 'ERP System';
        $company_address = defined('COMPANY_ADDRESS') ? COMPANY_ADDRESS : 'Plot 123, Standard Street';
        $company_phone = defined('COMPANY_PHONE') ? COMPANY_PHONE : '+255 000 000 000';
        $company_email = defined('COMPANY_EMAIL') ? COMPANY_EMAIL : 'payroll@example.com';

        // Construct Base URL for images
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $baseUrl = $protocol . $_SERVER['HTTP_HOST'] . dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))); 
        
        $subject = "Payslip for " . $month_year . " - " . $company_name;
        
        // Formatting numbers
        $basic = number_format($slip['basic_salary'], 2);
        $allowances = number_format($slip['total_allowances'], 2);
        $gross = number_format($slip['gross_salary'], 2);
        $nssf = number_format($slip['nssf_deduction'], 2);
        $tax = number_format($slip['tax_deduction'], 2);
        $other = number_format($slip['other_deductions'], 2);
        $net = number_format($slip['net_salary'], 2);
        $total_deductions = number_format($slip['nssf_deduction'] + $slip['tax_deduction'] + $slip['other_deductions'], 2);
        $period = $month_year; // Reuse

        // Signature Path
        $sigImg = '';
        $sigPath = getUserSignaturePathById($run['run_by']);
        if ($sigPath && file_exists('../../' . $sigPath)) {
            $sigUrl = $baseUrl . '/' . ltrim($sigPath, '/'); // Absolute URL
            $sigImg = "<img src='$sigUrl' alt='Signature' style='max-height: 80px; max-width: 100%; object-fit: contain;'>";
        }

        $logoUrl = $baseUrl . '/assets/images/Untitled.jpg';

        // HTML Body
        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Inter:wght@400;500;600&display=swap');
            </style>
        </head>
        <body style='background-color: #525659; font-family: sans-serif; margin: 0; padding: 20px;'>
            <div style='background-color: #f4f1ea; max-width: 800px; margin: 0 auto; padding: 40px; box-shadow: 0 0 10px rgba(0,0,0,0.1); color: #1a1a1a;'>
                
                <!-- Header -->
                <table style='width: 100%; border-collapse: collapse; margin-bottom: 30px;'>
                    <tr>
                        <td style='vertical-align: top;'>
                            <h1 style='font-family: \"Playfair Display\", serif; font-size: 36px; font-weight: 700; margin: 0; line-height: 1.1; color: #1a1a1a;'>Payslip</h1>
                            <div style='font-size: 13px; color: #666; margin-top: 5px;'>No. " . str_pad($slip['payroll_run_id'], 3, '0', STR_PAD_LEFT) . "-" . str_pad($slip['id'], 5, '0', STR_PAD_LEFT) . "</div>
                        </td>
                        <td style='text-align: right; vertical-align: top;'>
                            <img src='$logoUrl' alt='Logo' style='height: 50px; width: auto; margin-bottom: 5px;'>
                            <div style='font-family: \"Playfair Display\", serif; font-size: 18px; font-weight: 700;'>$company_name</div>
                            <div style='font-size: 12px; color: #666; font-style: italic;'>Excellence in Service</div>
                        </td>
                    </tr>
                </table>

                <!-- Info Section -->
                <table style='width: 100%; border-collapse: collapse; margin-bottom: 40px;'>
                    <tr>
                        <td style='vertical-align: top;'>
                            <h3 style='margin: 0 0 5px 0; font-size: 14px; color: #555; font-weight: 500;'>Payslip For:</h3>
                            <div style='font-family: sans-serif; font-size: 20px; font-weight: 700; margin-bottom: 5px;'>{$slip['full_name']}</div>
                            <div style='font-size: 13px; line-height: 1.5; color: #444;'>
                                {$slip['department']}<br>
                                " . ucfirst($slip['role']) . "<br>
                                TIN: " . ($slip['tin_number'] ?? 'N/A') . "
                            </div>
                        </td>
                        <td style='text-align: right; vertical-align: top; font-size: 13px; line-height: 1.8;'>
                            <div><span style='color: #666; margin-right: 15px;'>Email</span> <strong>{$slip['email']}</strong></div>
                            <div><span style='color: #666; margin-right: 15px;'>Period</span> <strong>$period</strong></div>
                            <div><span style='color: #666; margin-right: 15px;'>Run Date</span> <strong>" . date('M d, Y', strtotime($slip['run_date'])) . "</strong></div>
                        </td>
                    </tr>
                </table>

                <!-- Download Link -->
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$baseUrl}/modules/payroll/payslip.php?id={$payslip_id}' 
                       style='background-color: #1a1a1a; color: white; padding: 15px 30px; text-decoration: none; font-weight: 600; border-radius: 4px; display: inline-block;'>
                        Download / View Full Payslip (PDF)
                    </a>
                    <div style='font-size: 12px; color: #666; margin-top: 10px;'>
                        Note: You must be logged into the staff portal to view this link.
                    </div>
                </div>

                <!-- Table -->
                <table style='width: 100%; border-collapse: collapse; margin-bottom: 30px;'>
                    <thead>
                        <tr style='background-color: #1a1a1a; color: white;'>
                            <th style='padding: 10px; font-size: 12px; text-transform: uppercase; text-align: left;'>No</th>
                            <th style='padding: 10px; font-size: 12px; text-transform: uppercase; text-align: left;'>Item Description</th>
                            <th style='padding: 10px; font-size: 12px; text-transform: uppercase; text-align: right;'>Earnings</th>
                            <th style='padding: 10px; font-size: 12px; text-transform: uppercase; text-align: right;'>Deductions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>1.</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>Basic Salary</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>$basic</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>-</td>
                        </tr>
                        " . ($slip['total_allowances'] > 0 ? "
                        <tr>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>2.</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>Total Allowances</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>$allowances</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>-</td>
                        </tr>" : "") . "
                        " . ($slip['monthly_adjustment'] != 0 ? "
                        <tr>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>3.</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>Monthly Adjustment</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>" . ($slip['monthly_adjustment'] > 0 ? number_format($slip['monthly_adjustment'], 2) : '-') . "</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>" . ($slip['monthly_adjustment'] < 0 ? number_format(abs($slip['monthly_adjustment']), 2) : '-') . "</td>
                        </tr>" : "") . "
                        
                        <tr>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>4.</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>NSSF Contribution (10%)</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>-</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>$nssf</td>
                        </tr>
                        <tr>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>5.</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>P.A.Y.E (Tax)</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>-</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>$tax</td>
                        </tr>
                        " . ($slip['other_deductions'] > 0 ? "
                        <tr>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>6.</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>Other Deductions</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>-</td>
                            <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>$other</td>
                        </tr>" : "") . "
                    </tbody>
                </table>

                <!-- Footer Grid -->
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='vertical-align: top; width: 60%; padding-right: 20px;'>
                            <h4 style='margin: 0 0 10px 0; font-size: 14px; color: #555;'>Payment Method :</h4>
                            <div style='font-weight: 700; margin-bottom: 15px; font-size: 15px;'>Bank Transfer</div>
                            
                            <div style='font-size: 13px; margin-bottom: 5px; color: #666;'>Bank Name : <strong style='color: #000; margin-left: 10px;'>" . ($slip['bank_name'] ?? 'N/A') . "</strong></div>
                            <div style='font-size: 13px; margin-bottom: 5px; color: #666;'>Account Name : <strong style='color: #000; margin-left: 10px;'>{$slip['full_name']}</strong></div>
                            <div style='font-size: 13px; margin-bottom: 20px; color: #666;'>Account Number : <strong style='color: #000; margin-left: 10px;'>" . ($slip['account_number'] ?? 'N/A') . "</strong></div>

                            <div style='font-size: 12px; color: #777; margin-top: 30px;'>
                                <div>$company_address</div>
                                <div>$company_phone</div>
                                <div>$company_email</div>
                            </div>
                        </td>
                        <td style='vertical-align: top;'>
                            <!-- Totals Box -->
                            <div style='border: 1px solid #dcdcdc; padding: 15px; background: rgba(255,255,255,0.5); margin-bottom: 30px;'>
                                <div style='display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px;'>
                                    <span style='color: #666;'>Gross Salary</span>
                                    <span style='font-weight: 600;'>$gross</span>
                                </div>
                                <div style='display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px;'>
                                    <span style='color: #666;'>Total Deductions</span>
                                    <span style='font-weight: 600; color: #dc3545;'>-$total_deductions</span>
                                </div>
                                <div style='display: flex; justify-content: space-between; margin-top: 10px; padding-top: 10px; border-top: 1px solid #ccc; font-weight: 700; font-size: 16px; align-items: center;'>
                                    <span>Total Net Pay</span>
                                    <span>$net</span>
                                </div>
                            </div>

                            <!-- Signature -->
                            <div style='text-align: center; margin-left: auto; width: 200px;'>
                                <div style='font-family: \"Playfair Display\", serif; font-size: 18px; font-weight: 700; margin-bottom: 2px;'>" . ($slip['runner_name'] ?? 'Authorized Signatory') . "</div>
                                <div style='font-size: 12px; color: #666; margin-bottom: 10px;'>" . ($slip['runner_role'] ?? 'Finance Director') . "</div>
                                
                                <!-- Sig Image -->
                                <div style='height: 50px; display: flex; justify-content: center; align-items: flex-end; margin-bottom: 5px;'>
                                    $sigImg
                                </div>
                                
                                <div style='border-top: 1px solid #000; padding-top: 5px; font-size: 10px; text-transform: uppercase; letter-spacing: 1px;'>Authorized Signature</div>
                            </div>
                        </td>
                    </tr>
                </table>

            </div>
        </body>
        </html>
        ";
        
        $success = sendEmail($slip['email'], $subject, $body, true, [], 'payroll');
        echo json_encode(['status' => $success ? 'success' : 'error']);
        exit;
        exit;
    }
}


// --- LEGACY FALLBACK FOR BULK LOOP ONLY ---
// Fetch Payslips with User Emails AND Runner Details
$stmt = $pdo->prepare("
    SELECT p.*, u.full_name, u.email, u.role, u.department,
           es.bank_name, es.account_number, es.nssf_number, es.tin_number,
           runner.full_name as runner_name, runner.role as runner_role
    FROM " . payroll_table('payslips') . " p
    JOIN users u ON p.user_id = u.id
    LEFT JOIN " . payroll_table('employee_salary') . " es ON u.id = es.user_id
    LEFT JOIN " . payroll_table('payroll_runs') . " pr ON p.payroll_run_id = pr.id
    LEFT JOIN users runner ON pr.run_by = runner.id
    WHERE p.payroll_run_id = ?
");
$stmt->execute([$run_id]);
$slips = $stmt->fetchAll(PDO::FETCH_ASSOC);

$count_sent = 0;
$count_failed = 0;
$count_skipped = 0;

$period = date('F Y', strtotime($run['year'].'-'.$run['month'].'-01'));
$company_name = defined('COMPANY_NAME') ? COMPANY_NAME : 'ERP System';
$company_address = defined('COMPANY_ADDRESS') ? COMPANY_ADDRESS : 'Plot 123, Standard Street';
$company_phone = defined('COMPANY_PHONE') ? COMPANY_PHONE : '+255 000 000 000';
$company_email = defined('COMPANY_EMAIL') ? COMPANY_EMAIL : 'payroll@example.com';

// Construct Base URL for images
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$baseUrl = $protocol . $_SERVER['HTTP_HOST'] . dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))); 
// e.g. http://localhost/staff

foreach ($slips as $slip) {
    if (empty($slip['email'])) {
        $count_skipped++;
        continue;
    }

    $month_year = date('F Y', mktime(0, 0, 0, $run['month'], 1, $run['year']));
    $subject = "Payslip for " . $month_year . " - " . $company_name;
    
    // Formatting numbers
    $basic = number_format($slip['basic_salary'], 2);
    $allowances = number_format($slip['total_allowances'], 2);
    $gross = number_format($slip['gross_salary'], 2);
    $nssf = number_format($slip['nssf_deduction'], 2);
    $tax = number_format($slip['tax_deduction'], 2);
    $other = number_format($slip['other_deductions'], 2);
    $net = number_format($slip['net_salary'], 2);
    $total_deductions = number_format($slip['nssf_deduction'] + $slip['tax_deduction'] + $slip['other_deductions'], 2);

    // Signature Path
    $sigImg = '';
    $sigPath = getUserSignaturePathById($run['run_by']);
    if ($sigPath && file_exists('../../' . $sigPath)) {
        $sigUrl = $baseUrl . '/' . ltrim($sigPath, '/'); // Absolute URL
        $sigImg = "<img src='$sigUrl' alt='Signature' style='max-height: 80px; max-width: 100%; object-fit: contain;'>";
    }

    $logoUrl = $baseUrl . '/assets/images/Untitled.jpg';

    // HTML Body - mimicking "Monsieur" Layout with inline CSS
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Inter:wght@400;500;600&display=swap');
        </style>
    </head>
    <body style='background-color: #525659; font-family: sans-serif; margin: 0; padding: 20px;'>
        <div style='background-color: #f4f1ea; max-width: 800px; margin: 0 auto; padding: 40px; box-shadow: 0 0 10px rgba(0,0,0,0.1); color: #1a1a1a;'>
            
            <!-- Header -->
            <table style='width: 100%; border-collapse: collapse; margin-bottom: 30px;'>
                <tr>
                    <td style='vertical-align: top;'>
                        <h1 style='font-family: \"Playfair Display\", serif; font-size: 36px; font-weight: 700; margin: 0; line-height: 1.1; color: #1a1a1a;'>Payslip</h1>
                        <div style='font-size: 13px; color: #666; margin-top: 5px;'>No. " . str_pad($slip['payroll_run_id'], 3, '0', STR_PAD_LEFT) . "-" . str_pad($slip['id'], 5, '0', STR_PAD_LEFT) . "</div>
                    </td>
                    <td style='text-align: right; vertical-align: top;'>
                        <img src='$logoUrl' alt='Logo' style='height: 50px; width: auto; margin-bottom: 5px;'>
                        <div style='font-family: \"Playfair Display\", serif; font-size: 18px; font-weight: 700;'>$company_name</div>
                        <div style='font-size: 12px; color: #666; font-style: italic;'>Excellence in Service</div>
                    </td>
                </tr>
            </table>

            <!-- Info Section -->
            <table style='width: 100%; border-collapse: collapse; margin-bottom: 40px;'>
                <tr>
                    <td style='vertical-align: top;'>
                        <h3 style='margin: 0 0 5px 0; font-size: 14px; color: #555; font-weight: 500;'>Payslip For:</h3>
                        <div style='font-family: sans-serif; font-size: 20px; font-weight: 700; margin-bottom: 5px;'>{$slip['full_name']}</div>
                        <div style='font-size: 13px; line-height: 1.5; color: #444;'>
                            {$slip['department']}<br>
                            " . ucfirst($slip['role']) . "<br>
                            TIN: " . ($slip['tin_number'] ?? 'N/A') . "
                        </div>
                    </td>
                    <td style='text-align: right; vertical-align: top; font-size: 13px; line-height: 1.8;'>
                        <div><span style='color: #666; margin-right: 15px;'>Email</span> <strong>{$slip['email']}</strong></div>
                        <div><span style='color: #666; margin-right: 15px;'>Period</span> <strong>$period</strong></div>
                        <div><span style='color: #666; margin-right: 15px;'>Run Date</span> <strong>" . date('M d, Y', strtotime($slip['run_date'])) . "</strong></div>
                    </td>
                </tr>
            </table>

            <!-- Download Link -->
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$baseUrl}/modules/payroll/payslip.php?id={$slip['id']}' 
                   style='background-color: #1a1a1a; color: white; padding: 15px 30px; text-decoration: none; font-weight: 600; border-radius: 4px; display: inline-block;'>
                    Download / View Full Payslip (PDF)
                </a>
                <div style='font-size: 12px; color: #666; margin-top: 10px;'>
                    Note: You must be logged into the staff portal to view this link.
                </div>
            </div>

            <!-- Table -->
            <table style='width: 100%; border-collapse: collapse; margin-bottom: 30px;'>
                <thead>
                    <tr style='background-color: #1a1a1a; color: white;'>
                        <th style='padding: 10px; font-size: 12px; text-transform: uppercase; text-align: left;'>No</th>
                        <th style='padding: 10px; font-size: 12px; text-transform: uppercase; text-align: left;'>Item Description</th>
                        <th style='padding: 10px; font-size: 12px; text-transform: uppercase; text-align: right;'>Earnings</th>
                        <th style='padding: 10px; font-size: 12px; text-transform: uppercase; text-align: right;'>Deductions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>1.</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>Basic Salary</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>$basic</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>-</td>
                    </tr>
                    " . ($slip['total_allowances'] > 0 ? "
                    <tr>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>2.</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>Total Allowances</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>$allowances</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>-</td>
                    </tr>" : "") . "
                    " . ($slip['monthly_adjustment'] != 0 ? "
                    <tr>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>3.</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>Monthly Adjustment</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>" . ($slip['monthly_adjustment'] > 0 ? number_format($slip['monthly_adjustment'], 2) : '-') . "</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>" . ($slip['monthly_adjustment'] < 0 ? number_format(abs($slip['monthly_adjustment']), 2) : '-') . "</td>
                    </tr>" : "") . "
                    
                    <tr>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>4.</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>NSSF Contribution (10%)</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>-</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>$nssf</td>
                    </tr>
                    <tr>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>5.</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>P.A.Y.E (Tax)</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>-</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>$tax</td>
                    </tr>
                    " . ($slip['other_deductions'] > 0 ? "
                    <tr>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>6.</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px;'>Other Deductions</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>-</td>
                        <td style='padding: 12px 10px; border-bottom: 1px solid #e0e0e0; font-size: 14px; text-align: right;'>$other</td>
                    </tr>" : "") . "
                </tbody>
            </table>

            <!-- Footer Grid -->
            <table style='width: 100%; border-collapse: collapse;'>
                <tr>
                    <td style='vertical-align: top; width: 60%; padding-right: 20px;'>
                        <h4 style='margin: 0 0 10px 0; font-size: 14px; color: #555;'>Payment Method :</h4>
                        <div style='font-weight: 700; margin-bottom: 15px; font-size: 15px;'>Bank Transfer</div>
                        
                        <div style='font-size: 13px; margin-bottom: 5px; color: #666;'>Bank Name : <strong style='color: #000; margin-left: 10px;'>" . ($slip['bank_name'] ?? 'N/A') . "</strong></div>
                        <div style='font-size: 13px; margin-bottom: 5px; color: #666;'>Account Name : <strong style='color: #000; margin-left: 10px;'>{$slip['full_name']}</strong></div>
                        <div style='font-size: 13px; margin-bottom: 20px; color: #666;'>Account Number : <strong style='color: #000; margin-left: 10px;'>" . ($slip['account_number'] ?? 'N/A') . "</strong></div>

                        <div style='font-size: 12px; color: #777; margin-top: 30px;'>
                            <div>$company_address</div>
                            <div>$company_phone</div>
                            <div>$company_email</div>
                        </div>
                    </td>
                    <td style='vertical-align: top;'>
                        <!-- Totals Box -->
                        <div style='border: 1px solid #dcdcdc; padding: 15px; background: rgba(255,255,255,0.5); margin-bottom: 30px;'>
                            <div style='display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px;'>
                                <span style='color: #666;'>Gross Salary</span>
                                <span style='font-weight: 600;'>$gross</span>
                            </div>
                            <div style='display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px;'>
                                <span style='color: #666;'>Total Deductions</span>
                                <span style='font-weight: 600; color: #dc3545;'>-$total_deductions</span>
                            </div>
                            <div style='display: flex; justify-content: space-between; margin-top: 10px; padding-top: 10px; border-top: 1px solid #ccc; font-weight: 700; font-size: 16px; align-items: center;'>
                                <span>Total Net Pay</span>
                                <span>$net</span>
                            </div>
                        </div>

                        <!-- Signature -->
                        <div style='text-align: center; margin-left: auto; width: 200px;'>
                            <div style='font-family: \"Playfair Display\", serif; font-size: 18px; font-weight: 700; margin-bottom: 2px;'>" . ($slip['runner_name'] ?? 'Authorized Signatory') . "</div>
                            <div style='font-size: 12px; color: #666; margin-bottom: 10px;'>" . ($slip['runner_role'] ?? 'Finance Director') . "</div>
                            
                            <!-- Sig Image -->
                            <div style='height: 50px; display: flex; justify-content: center; align-items: flex-end; margin-bottom: 5px;'>
                                $sigImg
                            </div>
                            
                            <div style='border-top: 1px solid #000; padding-top: 5px; font-size: 10px; text-transform: uppercase; letter-spacing: 1px;'>Authorized Signature</div>
                        </div>
                    </td>
                </tr>
            </table>

        </div>
    </body>
    </html>
    ";

    // Send Email
    if (sendEmail($slip['email'], $subject, $body, true, [], 'payroll')) {
        $count_sent++;
    } else {
        $count_failed++;
    }
}

// Redirect back with status
$msg = "Email Batch Complete: Sent: $count_sent, Failed: $count_failed, Skipped (No Email): $count_skipped";
$type = ($count_failed == 0) ? "success" : "warning";

$_SESSION['flash_message'] = $msg;
$_SESSION['flash_type'] = $type;

header("Location: view_run.php?id=$run_id");
exit;
?>
