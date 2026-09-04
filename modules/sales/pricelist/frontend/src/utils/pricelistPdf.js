import { formatPlainNumber, PLACEHOLDER_IMG, EMPTY_CELL } from './pricelistFormat.js';

const PDF_THUMB_MAX = 48;
const IMAGE_CONCURRENCY = 16;
const IMAGE_TIMEOUT_MS = 6000;
const PAGE_WIDTH_MM = 210;
const PAGE_HEIGHT_MM = 297;
const MARGIN_X = 12;
const MARGIN_TOP = 14;
const MARGIN_BOTTOM = 14;
const ROW_H = 16;
const TABLE_WIDTH = PAGE_WIDTH_MM - (MARGIN_X * 2); // 186
const COL = {
  num: 10,
  photo: 16,
  product: 48,
  desc: 74,
  price: 38,
}; // 10+16+48+74+38 = 186

function getJsPdfCtor() {
  if (window.jspdf && window.jspdf.jsPDF) return window.jspdf.jsPDF;
  if (typeof window.jsPDF === 'function') return window.jsPDF;
  return null;
}

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
    return raw.replace(/([?&])size=(medium|large|original)\b/i, '$1size=thumbnail');
  }
}

function canvasToDataUrl(img, maxEdge = PDF_THUMB_MAX, asPng = false) {
  const srcW = img.naturalWidth || img.width || 1;
  const srcH = img.naturalHeight || img.height || 1;
  const scale = Math.min(1, maxEdge / Math.max(srcW, srcH));
  const width = Math.max(1, Math.round(srcW * scale));
  const height = Math.max(1, Math.round(srcH * scale));
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d');
  if (!asPng) {
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, width, height);
  } else {
    ctx.clearRect(0, 0, width, height);
  }
  ctx.drawImage(img, 0, 0, width, height);
  return asPng ? canvas.toDataURL('image/png') : canvas.toDataURL('image/jpeg', 0.72);
}

