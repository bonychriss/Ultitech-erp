import { escapeHtml, formatPlainNumber, PLACEHOLDER_IMG, EMPTY_CELL } from './pricelistFormat.js';

const PDF_THUMB_MAX = 96;
const IMAGE_CONCURRENCY = 8;
const IMAGE_TIMEOUT_MS = 12000;

function toPdfImageUrl(url) {
  const raw = String(url || '').trim();
  if (!raw) return '';
  if (raw.startsWith('data:')) return raw;
  try {
    const parsed = new URL(raw, window.location.href);
    if (parsed.searchParams.has('size')) {
      parsed.searchParams.set('size', 'thumbnail');
    }
    return parsed.toString();
  } catch {
    return raw
      .replace(/([?&])size=(medium|large|original)\b/i, '$1size=thumbnail');
  }
}

function canvasToJpegDataUrl(img, maxEdge = PDF_THUMB_MAX) {
  const srcW = img.naturalWidth || img.width || 1;
  const srcH = img.naturalHeight || img.height || 1;
  const scale = Math.min(1, maxEdge / Math.max(srcW, srcH));
  const width = Math.max(1, Math.round(srcW * scale));
  const height = Math.max(1, Math.round(srcH * scale));
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, width, height);
  ctx.drawImage(img, 0, 0, width, height);
  return canvas.toDataURL('image/jpeg', 0.82);
}

function loadImageElement(url, useCors) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    if (useCors) img.crossOrigin = 'anonymous';
    const timer = window.setTimeout(() => {
      img.onload = null;
      img.onerror = null;
      reject(new Error('timeout'));
    }, IMAGE_TIMEOUT_MS);
    img.onload = () => {
      window.clearTimeout(timer);
      resolve(img);
    };
    img.onerror = () => {
      window.clearTimeout(timer);
      reject(new Error('load failed'));
    };
    img.src = url;
  });
}

async function imageUrlToDataUrl(url) {
  const source = String(url || '').trim();
  if (!source) return PLACEHOLDER_IMG;
  if (source.startsWith('data:')) return source;

  const candidates = [true, false];
  for (const useCors of candidates) {
    try {
      const img = await loadImageElement(source, useCors);
      if (!img.naturalWidth) continue;
      return canvasToJpegDataUrl(img);
    } catch {
      /* try next strategy */
    }
  }
  return PLACEHOLDER_IMG;
}

async function mapWithConcurrency(items, limit, mapper, onItemDone) {
  const results = new Array(items.length);
  let nextIndex = 0;
  let completed = 0;

  async function worker() {
    while (nextIndex < items.length) {
      const index = nextIndex;
      nextIndex += 1;
      results[index] = await mapper(items[index], index);
      completed += 1;
      if (onItemDone) onItemDone(completed, items.length);
    }
  }

  const workers = Array.from({ length: Math.min(limit, Math.max(1, items.length)) }, () => worker());
  await Promise.all(workers);
  return results;
}

