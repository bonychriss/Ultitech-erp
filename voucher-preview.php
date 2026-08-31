<?php
// Standalone, login-free preview of the payment voucher layout using
// the exact structure and styles from the system's voucher view.
// This is useful to validate the print layout visually with sample data.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Voucher Preview - PV/UGC/2025/010</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
</head>
<body class="dashboard">
    <main class="main-content">
        <div class="actions no-print" style="margin-bottom:16px;">
            <button onclick="window.print()" class="btn btn-success">Print</button>
        </div>

        <div class="voucher-container">
            <!-- Voucher Header -->
            <div class="voucher-header">
                <div class="voucher-logo">
                    <img src="assets/images/Untitled.jpg" alt="Ultimate General Trading Logo" style="height: 80px; width: auto;" />
                    <div class="voucher-company-info">
                        <h1>ULTIMATE</h1>
                        <h2>GENERAL</h2>
                        <h2>TRADING.</h2>
                    </div>
                </div>
                <div class="voucher-title">
                    <h1>PAYMENT VOUCHER</h1>
                </div>
            </div>

            <!-- Voucher Information -->
            <div class="voucher-info">
                <div class="voucher-info-row">
                    <div class="voucher-info-cell">Vocher NO.:</div>
                    <div class="voucher-info-cell value">PV/UGC/2025/010</div>
                    <div class="voucher-info-cell">Date:</div>
                    <div class="voucher-info-cell value">2025-10-20</div>
                </div>
                <div class="voucher-info-row">
                    <div class="voucher-info-cell">Payee Name:</div>
                    <div class="voucher-info-cell value">NAFIS</div>
                    <div class="voucher-info-cell">Prepared By:</div>
                    <div class="voucher-info-cell value">MAUREEN</div>
                </div>
                <div class="voucher-info-row">
                    <div class="voucher-info-cell">Description:</div>
                    <div class="voucher-info-cell value">Purchase of PPE (Masks and Reflector)</div>
                    <div class="voucher-info-cell">Supporting<br>Documents (Qty.)</div>
                    <div class="voucher-info-cell value">6</div>
                </div>
                <div class="voucher-info-row">
                    <div class="voucher-info-cell">Currency:</div>
                    <div class="voucher-info-cell value">TZS</div>
                    <div class="voucher-info-cell">Amount:</div>
                    <div class="voucher-info-cell value">3,000,000.00</div>
                </div>
            </div>

            <!-- Payment Details Table -->
            <table class="voucher-table">
                <thead>
                    <tr>
                        <th>Payment Type</th>
                        <th>Budget Type</th>
                        <th>Name</th>
                        <th>Amount</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Bank Transfer</td>
                        <td>Procurement</td>
                        <td>NAFIS</td>
                        <td class="amount-cell">20,000.00</td>
                        <td>Masks</td>
                    </tr>
                    <tr>
                        <td>Bank Transfer</td>
                        <td>Procurement</td>
                        <td>NAFIS</td>
                        <td class="amount-cell">40,000.00</td>
                        <td>Reflector</td>
                    </tr>
                    <tr>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                    </tr>
                </tbody>
            </table>

            <!-- Signatures Section -->
            <div class="voucher-signatures">
                <div class="signature-cell">
                    <div class="signature-line"></div>
                    Applicant<br>
                    MASE
                </div>
                <div class="signature-cell">
                    <div class="signature-line"></div>
                    Check<br>
                    SAIDA
                </div>
                <div class="signature-cell">
                    <div class="signature-line"></div>
                    Department<br>
                    Manager<br>
                    MASE
                </div>
                <div class="signature-cell">
                    <div class="signature-line"></div>
                    General Manager<br>
                    
                </div>
            </div>
        </div>
    </main>
</body>
</html>

