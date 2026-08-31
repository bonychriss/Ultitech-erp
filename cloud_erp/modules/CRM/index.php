<?php
// modules/CRM/index.php
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Database.php';

use Core\Auth;
use Core\Database;

Auth::check();
$user = Auth::user();

// Fetch Leads
$pdo = Database::getInstance();
$stmt = $pdo->prepare("SELECT * FROM crm_leads WHERE company_id = ? ORDER BY created_at DESC");
$stmt->execute([$user['erp_company_id']]);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CRM - Leads</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>body { font-family: 'Inter', sans-serif; background: #f8f9fa; }</style>
</head>
<body>
    <div class="container-fluid p-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0">Leads</h4>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb small text-muted">
                        <li class="breadcrumb-item"><a href="../../index.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">CRM</li>
                    </ol>
                </nav>
            </div>
            <a href="create.php" class="btn btn-primary">+ New Lead</a>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Title</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th>Value</th>
                                <th>Assignments</th>
                                <th>Date</th>
                                <th class="text-end pe-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($leads)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">No leads found.</td></tr>
                            <?php else: ?>
                            <?php foreach($leads as $lead): ?>
                            <tr>
                                <td class="ps-4 fw-bold text-dark"><?= htmlspecialchars($lead['title']) ?></td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span><?= htmlspecialchars($lead['contact_name']) ?></span>
                                        <small class="text-muted"><?= htmlspecialchars($lead['email'] ?? $lead['phone']) ?></small>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                    $badge = match($lead['status']) {
                                        'new' => 'bg-secondary',
                                        'qualified' => 'bg-info',
                                        'won' => 'bg-success',
                                        'lost' => 'bg-danger',
                                        default => 'bg-primary'
                                    };
                                    ?>
                                    <span class="badge rounded-pill <?= $badge ?>"><?= ucfirst($lead['status']) ?></span>
                                </td>
                                <td><?= number_format($lead['estimated_value'], 2) ?></td>
                                <td><?= 'User #' . $lead['assigned_to'] ?></td>
                                <td class="text-muted small"><?= date('M d', strtotime($lead['created_at'])) ?></td>
                                <td class="text-end pe-4">
                                    <a href="#" class="btn btn-sm btn-light border">View</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>