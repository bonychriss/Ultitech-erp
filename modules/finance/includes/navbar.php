<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<div class="finance-nav mb-4">
    <a href="index.php" class="nav-link <?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
        <i class="fas fa-home"></i> Overview
    </a>
    <a href="transactions.php" class="nav-link <?php echo $current_page == 'transactions.php' ? 'active' : ''; ?>">
        <i class="fas fa-list"></i> Transactions
    </a>
    <a href="budgets.php" class="nav-link <?php echo $current_page == 'budgets.php' ? 'active' : ''; ?>">
        <i class="fas fa-bullseye"></i> Budgets
    </a>
    <a href="reports.php" class="nav-link <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>">
        <i class="fas fa-chart-pie"></i> Reports
    </a>
</div>