function canvasToJpegDataUrl(img, maxEdge = PDF_THUMB_MAX) {
  return canvasToDataUrl(img, maxEdge, false);
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

async function imageUrlToDataUrl(url, { maxEdge = PDF_THUMB_MAX, asPng = false } = {}) {
  const source = String(url || '').trim();
  if (!source) return PLACEHOLDER_IMG;
  if (source.startsWith('data:')) return source;

  for (const useCors of [true, false]) {
    try {
      const img = await loadImageElement(source, useCors);
      if (!img.naturalWidth) continue;
      return canvasToDataUrl(img, maxEdge, asPng);
    } catch {
      /* next */
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

  const workers = Array.from(
    { length: Math.min(limit, Math.max(1, items.length || 1)) },
    () => worker(),
  );
  await Promise.all(workers);
  return results;
}

function wrapText(pdf, text, maxWidth) {
  const value = String(text || '').trim() || EMPTY_CELL;
  return pdf.splitTextToSize(value, maxWidth);
}

function drawHeaderBar(pdf, y, currency) {
  const x = MARGIN_X;
  const usable = TABLE_WIDTH;
  const h = 8;
  pdf.setFillColor(0, 31, 63);
  pdf.rect(x, y, usable, h, 'F');
  pdf.setTextColor(255, 255, 255);
  pdf.setFont('helvetica', 'bold');
  pdf.setFontSize(8);
  let cx = x;
  pdf.text('#', cx + (COL.num / 2), y + 5.2, { align: 'center' });
  cx += COL.num;
  pdf.text('PHOTO', cx + (COL.photo / 2), y + 5.2, { align: 'center' });
  cx += COL.photo;
  pdf.text('PRODUCT', cx + 1.5, y + 5.2);
  cx += COL.product;
  pdf.text('DESCRIPTION', cx + 1.5, y + 5.2);
  cx += COL.desc;
  const priceLabel = `PRICE (${currency})`;
  pdf.text(priceLabel, cx + COL.price - 2, y + 5.2, { align: 'right' });
  return y + h;
}

function drawCover(pdf, {
  customerName,
  companyName,
  logoDataUrl,
}) {
  let y = MARGIN_TOP;
  pdf.setFont('helvetica', 'bold');
  pdf.setFontSize(9);
  pdf.setTextColor(37, 99, 235);
  pdf.text(`Dear ${customerName || 'Valued Customer'},`, MARGIN_X, y);
  y += 8;

  pdf.setFontSize(22);
  pdf.setTextColor(0, 31, 63);
  pdf.text('PRICE LIST', MARGIN_X, y);
  y += 7;

  pdf.setFontSize(11);
  pdf.setTextColor(17, 24, 39);
  pdf.text(String(companyName || ''), MARGIN_X, y);
  y += 5;

  pdf.setFont('helvetica', 'normal');
  pdf.setFontSize(9);
  pdf.setTextColor(107, 114, 128);
  pdf.text(`Date: ${new Date().toLocaleDateString()}`, MARGIN_X, y);

  if (logoDataUrl && logoDataUrl.startsWith('data:image')) {
    try {
      const format = logoDataUrl.includes('image/png') ? 'PNG' : 'JPEG';
      pdf.addImage(logoDataUrl, format, PAGE_WIDTH_MM - MARGIN_X - 38, MARGIN_TOP - 2, 36, 14, undefined, 'FAST');
    } catch {
      /* ignore logo */
    }
  }

  y += 6;
  pdf.setDrawColor(0, 31, 63);
  pdf.setLineWidth(0.6);
  pdf.line(MARGIN_X, y, PAGE_WIDTH_MM - MARGIN_X, y);
  return y + 4;
}

function drawContinuedHeader(pdf, companyName, pageNumber, totalPages) {
  let y = MARGIN_TOP;
  pdf.setFont('helvetica', 'bold');
  pdf.setFontSize(8);
  pdf.setTextColor(37, 99, 235);
  pdf.text('OFFICIAL PRICE LIST', MARGIN_X, y);
  pdf.setFontSize(9);
  pdf.setTextColor(17, 24, 39);
  pdf.text(String(companyName || ''), MARGIN_X, y + 4.5);
  pdf.setFont('helvetica', 'normal');
  pdf.setFontSize(8);
  pdf.setTextColor(107, 114, 128);
  pdf.text(`Page ${pageNumber} of ${totalPages}`, PAGE_WIDTH_MM - MARGIN_X, y + 2, { align: 'right' });
  y += 8;
  pdf.setDrawColor(229, 231, 235);
  pdf.setLineWidth(0.3);
  pdf.line(MARGIN_X, y, PAGE_WIDTH_MM - MARGIN_X, y);
  return y + 3;
}

function drawClosing(pdf, y, {
  companyName,
  companyAddress,
  signatureDataUrl,
  signatoryName,
}) {
  const blockHeight = 72;
  y += 10;
  if (y > PAGE_HEIGHT_MM - blockHeight) {
    pdf.addPage();
    y = MARGIN_TOP + 4;
  }

  pdf.setDrawColor(229, 231, 235);
  pdf.setLineWidth(0.4);
  pdf.line(MARGIN_X, y, PAGE_WIDTH_MM - MARGIN_X, y);
  y += 12;

  pdf.setFont('helvetica', 'bold');
  pdf.setFontSize(13);
  pdf.setTextColor(0, 31, 63);
  pdf.text(`Thank You for Choosing ${companyName || ''}!`, PAGE_WIDTH_MM / 2, y, { align: 'center' });
  y += 7;

  pdf.setFont('helvetica', 'italic');
  pdf.setFontSize(9);
  pdf.setTextColor(75, 85, 99);
  const thanks = pdf.splitTextToSize(
    'We are honored to be your partner. Our commitment is to deliver excellence through quality products and dedicated service.',
    150,
  );
  pdf.text(thanks, PAGE_WIDTH_MM / 2, y, { align: 'center' });
  y += (thanks.length * 4.2) + 16;

  const leftX = MARGIN_X;
  const sigBoxW = 58;
  const sigBoxH = 28;
  const rightX = PAGE_WIDTH_MM - MARGIN_X - sigBoxW;

  // Company block (left)
  pdf.setFont('helvetica', 'bold');
  pdf.setFontSize(9);
  pdf.setTextColor(17, 24, 39);
  pdf.text(String(companyName || '').toUpperCase(), leftX, y);
  pdf.setFont('helvetica', 'normal');
  pdf.setFontSize(8);
  pdf.setTextColor(107, 114, 128);
  const addrLines = wrapText(pdf, companyAddress || '', 90);
  pdf.text(addrLines, leftX, y + 5);

  // Signature block (right) — clear space for the image above the name line
  pdf.setFont('helvetica', 'bold');
  pdf.setFontSize(8);
  pdf.setTextColor(107, 114, 128);
  pdf.text('SALES REPRESENTATIVE', rightX + (sigBoxW / 2), y, { align: 'center' });

  const sigTop = y + 3;
  pdf.setDrawColor(226, 232, 240);
  pdf.setFillColor(255, 255, 255);
  pdf.roundedRect(rightX, sigTop, sigBoxW, sigBoxH, 1.5, 1.5, 'S');

  const hasSignature = signatureDataUrl
    && signatureDataUrl.startsWith('data:image')
    && !signatureDataUrl.startsWith('data:image/svg');

  if (hasSignature) {
    try {
      const format = signatureDataUrl.includes('image/png') ? 'PNG' : 'JPEG';
      // Keep signature large and readable inside the box with padding.
      const pad = 2;
      pdf.addImage(
        signatureDataUrl,
        format,
        rightX + pad,
        sigTop + pad,
        sigBoxW - (pad * 2),
        sigBoxH - (pad * 2),
        undefined,
        'FAST',
      );
    } catch {
      /* ignore broken signature */
    }
  }

  const lineY = sigTop + sigBoxH + 3;
  pdf.setDrawColor(0, 31, 63);
  pdf.setLineWidth(0.6);
  pdf.line(rightX, lineY, rightX + sigBoxW, lineY);

  pdf.setFont('helvetica', 'bold');
  pdf.setFontSize(9);
  pdf.setTextColor(17, 24, 39);
  pdf.text(String(signatoryName || 'Authorized Signatory'), rightX + (sigBoxW / 2), lineY + 5, { align: 'center' });
}

function estimateRowsPerPage(isFirstPage) {
  const top = isFirstPage ? 48 : 28;
  const available = PAGE_HEIGHT_MM - top - MARGIN_BOTTOM - 4;
  return Math.max(8, Math.floor(available / ROW_H));
}

function drawProductRow(pdf, y, row, index) {
  const x = MARGIN_X;
  const usable = TABLE_WIDTH;
  pdf.setDrawColor(229, 231, 235);
  pdf.setLineWidth(0.2);
  pdf.rect(x, y, usable, ROW_H);

  let cx = x;
  const midY = y + (ROW_H / 2) + 1.2;

  pdf.setFont('helvetica', 'normal');
  pdf.setFontSize(8);
  pdf.setTextColor(107, 114, 128);
  pdf.text(String(index + 1), cx + (COL.num / 2), midY, { align: 'center' });
  cx += COL.num;

  const imgSize = 11;
  const imgX = cx + ((COL.photo - imgSize) / 2);
  const imgY = y + ((ROW_H - imgSize) / 2);
  if (row.imageDataUrl && row.imageDataUrl.startsWith('data:image') && !row.imageDataUrl.startsWith('data:image/svg')) {
    try {
      const format = row.imageDataUrl.includes('image/png') ? 'PNG' : 'JPEG';
      pdf.addImage(row.imageDataUrl, format, imgX, imgY, imgSize, imgSize, undefined, 'FAST');
    } catch {
      pdf.setFillColor(249, 250, 251);
      pdf.rect(imgX, imgY, imgSize, imgSize, 'F');
    }
  } else {
    pdf.setFillColor(249, 250, 251);
    pdf.rect(imgX, imgY, imgSize, imgSize, 'F');
  }
  cx += COL.photo;

  pdf.setFont('helvetica', 'bold');
  pdf.setFontSize(8);
  pdf.setTextColor(17, 24, 39);
  const nameLines = wrapText(pdf, row.name, COL.product - 3).slice(0, 2);
  pdf.text(nameLines, cx + 1.5, y + 5.2);
  cx += COL.product;

  pdf.setFont('helvetica', 'normal');
  pdf.setFontSize(7.5);
  pdf.setTextColor(75, 85, 99);
  const descLines = wrapText(pdf, row.description || EMPTY_CELL, COL.desc - 3).slice(0, 2);
  pdf.text(descLines, cx + 1.5, y + 5.2);
  cx += COL.desc;

  pdf.setFont('helvetica', 'bold');
  pdf.setFontSize(8.5);
  pdf.setTextColor(17, 24, 39);
  pdf.text(formatPlainNumber(row.edited_price), cx + COL.price - 2, midY, { align: 'right' });

  return y + ROW_H;
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
  const JsPDF = getJsPdfCtor();
  if (!JsPDF) {
    throw new Error('PDF library is still loading. Please wait a moment and try again.');
  }

  const list = Array.isArray(products) ? products : [];
  const report = (value) => {
    if (typeof onProgress === 'function') onProgress(Math.min(100, Math.max(0, value)));
  };

  report(2);

  // Preload images quickly; failures fall back to placeholder.
  const imageSources = list.map((product) => toPdfImageUrl(product.image_url) || PLACEHOLDER_IMG);
  const imageDataUrls = await mapWithConcurrency(
    imageSources,
    IMAGE_CONCURRENCY,
    (url) => imageUrlToDataUrl(url),
    (done, total) => {
      const ratio = total > 0 ? done / total : 1;
      report(2 + Math.round(ratio * 70));
    },
  );

  report(74);

  const [logoDataUrl, signatureDataUrl] = await Promise.all([
    logoUrl ? imageUrlToDataUrl(String(logoUrl), { maxEdge: 220 }) : Promise.resolve(''),
    currentUser?.signature_url
      ? imageUrlToDataUrl(String(currentUser.signature_url), { maxEdge: 420, asPng: true })
      : Promise.resolve(''),
  ]);

  report(80);

  const companyName = String(company?.company_name || '');
  const companyAddress = String(company?.company_address || '');
  const currencyLabel = String(currency || 'TZS');
  const rows = list.map((product, index) => ({
    name: product.name,
    description: product.description,
    edited_price: product.edited_price,
    imageDataUrl: imageDataUrls[index] || PLACEHOLDER_IMG,
  }));

  const pdf = new JsPDF({
    unit: 'mm',
    format: 'a4',
    orientation: 'portrait',
    compress: true,
  });

  // Estimate page count for headers.
  let remaining = rows.length;
  let estimatedPages = 0;
  let first = true;
  while (remaining > 0 || estimatedPages === 0) {
    const capacity = estimateRowsPerPage(first);
    remaining -= capacity;
    estimatedPages += 1;
    first = false;
    if (remaining <= 0) break;
  }

  let pageNumber = 1;
  let y = drawCover(pdf, {
    customerName,
    companyName,
    logoDataUrl: logoDataUrl !== PLACEHOLDER_IMG ? logoDataUrl : '',
  });
  y = drawHeaderBar(pdf, y, currencyLabel);

  let capacity = estimateRowsPerPage(true);
  let usedOnPage = 0;

  for (let i = 0; i < rows.length; i += 1) {
    if (usedOnPage >= capacity) {
      pdf.addPage();
      pageNumber += 1;
      y = drawContinuedHeader(pdf, companyName, pageNumber, Math.max(estimatedPages, pageNumber));
      y = drawHeaderBar(pdf, y, currencyLabel);
      capacity = estimateRowsPerPage(false);
      usedOnPage = 0;
    }

    y = drawProductRow(pdf, y, rows[i], i);
    usedOnPage += 1;

    if (i % 20 === 0 || i === rows.length - 1) {
      report(80 + Math.round(((i + 1) / Math.max(1, rows.length)) * 18));
    }
  }

  drawClosing(pdf, y, {
    companyName,
    companyAddress,
    signatureDataUrl: signatureDataUrl !== PLACEHOLDER_IMG ? signatureDataUrl : '',
    signatoryName: currentUser?.full_name || 'Authorized Signatory',
  });

  // Fix continued-page totals if estimate was short/long.
  const totalPages = pdf.getNumberOfPages();
  for (let p = 2; p <= totalPages; p += 1) {
    pdf.setPage(p);
    // Clear and rewrite page count area lightly.
    pdf.setFillColor(255, 255, 255);
    pdf.rect(PAGE_WIDTH_MM - MARGIN_X - 40, MARGIN_TOP - 4, 40, 6, 'F');
    pdf.setFont('helvetica', 'normal');
    pdf.setFontSize(8);
    pdf.setTextColor(107, 114, 128);
    pdf.text(`Page ${p} of ${totalPages}`, PAGE_WIDTH_MM - MARGIN_X, MARGIN_TOP + 2, { align: 'right' });
  }

  report(100);
  pdf.save(`PriceList_${new Date().toISOString().split('T')[0]}.pdf`);
}
