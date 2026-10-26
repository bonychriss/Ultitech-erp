<?php
/** Layout 15  Yellow Bar Classic (React Layout5) */
/** @var array $lib */
/** @var callable $h */
/** @var callable $libMoney */
$bgUrl = rtrim($lib['assetBase'], '/') . '/bg-layout5.webp';
?>
<style>
.lib-sheet.lib-l5 {
  --yellow: #ffd000;
  --ink: #2b303b;
  --muted: #6a7280;
  --dark: #2c3038;
  box-sizing: border-box;
  position: relative;
  width: 210mm;
  min-height: 294mm;
  margin: 0 auto;
  background: #fff;
  overflow: hidden;
  font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
  color: var(--ink);
  font-size: 9.5pt;
  line-height: 1.4;
}
.pdf-mode .lib-sheet.lib-l5,
body.pdf-mode .lib-sheet.lib-l5 {
  width: 100% !important;
  min-height: auto !important;
}
@media print {
  .lib-sheet.lib-l5 {
    width: 210mm !important;
    max-width: 210mm !important;
    min-height: auto !important;
  }
}
.lib-l5 * { box-sizing: border-box; }
.lib-l5-bg {
  display: none;
}
.lib-l5-content {
  position: relative;
  z-index: 1;
  padding: 14mm 16mm 12mm;
  min-height: 294mm;
  display: flex;
  flex-direction: column;
  background: #fff;
}
.lib-l5-brand {
  display: flex;
  align-items: center;
  gap: 3mm;
  margin-bottom: 6mm;
}
.lib-l5-logo-mark {
  width: 12mm;
  height: 12mm;
  color: var(--dark);
  object-fit: contain;
}
.lib-l5-logo-svg {
  width: 12mm;
  height: 12mm;
  color: var(--dark);
  flex-shrink: 0;
}
.lib-l5-brand-name {
  font-size: 14pt;
  font-weight: 800;
  letter-spacing: -0.02em;
  line-height: 1;
  color: var(--dark);
  margin: 0;
}
.lib-l5-brand-tag {
  margin: 1mm 0 0;
  font-size: 7pt;
  letter-spacing: 0.12em;
  color: var(--muted);
}
.lib-l5-title-band {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: stretch;
  min-height: 12mm;
  margin: 0 0 8mm;
}
.lib-l5-title-band::before,
.lib-l5-title-band::after {
  content: '';
  background: var(--yellow);
}
.lib-l5-title {
  margin: 0;
  padding: 0 6mm;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 26pt;
  font-weight: 800;
  letter-spacing: 0.04em;
  color: var(--dark);
  line-height: 1;
  background: #fff;
}
.lib-l5-meta {
  display: flex;
  justify-content: space-between;
  margin-bottom: 10mm;
}
.lib-l5-to-label {
  font-size: 11pt;
  font-weight: 700;
  margin: 0 0 2mm;
}
.lib-l5-to-name {
  font-size: 11pt;
  font-weight: 700;
  margin: 0 0 1.5mm;
}
.lib-l5-muted {
  font-size: 8.5pt;
  color: var(--muted);
  line-height: 1.45;
  margin: 0;
  white-space: pre-line;
}
.lib-l5-ids { margin-top: 7mm; min-width: 55mm; }
.lib-l5-id-row {
  display: flex;
  justify-content: space-between;
  gap: 8mm;
  font-size: 9pt;
  line-height: 1.7;
}
.lib-l5-id-row span { color: var(--muted); }
.lib-l5-id-row strong { font-weight: 600; text-align: right; }
.lib-l5-table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  margin-bottom: 8mm;
  background: #fff;
}
.lib-l5-table thead th {
  height: 12mm;
  color: #fff;
  font-size: 9pt;
  font-weight: 700;
  padding: 0 3mm;
  text-align: left;
  background: var(--dark);
}
.lib-l5-table .col-sl { width: 10%; text-align: center; }
.lib-l5-table .col-desc { width: 42%; }
.lib-l5-table .col-num { width: 18%; text-align: right; }
.lib-l5-table .col-qty { width: 12%; text-align: center; }
.lib-l5-table thead th.col-num,
.lib-l5-table thead th.col-qty,
.lib-l5-table thead th.col-sl { text-align: center; }
.lib-l5-table tbody td {
  padding: 3.5mm 3mm;
  font-size: 9.5pt;
  font-weight: 500;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
  border-bottom: 1px solid #d5d8de;
  background: #fff;
}
.lib-l5-table tbody tr:last-child td { border-bottom: 1.5px solid var(--dark); }
.lib-l5-mid {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 8mm;
  margin-bottom: 10mm;
}
.lib-l5-thanks {
  font-size: 11pt;
  font-weight: 700;
  margin: 0 0 5mm;
}
.lib-l5-terms-label {
  font-size: 9.5pt;
  font-weight: 700;
  margin: 0 0 1.5mm;
}
.lib-l5-terms {
  font-size: 8pt;
  color: var(--muted);
  max-width: 80mm;
  line-height: 1.45;
  margin: 0;
  white-space: pre-line;
}
.lib-l5-sums { width: 75mm; flex-shrink: 0; padding-top: 1mm; }
.lib-l5-sum-row {
  display: flex;
  justify-content: space-between;
  font-size: 9pt;
  line-height: 1.85;
  padding: 0 3.5mm;
}
.lib-l5-sum-row span { color: var(--muted); }
.lib-l5-sum-row strong { font-weight: 600; font-variant-numeric: tabular-nums; }
.lib-l5-total {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 2.5mm;
  height: 11mm;
  padding: 0 3.5mm;
  background: var(--yellow);
  font-size: 11pt;
  font-weight: 800;
  color: var(--dark);
}
.lib-l5-total strong { font-variant-numeric: tabular-nums; }
.lib-l5-pay { margin-bottom: 10mm; }
.lib-l5-pay-title {
  font-size: 9.5pt;
  font-weight: 700;
  margin: 0 0 1.5mm;
}
.lib-l5-pay p {
  font-size: 8.5pt;
  line-height: 1.55;
  color: var(--ink);
  margin: 0 0 1mm;
  white-space: pre-line;
}
.lib-l5-sign-row {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 10mm;
}
.lib-l5-sign {
  min-width: 48mm;
  font-size: 9pt;
  font-weight: 700;
  color: var(--ink);
  text-align: center;
}
.lib-l5-sign img { max-height: 14mm; max-width: 40mm; display: block; margin: 0 auto 2mm; }
.lib-l5-sign-label {
  display: block;
  margin-top: 2mm;
  padding: 2.5mm 6mm;
  background: var(--yellow);
  color: #fff;
  font-weight: 700;
}
.lib-l5-foot {
  margin-top: auto;
  padding-top: 4mm;
  border-top: 2.5px solid var(--yellow);
  font-size: 8.5pt;
  color: var(--ink);
}
.lib-l5-foot span { color: var(--muted); padding: 0 1.5mm; }
</style>

