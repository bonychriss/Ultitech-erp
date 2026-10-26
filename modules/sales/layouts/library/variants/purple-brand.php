<?php
/** Layout 12  Purple Brand Name (React Layout2); cqw ? mm on ~210mm sheet */
/** @var array $lib */
/** @var callable $h */
/** @var callable $libMoney */
?>
<style>
.lib-sheet.lib-l2 {
  --purple: #504a90;
  --purple-soft: #6b649e;
  --ink: #2a2a32;
  --muted: #7a7a86;
  --line: #e6e4ef;
  box-sizing: border-box;
  position: relative;
  width: 210mm;
  min-height: 297mm;
  margin: 0 auto;
  padding: 12mm 16mm 22mm;
  background: #fff;
  overflow: hidden;
  font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
  color: var(--ink);
  font-size: 9.5pt;
  line-height: 1.4;
}
.pdf-mode .lib-sheet.lib-l2,
body.pdf-mode .lib-sheet.lib-l2 {
  width: 100% !important;
  min-height: auto !important;
  padding: 10mm 12mm 18mm !important;
}
@media print {
  .lib-sheet.lib-l2 {
    width: 210mm !important;
    max-width: 210mm !important;
    min-height: auto !important;
  }
}
.lib-l2 * { box-sizing: border-box; }
.lib-l2-foot-art {
  position: absolute;
  left: 0; right: 0; bottom: 0;
  height: 13.5mm;
  pointer-events: none;
}
.lib-l2-foot-art::before {
  content: '';
  position: absolute;
  left: 0; bottom: 0;
  width: 54%;
  height: 5mm;
  background: #1e1e1e;
}
.lib-l2-foot-art::after {
  content: '';
  position: absolute;
  right: 0; bottom: 0;
  width: 50%;
  height: 13.5mm;
  background: var(--purple);
  clip-path: polygon(0 100%, 20% 0, 100% 0, 100% 100%);
}
.lib-l2-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 10mm;
}
.lib-l2-brand { display: flex; align-items: flex-start; gap: 3.5mm; }
.lib-l2-brand-details {
  margin: 1.5mm 0 0;
  font-size: 8pt;
  color: var(--muted);
  line-height: 1.45;
}
.lib-l2-brand-details p { margin: 0 0 0.6mm; }
.lib-l2-mark {
  width: 13.5mm;
  height: 13.5mm;
  border-radius: 1.5mm;
  background: var(--purple);
  color: #fff;
  font-size: 16pt;
  font-weight: 800;
  display: grid;
  place-items: center;
  line-height: 1;
  overflow: hidden;
}
.lib-l2-mark img { width: 100%; height: 100%; object-fit: cover; }
.lib-l2-brand-name {
  font-size: 13pt;
  font-weight: 800;
  letter-spacing: 0.06em;
  color: var(--ink);
  margin: 0;
}
.lib-l2-brand-tag {
  margin: 0.5mm 0 0;
  font-size: 7.5pt;
  letter-spacing: 0.08em;
  color: var(--muted);
}
.lib-l2-title-block { text-align: right; }
.lib-l2-title {
  margin: 0;
  font-size: 26pt;
  font-weight: 800;
  letter-spacing: 0.06em;
  line-height: 1;
  color: var(--purple);
}
.lib-l2-date, .lib-l2-no {
  font-size: 9pt;
  color: var(--muted);
  margin: 1.5mm 0 0;
}
.lib-l2-no { color: var(--ink); font-weight: 600; }
.lib-l2-to { margin-bottom: 8mm; }
.lib-l2-to-label {
  font-size: 9pt;
  font-weight: 700;
  letter-spacing: 0.08em;
  margin: 0 0 1.5mm;
}
.lib-l2-to-name {
  font-size: 12pt;
  font-weight: 800;
  letter-spacing: 0.04em;
  margin: 0 0 1.5mm;
}
.lib-l2-muted {
  font-size: 9pt;
  color: var(--muted);
  line-height: 1.45;
  margin: 0;
  white-space: pre-line;
}
.lib-l2-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  table-layout: fixed;
  margin-bottom: 8mm;
}
.lib-l2-table thead th {
  background: var(--purple);
  color: #fff;
  font-size: 8.5pt;
  font-weight: 700;
  letter-spacing: 0.06em;
  height: 11mm;
  padding: 0 3mm;
}
.lib-l2-table thead th:first-child { border-radius: 5.5mm 0 0 5.5mm; }
.lib-l2-table thead th:last-child { border-radius: 0 5.5mm 5.5mm 0; }
.lib-l2-table .col-sl { width: 10%; text-align: center; }
.lib-l2-table .col-desc { width: 38%; text-align: left; }
.lib-l2-table .col-num { width: 18%; text-align: center; }
.lib-l2-table .col-qty { width: 16%; text-align: center; }
.lib-l2-table tbody td {
  padding: 3.5mm 3mm;
  font-size: 9.5pt;
  font-weight: 500;
  border-bottom: 1px solid var(--line);
  font-variant-numeric: tabular-nums;
  vertical-align: middle;
}
.lib-l2-after {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 8mm;
  margin-bottom: 10mm;
}
.lib-l2-thanks {
  font-size: 11pt;
  font-weight: 700;
  margin: 0 0 3mm;
}
.lib-l2-contact {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 2mm;
  font-size: 8.5pt;
  color: var(--muted);
  margin: 0;
}
.lib-l2-ico {
  width: 5mm;
  height: 5mm;
  color: var(--purple);
  display: inline-grid;
  place-items: center;
  vertical-align: middle;
}
.lib-l2-ico svg { width: 100%; height: 100%; }
.lib-l2-ico-web { margin-left: 3mm; }
.lib-l2-sums { width: 70mm; flex-shrink: 0; margin-left: auto; }
.lib-l2-sum-row {
  display: flex;
  justify-content: space-between;
  font-size: 9pt;
  line-height: 1.75;
  padding: 0 5mm;
}
.lib-l2-sum-row span { color: var(--muted); }
.lib-l2-sum-row strong { font-weight: 600; font-variant-numeric: tabular-nums; }
.lib-l2-total {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 2mm;
  height: 11mm;
  padding: 0 5.5mm;
  background: var(--purple);
  color: #fff;
  border-radius: 5.5mm;
  font-size: 10pt;
  font-weight: 800;
}
.lib-l2-total strong { font-variant-numeric: tabular-nums; }
.lib-l2-sign {
  text-align: center;
  min-width: 50mm;
  margin-left: auto;
  margin-top: 18mm;
}
.lib-l2-sign-script {
  font-family: "Caveat", "Segoe Script", cursive;
  font-size: 18pt;
  line-height: 1;
  color: var(--ink);
  margin: 0 0 1.5mm;
}
.lib-l2-sign img {
  max-height: 16mm;
  max-width: 48mm;
  display: block;
  margin: 0 auto 2mm;
}
.lib-l2-sign-name { font-size: 10pt; font-weight: 800; margin: 0; }
.lib-l2-sign-role {
  font-size: 8.5pt;
  color: var(--muted);
  margin: 2mm 0 0;
  padding-top: 2mm;
  border-top: 1px solid var(--line);
}
.lib-l2-bottom {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12mm;
  margin-top: 10mm;
}
.lib-l2-lower-left { display: flex; flex-direction: column; gap: 8mm; flex: 1; }
.lib-l2-label {
  font-size: 9pt;
  font-weight: 800;
  letter-spacing: 0.04em;
  margin: 0 0 2mm;
}
.lib-l2-pay p, .lib-l2-terms p {
  font-size: 8.5pt;
  color: var(--muted);
  line-height: 1.5;
  margin: 0 0 1mm;
  white-space: pre-line;
}
.lib-l2-terms { width: 70mm; flex-shrink: 0; }
</style>

