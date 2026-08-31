<?php
// modules/payroll/help.php
session_start();
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

define('ALLOW_ANONYMOUS_PAYROLL', true);
if (!isset($_SESSION['role'])) {
    $_SESSION['role'] = 'admin';
    $_SESSION['full_name'] = 'System Admin (Demo)';
    $_SESSION['user_id'] = 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll User Manual - Help</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-light: #eff6ff;
            --secondary: #64748b;
            --dark: #0f172a;
            --light: #f8fafc;
            --border: #e2e8f0;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --premium-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: #f1f5f9; 
            color: #334155;
            line-height: 1.7;
        }

        .help-wrapper {
            max-width: 1000px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .header-section {
            background: linear-gradient(135deg, var(--dark) 0%, #1e293b 100%);
            color: white;
            padding: 60px 40px;
            border-radius: 20px 20px 0 0;
            position: relative;
            overflow: hidden;
        }

        .header-section::after {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
        }

        .main-content {
            background: white;
            padding: 40px;
            border-radius: 0 0 20px 20px;
            box-shadow: var(--premium-shadow);
        }

        .toc-card {
            background: var(--light);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 40px;
        }

        .toc-title {
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--secondary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .toc-list {
            list-style: none;
            padding: 0;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }

        .toc-link {
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
        }

        .toc-link:hover {
            color: var(--primary);
            transform: translateX(4px);
        }

        .toc-number {
            background: white;
            color: var(--primary);
            width: 24px;
            height: 24px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        section {
            margin-bottom: 60px;
            scroll-margin-top: 40px;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 25px;
        }

        .section-icon {
            width: 48px;
            height: 48px;
            background: var(--primary-light);
            color: var(--primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        h2 {
            font-weight: 800;
            font-size: 1.75rem;
            color: var(--dark);
            margin: 0;
        }

        h3 {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--dark);
            margin: 30px 0 15px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .step-list {
            list-style: none;
            padding: 0;
        }

        .step-item {
            background: white;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 12px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
            transition: border-color 0.2s;
        }

        .step-item:hover {
            border-color: var(--primary);
        }

        .step-num {
            background: var(--dark);
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .feature-card {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            height: 100%;
            transition: all 0.2s;
        }

        .feature-card:hover {
            box-shadow: var(--card-shadow);
            border-color: var(--primary);
        }

        .feature-title {
            font-weight: 700;
            font-size: 1rem;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--primary);
        }

        .feature-desc {
            font-size: 0.9rem;
            color: var(--secondary);
            margin: 0;
        }

        .note-box {
            background: rgba(37, 99, 235, 0.05);
            border-left: 4px solid var(--primary);
            padding: 20px;
            border-radius: 0 12px 12px 0;
            margin-top: 20px;
            display: flex;
            gap: 15px;
        }

        .note-icon {
            color: var(--primary);
            font-size: 1.25rem;
        }

        .btn-back {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-back:hover {
            background: white;
            color: var(--dark);
        }

        @media (max-width: 768px) {
            .header-section { padding: 40px 20px; }
            .main-content { padding: 25px; }
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <?php include '../../sidebar.php'; ?>
        
        <div class="flex-grow-1">
            <?php 
            $script = $_SERVER['SCRIPT_NAME'];
            if (strpos($script, '/modules/') !== false) {
                include '../../includes/header_admin.php'; 
            }
            ?>
            
            <div class="help-wrapper">
                <div class="header-section">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <a href="index.php" class="btn-back">
                            <i class="bi bi-arrow-left"></i> Back to Dashboard
                        </a>
                        <span class="badge bg-primary px-3 py-2 rounded-pill">v2.0 Update</span>
                    </div>
                    <h1 class="display-5 fw-bold mb-2">Payroll Module Guide</h1>
                    <p class="lead opacity-75 mb-0">A complete, professional guide to managing staff salaries and payments.</p>
                </div>

                <div class="main-content">
                    <!-- Table of Contents -->
                    <div class="toc-card">
                        <div class="toc-title">
                            <i class="bi bi-list-nested"></i> Table of Contents
                        </div>
                        <div class="toc-list">
                            <a href="#setup" class="toc-link"><span class="toc-number">1</span> Setting up Salaries</a>
                            <a href="#adjustments" class="toc-link"><span class="toc-number">2</span> Adjustments & Deductions</a>
                            <a href="#running" class="toc-link"><span class="toc-number">3</span> Running Monthly Payroll</a>
                            <a href="#review" class="toc-link"><span class="toc-number">4</span> Reviewing & Exporting</a>
                            <a href="#delivery" class="toc-link"><span class="toc-number">5</span> Delivery & Self-Service</a>
                            <a href="#config" class="toc-link"><span class="toc-number">6</span> Global Configuration</a>
                        </div>
                    </div>

                    <!-- 1. Setting up Salaries -->
                    <section id="setup">
                        <div class="section-header">
                            <div class="section-icon"><i class="bi bi-person-check"></i></div>
                            <h2>1. Setting up Salaries</h2>
                        </div>
                        <p class="mb-4">Before you can run payroll, active employees must have a salary structure defined in the system.</p>
                        
                        <div class="step-list">
                            <div class="step-item">
                                <div class="step-num">1</div>
                                <div>Navigate to <strong>Manage Salaries</strong> from the payroll dashboard.</div>
                            </div>
                            <div class="step-item">
                                <div class="step-num">2</div>
                                <div>Find the employee and click the <strong>Edit</strong> button.</div>
                            </div>
                            <div class="step-item">
                                <div class="step-num">3</div>
                                <div>Enter the <strong>Basic Salary</strong> and any recurring allowances (e.g., House or Transport).</div>
                            </div>
                            <div class="step-item">
                                <div class="step-num">4</div>
                                <div>Add <strong>Bank Details</strong> to ensure they automatically appear on the payslips.</div>
                            </div>
                        </div>
                    </section>

                    <!-- 2. Adjustments & Deductions -->
                    <section id="adjustments">
                        <div class="section-header">
                            <div class="section-icon"><i class="bi bi-calculator"></i></div>
                            <h2>2. Adjustments & Deductions</h2>
                        </div>
                        <p>Handle one-time or recurring variations in pay using the <strong>Monthly Adjustment</strong> field.</p>
                        
                        <div class="row g-4 mt-2">
                            <div class="col-md-6">
                                <div class="feature-card">
                                    <div class="feature-title"><i class="bi bi-plus-circle-fill"></i> Positive Adjustment</div>
                                    <p class="feature-desc">Adds to the Gross Pay for items like Overtime or Performance Bonuses (e.g., 50,000).</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="feature-card">
                                    <div class="feature-title"><i class="bi bi-dash-circle-fill"></i> Negative Adjustment</div>
                                    <p class="feature-desc">Subtracts from Gross Pay for one-time fines or corrections (e.g., -20,000).</p>
                                </div>
                            </div>
                        </div>

                        <h3>Other Deductions (Fixed)</h3>
                        <p>Use this for recurring deductions that happen <em>after</em> tax and NSSF are calculated, such as Salary Advance repayments or Bank Loans.</p>
                    </section>

                    <!-- 3. Running Monthly Payroll -->
                    <section id="running">
                        <div class="section-header">
                            <div class="section-icon"><i class="bi bi-play-circle-fill"></i></div>
                            <h2>3. Running Monthly Payroll</h2>
                        </div>
                        <p>Generate the payroll cycle for the current month in three simple steps:</p>
                        
                        <div class="step-list">
                            <div class="step-item">
                                <div class="step-num">1</div>
                                <div>Click <strong>Run Payroll</strong> on the primary dashboard.</div>
                            </div>
                            <div class="step-item">
                                <div class="step-num">2</div>
                                <div>Select the target <strong>Month and Year</strong> from the calendar selector.</div>
                            </div>
                            <div class="step-item">
                                <div class="step-num">3</div>
                                <div>Click <strong>Generate Draft Payroll</strong> to process calculations.</div>
                            </div>
                        </div>

                        <div class="note-box">
                            <div class="note-icon"><i class="bi bi-info-circle-fill"></i></div>
                            <div>
                                <strong>System Note:</strong> The system automatically calculates <strong>NSSF and Tax (PAYE)</strong> based on your global settings during this process.
                            </div>
                        </div>
                    </section>

                    <!-- 4. Reviewing & Exporting -->
                    <section id="review">
                        <div class="section-header">
                            <div class="section-icon"><i class="bi bi-file-earmark-spreadsheet"></i></div>
                            <h2>4. Reviewing & Exporting</h2>
                        </div>
                        <p>Review the calculated runs in the Payroll History table:</p>
                        
                        <div class="step-list">
                            <div class="step-item">
                                <i class="bi bi-check2-circle text-success fs-5"></i>
                                <div>Click <strong>View</strong> to see a full audit breakdown of every employee's pay for that cycle.</div>
                            </div>
                            <div class="step-item">
                                <i class="bi bi-check2-circle text-success fs-5"></i>
                                <div>Click <strong>Export</strong> to download a formatted CSV for accounting, showing the complete "Math Flow".</div>
                            </div>
                        </div>
                    </section>

                    <!-- 5. Delivery & Self-Service -->
                    <section id="delivery">
                        <div class="section-header">
                            <div class="section-icon"><i class="bi bi-shield-check"></i></div>
                            <h2>5. Delivery & Self-Service</h2>
                        </div>
                        <p>A secure, link-based approach for seamless payslip distribution:</p>
                        
                        <div class="feature-grid">
                            <div class="feature-card">
                                <div class="feature-title"><i class="bi bi-envelope-at"></i> Secure Email</div>
                                <p class="feature-desc">Employees receive a professional link to view/download their payslip via email, replacing unreliable PDF attachments.</p>
                            </div>
                            <div class="feature-card">
                                <div class="feature-title"><i class="bi bi-person-badge"></i> Self-Service</div>
                                <p class="feature-desc">All staff can view their history by logging in and clicking <strong>"My Payslips"</strong> in their sidebar.</p>
                            </div>
                            <div class="feature-card">
                                <div class="feature-title"><i class="bi bi-file-earmark-pdf"></i> One-Click PDF</div>
                                <p class="feature-desc">Optimized viewer that generates a high-quality, signed PDF document instantly upon clicking <strong>"Download PDF"</strong>.</p>
                            </div>
                            <div class="feature-card">
                                <div class="feature-title"><i class="bi bi-lightning-charge"></i> Auto-Download</div>
                                <p class="feature-desc">System links with <code>download=1</code> automatically trigger the generation for a smooth user experience.</p>
                            </div>
                        </div>
                    </section>

                    <!-- 6. Global Configuration -->
                    <section id="config" class="mb-0">
                        <div class="section-header">
                            <div class="section-icon"><i class="bi bi-gear-wide-connected"></i></div>
                            <h2>6. Global Configuration</h2>
                        </div>
                        <p>Adjust system-wide variables in the **Settings** panel:</p>
                        
                        <div class="step-list">
                            <div class="step-item">
                                <i class="bi bi-percent text-primary fw-bold"></i>
                                <div><strong>NSSF Rate:</strong> Percentage deducted for social security from the basic pay.</div>
                            </div>
                            <div class="step-item">
                                <i class="bi bi-receipt-cutoff text-primary fw-bold"></i>
                                <div><strong>Tax Rate:</strong> Flat percentage used for income tax (simplified model).</div>
                            </div>
                            <div class="step-item">
                                <i class="bi bi-calendar-event text-primary fw-bold"></i>
                                <div><strong>Pay Day:</strong> The target day of the month for salary disbursement.</div>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="text-center mt-5 mb-4 text-muted small">
                    &copy; <?= date('Y') ?> <?= defined('COMPANY_NAME') ? COMPANY_NAME : 'ERP System' ?> &bull; All Rights Reserved
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
