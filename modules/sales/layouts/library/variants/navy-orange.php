<?php
/** Layout 13  Navy & Orange Swoosh (React Layout3) */
/** @var array $lib */
/** @var callable $h */
/** @var callable $libMoney */
$bgUrl = rtrim($lib['assetBase'], '/') . '/bg-layout3.webp';
?>
<style>
.lib-sheet.lib-l3 {
  --navy: #2c3e5a;
  --navy-deep: #1f2d44;
  --orange: #f5a623;
  --ink: #232a36;
  --muted: #6a7280;
  box-sizing: border-box;
  position: relative;
  width: 210mm;
  min-height: 280mm;
  margin: 0 auto;
  background: #fff;
  overflow: hidden;
  font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
}
.pdf-mode .lib-sheet.lib-l3,
body.pdf-mode .lib-sheet.lib-l3 {
  width: 100% !important;
  min-height: auto !important;
}
@media print {
  .lib-sheet.lib-l3 {
    width: 210mm !important;
    max-width: 210mm !important;
    min-height: auto !important;
  }
}
.lib-l3 * { box-sizing: border-box; }
.lib-l3-bg {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: fill;
  pointer-events: none;
  z-index: 0;
}
.lib-l3-content {
  position: relative;
  z-index: 1;
  padding: 16mm 16mm 15mm;
  display: flex;
  flex-direction: column;
  color: var(--ink);
  font-size: 9.5pt;
  line-height: 1.4;
  min-height: 280mm;
}
.lib-l3-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 6mm;
  padding-right: 28mm;
  margin-bottom: 8mm;
}
.lib-l3-brand-name {
  font-size: 15pt;
  font-weight: 800;
  letter-spacing: -0.01em;
  color: var(--navy);
  margin: 0 0 2mm;
}
.lib-l3-brand-sub {
  font-size: 8.5pt;
  color: var(--muted);
  margin: 0;
  white-space: pre-line;
}
.lib-l3-title-block { text-align: right; }
.lib-l3-title {
  font-size: 26pt;
  font-weight: 800;
  letter-spacing: 0.06em;
  line-height: 1;
  color: var(--navy);
  margin: 0 0 3mm;
}
.lib-l3-meta {
  font-size: 9pt;
  color: var(--ink);
  margin: 0 0 1mm;
}
.lib-l3-meta span {
  display: inline-block;
  min-width: 10mm;
  color: var(--muted);
  text-align: left;
}
.lib-l3-billto { margin-bottom: 6mm; }
.lib-l3-label {
  font-size: 8pt;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--orange);
  margin: 0 0 2mm;
}
.lib-l3-label-gap { margin-top: 4mm; }
.lib-l3-name {
  font-size: 12pt;
  font-weight: 700;
  color: var(--navy);
  margin: 0 0 1mm;
}
.lib-l3-muted {
  font-size: 8.5pt;
  color: var(--muted);
  margin: 0 0 1mm;
  white-space: pre-line;
}
.lib-l3-muted strong { color: var(--ink); font-weight: 600; }
.lib-l3-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 6mm;
}
.lib-l3-table thead th {
  background: var(--navy);
  color: #fff;
  font-size: 8.5pt;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  padding: 3mm 3.5mm;
  text-align: left;
}
.lib-l3-table thead th:nth-child(n + 2) { text-align: center; width: 28mm; }
.lib-l3-table thead th:nth-child(3) { width: 16mm; }
.lib-l3-table tbody td {
  padding: 3.5mm;
  border-bottom: 1px solid rgba(44, 62, 90, 0.16);
  vertical-align: top;
  font-size: 9.5pt;
}
.lib-l3-table tbody td:nth-child(n + 2) {
  text-align: center;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
  color: #3b4452;
}
.lib-l3-item-name { font-weight: 700; color: var(--navy); margin: 0 0 1mm; }
.lib-l3-item-detail { font-size: 8pt; color: var(--muted); margin: 0; max-width: 90mm; }
.lib-l3-bottom {
  display: grid;
  grid-template-columns: 1fr 60mm;
  gap: 10mm;
  align-items: start;
}
.lib-l3-terms { max-width: 80mm; font-size: 8pt; }
.lib-l3-sums { display: grid; gap: 2mm; }
.lib-l3-sum-row {
  display: flex;
  justify-content: space-between;
  gap: 4mm;
  font-size: 9pt;
  color: var(--muted);
}
.lib-l3-sum-row strong { color: var(--ink); font-variant-numeric: tabular-nums; }
.lib-l3-sum-due {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 4mm;
  margin-top: 2mm;
  padding: 3mm 3.5mm;
  background: var(--navy);
  color: #fff;
  font-size: 10pt;
  font-weight: 700;
  border-left: 1.5mm solid var(--orange);
}
.lib-l3-sum-due strong { font-variant-numeric: tabular-nums; color: var(--orange); }
.lib-l3-foot {
  margin-top: auto;
  padding: 8mm 20mm 0 40mm;
  text-align: center;
}
.lib-l3-thanks {
  font-size: 11pt;
  font-weight: 700;
  color: var(--navy);
  margin: 0 0 1.5mm;
}
</style>

