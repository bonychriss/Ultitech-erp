<?php
require_once '../includes/functions.php';
requireLogin();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Manual - Payment Voucher Module</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg-body: #F7F7F7; --c-orange: #FF902F; }
        body { background-color: var(--bg-body); font-family: 'Inter', sans-serif; color: #64748b; }
        .main-content { padding: 40px 25px; max-width: 900px; margin: 0 auto; }
        
        .manual-card { background: white; border-radius: 8px; border: 1px solid #ededed; padding: 40px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .manual-header { border-bottom: 2px solid #f5f5f5; padding-bottom: 20px; margin-bottom: 30px; }
        .manual-header h1 { font-size: 28px; font-weight: 700; color: #475569; margin: 0; }
        .manual-header p { color: #94a3b8; margin-top: 5px; font-size: 14px; }
        
        .section-title { font-size: 20px; font-weight: 600; color: #64748b; margin: 40px 0 20px 0; display: flex; align-items: center; gap: 12px; }
        .section-title i { color: var(--c-orange); font-size: 18px; }
        
        .manual-content p { font-size: 15px; line-height: 1.6; color: #64748b; margin-bottom: 16px; }
        .manual-content ul { padding-left: 20px; margin-bottom: 20px; }
        .manual-content li { font-size: 15px; line-height: 1.6; color: #64748b; margin-bottom: 8px; }
        
        .feature-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 20px; margin-bottom: 24px; }
        .feature-box h4 { font-size: 16px; font-weight: 600; color: #64748b; margin-bottom: 12px; }
        
        .alert-tip { background: #fff7ed; border-left: 4px solid #f97316; padding: 16px; border-radius: 4px; margin: 30px 0; }
        .alert-tip h5 { font-size: 14px; font-weight: 700; color: #9a3412; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em; }
        .alert-tip p { font-size: 14px; color: #9a3412; margin: 0; }
        
        .btn-back { display: inline-flex; align-items: center; gap: 8px; text-decoration: none; color: #6b7280; font-size: 14px; font-weight: 500; margin-bottom: 20px; transition: color 0.2s; }
        .btn-back:hover { color: #1e293b; }
        
        @media (max-width: 768px) {
            .manual-card { padding: 25px; }
            .section-title { font-size: 18px; }
        }

        /* EXACT Snippet Styles */
        .modern-player-card {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            max-width: 600px;
            gap: 15px;
            margin-bottom: 30px;
        }

        .card-header {
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .icon-wrapper {
            background-color: #3b82f6;
            color: white;
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .icon-wrapper svg {
            width: 24px;
            height: 24px;
            fill: currentColor;
        }

        .text-wrapper h3 {
            margin: 0 0 4px 0;
            color: #0f172a;
            font-size: 16px;
            font-weight: 600;
        }

        .text-wrapper p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.4;
        }

        audio {
            width: 100%;
            height: 40px;
            border-radius: 8px;
        }
        
        audio::-webkit-media-controls-panel {
            background-color: #ffffff;
            border: 1px solid #cbd5e1;
        }

        .download-box {
            text-align: right;
            margin-top: -10px;
        }

        .download-link {
            color: #3b82f6;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }

        .download-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header_employee.php'; ?>

    <div class="main-content">
        <a href="dashboard.php" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <div class="manual-card">
            <div class="manual-header">
                <h1>Employee User Guide</h1>
                <p>Learn how to use the Payment Voucher Module effectively.</p>
            </div>

            <!-- Modern Audio Manual Section (Exact Layout) -->
            <div class="modern-player-card">
                <div class="card-header">
                    <div class="icon-wrapper">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12v7c0 1.66 1.34 3 3 3h3v-8H5v-2c0-3.87 3.13-7 7-7s7 3.13 7 7v2h-3v8h3c1.66 0 3-1.34 3-3v-7c0-5.52-4.48-10-10-10z"/></svg>
                    </div>
                    <div class="text-wrapper">
                        <h3>Audio Navigation Guide</h3>
                        <p>Click play to listen to the detailed module walkthrough.</p>
                    </div>
                </div>
                
                <audio controls id="mainGuideAudio">
                    <source src="../voice manuals/payment voucher manual.wav" type="audio/wav">
                    Your browser does not support the audio element.
                </audio>
                
                <div class="download-box">
                    <a href="../voice manuals/payment voucher manual.wav" download="Payment_Voucher_Guide.wav" class="download-link">
                        <i class="fas fa-download me-1"></i> Download Audio Manual
                    </a>
                </div>
            </div>

            <div class="manual-content">
                <div class="section-title">
                    <i class="fas fa-tachometer-alt"></i> Dashboard Overview
                </div>
                <p>When you enter the module, your dashboard provides a real-time summary of your activities:</p>
                <ul>
                    <li><strong>Key Metrics</strong>: Instantly view the status of your vouchers (Pending, Approved, Paid, Rejected).</li>
                    <li><strong>Recent Activity</strong>: A quick-access list of your latest submissions.</li>
                    <li><strong>Performance Analytics</strong>: Charts showing your top payees and approval rates.</li>
                </ul>

                <div class="section-title">
                    <i class="fas fa-plus-circle"></i> Creating a New Voucher
                </div>
                <p>To request a payment, click <strong>Create Voucher</strong> from the dashboard or sidebar. The form is organized into logical sections:</p>
                
                <div class="feature-box">
                    <h4 style="color:var(--c-orange);"><i class="fas fa-file-invoice me-2"></i> 1. Voucher Details</h4>
                    <p>Provide the core identity of the payment request:</p>
                    <ul class="mb-0">
                        <li><strong>Payee Name *</strong>: The individual or entity receiving payment.</li>
                        <li><strong>Date *</strong>: The submission date.</li>
                        <li><strong>Currency</strong>: TZS (default) or USD.</li>
                        <li><strong>Supporting Documents (Qty.)</strong>: The expected number of invoices or receipts you will attach.</li>
                        <li><strong>Restricted Access (Confidential)</strong>: If checked, the voucher is hidden from regular employees. Only Finance and Admins can view it.</li>
                    </ul>
                </div>

                <div class="feature-box">
                    <h4 style="color:var(--c-orange);"><i class="fas fa-list-ol me-2"></i> 2. Payment Details</h4>
                    <p>Enter the specific financial items. The <strong>Name</strong> field is pre-populated from the Payee Name.</p>
                    <ul class="mb-0">
                        <li><strong>Payment Type</strong>: Choose Bank Transfer, Cash, etc.</li>
                        <li><strong>Budget Type</strong>: Select the expense category.</li>
                        <li><strong>Amount</strong>: The price per item line.</li>
                        <li><strong>Item Description</strong>: Specific notes for each line item.</li>
                        <li><strong>Total Amount</strong>: Automatically calculated sum of all items.</li>
                    </ul>
                </div>

                <div class="feature-box">
                    <h4 style="color:var(--c-orange);"><i class="fas fa-align-left me-2"></i> 3. Description & Attachments</h4>
                    <ul class="mb-0">
                        <li><strong>Description *</strong>: A general justification or explanation for the entire voucher.</li>
                        <li><strong>Attachments</strong>: Upload the actual files (PDF/Images) to support your request.</li>
                    </ul>
                </div>

                <div class="section-title">
                    <i class="fas fa-history"></i> Managing Your History
                </div>
                <p>Use the <strong>My History</strong> page to track and manage your submissions:</p>
                <ul>
                    <li><strong>Real-time Status</strong>: Monitor exactly where your voucher is in the approval pipeline.</li>
                    <li><strong>Edit Corrections</strong>: If a voucher is pending or rejected, you can edit it to fix errors.</li>
                    <li><strong>Smart Filtering</strong>: Search for vouchers by number, date, or payee name.</li>
                </ul>

                <div class="section-title">
                    <i class="fab fa-whatsapp"></i> WhatsApp Sharing
                </div>
                <p>Need to notify someone about a voucher? Use our integrated sharing tool:</p>
                <ul>
                    <li>Click the <strong>WhatsApp icon</strong> next to any voucher in your list.</li>
                    <li>Select a recipient from the system directory.</li>
                    <li>The system will generate a professional message with a direct link to the voucher.</li>
                </ul>

                <div class="section-title">
                    <i class="fas fa-signature"></i> Profile & Digital Signature
                </div>
                <p>For consistent processing, ensure your profile is complete:</p>
                <ul>
                    <li><strong>Digital Signature</strong>: Go to your Account page to draw and save your signature. It will be automatically added to all vouchers you "Prepare".</li>
                    <li><strong>Contact Info</strong>: Keep your WhatsApp number updated to ensure others can reach out regarding your vouchers.</li>
                </ul>

                <div class="alert-tip">
                    <h5><i class="fas fa-lightbulb"></i> Pro Tip</h5>
                    <p>Always attach clear photos or PDFs of receipts. Vouchers with complete documentation are approved much faster by the Finance team!</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
