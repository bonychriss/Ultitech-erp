<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tasks Module</title>
  <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>" />
</head>
<body class="dashboard">
<?php require_once __DIR__ . '/../includes/header_employee.php'; ?>
<main class="main-content">
  <div class="form-container">
    <h2>Tasks Module (Placeholder)</h2>
    <p>Karibu. Hapa utaongeza task management features (placeholder).</p>
    <div style="margin-top:10px;">
      <a class="btn" href="../employee/dashboard.php">Rudi Payment Voucher</a>
      <a class="btn btn-secondary" href="../employee/sign.php">Nenda Sign (Attendance)</a>
    </div>
  </div>
</main>
</body>
</html>

