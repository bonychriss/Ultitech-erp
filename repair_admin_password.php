<?php
/**
 * One-time repair for admin@ultimatetrading.com password (broken seed hash).
 * Visit once, then delete this file.
 */

require_once __DIR__ . '/includes/functions.php';

header('Content-Type: text/html; charset=UTF-8');

$controlPdo = (isset($control_pdo) && $control_pdo instanceof PDO) ? $control_pdo : ((isset($pdo) && $pdo instanceof PDO) ? $pdo : null);

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Repair Admin Password</title></head><body>';
echo '<h1>Repair admin password</h1>';

if (!($controlPdo instanceof PDO)) {
    echo '<p style="color:#991b1b">No database connection.</p></body></html>';
    exit;
}

if (function_exists('repairLegacyAdminPasswordHash')) {
    repairLegacyAdminPasswordHash($controlPdo);
}

$ok = false;
$detail = '';
try {
    $st = $controlPdo->prepare('SELECT id, email, username, company_id, password FROM users WHERE email = ? OR username = ? LIMIT 1');
    $st->execute(array('admin@ultimatetrading.com', 'admin'));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $ok = password_verify('admin123', (string) ($row['password'] ?? ''));
        $detail = 'User #' . (int) $row['id'] . ', email=' . (string) $row['email'] . ', company_id=' . (int) ($row['company_id'] ?? 0);
        if ($ok && function_exists('authenticate')) {
            $authTest = authenticate('admin@ultimatetrading.com', 'admin123', 'ultimate');
            $detail .= ', authenticate(ultimate)=' . ($authTest ? 'PASS' : 'FAIL');
        }
    } else {
        $detail = 'admin user not found';
    }
} catch (Exception $e) {
    $detail = $e->getMessage();
}

echo '<p><strong>password_verify(admin123):</strong> ' . ($ok ? '<span style="color:#166534">OK</span>' : '<span style="color:#991b1b">FAIL</span>') . '</p>';
echo '<p>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</p>';
echo '<p><a href="login.php?next=my-account.php">Try login now</a></p>';
echo '<p>Delete <code>repair_admin_password.php</code> after success.</p>';
echo '</body></html>';
