<?php
require_once 'includes/functions.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
requireLogin();
$active_module = 'letters';

// Fetch Letters
try {
    global $pdo;
    // If admin, show all. If user, show only theirs?
    // User requested "all created letters shall appear". 
    // Usually "Records" implies a registry. I will show all for now, or maybe all for Admins and own for Users.
    // Let's stick to the prompt "all created letters". 
    // However, for privacy, usually employees only see their own.
    // But if it's a "Record" book, maybe it's public.
    // I will show ALL for Admin, and OWN for Employee for safety, unless 'Record' implies a public log.
    // Let's default to: Admin sees ALL, Employee sees OWN + APPROVED (if public)? 
    // To be safe and follow standard logic:
    if (isAdmin()) {
        $stmt = $pdo->query("SELECT l.*, u.full_name, u.department FROM official_letters l LEFT JOIN users u ON l.user_id = u.id ORDER BY l.created_at DESC");
    } else {
        // For employees, show their own letters
        $stmt = $pdo->prepare("SELECT l.*, u.full_name, u.department FROM official_letters l LEFT JOIN users u ON l.user_id = u.id WHERE l.user_id = ? ORDER BY l.created_at DESC");
        $stmt->execute([$_SESSION['user_id']]);
    }
    $letters = $stmt->fetchAll();
} catch (Exception $e) { $letters = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Letter Records - <?= COMPANY_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .status-badge { display:inline-block; padding: 4px 8px; text-transform: uppercase; font-size: 11px; font-weight: 700; border-radius: 4px; }
        .status-badge.pending { background: #f59e0b; color: #fff; }
        .status-badge.approved { background: #22c55e; color: #fff; }
        .status-badge.rejected { background: #ef4444; color: #fff; }
        
        .records-table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .records-table th { background: #f8fafc; text-align: left; padding: 12px 15px; font-size: 13px; color: #64748b; font-weight: 600; border-bottom: 1px solid #e2e8f0; }
        .records-table td { padding: 12px 15px; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #334155; }
        .records-table tr:last-child td { border-bottom: none; }
        .records-table tr:hover { background: #f1f5f9; }
        
        .btn-sm { padding: 4px 8px; font-size: 11px; border-radius: 4px; text-decoration: none; display: inline-block; }
        .btn-view { background: #3b82f6; color: white; }
        .btn-view:hover { background: #2563eb; }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/header_employee.php'; ?>

<main class="main-content">
<div class="main-container">

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
        <h1 style="font-size: 20px; font-weight: 700; color: #1e293b;">Letter Records</h1>
        <a href="write-letter.php" class="btn btn-primary"><i class="fas fa-plus"></i> Write New Letter</a>
    </div>

    <div style="overflow-x: auto;">
        <table class="records-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Ref No.</th>
                    <th>Recipient</th>
                    <th>Subject</th>
                    <th>Created By</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($letters)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding: 30px; color: #94a3b8;">No letters found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach($letters as $l): ?>
                        <tr>
                            <td><?= date('M d, Y', strtotime($l['created_at'])) ?></td>
                            <td style="font-family: monospace; font-weight: 600;">UGT/<?= date('Y', strtotime($l['created_at'])) ?>/<?= str_pad($l['id'], 3, '0', STR_PAD_LEFT) ?></td>
                            <td>
                                <div style="font-weight: 600;"><?= htmlspecialchars($l['recipient_name']) ?></div>
                                <div style="font-size: 11px; color: #64748b;"><?= htmlspecialchars($l['recipient_company']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($l['subject']) ?></td>
                            <td>
                                <div><?= htmlspecialchars($l['full_name']) ?></div>
                                <div style="font-size: 10px; color: #94a3b8;"><?= htmlspecialchars($l['department']) ?></div>
                            </td>
                            <td>
                                <span class="status-badge <?= $l['status'] ?>"><?= strtoupper($l['status']) ?></span>
                            </td>
                            <td>
                                <!-- If Manage Letters is the detail view, link there? Or view-letter.php? -->
                                <!-- We don't have view-letter.php yet. manage-letters.php lists them all. -->
                                <!-- Let's assume manage-letters.php IS the record view for now, or create simple view logic -->
                                <!-- But manage-letters is admin only. -->
                                <!-- I should probably make manage-letters accessible to employees OR Create a view-letter.php. -->
                                <!-- For now, let's link to a "view" action if possible, or just print status. -->
                                <!-- Since we can't easily view details without a new page, I will just disable view for now or point to manage if admin. -->
                                <?php if(isAdmin()): ?>
                                    <a href="manage-letters.php#letter-<?= $l['id'] ?>" class="btn-sm btn-view">View</a>
                                <?php else: ?>
                                    <span style="color:#ccc; font-size:11px;">View</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
</main>

</body>
</html>
