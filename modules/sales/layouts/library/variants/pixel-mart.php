<?php
/** Layout 14 — Pixel Mart Red & Black (React Layout4) with product images */
/** @var array $lib */
/** @var callable $h */
/** @var callable $libMoney */
$bgUrl = rtrim($lib['assetBase'], '/') . '/bg-layout4.webp';
?>
<style>
.lib-sheet.lib-l4 {
  --red: #e8232a;
  --black: #383838;
  --ink: #23262b;
  --muted: #6b6f76;
  box-sizing: border-box;
  position: relative;
  width: 210mm;
  min-height: 286mm;
  margin: 0 auto;
  background: #fff;
  overflow: hidden;
  font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
  color: var(--ink);
  font-size: 9.5pt;
  line-height: 1.4;
}
.pdf-mode .lib-sheet.lib-l4,
body.pdf-mode .lib-sheet.lib-l4 {
  width: 100% !important;
  min-height: auto !important;
}
@media print {
  .lib-sheet.lib-l4 {
    width: 210mm !important;
    max-width: 210mm !important;
    min-height: auto !important;
  }
}
.lib-l4 * { box-sizing: border-box; }
.lib-l4-bg {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: fill;
  pointer-events: none;
  z-index: 0;
}
.lib-l4-content {
  position: relative;
  z-index: 1;
  padding: 0 17mm 12mm;
  min-height: 286mm;
  display: flex;
  flex-direction: column;
}
.lib-l4-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  min-height: 42mm;
  padding-top: 17mm;
  margin-bottom: 8mm;
}
.lib-l4-logo { display: flex; align-items: center; gap: 3mm; }
.lib-l4-logo-mark {
  width: 18mm;
  height: 18mm;
  border-radius: 4mm;
  object-fit: cover;
  background: var(--red);
}
.lib-l4-logo-svg {
  width: 18mm;
  height: 18mm;
  flex-shrink: 0;
}
.lib-l4-logo-name {
  font-size: 18pt;
  font-weight: 800;
  letter-spacing: -0.02em;
  line-height: 1;
  color: var(--black);
  margin: 0;
}
.lib-l4-logo-tag {
  margin: 1mm 0 0;
  font-size: 8.5pt;
  color: var(--muted);
}
.lib-l4-title {
  font-size: 28pt;
  font-weight: 800;
  letter-spacing: 0.01em;
  color: #fff;
  line-height: 1;
  margin: 0 0 4mm;
  text-shadow: 0 1px 2px rgba(0,0,0,0.15);
}
.lib-l4-meta {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 8mm;
}
.lib-l4-to-label { font-size: 9pt; margin: 0 0 1mm; }
.lib-l4-to-name {
  font-size: 13pt;
  font-weight: 800;
  color: var(--red);
  margin: 0 0 1mm;
}
.lib-l4-to-role { font-size: 8.5pt; color: var(--muted); margin: 0 0 3mm; }
.lib-l4-to-line { font-size: 8.5pt; margin: 0 0 1mm; }
.lib-l4-to-line span { color: var(--muted); }
.lib-l4-ids { text-align: right; margin-top: 6mm; }
.lib-l4-no {
  display: inline-block;
  background: var(--black);
  color: #fff;
  font-size: 9pt;
  font-weight: 700;
  padding: 1.5mm 3mm;
  margin-bottom: 3mm;
}
.lib-l4-id-row {
  display: flex;
  justify-content: flex-end;
  gap: 5mm;
  font-size: 8.5pt;
  line-height: 1.5;
}
.lib-l4-id-row span { color: var(--muted); }
.lib-l4-id-row strong { min-width: 22mm; text-align: right; font-weight: 500; }
.lib-l4-table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  margin-bottom: 8mm;
}
.lib-l4-table thead th {
  height: 12mm;
  color: #fff;
  font-size: 9pt;
  font-weight: 700;
  padding: 0 3mm;
  text-align: center;
}
.lib-l4-table thead th:nth-child(2) { text-align: left; padding-left: 3.5mm; }
.lib-l4-table .th-red { background: var(--red); }
.lib-l4-table .th-dark { background: var(--black); }
.lib-l4-table th:nth-child(1), .lib-l4-table td:nth-child(1) { width: 11%; }
.lib-l4-table th:nth-child(2), .lib-l4-table td:nth-child(2) { width: 33.5%; }
.lib-l4-table th:nth-child(3), .lib-l4-table td:nth-child(3) { width: 14%; }
.lib-l4-table th:nth-child(4), .lib-l4-table td:nth-child(4) { width: 22.5%; }
.lib-l4-table th:nth-child(5), .lib-l4-table td:nth-child(5) { width: 19%; }
.lib-l4-thumb {
  display: block;
  width: 13.5mm;
  height: 13.5mm;
  margin: 0 auto;
  border-radius: 1.5mm;
  object-fit: cover;
  background: #f2f2f4;
  border: 1px solid #e3e4e7;
}
.lib-l4-thumb-empty {
  display: grid;
  place-items: center;
  color: #b9bcc3;
}
.lib-l4-thumb-empty svg { width: 55%; height: 55%; }
.lib-l4-table tbody td {
  padding: 3mm;
  border-bottom: 1px solid #e3e4e7;
  vertical-align: middle;
  font-size: 10pt;
  font-weight: 600;
  text-align: center;
  font-variant-numeric: tabular-nums;
}
.lib-l4-table tbody td:nth-child(2) { text-align: left; padding-left: 3.5mm; }
.lib-l4-item-name { font-weight: 600; font-size: 9.5pt; margin: 0 0 1mm; }
.lib-l4-item-detail {
  font-size: 7.5pt;
  font-weight: 400;
  color: var(--muted);
  line-height: 1.35;
  margin: 0;
  max-width: 65mm;
}
.lib-l4-bottom {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 6mm;
}
.lib-l4-pay { margin-top: 3mm; }
.lib-l4-pay-title { font-size: 9pt; font-weight: 700; margin: 0 0 2mm; }
.lib-l4-pay-body {
  font-size: 8.5pt;
  line-height: 1.55;
  color: var(--ink);
  white-space: pre-line;
  margin: 0;
}
.lib-l4-sums { width: 65mm; }
.lib-l4-sum-row {
  display: flex;
  justify-content: space-between;
  font-size: 8.5pt;
  line-height: 1.75;
  padding: 0 6mm;
}
.lib-l4-sum-row strong { font-weight: 500; font-variant-numeric: tabular-nums; }
.lib-l4-grand {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 2mm;
  height: 9mm;
  padding: 0 6mm;
  background: var(--red);
  color: #fff;
  font-size: 9.5pt;
  font-weight: 700;
}
.lib-l4-grand strong { font-variant-numeric: tabular-nums; }
.lib-l4-terms-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 8mm;
  margin-bottom: 8mm;
}
.lib-l4-terms {
  font-size: 7.5pt;
  color: var(--muted);
  max-width: 95mm;
  line-height: 1.4;
  margin: 0;
  white-space: pre-line;
}
.lib-l4-terms strong { color: var(--ink); font-weight: 700; }
.lib-l4-thanks { margin: 5mm 0 0; font-size: 9.5pt; font-weight: 600; }
.lib-l4-sign { text-align: center; width: 40mm; margin-top: 6mm; }
.lib-l4-sign img { max-height: 14mm; max-width: 36mm; margin-bottom: 1mm; }
.lib-l4-sign-script {
  font-family: "Caveat", "Segoe Script", cursive;
  font-size: 16pt;
  line-height: 1;
  margin: 0 0 2mm;
}
.lib-l4-sign-name {
  font-size: 8.5pt;
  font-weight: 800;
  letter-spacing: 0.02em;
  border-top: 1px solid var(--ink);
  padding-top: 1.5mm;
  line-height: 1.3;
  margin: 0;
}
.lib-l4-sign-role { font-size: 7.5pt; color: var(--muted); margin: 0; }
.lib-l4-foot {
  display: flex;
  flex-wrap: wrap;
  gap: 5mm;
  font-size: 8pt;
  margin-top: auto;
}
.lib-l4-foot p {
  display: flex;
  align-items: center;
  gap: 2mm;
  margin: 0;
  white-space: nowrap;
}
.lib-l4-ico {
  width: 5.5mm;
  height: 5.5mm;
  border-radius: 50%;
  background: var(--red);
  color: #fff;
  display: grid;
  place-items: center;
  flex: 0 0 auto;
}
.lib-l4-foot p:first-child .lib-l4-ico,
.lib-l4-foot p:last-child .lib-l4-ico { background: var(--black); }
.lib-l4-ico svg { width: 3mm; height: 3mm; }
</style>

