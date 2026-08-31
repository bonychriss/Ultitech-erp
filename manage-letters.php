<?php
require_once 'includes/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
requireLogin();
$active_module = 'letters';

// Strict Admin/Boss Check
// Since requirements said "boss", assuming admin role here.
if (!isAdmin()) {
    die("Access Denied: Management Only.");
}

// Handle Actions (Approve/Reject/Reply)
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['letter_id'])) {
    $letterId = intval($_POST['letter_id']);
    $status = ($_POST['action'] === 'approve') ? 'approved' : 'rejected';
    $reply = trim($_POST['reply'] ?? '');
    
    try {
        global $pdo;
        $stmt = $pdo->prepare("UPDATE official_letters SET status = ?, reply = ? WHERE id = ?");
        $stmt->execute([$status, $reply, $letterId]);
        $msg = "Letter updated successfully!";
    } catch (PDOException $e) {
        $msg = "Error: " . $e->getMessage();
    }
}

// Fetch Letters
try {
    global $pdo;
    $stmt = $pdo->query("SELECT l.*, u.full_name, u.department FROM official_letters l JOIN users u ON l.user_id = u.id ORDER BY field(l.status, 'pending', 'approved', 'rejected'), l.created_at DESC");
    $letters = $stmt->fetchAll();
} catch (Exception $e) { $letters = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Letters - <?= COMPANY_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* --- GLOBAL & DASHBOARD STYLES --- */
        :root {
            --brand-orange: #f79f1f;
            --brand-dark: #2f3542;
            --text-grey: #57606f;
            --bg-dashboard: #0f172a;
        }

        body { 
            font-family: 'Poppins', sans-serif; 
            background: var(--bg-dashboard); 
            color: #f8fafc; 
            margin: 0; 
            padding: 20px; 
        }
        
        .container { max-width: 1000px; margin: 0 auto; }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 1px solid #334155; padding-bottom: 20px; }
        h1 { margin: 0; font-size: 24px; color: white; }
        
        .card { background: #1e293b; padding: 25px; margin-bottom: 30px; border-left: 4px solid #3b82f6; }
        .card.pending { border-left-color: #f59e0b; }
        .card.approved { border-left-color: #22c55e; }
        .card.rejected { border-left-color: #ef4444; }
        
        .meta { display: flex; justify-content: space-between; font-size: 13px; color: #94a3b8; margin-bottom: 10px; }
        .status-badge { display:inline-block; padding: 4px 8px; text-transform: uppercase; font-size: 11px; font-weight: 700; border-radius: 4px; margin-bottom:15px; }
        .status-badge.pending { background: #f59e0b; color: #fff; }
        .status-badge.approved { background: #22c55e; color: #fff; }
        .status-badge.rejected { background: #ef4444; color: #fff; }

        /* --- THE A4 PAPER CONTAINER --- */
        .letter-paper {
            width: 210mm;
            min-height: 297mm;
            background: white;
            position: relative;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            margin: 0 auto 20px auto;
            overflow: hidden;
            color: #333;
        }

        /* --- COMPONENT STYLES REFLECTED FROM WRITE-LETTER.PHP --- */
        .header-shape-orange {
            position: absolute; top: 0; left: 0; width: 70%; height: 110px;
            background-color: var(--brand-orange);
            clip-path: polygon(0 0, 100% 0, 85% 100%, 0% 100%);
            z-index: 1;
        }
        .header-shape-dark {
            position: absolute; top: 0; right: 0; width: 40%; height: 35px;
            background-color: var(--brand-dark);
            clip-path: polygon(15% 0, 100% 0, 100% 100%, 0% 100%);
            z-index: 2;
        }
        .header-logo-section {
            position: absolute; top: 50px; right: 50px; text-align: right; z-index: 3;
        }
        .logo-title {
            font-size: 22px; font-weight: 700; color: var(--brand-dark);
            text-transform: uppercase; letter-spacing: 1px;
        }
        .logo-sub {
            font-size: 10px; color: var(--text-grey); letter-spacing: 2px; text-transform: uppercase;
        }
        
        .content-area {
            position: relative; z-index: 5;
            padding: 160px 50px 150px 50px;
        }
        .bg-pattern {
            position: absolute; top: 150px; left: 0; width: 100%; height: 60%;
            opacity: 0.03; z-index: 0; pointer-events: none;
            background-image: radial-gradient(#000 1px, transparent 1px);
            background-size: 20px 20px;
        }

        /* Text Styles for Display */
        .disp-date { font-weight: 600; }
        .disp-recipient { font-weight: 700; font-size: 14px; }
        .disp-subject { font-weight: 700; text-transform: uppercase; font-size: 15px; border-bottom: 1px solid #333; display:inline-block; }
        .disp-body { line-height: 1.8; font-size: 14px; text-align: justify; white-space: pre-wrap; margin-top: 20px; margin-bottom: 40px;}

        /* Footer */
        .footer-wrapper { position: absolute; bottom: 0; width: 100%; height: 100px; }
        .footer-dark-strip { position: absolute; bottom: 0; left: 0; width: 100%; height: 25px; background: var(--brand-dark); z-index: 1; }
        .footer-orange-shape {
            position: absolute; bottom: 25px; right: 0; width: 85%; height: 60px;
            background: var(--brand-orange);
            clip-path: polygon(5% 0, 100% 0, 100% 100%, 0% 100%);
            z-index: 2;
            display: flex; align-items: center; justify-content: flex-end;
            padding-right: 50px; gap: 30px; color: white;
        }
        .footer-item { display: flex; align-items: center; gap: 8px; font-size: 11px; }
        .footer-item i { background: rgba(255,255,255,0.2); padding: 5px; border-radius: 4px; }
        .footer-left-contact {
            position: absolute; bottom: 35px; left: 40px; font-size: 12px; font-weight: 700;
            color: var(--brand-dark); z-index: 3; display: flex; gap: 10px;
        }
        .footer-left-contact i { color: var(--brand-orange); }

        /* Admin Controls */
        .actions { background: #334155; padding: 15px; margin-top: 15px; }
        textarea.reply-box { width: 100%; background: #1e293b; color: white; border: 1px solid #475569; padding: 10px; font-family: inherit; margin-bottom: 10px; box-sizing: border-box; }
        .btn { border: none; padding: 8px 16px; cursor: pointer; font-weight: 600; text-transform: uppercase; font-size: 12px; }
        .btn-approve { background: #22c55e; color: white; }
        .btn-reject { background: #ef4444; color: white; }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/header_employee.php'; ?>

<main class="main-content">

<div class="container">
    <div class="header">
        <h1>Official Letters Inbox</h1>
        <a href="select-module.php" style="color: #94a3b8; text-decoration: none;">← Back to Hub</a>
    </div>
    
    <?php if($msg): ?><div style="background:#3b82f6; color:white; padding:10px; margin-bottom:20px;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <?php foreach($letters as $l): ?>
        <div class="card <?= $l['status'] ?>">
            <div class="meta">
                <span>
                    <i class="fas fa-user-circle"></i> <strong><?= htmlspecialchars($l['full_name']) ?></strong> from <?= htmlspecialchars($l['department']) ?>
                </span>
                <span><?= date('M d, Y H:i', strtotime($l['created_at'])) ?></span>
            </div>
            
            <div class="status-badge <?= $l['status'] ?>">
                <?= strtoupper($l['status']) ?>
            </div>

            <!-- LETTERHEAD VIEW -->
            <div class="letter-paper">
                <!-- HEADER ART -->
                <div class="header-shape-orange"></div>
                <div class="header-shape-dark"></div>
                
                <!-- Ref No (Static) -->
                <div style="position: absolute; top: 30px; left: 40px; z-index: 5;">
                    <span style="font-size: 10px; font-weight: 700; text-transform: uppercase; display: block; margin-bottom: 2px; color: #555;">REF NO.</span>
                    <div style="font-family: 'Montserrat', sans-serif; font-size: 14px; font-weight: 700; color: #333;">UGT/<?= date('Y') ?>/001</div>
                </div>

                <!-- Logo Section -->
                <div class="header-logo-section" style="position: absolute; top: 20px; right: 0px; z-index: 10; display: flex; flex-direction: column; align-items: flex-end; padding-right: 20px;">
                    <img src="assets/images/Untitled.jpg" alt="Logo" style="height: 80px; width: auto; object-fit: contain; background: white; border-radius: 4px; margin-bottom: 10px;">
                     <div style="text-align: right; color: var(--brand-dark);">
                        <span style="font-size: 10px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 1px; display: block;">DATE</span>
                        <div style="font-weight: 700; font-size: 16px; margin-top: 2px; color: #333;"><?= date('m / d / Y', strtotime($l['letter_date'] ?: $l['created_at'])) ?></div>
                    </div>
                </div>

                <!-- BACKGROUND PATTERN -->
                <div class="bg-pattern"></div>

                <!-- LETTER CONTENT -->
                <div class="content-area" style="position: relative; z-index: 5; padding: 200px 60px 100px 60px;">
                    
                    <!-- Date Removed (Moved to header) -->

                    <!-- Recipient -->
                    <div style="margin-bottom: 30px;">
                        <div style="font-weight: 700; margin-bottom: 5px; color: #444;">To,</div>
                        <div class="disp-recipient"><?= htmlspecialchars($l['recipient_name'] ?: '[Recipient Name]') ?></div>
                        <div style="font-size:13px;"><?= htmlspecialchars($l['recipient_company'] ?: '') ?></div>
                        <div style="white-space: pre-line; font-size: 13px;"><?= htmlspecialchars($l['recipient_address'] ?? '') ?></div>
                    </div>

                    <!-- Subject -->
                    <div style="text-align: center; margin: 40px 0;">
                        <div style="display: inline-block; border-bottom: 2px solid #333; padding-bottom: 2px;">
                            <span style="font-weight:800; font-size:15px; text-transform: uppercase;">SUBJECT:</span>
                            <span class="disp-subject" style="border:none;"><?= htmlspecialchars($l['subject']) ?></span>
                        </div>
                    </div>

                    <!-- Salutation -->
                    <div style="margin-bottom: 20px;">
                        <span style="font-weight:600;"><?= htmlspecialchars($l['salutation'] ?: 'Dear Sir/Madam,') ?></span>
                    </div>

                    <!-- Body -->
                    <div class="disp-body" style="line-height: 1.8; text-align: justify; margin-top: 20px; margin-bottom: 40px;">
<?= htmlspecialchars($l['body']) ?>
                    </div>

                    <!-- Closing -->
                    <div style="margin-top: 60px; max-width: 300px;">
                        <div style="margin-bottom:50px;"><?= htmlspecialchars($l['closing'] ?: 'Sincerely,') ?></div>
                        
                        <div style="position: relative; margin-top: 20px;">
                            <div style="width: 250px; border-top: 1px solid #333; margin-bottom: 10px;"></div>
                            <div style="font-weight:700; color:var(--brand-orange); text-transform:uppercase; font-size: 14px;">
                                <?= htmlspecialchars($l['sender_name'] ?: $l['full_name']) ?>
                            </div>
                            <div style="font-size:12px; font-weight:600; color:#555; text-transform: capitalize;">
                                <?= htmlspecialchars($l['sender_dept'] ?: $l['department']) ?>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- FOOTER ART -->
                <div class="footer-wrapper">
                    <div class="footer-left-contact">
                        <i class="fas fa-phone-alt"></i>
                        <span>+255 123 456 789</span>
                    </div>
                    <div class="footer-dark-strip"></div>
                    <div class="footer-orange-shape">
                        <div style="flex:1;"></div> 
                        <div class="footer-item">
                            <i class="fas fa-envelope"></i>
                            <span>info@ultimategeneral.com</span>
                        </div>
                        <div class="footer-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <span>Building 123, Dar es Salaam</span>
                        </div>
                    </div>
                </div>

            </div>
            <!-- END LETTERHEAD VIEW -->
            
            <?php if($l['reply']): ?>
                <div style="font-size:14px; color:#334155; margin-top:20px; background:#f1f5f9; padding:15px; border-left:4px solid #3b82f6;">
                    <strong><i class="fas fa-reply"></i> Your Reply:</strong><br>
                    <?= nl2br(htmlspecialchars($l['reply'])) ?>
                </div>
            <?php endif; ?>

            <?php if($l['status'] === 'pending'): ?>
            <div class="actions">
                <form method="POST">
                    <input type="hidden" name="letter_id" value="<?= $l['id'] ?>">
                    <textarea name="reply" class="reply-box" rows="2" placeholder="Write a reply/reason (optional)..."></textarea>
                    <div style="display:flex; gap:10px;">
                        <button type="submit" name="action" value="approve" class="btn btn-approve">Approve Request</button>
                        <button type="submit" name="action" value="reject" class="btn btn-reject">Reject Request</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    
    <?php if(empty($letters)): ?>
        <div style="text-align:center; color:#64748b; padding:50px;">No letters found.</div>
    <?php endif; ?>
</div>
</main>

</body>
</html>