async function getBase64Image(url) {
  return imageUrlToDataUrl(url);
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

  const list = Array.isArray(products) ? products : [];
  const report = (value) => {
    if (typeof onProgress === 'function') onProgress(value);
  };

  report(4);

  const imageSources = list.map((product) => toPdfImageUrl(product.image_url) || PLACEHOLDER_IMG);
  const imageDataUrls = await mapWithConcurrency(
    imageSources,
    IMAGE_CONCURRENCY,
    (url) => imageUrlToDataUrl(url),
    (done, total) => {
      const ratio = total > 0 ? done / total : 1;
      report(4 + Math.round(ratio * 72));
    },
  );

  report(78);

  const safeLogoUrl = String(logoUrl || '').replace(/"/g, '');
  const signatureUrl = String(currentUser?.signature_url || '').replace(/"/g, '');
  const [logoDataUrl, signatureDataUrl] = await Promise.all([
    safeLogoUrl ? imageUrlToDataUrl(safeLogoUrl) : Promise.resolve(''),
    signatureUrl ? imageUrlToDataUrl(signatureUrl) : Promise.resolve(''),
  ]);

  report(84);

  const element = document.createElement('div');
  element.style.width = '190mm';
  element.style.padding = '12mm';
  element.style.backgroundColor = '#ffffff';
  element.style.color = '#111827';
  element.style.fontFamily = 'Inter, system-ui, sans-serif';

  const footerName = escapeHtml(company?.company_name || '');
  const footerAddr = escapeHtml(company?.company_address || '');
  const logoSrc = logoDataUrl && logoDataUrl !== PLACEHOLDER_IMG ? logoDataUrl : safeLogoUrl;
  const signatureSrc = signatureDataUrl && signatureDataUrl !== PLACEHOLDER_IMG
    ? signatureDataUrl
    : signatureUrl;

  element.innerHTML = `
    <style>
      table { width: 100%; border-collapse: collapse; table-layout: fixed; }
      tr { page-break-inside: avoid !important; break-inside: avoid !important; }
      td, th { border: 1px solid #e5e7eb; word-wrap: break-word; }
      .pdf-header-p1 { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 20px; border-bottom: 2px solid #001f3f; margin-bottom: 30px; }
      .pdf-thumb { width: 48px; height: 48px; border-radius: 4px; overflow: hidden; background: #f9fafb; margin: 0 auto; }
      .pdf-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    </style>

    <div class="pdf-header-p1">
      <div>
        <div style="font-size: 10px; font-weight: 700; color: #2563eb; margin-bottom: 6px; text-transform: uppercase;">Dear ${escapeHtml(customerName || 'Valued Customer')},</div>
        <h1 style="font-size: 32px; font-weight: 800; margin: 0; color: #001f3f; letter-spacing: -1px; text-transform: uppercase;">PRICE LIST</h1>
        <div style="font-size: 12px; font-weight: 700; color: #111827; margin-top: 4px;">${footerName}</div>
        <div style="font-size: 10px; color: #6b7280; margin-top: 4px;">Date: ${escapeHtml(new Date().toLocaleDateString())}</div>
      </div>
      ${logoSrc ? `<img src="${logoSrc}" style="max-height: 65px; max-width: 180px; object-fit: contain;" alt="" />` : ''}
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
        ${list.map((product, index) => `
          <tr>
            <td style="padding: 10px 8px; font-size: 11px; color: #6b7280; text-align: center;">${index + 1}</td>
            <td style="padding: 10px 8px;">
              <div class="pdf-thumb">
                <img src="${imageDataUrls[index] || PLACEHOLDER_IMG}" alt="" />
              </div>
            </td>
            <td style="padding: 10px 8px; font-size: 12px; font-weight: 700; color: #111827; vertical-align: middle;">${escapeHtml(product.name)}</td>
            <td style="padding: 10px 8px; font-size: 11px; color: #4b5563; vertical-align: middle;">${escapeHtml(product.description || EMPTY_CELL)}</td>
            <td style="padding: 10px 8px; text-align: right; font-size: 12px; font-weight: 800; color: #111827; vertical-align: middle;">
              ${formatPlainNumber(product.edited_price)}
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
          ${signatureSrc ? `<img src="${signatureSrc}" style="max-height: 45px; max-width: 160px; object-fit: contain;" alt="" />` : '<div style="width: 100%; border-bottom: 1px solid #e5e7eb; margin-top: 30px;"></div>'}
        </div>
        <div style="border-top: 2px solid #001f3f; margin-top: 5px; padding-top: 8px;">
          <div style="font-size: 12px; font-weight: 800; color: #111827;">${escapeHtml(currentUser?.full_name || 'Authorized Signatory')}</div>
        </div>
      </div>
    </div>
  `;

  // Keep off-screen so images decode before capture.
  element.style.position = 'fixed';
  element.style.left = '-10000px';
  element.style.top = '0';
  element.style.zIndex = '-1';
  document.body.appendChild(element);

  try {
    await Promise.all(
      Array.from(element.querySelectorAll('img')).map(
        (img) => (img.complete
          ? Promise.resolve()
          : new Promise((resolve) => {
            img.onload = () => resolve();
            img.onerror = () => resolve();
          })),
      ),
    );

    report(90);

    const scale = list.length > 120 ? 1 : (list.length > 60 ? 1.15 : 1.35);
    const opt = {
      margin: [30, 10, 15, 10],
      filename: `PriceList_${new Date().toISOString().split('T')[0]}.pdf`,
      image: { type: 'jpeg', quality: 0.92 },
      html2canvas: {
        scale,
        useCORS: true,
        allowTaint: false,
        logging: false,
        imageTimeout: 0,
        backgroundColor: '#ffffff',
      },
      jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
      pagebreak: { mode: ['css', 'legacy'] },
    };

    let logoBase64 = null;
    if (logoDataUrl && logoDataUrl.startsWith('data:image')) {
      logoBase64 = logoDataUrl;
    } else if (safeLogoUrl) {
      try {
        logoBase64 = await getBase64Image(safeLogoUrl);
      } catch {
        logoBase64 = null;
      }
    }

    await window.html2pdf().set(opt).from(element).toPdf().get('pdf').then(async (pdf) => {
      const totalPages = pdf.internal.getNumberOfPages();
      for (let i = 2; i <= totalPages; i += 1) {
        pdf.setPage(i);
        pdf.setDrawColor(229, 231, 235);
        pdf.setLineWidth(0.2);
        pdf.line(10, 20, 200, 20);
        if (logoBase64) {
          try {
            const format = logoBase64.includes('image/png') ? 'PNG' : 'JPEG';
            pdf.addImage(logoBase64, format, 165, 5, 35, 12, undefined, 'FAST');
          } catch {
            /* skip broken header logo */
          }
        }
        pdf.setFontSize(8);
        pdf.setTextColor(37, 99, 235);
        pdf.setFont('helvetica', 'bold');
        pdf.text('OFFICIAL PRICE LIST', 10, 10);
        pdf.setFontSize(7);
        pdf.setTextColor(107, 114, 128);
        pdf.setFont('helvetica', 'normal');
        pdf.text(`${String(company?.company_name || '')} | Page ${i} of ${totalPages}`, 10, 14);
      }
      report(100);
    }).save();
  } finally {
    if (element.parentNode) {
      element.parentNode.removeChild(element);
    }
  }
}
