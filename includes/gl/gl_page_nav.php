<?php
$glNavActive = $glNavActive ?? '';
$glNavItems = [
    'journal' => ['gl-journal.php', 'Journal'],
    'trial' => ['trial-balance.php', 'Trial Balance'],
    'pl' => ['profit-loss.php', 'Profit & Loss'],
    'bs' => ['balance-sheet.php', 'Balance Sheet'],
    'cf' => ['cash-flow.php', 'Cash Flow'],
    'ledger' => ['ledger.php', 'General Ledger'],
    'categories' => ['gl-category-map.php', 'Voucher Categories'],
    'recon' => ['gl-reconciliation.php', 'Reconciliation'],
];
?>
<nav class="glr-nav">
  <?php foreach ($glNavItems as $key => [$href, $label]): ?>
    <a href="<?= htmlspecialchars($href) ?>" class="<?= $key === $glNavActive ? 'on' : '' ?>"><?= htmlspecialchars($label) ?></a>
  <?php endforeach; ?>
</nav>