<div class="lib-sheet lib-l4">
  <img class="lib-l4-bg" src="<?= $h($bgUrl) ?>" alt="">

  <div class="lib-l4-content">
    <header class="lib-l4-head">
      <div class="lib-l4-logo">
        <?php if ($lib['logoUrl'] !== ''): ?>
          <img class="lib-l4-logo-mark" src="<?= $h($lib['logoUrl']) ?>" alt="">
        <?php else: ?>
          <svg class="lib-l4-logo-svg" viewBox="0 0 48 48" aria-hidden="true">
            <rect x="2" y="2" width="44" height="44" rx="11" fill="#e8232a"/>
            <path d="M17 36V13h9.5c5.2 0 8.5 3 8.5 7.6s-3.3 7.6-8.5 7.6H22.4V36H17zm5.4-12.3h3.7c2.3 0 3.6-1.2 3.6-3.1s-1.3-3.1-3.6-3.1h-3.7v6.2z" fill="#fff"/>
          </svg>
        <?php endif; ?>
      </div>
      <h1 class="lib-l4-title"><?= $h($lib['docTitle']) ?></h1>
    </header>

    <div class="lib-l4-meta">
      <section class="lib-l4-to">
        <p class="lib-l4-to-label">Invoice To:</p>
        <p class="lib-l4-to-name"><?= $h($lib['customerName']) ?></p>
        <?php if ($lib['customerTin'] !== ''): ?>
          <p class="lib-l4-to-role">TIN: <?= $h($lib['customerTin']) ?></p>
        <?php endif; ?>
        <?php if ($lib['customerPhone'] !== ''): ?>
          <p class="lib-l4-to-line"><span>P :</span> <?= $h($lib['customerPhone']) ?></p>
        <?php endif; ?>
        <?php if ($lib['customerEmail'] !== ''): ?>
          <p class="lib-l4-to-line"><span>E :</span> <?= $h($lib['customerEmail']) ?></p>
        <?php endif; ?>
      </section>
      <section class="lib-l4-ids">
        <p class="lib-l4-no">INVOICE NO:<?= $h($lib['invoiceNumber']) ?></p>
        <?php if ($lib['customerTin'] !== ''): ?>
          <div class="lib-l4-id-row">
            <span>TIN</span>
            <strong><?= $h($lib['customerTin']) ?></strong>
          </div>
        <?php endif; ?>
        <div class="lib-l4-id-row">
          <span>Invoice Date</span>
          <strong><?= $h($lib['invoiceDate']) ?></strong>
        </div>
      </section>
    </div>

    <table class="lib-l4-table">
      <thead>
        <tr>
          <th class="th-dark">Image</th>
          <th class="th-red">Item description</th>
          <th class="th-dark">Quantity</th>
          <th class="th-red">Unite Price</th>
          <th class="th-dark">Total Price</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lib['items'] as $row): ?>
          <tr>
            <td>
              <?php if ($row['image_url'] !== ''): ?>
                <img class="lib-l4-thumb" src="<?= $h($row['image_url']) ?>" alt="<?= $h($row['name']) ?>">
              <?php else: ?>
                <span class="lib-l4-thumb lib-l4-thumb-empty" aria-hidden="true">
                  <svg viewBox="0 0 24 24">
                    <rect x="3" y="4" width="18" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="1.6"/>
                    <circle cx="9" cy="9.5" r="1.8" fill="currentColor"/>
                    <path d="M5 18l4.5-5 3 3.2 2.5-2.7L19 18z" fill="currentColor"/>
                  </svg>
                </span>
              <?php endif; ?>
            </td>
            <td>
              <p class="lib-l4-item-name"><?= $h($row['name']) ?></p>
              <?php if ($row['detail'] !== ''): ?>
                <p class="lib-l4-item-detail"><?= $h($row['detail']) ?></p>
              <?php endif; ?>
            </td>
            <td><?= $h(str_pad((string) (int) $row['quantity'], 2, '0', STR_PAD_LEFT)) ?></td>
            <td><?= $libMoney($row['unit_price']) ?></td>
            <td><?= $libMoney($row['line_total']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="lib-l4-bottom">
      <section class="lib-l4-pay">
        <p class="lib-l4-pay-title">Payment method</p>
        <p class="lib-l4-pay-body"><?= nl2br($h($lib['paymentDetails'])) ?></p>
      </section>
      <section class="lib-l4-sums">
        <div class="lib-l4-sum-row">
          <span>Sub Total</span>
          <strong><?= $libMoney($lib['subtotal']) ?></strong>
        </div>
        <div class="lib-l4-sum-row">
          <span>Vat &amp; Tax<?= $lib['taxRatePct'] > 0 ? ' ' . $h((string) $lib['taxRatePct']) . '%' : '' ?></span>
          <strong><?= $libMoney($lib['taxAmount']) ?></strong>
        </div>
        <?php if ($lib['discountAmount'] > 0): ?>
          <div class="lib-l4-sum-row">
            <span>Discount<?= $lib['discountRatePct'] > 0 ? ' ' . $h((string) $lib['discountRatePct']) . '%' : '' ?></span>
            <strong><?= $libMoney($lib['discountAmount']) ?></strong>
          </div>
        <?php endif; ?>
        <div class="lib-l4-grand">
          <span>Grand Total</span>
          <strong><?= $libMoney($lib['totalAmount']) ?></strong>
        </div>
      </section>
    </div>

    <div class="lib-l4-terms-row">
      <div>
        <?php if ($lib['terms'] !== ''): ?>
        <p class="lib-l4-terms"><strong>Terms &amp; Conditions:</strong> <?= $h($lib['terms']) ?></p>
        <?php endif; ?>
        <?php if ($lib['thanks'] !== ''): ?>
        <p class="lib-l4-thanks"><?= $h($lib['thanks']) ?></p>
        <?php endif; ?>
      </div>
      <div class="lib-l4-sign">
        <?php if ($lib['signatureUrl'] !== ''): ?>
          <img src="<?= $h($lib['signatureUrl']) ?>" alt="">
        <?php elseif ($lib['signerName'] !== ''): ?>
          <p class="lib-l4-sign-script"><?= $h($lib['signerName']) ?></p>
        <?php endif; ?>
        <?php if ($lib['signerName'] !== ''): ?>
          <p class="lib-l4-sign-name"><?= $h(strtoupper($lib['signerName'])) ?></p>
        <?php endif; ?>
        <p class="lib-l4-sign-role"><?= $h($lib['signerRole']) ?></p>
      </div>
    </div>

    <footer class="lib-l4-foot">
      <?php if ($lib['phone'] !== ''): ?>
        <p>
          <span class="lib-l4-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path fill="currentColor" d="M6.6 10.8a15.5 15.5 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.24c1.1.37 2.3.56 3.5.56a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.2.19 2.4.56 3.5a1 1 0 0 1-.25 1L6.6 10.8z"/></svg>
          </span>
          <?= $h($lib['phone']) ?>
        </p>
      <?php endif; ?>
      <?php if ($lib['website'] !== ''): ?>
        <p>
          <span class="lib-l4-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm7.9 9h-3.1a15 15 0 0 0-1.3-5 8.05 8.05 0 0 1 4.4 5zM12 4c.9 1.3 1.8 3.3 2.2 5.5H9.8C10.2 7.3 11.1 5.3 12 4zM4.1 11h3.1a15 15 0 0 1 1.3-5 8.05 8.05 0 0 0-4.4 5zM7.2 13H4.1a8.05 8.05 0 0 0 4.4 5 15 15 0 0 1-1.3-5zm2.6 0h4.4c-.4 2.2-1.3 4.2-2.2 5.5-.9-1.3-1.8-3.3-2.2-5.5zm6.2 0h3.1a8.05 8.05 0 0 1-4.4 5 15 15 0 0 0 1.3-5z"/></svg>
          </span>
          <?= $h($lib['website']) ?>
        </p>
      <?php endif; ?>
      <?php if ($lib['officeAddress'] !== ''): ?>
        <p>
          <span class="lib-l4-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path fill="currentColor" d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5z"/></svg>
          </span>
          <?= $h(preg_replace('/\s+/', ' ', $lib['officeAddress'])) ?>
        </p>
      <?php endif; ?>
    </footer>
  </div>
</div>