<div class="lib-sheet lib-l3">
  <img class="lib-l3-bg" src="<?= $h($bgUrl) ?>" alt="">

  <div class="lib-l3-content">
    <header class="lib-l3-head">
      <div class="lib-l3-brand">
        <p class="lib-l3-brand-name"><?= $h($lib['brand']) ?></p>
        <p class="lib-l3-brand-sub"><?= $h($lib['officeAddress']) ?></p>
        <?php if ($lib['phone'] !== ''): ?>
          <p class="lib-l3-brand-sub"><?= $h($lib['phone']) ?></p>
        <?php endif; ?>
      </div>
      <div class="lib-l3-title-block">
        <h1 class="lib-l3-title"><?= $h($lib['docTitle']) ?></h1>
        <p class="lib-l3-meta"><span>No.</span> <?= $h($lib['invoiceNumber']) ?></p>
        <p class="lib-l3-meta"><span>Date</span> <?= $h($lib['invoiceDate']) ?></p>
      </div>
    </header>

    <section class="lib-l3-billto">
      <p class="lib-l3-label">Invoice to</p>
      <p class="lib-l3-name"><?= $h($lib['customerName']) ?></p>
      <p class="lib-l3-muted"><?= $h($lib['customerAddress']) ?></p>
      <?php if ($lib['customerTin'] !== ''): ?>
        <p class="lib-l3-muted">TIN: <?= $h($lib['customerTin']) ?></p>
      <?php endif; ?>
    </section>

    <table class="lib-l3-table">
      <thead>
        <tr>
          <th>Description</th>
          <th>Unit Price</th>
          <th>Qty</th>
          <th>Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lib['items'] as $row): ?>
          <tr>
            <td>
              <p class="lib-l3-item-name"><?= $h($row['name']) ?></p>
              <?php if ($row['detail'] !== ''): ?>
                <p class="lib-l3-item-detail"><?= $h($row['detail']) ?></p>
              <?php endif; ?>
            </td>
            <td><?= $libMoney($row['unit_price']) ?></td>
            <td><?= $h(number_format($row['quantity'], 0)) ?></td>
            <td><?= $libMoney($row['line_total']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="lib-l3-bottom">
      <section class="lib-l3-pay">
        <p class="lib-l3-label">Payment info</p>
        <p class="lib-l3-muted"><?= nl2br($h($lib['paymentDetails'])) ?></p>
        <?php if ($lib['terms'] !== ''): ?>
        <p class="lib-l3-label lib-l3-label-gap">Terms</p>
        <p class="lib-l3-muted lib-l3-terms"><?= nl2br($h($lib['terms'])) ?></p>
        <?php endif; ?>
      </section>
      <section class="lib-l3-sums">
        <div class="lib-l3-sum-row">
          <span>Subtotal</span>
          <strong><?= $libMoney($lib['subtotal']) ?></strong>
        </div>
        <div class="lib-l3-sum-row">
          <span>Tax VAT<?= $lib['taxRatePct'] > 0 ? ' ' . $h((string) $lib['taxRatePct']) . '%' : '' ?></span>
          <strong><?= $libMoney($lib['taxAmount']) ?></strong>
        </div>
        <?php if ($lib['discountAmount'] > 0): ?>
          <div class="lib-l3-sum-row">
            <span>Discount<?= $lib['discountRatePct'] > 0 ? ' ' . $h((string) $lib['discountRatePct']) . '%' : '' ?></span>
            <strong><?= $libMoney($lib['discountAmount']) ?></strong>
          </div>
        <?php endif; ?>
        <div class="lib-l3-sum-due">
          <span>Total due</span>
          <strong><?= $libMoney($lib['balanceDue']) ?></strong>
        </div>
      </section>
    </div>

    <footer class="lib-l3-foot">
      <?php if ($lib['thanks'] !== ''): ?>
      <p class="lib-l3-thanks"><?= $h($lib['thanks']) ?></p>
      <?php endif; ?>
      <p class="lib-l3-muted">
        <?= $h(trim(implode('  ', array_filter([$lib['email'], $lib['phone']])))) ?>
      </p>
    </footer>
  </div>
</div>