<div class="lib-sheet lib-l5">
  <img class="lib-l5-bg" src="<?= $h($bgUrl) ?>" alt="">

  <div class="lib-l5-content">
    <header class="lib-l5-brand">
      <?php if ($lib['logoUrl'] !== ''): ?>
        <img class="lib-l5-logo-mark" src="<?= $h($lib['logoUrl']) ?>" alt="">
      <?php else: ?>
        <svg class="lib-l5-logo-svg" viewBox="0 0 48 48" aria-hidden="true">
          <rect x="3" y="3" width="42" height="42" rx="6" fill="none" stroke="currentColor" stroke-width="2.4"/>
          <path d="M14 30 L24 16 L34 30" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      <?php endif; ?>
      <div>
        <p class="lib-l5-brand-name"><?= $h($lib['brand']) ?></p>
        <?php if ($lib['tagline'] !== ''): ?>
          <p class="lib-l5-brand-tag"><?= $h($lib['tagline']) ?></p>
        <?php endif; ?>
      </div>
    </header>

    <div class="lib-l5-title-band">
      <h1 class="lib-l5-title"><?= $h($lib['docTitle']) ?></h1>
    </div>

    <div class="lib-l5-meta">
      <section>
        <p class="lib-l5-to-label">Invoice to:</p>
        <p class="lib-l5-to-name"><?= $h($lib['customerName']) ?></p>
        <p class="lib-l5-muted"><?= $h($lib['customerAddress']) ?></p>
      </section>
      <section class="lib-l5-ids">
        <div class="lib-l5-id-row">
          <span>Invoice#</span>
          <strong><?= $h($lib['invoiceNumber']) ?></strong>
        </div>
        <div class="lib-l5-id-row">
          <span>Date</span>
          <strong><?= $h($lib['invoiceDate']) ?></strong>
        </div>
      </section>
    </div>

    <table class="lib-l5-table">
      <thead>
        <tr>
          <th class="col-sl">SL.</th>
          <th class="col-desc">Item Description</th>
          <th class="col-num">Price</th>
          <th class="col-qty">Qty.</th>
          <th class="col-num">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lib['items'] as $i => $row): ?>
          <tr>
            <td class="col-sl"><?= $h((string) ($i + 1)) ?></td>
            <td class="col-desc"><?= $h($row['name']) ?></td>
            <td class="col-num"><?= $libMoney($row['unit_price']) ?></td>
            <td class="col-qty"><?= $h(number_format($row['quantity'], 0)) ?></td>
            <td class="col-num"><?= $libMoney($row['line_total']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="lib-l5-mid">
      <div>
        <?php if ($lib['thanks'] !== ''): ?>
        <p class="lib-l5-thanks"><?= $h($lib['thanks']) ?></p>
        <?php endif; ?>
        <?php if ($lib['terms'] !== ''): ?>
        <p class="lib-l5-terms-label">Terms &amp; Conditions</p>
        <p class="lib-l5-terms"><?= nl2br($h($lib['terms'])) ?></p>
        <?php endif; ?>
      </div>
      <div class="lib-l5-sums">
        <div class="lib-l5-sum-row">
          <span>Sub Total:</span>
          <strong><?= $libMoney($lib['subtotal']) ?></strong>
        </div>
        <div class="lib-l5-sum-row">
          <span>Tax:</span>
          <strong><?= $libMoney($lib['taxAmount']) ?></strong>
        </div>
        <div class="lib-l5-total">
          <span>Total:</span>
          <strong><?= $libMoney($lib['totalAmount']) ?></strong>
        </div>
      </div>
    </div>

    <section class="lib-l5-pay">
      <p class="lib-l5-pay-title">Payment Info:</p>
      <p><?= nl2br($h($lib['paymentDetails'])) ?></p>
    </section>

    <div class="lib-l5-sign-row">
      <div class="lib-l5-sign">
        <?php if ($lib['signatureUrl'] !== ''): ?>
          <img src="<?= $h($lib['signatureUrl']) ?>" alt="">
        <?php endif; ?>
        <span class="lib-l5-sign-label"><?= $h($lib['signerRole']) ?></span>
      </div>
    </div>

    <footer class="lib-l5-foot">
      <p>
        <?= $h($lib['phone']) ?>
        <?php if ($lib['officeAddress'] !== ''): ?>
          <span>|</span> <?= $h(preg_replace('/\s+/', ' ', $lib['officeAddress'])) ?>
        <?php endif; ?>
        <?php if ($lib['website'] !== ''): ?>
          <span>|</span> <?= $h($lib['website']) ?>
        <?php endif; ?>
      </p>
    </footer>
  </div>
</div>
