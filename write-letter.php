<?php
// Load shared bootstrap
require_once __DIR__ . '/includes/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
requireLogin();
$active_module = 'letters';

$error = '';
$success = '';

// --- PHP LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_letter') {
    try {
        if (!isset($pdo)) { /* mock db for demo */ }
        
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $recipient_name = trim($_POST['recipient_name'] ?? '');
        $recipient_company = trim($_POST['recipient_company'] ?? '');
        $recipient_address = trim($_POST['recipient_address'] ?? '');
        $letter_date = !empty($_POST['letter_date']) ? $_POST['letter_date'] : date('Y-m-d');
        
        $sender_name = $_SESSION['full_name'] ?? 'John Doe';
        $sender_dept = $_SESSION['department'] ?? 'HR Manager';

        if (empty($subject) || empty($body)) {
            throw new Exception("Subject and Body are required.");
        }

        // Database Logic would go here...

        $success = "Letter created successfully!";

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Official Letter</title>
    <!-- Modern Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Open+Sans:wght@400;600&display=swap" rel="stylesheet">
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    
    <style>
        /* --- GLOBAL VARIABLES --- */
        :root {
            --brand-gold: #E6B800; 
            --brand-dark: #2f3542;
            --text-grey: #57606f;
            --bg-dashboard: #f1f2f6;
            --paper-shadow: 0 15px 35px rgba(50,50,93,.1), 0 5px 15px rgba(0,0,0,.07);
        }

        * { box-sizing: border-box; }

        body {
            background-color: var(--bg-dashboard);
            font-family: 'Open Sans', sans-serif;
            margin: 0;
            padding: 40px 0;
            color: #333;
            -webkit-font-smoothing: antialiased;
        }

        .main-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
        }

        /* --- THE A4 PAPER --- */
        .page {
            width: 210mm;
            min-height: 297mm;
            background: white;
            position: relative;
            box-shadow: var(--paper-shadow);
            margin-bottom: 30px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        /* --- HEADER ARTWORK --- */
        .header-design {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 160px;
            z-index: 1;
        }
        
        /* --- UPDATED: BALANCED GOLD SHAPE --- */
        .shape-gold {
            position: absolute; top: 0; left: 0;
            /* Increased to balance the layout */
            width: 48%; 
            height: 100px; 
            background-color: var(--brand-gold);
            clip-path: polygon(0 0, 100% 0, 85% 100%, 0% 100%);
            z-index: 2;
        }

        .shape-dark {
            position: absolute; top: 0; right: 0;
            width: 45%; height: 40px;
            background-color: var(--brand-dark);
            clip-path: polygon(10% 0, 100% 0, 100% 100%, 0% 100%);
            z-index: 1;
        }

        /* --- HEADER CONTENT --- */
        .header-content {
            position: relative;
            z-index: 10;
            display: flex;
            justify-content: flex-end;
            padding: 50px 50px 0 0;
            text-align: right;
        }

        .brand-box h1 {
            font-family: 'Montserrat', sans-serif;
            font-size: 26px;
            font-weight: 800;
            color: var(--brand-dark);
            margin: 0;
            text-transform: uppercase;
            line-height: 1;
        }
        
        .brand-box span {
            font-family: 'Montserrat', sans-serif;
            font-size: 11px;
            font-weight: 600;
            color: var(--brand-gold);
            letter-spacing: 3px;
            text-transform: uppercase;
            display: block;
            margin-top: 4px;
        }

        /* --- BODY CONTENT --- */
        .content-area {
            position: relative;
            z-index: 5;
            padding: 40px 60px 100px 60px;
            flex-grow: 1;
        }

        /* --- INPUT STYLES --- */
        .input-group { margin-bottom: 20px; }

        input[type="text"], input[type="date"], textarea {
            width: 100%;
            border: 1px dashed transparent;
            background: transparent;
            font-family: inherit;
            color: inherit;
            padding: 4px 0;
            transition: all 0.2s;
        }

        .recipient-box {
            border: 1px solid #ccc !important;
            background: #fff !important;
            padding: 10px !important;
            margin-bottom: 10px;
            font-size: 14px;
            border-radius: 4px;
            display: block;
            width: 250px !important;
        }

        .recipient-box:focus {
            border-color: var(--brand-gold) !important;
            background: #fff !important;
            box-shadow: 0 0 0 2px rgba(230, 184, 0, 0.1);
        }

        input[type="text"]:hover, input[type="date"]:hover, textarea:hover {
            border-bottom: 1px dashed #ccc;
            background: rgba(0,0,0,0.01);
        }

        input:focus, textarea:focus {
            outline: none;
            border-bottom: 2px solid var(--brand-gold);
            background: rgba(230, 184, 0, 0.05);
        }

        /* --- TYPOGRAPHY FOR LETTER --- */
        .meta-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 40px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        .label-text { font-size: 11px; color: #999; font-weight: 600; text-transform: uppercase; display: block; margin-bottom: 2px; }

        .recipient-block { margin-bottom: 40px; }
        .recipient-name { font-weight: 700; font-size: 15px; color: #000; }
        
        .subject-block { 
            text-align: center; 
            margin: 30px 0 40px 0; 
        }
        
        .subject-input {
            text-align: center;
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            font-size: 16px;
            text-decoration: underline;
            text-underline-offset: 5px;
            text-decoration-color: var(--brand-dark);
            text-transform: uppercase;
        }

        .body-textarea {
            resize: none;
            line-height: 1.8;
            font-size: 14px;
            text-align: justify;
            min-height: 300px;
            overflow: hidden;
        }

        .signature-section {
            margin-top: 60px;
            page-break-inside: avoid;
        }

        .sig-line {
            width: 220px;
            border-top: 2px solid #333;
            padding-top: 8px;
            margin-top: 50px;
        }

        /* --- FOOTER ARTWORK --- */
        .footer-design {
            position: absolute;
            bottom: 0; left: 0; width: 100%; height: 90px;
            z-index: 1;
        }

        .footer-gold {
            position: absolute; bottom: 20px; right: 0;
            width: 80%; height: 50px;
            background: var(--brand-gold);
            clip-path: polygon(5% 0, 100% 0, 100% 100%, 0% 100%);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 50px;
            color: white;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
            font-size: 11px;
            gap: 25px;
        }

        .footer-dark {
            position: absolute; bottom: 0; left: 0;
            width: 100%; height: 20px;
            background: var(--brand-dark);
        }

        .footer-left {
            position: absolute; bottom: 30px; left: 50px;
            font-size: 12px; font-weight: 700; color: var(--brand-dark);
            display: flex; align-items: center; gap: 8px;
        }
        .footer-left i { color: var(--brand-gold); }

        /* --- BUTTONS --- */
        .action-bar { margin-top: 20px; display: flex; gap: 15px; }
        .btn { padding: 12px 25px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 14px; border: none; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-cancel { background: white; color: #555; border: 1px solid #ddd; }
        .btn-save { background: var(--brand-dark); color: white; }
        .btn-save:hover { background: var(--brand-gold); }

        /* --- PRINT --- */
        @media print {
            body { background: white; padding: 0; margin: 0; }
            .action-bar, .alert { display: none !important; }
            .page { box-shadow: none; margin: 0; width: 100%; height: 100%; }
            input, textarea { border: none !important; background: transparent !important; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            @page { margin: 0; size: auto; }
        }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/includes/header_employee.php'; ?>

    <main class="main-content">

    <div class="main-container">
    
    <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; max-width: 210mm; margin-bottom: 20px;">
        <h2 style="margin: 0; color: #1e293b;">New Official Letter</h2>
        <div>
            <a href="letter-records.php" class="btn btn-cancel" style="background: #e2e8f0; color: #334155; border: none;"><i class="fas fa-list"></i> My Records</a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert" style="background:#d4edda; color:#155724; padding:15px; border-radius:4px; margin-bottom:20px; width: 100%; max-width: 210mm;">
            <i class="fas fa-check-circle"></i> <?= $success ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="letterForm">
        <input type="hidden" name="action" value="submit_letter">
        
        <!-- A4 PAPER START -->
        <div class="page">
            
            <!-- HEADER -->
            <div class="header-design">
                <div class="shape-gold"></div>
                <div class="shape-dark"></div>
                
                <!-- Ref No in Header -->
                <div style="position: absolute; top: 30px; left: 40px; z-index: 5; color: var(--brand-dark);">
                    <span style="font-size: 10px; font-weight: 700; text-transform: uppercase; display: block; margin-bottom: 2px; color: #555;">REF NO.</span>
                    <input type="text" value="UGT/<?= date('Y') ?>/001" style="font-family: 'Montserrat', sans-serif; background: transparent; border: none; font-size: 14px; font-weight: 700; color: #333; width: 140px;">
                </div>

                <!-- Logo Section -->
                <div class="header-content" style="position: absolute; top: 20px; right: 0px; z-index: 10; display: flex; flex-direction: column; align-items: flex-end; padding-right: 20px;">
                    <img src="assets/images/Untitled.jpg" alt="Logo" style="height: 80px; width: auto; object-fit: contain; background: white; border-radius: 4px; margin-bottom: 10px;">
                    <div style="text-align: right; color: var(--brand-dark);">
                        <span style="font-size: 10px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 1px; display: block;">DATE</span>
                        <div style="font-weight: 700; font-size: 16px; margin-top: 2px; color: #333;"><?= date('m / d / Y') ?></div>
                    </div>
                </div>
            </div>

            <!-- BODY -->
            <div class="content-area" style="padding-top: 200px;">
                
                <!-- Date removed -->
                
                <!-- Recipient -->

                <!-- Recipient -->
                <div class="recipient-block">
                    <span class="label-text" style="color: #333; font-weight: 700;">TO:</span>
                    <input type="text" name="recipient_name" class="recipient-box" placeholder="[Recipient Name / Title]">
                    <input type="text" name="recipient_company" class="recipient-box" placeholder="[Company Name]">
                    <input type="text" name="recipient_address_1" class="recipient-box" placeholder="[Recipient Address Line 1]">
                    <input type="text" name="recipient_city_country" class="recipient-box" placeholder="[City, Country]">
                    <!-- Hidden textarea for backward compatibility if needed, or just combine on submit -->
                    <textarea name="recipient_address" hidden></textarea>
                </div>

                <!-- Subject -->
                <div class="subject-block">
                    <input type="text" name="subject" class="subject-input" placeholder="SUBJECT OF THE LETTER GOES HERE">
                </div>

                <!-- Salutation -->
                <input type="text" name="salutation" class="recipient-box" value="Dear Sir/Madam," style="font-weight: 600; margin-bottom: 15px;">

                <!-- Letter Body -->
                <textarea name="body" class="body-textarea" id="autoExpand" placeholder="Start typing your letter content here... The text area will automatically expand as you type more content."></textarea>

                <!-- Signature -->
                <div class="signature-section">
                    <input type="text" name="closing" value="Sincerely," style="margin-bottom: 40px;">
                    
                    <div class="sig-line">
                        <div style="font-weight: 700; text-transform: uppercase; color: var(--brand-dark);">
                            <?= htmlspecialchars($_SESSION['full_name'] ?? 'Authorized Signatory') ?>
                        </div>
                        <div style="font-size: 13px; color: #666;">
                            <?= htmlspecialchars($_SESSION['department'] ?? 'Manager') ?>
                        </div>
                    </div>
                </div>

            </div>

            <!-- FOOTER -->
            <div class="footer-design">
                <div class="footer-left">
                    <i class="fas fa-phone-alt"></i> +255 123 456 789
                </div>
                
                <div class="footer-dark"></div>
                
                <div class="footer-gold">
                    <div><i class="fas fa-envelope"></i> info@ultimategeneral.com</div>
                    <div><i class="fas fa-map-marker-alt"></i> Dar es Salaam, Tanzania</div>
                </div>
            </div>

        </div>
        <!-- A4 PAPER END -->

        <div class="action-bar">
            <a href="dashboard.php" class="btn btn-cancel">Cancel</a>
            <button type="button" class="btn btn-cancel" onclick="window.print()"><i class="fas fa-print"></i> Print Preview</button>
            <button type="submit" class="btn btn-save"><i class="fas fa-paper-plane"></i> Save Letter</button>
        </div>

    </form>
</div>
</main>

<!-- Auto-expand textarea script & Address Combiner -->
<script>
    const tx = document.getElementById("autoExpand");
    if(tx){
        tx.setAttribute("style", "height:" + (tx.scrollHeight) + "px;overflow-y:hidden;");
        tx.addEventListener("input", OnInput, false);
    }

    function OnInput() {
        this.style.height = "auto";
        this.style.height = (this.scrollHeight) + "px";
    }

    // Combine address fields on submit
    document.getElementById('letterForm').addEventListener('submit', function() {
        var addr1 = document.querySelector('input[name="recipient_address_1"]').value;
        var city = document.querySelector('input[name="recipient_city_country"]').value;
        document.querySelector('textarea[name="recipient_address"]').value = addr1 + "\n" + city;
    });
</script>

</body>
</html>