<div class="lib-sheet lib-l2">
  <div class="lib-l2-foot-art" aria-hidden="true"></div>

  <header class="lib-l2-head">
    <div class="lib-l2-brand">
      <span class="lib-l2-mark">
        <?php if ($lib['logoUrl'] !== ''): ?>
          <img src="<?= $h($lib['logoUrl']) ?>" alt="">
        <?php else: ?>
          <?= $h($lib['brandInitial']) ?>
        <?php endif; ?>
      </span>
      <div>
        <p class="lib-l2-brand-name"><?= $h($lib['brand']) ?></p>
        <?php if ($lib['tagline'] !== ''): ?>
          <p class="lib-l2-brand-tag"><?= $h($lib['tagline']) ?></p>
        <?php endif; ?>
        <?php
        $brandDetailLines = array_values(array_filter([
            trim((string) ($lib['officeAddress'] ?? '')),
            trim((string) ($lib['phone'] ?? '')) !== '' ? ('Phone: ' . trim((string) $lib['phone'])) : '',
            trim((string) ($lib['email'] ?? '')) !== '' ? ('Email: ' . trim((string) $lib['email'])) : '',
            trim((string) ($lib['website'] ?? '')) !== '' ? ('Web: ' . trim((string) $lib['website'])) : '',
            trim((string) ($lib['companyTin'] ?? '')) !== '' ? ('TIN: ' . trim((string) $lib['companyTin'])) : '',
            trim((string) ($lib['companyVrn'] ?? '')) !== '' ? ('VRN: ' . trim((string) $lib['companyVrn'])) : '',
        ], static fn ($line) => $line !== ''));
        ?>
        <?php if ($brandDetailLines !== []): ?>
          <div class="lib-l2-brand-details">
            <?php foreach ($brandDetailLines as $line): ?>
              <p><?= nl2br($h($line)) ?></p>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="lib-l2-title-block">
      <h1 class="lib-l2-title"><?= $h($lib['docTitle']) ?></h1>
      <p class="lib-l2-date">Date: <?= $h($lib['invoiceDate']) ?></p>
      <p class="lib-l2-no"><?= $h($lib['invoiceNumber']) ?></p>
    </div>
  </header>

  <section class="lib-l2-to">
    <p class="lib-l2-to-label">TO:</p>
    <p class="lib-l2-to-name"><?= $h($lib['customerName']) ?></p>
    <p class="lib-l2-muted"><?= $h($lib['customerAddress']) ?></p>
    <?php if ($lib['customerTin'] !== ''): ?>
      <p class="lib-l2-muted">TIN: <?= $h($lib['customerTin']) ?></p>
    <?php endif; ?>
  </section>

  <table class="lib-l2-table">
    <thead>
      <tr>
        <th class="col-sl">SL</th>
        <th class="col-desc">DESCRIPTION</th>
        <th class="col-num">PRICE</th>
        <th class="col-qty">QUANTITY</th>
        <th class="col-num">AMOUNT</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($lib['items'] as $i => $row): ?>
        <tr>
          <td class="col-sl"><?= $h(str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)) ?></td>
          <td class="col-desc"><?= $h($row['name']) ?></td>
          <td class="col-num"><?= $libMoney($row['unit_price']) ?></td>
          <td class="col-qty"><?= $h(number_format($row['quantity'], 0)) ?></td>
          <td class="col-num"><?= $libMoney($row['line_total']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="lib-l2-after">
    <div class="lib-l2-thanks-block">
      <?php if ($lib['thanks'] !== ''): ?>
      <p class="lib-l2-thanks"><?= $h($lib['thanks']) ?></p>
      <?php endif; ?>
      <p class="lib-l2-contact">
        <?php if ($lib['phone'] !== ''): ?>
          <span class="lib-l2-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path fill="currentColor" d="M6.6 10.8a15.5 15.5 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.24c1.1.37 2.3.56 3.5.56a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.2.19 2.4.56 3.5a1 1 0 0 1-.25 1L6.6 10.8z"/></svg>
          </span>
          <?= $h($lib['phone']) ?>
        <?php endif; ?>
        <?php if ($lib['website'] !== ''): ?>
          <span class="lib-l2-ico lib-l2-ico-web" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm7.9 9h-3.1a15 15 0 0 0-1.3-5 8.05 8.05 0 0 1 4.4 5zM12 4c.9 1.3 1.8 3.3 2.2 5.5H9.8C10.2 7.3 11.1 5.3 12 4zM4.1 11h3.1a15 15 0 0 1 1.3-5 8.05 8.05 0 0 0-4.4 5zM7.2 13H4.1a8.05 8.05 0 0 0 4.4 5 15 15 0 0 1-1.3-5zm2.6 0h4.4c-.4 2.2-1.3 4.2-2.2 5.5-.9-1.3-1.8-3.3-2.2-5.5zm6.2 0h3.1a8.05 8.05 0 0 1-4.4 5 15 15 0 0 0 1.3-5z"/></svg>
          </span>
          <?= $h($lib['website']) ?>
        <?php endif; ?>
      </p>
    </div>
    <div class="lib-l2-sums">
      <div class="lib-l2-sum-row">
        <span>Subtotal</span>
        <strong><?= $libMoney($lib['subtotal']) ?></strong>
      </div>
      <div class="lib-l2-sum-row">
        <span>VAT<?= $lib['taxRatePct'] > 0 ? ' (' . $h((string) $lib['taxRatePct']) . '%)' : '' ?></span>
        <strong><?= $libMoney($lib['taxAmount']) ?></strong>
      </div>
      <div class="lib-l2-total">
        <span>TOTAL</span>
        <strong><?= $libMoney($lib['totalAmount']) ?></strong>
      </div>
    </div>
  </div>

  <div class="lib-l2-bottom">
    <div class="lib-l2-lower-left">
      <section class="lib-l2-pay">
        <p class="lib-l2-label">Payment Method</p>
        <p><?= nl2br($h($lib['paymentDetails'])) ?></p>
      </section>
      <?php if ($lib['terms'] !== ''): ?>
      <section class="lib-l2-terms">
        <p class="lib-l2-label">TERMS &amp; CONDITIONS</p>
        <p><?= nl2br($h($lib['terms'])) ?></p>
      </section>
      <?php endif; ?>
    </div>
    <div class="lib-l2-sign">
      <?php if ($lib['signatureUrl'] !== ''): ?>
        <img src="<?= $h($lib['signatureUrl']) ?>" alt="">
      <?php elseif ($lib['signerName'] !== ''): ?>
        <p class="lib-l2-sign-script"><?= $h($lib['signerName']) ?></p>
      <?php endif; ?>
      <?php if ($lib['signerName'] !== ''): ?>
        <p class="lib-l2-sign-name"><?= $h($lib['signerName']) ?></p>
      <?php endif; ?>
      <p class="lib-l2-sign-role"><?= $h($lib['signerRole']) ?></p>
    </div>
  </div>
</div>
