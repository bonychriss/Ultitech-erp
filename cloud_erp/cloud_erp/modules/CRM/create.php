<?php
// modules/CRM/create.php
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/Database.php';

use Core\Auth;
use Core\Database;

Auth::check();
$user = Auth::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $contact = $_POST['contact_name'];
    $email = $_POST['email'];
    $value = $_POST['estimated_value'] ?? 0;
    
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("INSERT INTO crm_leads (company_id, title, contact_name, email, estimated_value, assigned_to) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user['erp_company_id'], $title, $contact, $email, $value, $user['erp_user_id']]);
    
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New Lead - CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5" style="max-width: 600px;">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="mb-0 fw-bold">Create New Lead</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Lead Title</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Website Inquiry" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Contact Name</label>
                            <input type="text" name="contact_name" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Estimated Value</label>
                        <input type="number" name="estimated_value" class="form-control" value="0.00" step="0.01">
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <a href="index.php" class="btn btn-light">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save Lead</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
