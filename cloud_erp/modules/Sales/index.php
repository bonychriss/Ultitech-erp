<?php
require_once __DIR__ . '/../../core/Auth.php';
use Core\Auth; // <--- This was missing!
Auth::check();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Sales Module</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light">
<div class="container p-5">
    <h3 class="mb-4">Sales & Invoicing</h3>
    <div class="row g-4">
        <div class="col-md-6">
            <a href="quotations.php" class="card p-5 text-center text-decoration-none shadow-sm hover-shadow">
                <h2 class="fw-bold text-primary">Quotations</h2>
                <p class="text-muted">Create and manage price quotes</p>
            </a>
        </div>
        <div class="col-md-6">
            <a href="invoices.php" class="card p-5 text-center text-decoration-none shadow-sm hover-shadow">
                <h2 class="fw-bold text-success">Invoices</h2>
                <p class="text-muted">View invoices and track payments</p>
            </a>
        </div>
    </div>
</div>
</body></html>