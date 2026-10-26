<?php
/** Layout 11  Purple Classic (React Layout1) */
/** @var array $lib */
/** @var callable $h */
/** @var callable $libMoney */
?>
<style>
.lib-sheet.lib-l1 {
  --purple: #5b4ea3;
  box-sizing: border-box;
  width: 210mm;
  min-height: auto;
  margin: 0 auto;
  padding: 14mm 12mm 10mm;
  background: #fff;
  color: #2a2a2a;
  font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
  position: relative;
}
@media screen {
  .lib-sheet.lib-l1 { min-height: 297mm; }
}
.pdf-mode .lib-sheet.lib-l1,
body.pdf-mode .lib-sheet.lib-l1 {
  width: 100% !important;
  padding: 10mm 12mm !important;
  min-height: auto !important;
}
@media print {
  .lib-sheet.lib-l1 {
    width: 210mm !important;
    max-width: 210mm !important;
    min-height: auto !important;
    margin: 0 auto !important;
    padding: 8mm 10mm !important;
  }
}
.lib-l1 * { box-sizing: border-box; }
.lib-l1-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 8mm;
  margin-bottom: 8mm;
}
.lib-l1-brand { display: flex; flex-direction: column; align-items: flex-start; gap: 2.5mm; }
.lib-l1-logo-img { width: 14mm; height: 14mm; object-fit: contain; }
.lib-l1-logo-svg { width: 14mm; height: 14mm; color: var(--purple); }
.lib-l1-brand-name { font-size: 12pt; font-weight: 700; color: #1a1a1a; margin: 0; }
.lib-l1-title-block { text-align: right; }
.lib-l1-title {
  font-size: 28pt;
  font-weight: 800;
  letter-spacing: 0.02em;
  color: var(--purple);
  line-height: 1;
  margin: 0;
}
.lib-l1-date { margin-top: 2mm; font-size: 10pt; color: #555; }
.lib-l1-parties {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10mm;
  margin-bottom: 8mm;
}
.lib-l1-party h2 {
  font-size: 10pt;
  font-weight: 700;
  color: #1a1a1a;
  margin: 0 0 2mm;
}
.lib-l1-party p {
  font-size: 9pt;
  line-height: 1.45;
  color: #666;
  margin: 0;
  white-space: pre-line;
}
.lib-l1-party-phone { margin-top: 1.5mm !important; }
.lib-l1-party-to { text-align: right; }
.lib-l1-party-name { font-weight: 700 !important; color: #1a1a1a !important; margin-bottom: 1mm !important; }
.lib-l1-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 7mm;
}
.lib-l1-table thead th {
  background: var(--purple);
  color: #fff;
  font-size: 9pt;
  font-weight: 700;
  padding: 3.5mm 4mm;
  text-align: left;
}
.lib-l1-table thead th.col-price,
.lib-l1-table thead th.col-qty,
.lib-l1-table thead th.col-total { text-align: center; }
.lib-l1-table tbody td {
  padding: 4mm;
  border-bottom: 1px solid #e6e4ee;
  vertical-align: top;
  font-size: 9.5pt;
}
.lib-l1-item-name { font-weight: 700; color: #1a1a1a; margin: 0 0 1mm; }
.lib-l1-item-detail { font-size: 8pt; line-height: 1.4; color: #888; margin: 0; max-width: 90mm; }
.lib-l1-table .col-price,
.lib-l1-table .col-qty,
.lib-l1-table .col-total {
  text-align: center;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
  color: #444;
  width: 28mm;
}
.lib-l1-table .col-qty { width: 16mm; }
.lib-l1-mid {
  display: grid;
  grid-template-columns: 1fr 55mm;
  gap: 8mm;
  align-items: start;
  margin-bottom: 8mm;
}
.lib-l1-note h2 { font-size: 10pt; font-weight: 700; margin: 0 0 2mm; }
.lib-l1-note p { font-size: 8.5pt; line-height: 1.5; color: #888; margin: 0; max-width: 80mm; white-space: pre-line; }
.lib-l1-sums { display: grid; gap: 2mm; }
.lib-l1-sum-row {
  display: flex;
  justify-content: space-between;
  gap: 4mm;
  font-size: 8.5pt;
  color: #666;
}
.lib-l1-sum-row strong { font-weight: 700; color: #333; font-variant-numeric: tabular-nums; }
.lib-l1-sum-due {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 4mm;
  margin-top: 1.5mm;
  padding: 3mm 3.5mm;
  background: var(--purple);
  color: #fff;
  font-size: 9.5pt;
  font-weight: 700;
}
.lib-l1-sum-due strong { font-variant-numeric: tabular-nums; }
.lib-l1-thanks {
  font-size: 11pt;
  font-weight: 700;
  color: var(--purple);
  margin: 0 0 3.5mm;
}
.lib-l1-rule {
  border: none;
  border-top: 1.5px solid var(--purple);
  margin: 0 0 5mm;
}
.lib-l1-foot {
  display: grid;
  grid-template-columns: 1fr 1fr 1.15fr;
  gap: 6mm;
}
.lib-l1-foot h3 { font-size: 9.5pt; font-weight: 700; margin: 0 0 2mm; }
.lib-l1-foot p { font-size: 8pt; line-height: 1.45; color: #777; margin: 0 0 1mm; white-space: pre-line; }
.lib-l1-foot-label { font-weight: 600; color: #555; }
.lib-l1-sig { margin-top: 6mm; text-align: right; }
.lib-l1-sig img { max-height: 16mm; max-width: 40mm; }
</style>

<div class="lib-sheet lib-l1">
  <header class="lib-l1-head">
    <div class="lib-l1-brand">
      <?php if ($lib['logoUrl'] !== ''): ?>
        <img class="lib-l1-logo-img" src="<?= $h($lib['logoUrl']) ?>" alt="">
      <?php else: ?>
        <svg class="lib-l1-logo-svg" viewBox="0 0 72 64" aria-hidden="true">
          <path d="M38 8c-14 0-26 11-26 24s12 24 26 24c8.2 0 15.5-3.6 20.2-9.3l-7.4-6.2C47.5 44.8 43 47.2 38 47.2c-8.4 0-15.2-6.8-15.2-15.2S29.6 16.8 38 16.8c5 0 9.5 2.4 12.4 6.3l7.4-6.2C53.5 11.6 46.2 8 38 8z" fill="currentColor"/>
          <path d="M52 14c-10.5 0-19 8.5-19 19s8.5 19 19 19c6 0 11.3-2.6 15-6.8l-5.8-4.8c-2.2 2.5-5.5 4.1-9.2 4.1-6.6 0-12-5.4-12-12s5.4-12 12-12c3.7 0 7 1.6 9.2 4.1l5.8-4.8C63.3 16.6 58 14 52 14z" fill="currentColor" opacity="0.55"/>
        </svg>
      <?php endif; ?>
      <p class="lib-l1-brand-name"><?= $h($lib['brand']) ?></p>
    </div>
    <div class="lib-l1-title-block">
      <h1 class="lib-l1-title"><?= $h($lib['docTitle']) ?></h1>
      <p class="lib-l1-date"><?= $h($lib['invoiceDateLong']) ?></p>
    </div>
  </header>

  <div class="lib-l1-parties">
    <section class="lib-l1-party">
      <h2>Office Address</h2>
      <p><?= $h($lib['officeAddress']) ?></p>
      <?php if ($lib['phone'] !== ''): ?>
        <p class="lib-l1-party-phone"><?= $h($lib['phone']) ?></p>
      <?php endif; ?>
    </section>
    <section class="lib-l1-party lib-l1-party-to">
      <h2>To :</h2>
      <p class="lib-l1-party-name"><?= $h($lib['customerName']) ?></p>
      <p><?= $h($lib['customerAddress']) ?></p>
      <?php if ($lib['customerTin'] !== ''): ?>
        <p>TIN: <?= $h($lib['customerTin']) ?></p>
      <?php endif; ?>
    </section>
  </div>

  <table class="lib-l1-table">
    <thead>
      <tr>
        <th class="col-desc">Items Description</th>
        <th class="col-price">Unit Price</th>
        <th class="col-qty">Qnt</th>
        <th class="col-total">Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($lib['items'] as $row): ?>
        <tr>
          <td class="col-desc">
            <p class="lib-l1-item-name"><?= $h($row['name']) ?></p>
            <?php if ($row['detail'] !== ''): ?>
              <p class="lib-l1-item-detail"><?= $h($row['detail']) ?></p>
            <?php endif; ?>
          </td>
          <td class="col-price"><?= $libMoney($row['unit_price']) ?></td>
          <td class="col-qty"><?= $h(number_format($row['quantity'], 0)) ?></td>
          <td class="col-total"><?= $libMoney($row['line_total']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="lib-l1-mid">
    <?php
    $libNoteBody = $lib['note'] !== '' ? $lib['note'] : $lib['terms'];
    ?>
    <?php if ($libNoteBody !== ''): ?>
    <section class="lib-l1-note">
      <h2>Note :</h2>
      <p><?= $h($libNoteBody) ?></p>
    </section>
    <?php endif; ?>
    <section class="lib-l1-sums">
      <div class="lib-l1-sum-row">
        <span>SUBTOTAL</span>
        <strong><?= $libMoney($lib['subtotal']) ?></strong>
      </div>
      <div class="lib-l1-sum-row">
        <span>Tax VAT<?= $lib['taxRatePct'] > 0 ? ' ' . $h((string) $lib['taxRatePct']) . '%' : '' ?></span>
        <strong><?= $libMoney($lib['taxAmount']) ?></strong>
      </div>
      <?php if ($lib['discountAmount'] > 0): ?>
        <div class="lib-l1-sum-row">
          <span>DISCOUNT<?= $lib['discountRatePct'] > 0 ? ' ' . $h((string) $lib['discountRatePct']) . '%' : '' ?></span>
          <strong><?= $libMoney($lib['discountAmount']) ?></strong>
        </div>
      <?php endif; ?>
      <div class="lib-l1-sum-due">
        <span>TOTAL DUE :</span>
        <strong><?= $libMoney($lib['balanceDue']) ?></strong>
      </div>
    </section>
  </div>

  <?php if ($lib['thanks'] !== ''): ?>
  <p class="lib-l1-thanks"><?= $h($lib['thanks']) ?></p>
  <?php endif; ?>
  <hr class="lib-l1-rule">

  <footer class="lib-l1-foot">
    <section>
      <h3>Questions?</h3>
      <?php if ($lib['email'] !== ''): ?>
        <p><span class="lib-l1-foot-label">Email us :</span> <?= $h($lib['email']) ?></p>
      <?php endif; ?>
      <?php if ($lib['phone'] !== ''): ?>
        <p><span class="lib-l1-foot-label">Call us :</span> <?= $h($lib['phone']) ?></p>
      <?php endif; ?>
    </section>
    <section>
      <h3>Payment Info</h3>
      <p><?= nl2br($h($lib['paymentDetails'])) ?></p>
    </section>
    <?php if ($lib['terms'] !== ''): ?>
    <section>
      <h3>Terms &amp; Conditions / Note</h3>
      <p><?= nl2br($h($lib['terms'])) ?></p>
    </section>
    <?php endif; ?>
  </footer>

  <?php if ($lib['signatureUrl'] !== ''): ?>
    <div class="lib-l1-sig">
      <img src="<?= $h($lib['signatureUrl']) ?>" alt="Signature">
    </div>
  <?php endif; ?>
</div>
