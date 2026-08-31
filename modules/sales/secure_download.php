<?php
require_once '../../includes/config.php';

if (session_status() == PHP_SESSION_NONE) session_start();

$token = $_GET['token'] ?? '';

if (empty($token)) {
    die("Invalid link.");
}

// Check Token
try {
    $stmt = $pdo->prepare("SELECT * FROM sales_share_tokens WHERE token = ?");
    $stmt->execute([$token]);
    $share = $stmt->fetch();
} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}

if (!$share) {
    die("This link is invalid or has already been used. Please request a new link from the sender.");
}

// Check Expiry: Allow multiple downloads within the expiration window (24h)
$isLoggedIn = isset($_SESSION['user_id']);
$now = date('Y-m-d H:i:s');
$isExpired = $share['expires_at'] && $share['expires_at'] < $now;

if ($isExpired && !$isLoggedIn) {
    // Show nice error page for Expired
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Link Expired</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">
        <div class="card shadow-sm p-4 text-center" style="max-width: 400px; border-radius: 12px;">
            <div class="mb-3 text-danger" style="font-size: 3.5rem;">🕒</div>
            <h3 class="fw-bold">Link Expired</h3>
            <p class="text-muted">For security reasons, this document link was only valid for 24 hours.</p>
            <p class="small text-muted mb-4">The link expired on: <br><strong><?php echo date('d M Y, H:i', strtotime($share['expires_at'])); ?></strong></p>
            <div class="alert alert-warning py-2 small">
                Please contact <strong><?php echo htmlspecialchars($pdo->query("SELECT company_name FROM sales_settings LIMIT 1")->fetchColumn() ?: 'the sender'); ?></strong> to request a fresh link.
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// If Request is POST, Consume Token and Download
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Mark as Used ONLY if NOT logged in
    if (!$isLoggedIn) {
        $pdo->prepare("UPDATE sales_share_tokens SET used_at = NOW() WHERE id = ?")->execute([$share['id']]);
    }

    // Prepare context for print.php
    $_GET['id'] = $share['doc_id'];
    $_GET['download'] = 1; // Force print/download mode

    // Serve Document with CHDIR fix
    if ($share['doc_type'] === 'invoice') {
        chdir(__DIR__ . '/invoices');
        require 'print.php';
    } else {
        chdir(__DIR__ . '/orders');
        require 'print.php';
    }
    exit;
}

// GET Request: Show Landing Page
// Get Company Name for "Sent by..."
$compSettings = $pdo->query("SELECT company_name FROM sales_settings LIMIT 1")->fetch();
$compName = $compSettings['company_name'] ?? 'Company';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Download Document</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .download-card { max-width: 450px; width: 100%; border: none; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); }
        .btn-download { background-color: #008784; border-color: #008784; padding: 12px 30px; font-weight: 600; font-size: 1.1rem; transition: all 0.3s; }
        .btn-download:hover { background-color: #006c6a; border-color: #006c6a; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,135,132,0.3); }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center" style="height: 100vh;">
    <div class="card download-card p-5 text-center bg-white">
        <div class="mb-4">
            <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                <svg width="40" height="40" fill="none" stroke="#008784" stroke-width="2" viewBox="0 0 24 24"><path d="M12 15V3m0 12l-4-4m4 4l4-4M2 17l.621 2.485A2 2 0 0 0 4.561 21h14.878a2 2 0 0 0 1.94-1.515L22 17"></path></svg>
            </div>
        </div>
        <h2 class="mb-3 fw-bold text-dark">Document Ready</h2>
        <p class="text-muted mb-4">You have received a secure document from <strong><?php echo htmlspecialchars($compName); ?></strong>.</p>
        
        <?php if ($isLoggedIn): ?>
            <div class="alert alert-success py-2 small mb-4">
                <i class="bi bi-person-check me-1"></i> Logged in as staff. Token tracking bypassed.
            </div>
        <?php else: ?>
            <div class="alert alert-info py-2 small mb-4">
                <i class="bi bi-clock me-1"></i> This secure link is valid until:<br>
                <strong><?php 
                    $expiry = !empty($share['expires_at']) ? strtotime($share['expires_at']) : strtotime('+24 hours', strtotime($share['created_at']));
                    echo date('d M Y, H:i', $expiry); 
                ?></strong>
            </div>
        <?php endif; ?>

        <form method="POST">
            <button type="submit" class="btn btn-primary btn-download w-100 rounded-pill text-white">
                Download Now
            </button>
        </form>
        
        <div class="mt-4 pt-3 border-top">
            <small class="text-muted" style="font-size: 0.8rem;">Powered by Ultimate System</small>
        </div>
    </div>
</body>
</html>
<?php
// End of file
?>
