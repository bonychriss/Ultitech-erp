import { escapeHtml, formatPlainNumber, PLACEHOLDER_IMG, EMPTY_CELL } from './pricelistFormat.js';

function getBase64Image(url) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.crossOrigin = 'Anonymous';
    img.src = url;
    img.onload = () => {
      const canvas = document.createElement('canvas');
      canvas.width = img.width;
      canvas.height = img.height;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0);
      resolve(canvas.toDataURL('image/jpeg'));
    };
    img.onerror = () => reject(new Error('Could not load logo for repeating header'));
  });
}

export async function generatePriceListPdf({
  products,
  customerName,
  company,
  currency,
  logoUrl,
  currentUser,
  onProgress,
}) {
  if (typeof window.html2pdf === 'undefined') {
    throw new Error('PDF library is still loading. Please wait a moment and try again.');
  }

  const element = document.createElement('div');
  element.style.width = '190mm';
  element.style.padding = '12mm';
  element.style.backgroundColor = '#ffffff';
  element.style.color = '#111827';
  element.style.fontFamily = 'Inter, system-ui, sans-serif';

  const footerName = escapeHtml(company?.company_name || '');
  const footerAddr = escapeHtml(company?.company_address || '');
  const safeLogoUrl = String(logoUrl || '').replace(/"/g, '');
  const signatureUrl = currentUser?.signature_url || '';

  element.innerHTML = `
    <style>
      table { width: 100%; border-collapse: collapse; table-layout: fixed; }
      tr { page-break-inside: avoid !important; break-inside: avoid !important; }
      td, th { border: 1px solid #e5e7eb; word-wrap: break-word; }
      .pdf-header-p1 { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 20px; border-bottom: 2px solid #001f3f; margin-bottom: 30px; }
    </style>

    <div class="pdf-header-p1">
      <div>
        <div style="font-size: 10px; font-weight: 700; color: #2563eb; margin-bottom: 6px; text-transform: uppercase;">Dear ${escapeHtml(customerName || 'Valued Customer')},</div>
        <h1 style="font-size: 32px; font-weight: 800; margin: 0; color: #001f3f; letter-spacing: -1px; text-transform: uppercase;">PRICE LIST</h1>
        <div style="font-size: 12px; font-weight: 700; color: #111827; margin-top: 4px;">${footerName}</div>
        <div style="font-size: 10px; color: #6b7280; margin-top: 4px;">Date: ${escapeHtml(new Date().toLocaleDateString())}</div>
      </div>
      <img src="${safeLogoUrl}" style="max-height: 65px; max-width: 180px; object-fit: contain;" alt="" />
    </div>

    <table>
      <thead>
        <tr style="background: #001f3f;">
          <th style="width: 6%; padding: 12px 8px; text-align: center; font-size: 10px; font-weight: 700; color: #ffffff; text-transform: uppercase; border-color: #002d5b;">#</th>
          <th style="width: 14%; padding: 12px 8px; text-align: left; font-size: 10px; font-weight: 700; color: #ffffff; text-transform: uppercase; border-color: #002d5b;">Photo</th>
          <th style="width: 25%; padding: 12px 8px; text-align: left; font-size: 10px; font-weight: 700; color: #ffffff; text-transform: uppercase; border-color: #002d5b;">Product</th>
          <th style="width: 35%; padding: 12px 8px; text-align: left; font-size: 10px; font-weight: 700; color: #ffffff; text-transform: uppercase; border-color: #002d5b;">Description</th>
          <th style="width: 20%; padding: 12px 8px; text-align: right; font-size: 10px; font-weight: 700; color: #ffffff; text-transform: uppercase; border-color: #002d5b;">Price (${escapeHtml(currency)})</th>
        </tr>
      </thead>
      <tbody>
        ${products.map((product, index) => `
          <tr>
            <td style="padding: 10px 8px; font-size: 11px; color: #6b7280; text-align: center;">${index + 1}</td>
            <td style="padding: 10px 8px;">
              <div style="width: 48px; height: 48px; border-radius: 4px; overflow: hidden; background: #f9fafb; margin: 0 auto;">
                <img src="${product.image_url || PLACEHOLDER_IMG}" style="width: 100%; height: 100%; object-fit: cover;" />
              </div>
            </td>
            <td style="padding: 10px 8px; font-size: 12px; font-weight: 700; color: #111827; vertical-align: middle;">${escapeHtml(product.name)}</td>
            <td style="padding: 10px 8px; font-size: 11px; color: #4b5563; vertical-align: middle;">${escapeHtml(product.description || EMPTY_CELL)}</td>
            <td style="padding: 10px 8px; text-align: right; font-size: 12px; font-weight: 800; color: #111827; vertical-align: middle;">
              ${formatPlainNumber(product.edited_price)}
              ${product.edited_price !== product.selling_price ? `<div style="font-size: 9px; color: #d97706; font-weight: 500; margin-top: 2px;">Was ${formatPlainNumber(product.selling_price)}</div>` : ''}
            </td>
          </tr>
        `).join('')}
      </tbody>
    </table>

    <div style="margin-top: 80px; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 40px; page-break-inside: avoid; break-inside: avoid;">
      <h2 style="font-size: 20px; color: #001f3f; margin: 0 0 12px 0; font-weight: 800; text-transform: uppercase; letter-spacing: -0.5px;">Thank You for Choosing ${footerName}!</h2>
      <p style="font-size: 13px; color: #4b5563; line-height: 1.8; max-width: 550px; margin: 0 auto; font-style: italic;">
        We are honored to be your partner. Our commitment is to deliver excellence through quality products and dedicated service.
      </p>
    </div>

    <div style="margin-top: 60px; display: flex; justify-content: space-between; align-items: flex-end; page-break-inside: avoid; break-inside: avoid;">
      <div style="text-align: center;">
        <div style="font-size: 11px; font-weight: 800; color: #111827; text-transform: uppercase;">${footerName}</div>
        <div style="font-size: 10px; color: #6b7280; margin-top: 4px;">${footerAddr}</div>
      </div>
      <div style="text-align: center; width: 220px;">
        <div style="font-size: 10px; color: #6b7280; margin-bottom: 5px; text-transform: uppercase; font-weight: 600;">Sales Representative</div>
        <div style="height: 45px; display: flex; align-items: center; justify-content: center;">
          ${signatureUrl ? `<img src="${signatureUrl}" style="max-height: 45px; max-width: 160px; object-fit: contain;" />` : '<div style="width: 100%; border-bottom: 1px solid #e5e7eb; margin-top: 30px;"></div>'}
        </div>
        <div style="border-top: 2px solid #001f3f; margin-top: 5px; padding-top: 8px;">
          <div style="font-size: 12px; font-weight: 800; color: #111827;">${escapeHtml(currentUser?.full_name || 'Authorized Signatory')}</div>
        </div>
      </div>
    </div>
  `;

  const opt = {
    margin: [30, 10, 15, 10],
    filename: `PriceList_${new Date().toISOString().split('T')[0]}.pdf`,
    image: { type: 'jpeg', quality: 0.98 },
    html2canvas: { scale: 1.5, useCORS: true, logging: false },
    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
    pagebreak: { mode: ['css', 'legacy'] },
  };

  let logoBase64 = null;
  try {
    logoBase64 = await getBase64Image(safeLogoUrl);
  } catch {
    logoBase64 = null;
  }

  await window.html2pdf().set(opt).from(element).toPdf().get('pdf').then(async (pdf) => {
    const totalPages = pdf.internal.getNumberOfPages();
    for (let i = 2; i <= totalPages; i += 1) {
      pdf.setPage(i);
      pdf.setDrawColor(229, 231, 235);
      pdf.setLineWidth(0.2);
      pdf.line(10, 20, 200, 20);
      if (logoBase64) {
        pdf.addImage(logoBase64, 'JPEG', 165, 5, 35, 12, undefined, 'FAST');
      }
      pdf.setFontSize(8);
      pdf.setTextColor(37, 99, 235);
      pdf.setFont('helvetica', 'bold');
      pdf.text('OFFICIAL PRICE LIST', 10, 10);
      pdf.setFontSize(7);
      pdf.setTextColor(107, 114, 128);
      pdf.setFont('helvetica', 'normal');
      pdf.text(`${footerName} | Page ${i} of ${totalPages}`, 10, 14);
    }
    if (onProgress) onProgress(100);
  }).save();
}
