function invoicePdfJsPdfCtor() {
  if (window.jspdf && window.jspdf.jsPDF) return window.jspdf.jsPDF;
  return window.jsPDF;
}

export function formatPdfError(err) {
  if (!err) return 'Failed to generate PDF.';
  if (typeof err === 'string') return err;

  const name = String(err.name || '');
  const msg = String(err.message || err.reason || err.details || '');

  if (name === 'SecurityError' || /tainted|toDataURL|cross-origin|cross origin/i.test(msg)) {
    return 'Browser blocked PDF export because of document images. Refresh the page and try again.';
  }
  if (/PDF library not loaded/i.test(msg)) return msg;
  if (/Nothing to capture/i.test(msg)) return msg;
  if (msg) return msg;

  try {
    const json = JSON.stringify(err);
    if (json && json !== '{}') return json.slice(0, 400);
  } catch (_) {
    /* ignore */
  }

  try {
    const fallback = String(err);
    if (fallback && fallback !== '[object Object]') return fallback;
  } catch (_) {
    /* ignore */
  }

  return 'Failed to generate PDF.';
}

export function invoicePdfCaptureElements() {
  const wrap = document.getElementById('invoice-content');
  if (!wrap) return [];

  if (window.SalesDocumentPdfCapture) {
    return window.SalesDocumentPdfCapture.captureElements('invoice-content');
  }

  const selectors = ['.sheet-container', '.spare-sheet', '.truck-sheet', '.truck-sheet-second'];
  const seen = new Set();
  const elements = [];

  selectors.forEach((selector) => {
    wrap.querySelectorAll(selector).forEach((el) => {
      if (!seen.has(el) && el.offsetWidth > 0 && el.offsetHeight > 0) {
        seen.add(el);
        elements.push(el);
      }
    });
  });

  if (!elements.length && wrap.offsetWidth > 0 && wrap.offsetHeight > 0) {
    elements.push(wrap);
  }

  return elements;
}

function invoicePdfPrepareCloneForCapture(clonedDoc) {
  clonedDoc.querySelectorAll('.sheet, .doc-terms-sheet-inner, .sheet-container').forEach((node) => {
    node.style.minHeight = 'auto';
    node.style.height = 'auto';
    node.style.marginTop = '0';
  });
}

function trimCanvasBottomWhitespace(canvas) {
  const ctx = canvas.getContext('2d');
  if (!ctx) return canvas;

  const { width, height } = canvas;
  if (width <= 0 || height <= 0) return canvas;

  const data = ctx.getImageData(0, 0, width, height).data;
  let bottom = height - 1;
  const step = Math.max(1, Math.floor(width / 120));

  outer: for (; bottom >= 0; bottom -= 1) {
    for (let x = 0; x < width; x += step) {
      const i = (bottom * width + x) * 4;
      if (data[i] < 248 || data[i + 1] < 248 || data[i + 2] < 248) {
        break outer;
      }
    }
  }

  const trimmedHeight = bottom + 1;
  if (trimmedHeight <= 0 || trimmedHeight >= height - 8) {
    return canvas;
  }

  const trimmed = document.createElement('canvas');
  trimmed.width = width;
  trimmed.height = trimmedHeight;
  trimmed.getContext('2d').drawImage(canvas, 0, 0, width, trimmedHeight, 0, 0, width, trimmedHeight);
  return trimmed;
}

function canvasSliceIsMostlyBlank(tempCanvas) {
  const ctx = tempCanvas.getContext('2d');
  if (!ctx) return true;

  const { width, height } = tempCanvas;
  if (width <= 0 || height <= 0) return true;

  const data = ctx.getImageData(0, 0, width, height).data;
  const stepX = Math.max(1, Math.floor(width / 48));
  const stepY = Math.max(1, Math.floor(height / 48));
  let white = 0;
  let total = 0;

  for (let y = 0; y < height; y += stepY) {
    for (let x = 0; x < width; x += stepX) {
      const i = (y * width + x) * 4;
      total += 1;
      if (data[i] > 248 && data[i + 1] > 248 && data[i + 2] > 248) {
        white += 1;
      }
    }
  }

  return total > 0 && white / total >= 0.985;
}

async function invoicePdfWaitForAssets() {
  if (document.fonts && document.fonts.ready) {
    try {
      await document.fonts.ready;
    } catch (_) {
      /* ignore font load errors */
    }
  }

  const imgs = document.querySelectorAll('#invoice-content img, #catalog-content img');
  await Promise.all([...imgs].map((img) => {
    if (img.complete) return Promise.resolve();
    return new Promise((resolve) => {
      img.addEventListener('load', resolve, { once: true });
      img.addEventListener('error', resolve, { once: true });
    });
  }));
}

/**
 * Measure avoid-bands in CSS px relative to rootEl (call on the html2canvas clone).
 */
function measureAvoidBandsCss(rootEl) {
  if (!rootEl) return { cssHeight: 1, bands: [] };
  const rootRect = rootEl.getBoundingClientRect();
  const cssHeight = Math.max(1, rootEl.scrollHeight, rootEl.offsetHeight, rootRect.height);
  const bands = [];
  const seen = new Set();

  const pushBox = (top, bottom) => {
    if (!(bottom - top >= 2)) return;
    bands.push({ top, bottom });
  };

  const pushEl = (node) => {
    if (!node || seen.has(node)) return;
    seen.add(node);
    const r = node.getBoundingClientRect();
    pushBox(r.top - rootRect.top, r.bottom - rootRect.top);
  };

  rootEl.querySelectorAll(
    '.o-table tbody tr, .o-table thead tr, .items-table tbody tr, .items-table thead tr, .product-grid-item, .catalog-item',
  ).forEach(pushEl);

  // Keep totals + payment details + closing line together when possible.
  rootEl.querySelectorAll('.o-totals-table').forEach((table) => {
    const wrap = table.parentElement && table.parentElement.tagName === 'DIV'
      ? table.parentElement
      : table;
    let bottomEl = wrap;
    let sib = wrap.nextElementSibling;
    while (sib) {
      if (
        sib.classList?.contains('quot-bank-details')
        || sib.classList?.contains('document-footer-closing')
        || sib.classList?.contains('avoid-break')
      ) {
        bottomEl = sib;
        sib = sib.nextElementSibling;
        continue;
      }
      break;
    }
    const topR = wrap.getBoundingClientRect();
    const botR = bottomEl.getBoundingClientRect();
    pushBox(topR.top - rootRect.top, botR.bottom - rootRect.top);
  });

  rootEl.querySelectorAll(
    '.invoice-header, .sheet-header, .doc-terms-section, .terms-block, .signature-block, .quot-bank-details, .document-footer-closing',
  ).forEach(pushEl);

  bands.sort((a, b) => a.top - b.top || a.bottom - b.bottom);
  return { cssHeight, bands };
}

function scaleAvoidBands(measured, canvas) {
  if (!measured || !canvas || !canvas.height) return [];
  const scaleY = canvas.height / Math.max(1, measured.cssHeight || 1);
  const pad = Math.max(1, Math.round(scaleY)); // ~1 CSS px — catches letter tips
  return (measured.bands || [])
    .map((b) => ({
      top: Math.max(0, Math.round(b.top * scaleY) - pad),
      bottom: Math.min(canvas.height, Math.round(b.bottom * scaleY) + pad),
    }))
    .filter((b) => b.bottom > b.top + 1)
    .sort((a, b) => a.top - b.top || a.bottom - b.bottom);
}

async function invoicePdfElementToCanvas(el, options = {}) {
  const stripImages = options.stripImages === true;
  if (!el) throw new Error('Nothing to capture for PDF.');

  el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  await new Promise((resolve) => {
    requestAnimationFrame(() => { requestAnimationFrame(resolve); });
  });

  if (typeof window.html2canvas !== 'function') {
    throw new Error('PDF library not loaded.');
  }

  await invoicePdfWaitForAssets();

  let measured = { cssHeight: 1, bands: [] };
  const marker = `pdf-cap-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
  el.setAttribute('data-pdf-capture', marker);
  const html2canvasOpts = {
    scale: options.scale || 2,
    logging: false,
    backgroundColor: '#ffffff',
    scrollX: 0,
    scrollY: -window.scrollY,
    windowWidth: el.scrollWidth,
    windowHeight: el.scrollHeight,
    onclone: (clonedDoc, clonedEl) => {
      invoicePdfPrepareCloneForCapture(clonedDoc);
      if (stripImages) {
        clonedDoc.querySelectorAll('img').forEach((img) => img.remove());
      }
      const root = clonedEl
        || clonedDoc.querySelector(`[data-pdf-capture="${marker}"]`)
        || (el.id ? clonedDoc.getElementById(el.id) : null)
        || clonedDoc.body;
      measured = measureAvoidBandsCss(root);
    },
  };

  if (stripImages) {
    html2canvasOpts.useCORS = false;
  } else {
    html2canvasOpts.useCORS = true;
  }

  try {
    const canvas = await window.html2canvas(el, html2canvasOpts);
    return { canvas, bands: scaleAvoidBands(measured, canvas) };
  } finally {
    el.removeAttribute('data-pdf-capture');
  }
}

async function invoicePdfElementToCanvasWithFallback(el) {
  try {
    return await invoicePdfElementToCanvas(el);
  } catch (firstErr) {
    try {
      return await invoicePdfElementToCanvas(el, { stripImages: true, scale: 2 });
    } catch (secondErr) {
      throw secondErr || firstErr;
    }
  }
}

function canvasSliceToDataUrl(tempCanvas, jpegQuality) {
  try {
    return tempCanvas.toDataURL('image/jpeg', jpegQuality);
  } catch (err) {
    throw new Error(formatPdfError(err));
  }
}

/**
 * Only shorten the page when hardEnd would cut through a row/block.
 * Otherwise use the full page window (avoids blank-page orphans).
 */
function pickSliceEnd(sourceY, maxEnd, bands) {
  if (!Array.isArray(bands) || !bands.length) return maxEnd;

  let cutBand = null;
  for (let i = 0; i < bands.length; i += 1) {
    const band = bands[i];
    if (band.bottom <= sourceY + 1) continue;
    if (band.top >= maxEnd) break;
    // Any straddle: page edge lands inside this band.
    if (band.top < maxEnd && band.bottom > maxEnd) {
      cutBand = band;
      break;
    }
  }
  if (!cutBand) return maxEnd;

  let bestBottom = 0;
  for (let i = 0; i < bands.length; i += 1) {
    const bottom = bands[i].bottom;
    if (bottom > sourceY + 2 && bottom <= cutBand.top + 0.5 && bottom <= maxEnd) {
      bestBottom = Math.max(bestBottom, bottom);
    }
  }
  if (bestBottom > sourceY) return bestBottom;
  if (cutBand.top - sourceY > 8) return Math.max(sourceY + 1, cutBand.top);
  return maxEnd;
}

function appendRasterCanvasToPdf(doc, canvas, jpegQuality, onSliceProgress, options = {}) {
  const trimmedCanvas = trimCanvasBottomWhitespace(canvas);
  if (!trimmedCanvas.width || !trimmedCanvas.height) {
    throw new Error('Document rendered empty. Refresh the page and try again.');
  }

  const marginMm = 8;
  const pageHmm = doc.internal.pageSize.getHeight();
  const pageWmm = doc.internal.pageSize.getWidth();
  const innerWmm = pageWmm - 2 * marginMm;
  const innerHmm = pageHmm - 2 * marginMm;

  const sliceHeightInPixels = (trimmedCanvas.width * innerHmm) / innerWmm;
  const totalHeightInPixels = trimmedCanvas.height;
  const rawBands = Array.isArray(options.bands)
    ? options.bands
    : (Array.isArray(options.breakYs) ? options.breakYs.map((y) => ({ top: y, bottom: y })) : []);
  const usableBands = rawBands
    .map((b) => ({
      top: typeof b === 'number' ? b : Number(b.top) || 0,
      bottom: typeof b === 'number' ? b : Number(b.bottom) || 0,
    }))
    .filter((b) => b.bottom > b.top && b.bottom > 0 && b.top < totalHeightInPixels)
    .map((b) => ({
      top: Math.max(0, b.top),
      bottom: Math.min(totalHeightInPixels, b.bottom),
    }))
    .sort((a, b) => a.top - b.top || a.bottom - b.bottom);
  const totalSlices = Math.max(1, Math.ceil(totalHeightInPixels / sliceHeightInPixels));

  let sourceY = 0;
  let pageNum = 0;

  while (sourceY < totalHeightInPixels) {
    const hardEnd = Math.min(sourceY + sliceHeightInPixels, totalHeightInPixels);
    const sliceEnd = pickSliceEnd(sourceY, hardEnd, usableBands);
    const currentSliceHeight = Math.max(1, sliceEnd - sourceY);
    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = trimmedCanvas.width;
    tempCanvas.height = currentSliceHeight;

    const tempCtx = tempCanvas.getContext('2d');
    tempCtx.fillStyle = '#ffffff';
    tempCtx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);
    tempCtx.drawImage(
      trimmedCanvas,
      0, sourceY, trimmedCanvas.width, currentSliceHeight,
      0, 0, trimmedCanvas.width, currentSliceHeight,
    );

    sourceY += currentSliceHeight;

    if (canvasSliceIsMostlyBlank(tempCanvas)) {
      continue;
    }

    const needsNewPage = pageNum > 0
      || (options.startOnNewPage === true && pageNum === 0 && doc.internal.getNumberOfPages() > 0);
    if (needsNewPage) {
      doc.addPage();
    }

    const sliceDataUrl = canvasSliceToDataUrl(tempCanvas, jpegQuality);
    const destHeightMm = (currentSliceHeight * innerWmm) / trimmedCanvas.width;
    doc.addImage(sliceDataUrl, 'JPEG', marginMm, marginMm, innerWmm, destHeightMm);

    pageNum += 1;
    if (typeof onSliceProgress === 'function') {
      onSliceProgress(Math.min(1, pageNum / totalSlices));
    }
  }
}

async function appendInvoiceElementsToPdf(doc, elements, jpegQuality, onProgress, rangeStart, rangeEnd) {
  const total = Math.max(elements.length, 1);
  const span = rangeEnd - rangeStart;

  for (let index = 0; index < elements.length; index += 1) {
    const el = elements[index];
    if (!el) continue;

    const blockStart = rangeStart + (span * index) / total;
    const captureDone = rangeStart + (span * (index + 0.55)) / total;
    const blockEnd = rangeStart + (span * (index + 1)) / total;

    if (onProgress) {
      onProgress(blockStart, `Capturing page ${index + 1} of ${total}...`);
    }

    const captured = await invoicePdfElementToCanvasWithFallback(el);

    if (onProgress) {
      onProgress(captureDone, `Building PDF page ${index + 1} of ${total}...`);
    }

    appendRasterCanvasToPdf(doc, captured.canvas, jpegQuality, (sliceRatio) => {
      if (!onProgress) return;
      const slicePct = captureDone + ((blockEnd - captureDone) * sliceRatio);
      onProgress(slicePct, `Assembling page ${index + 1} of ${total}...`);
    }, { startOnNewPage: index > 0, bands: captured.bands });

    if (onProgress) {
      onProgress(blockEnd, `Page ${index + 1} of ${total} ready`);
    }
  }
}

export async function generateInvoicePdf(displayInvoiceNumber, onProgress) {
  const report = (percent, message) => {
    if (typeof onProgress === 'function') {
      onProgress(Math.max(0, Math.min(100, percent)), message);
    }
  };

  const JsPDF = invoicePdfJsPdfCtor();
  if (!JsPDF) throw new Error('PDF library not loaded.');

  report(3, 'Preparing document...');
  await invoicePdfWaitForAssets();
  report(8, 'Document assets loaded');

  const doc = new JsPDF({ orientation: 'p', unit: 'mm', format: 'a4' });
  const elements = invoicePdfCaptureElements();
  if (!elements.length) {
    throw new Error('Nothing to capture for PDF.');
  }

  const catalogElement = document.getElementById('catalog-content');
  const bodyEnd = catalogElement ? 72 : 88;

  await appendInvoiceElementsToPdf(doc, elements, 0.93, report, 10, bodyEnd);

  if (catalogElement && catalogElement.offsetHeight > 0) {
    report(74, 'Capturing product catalog...');
    const catalogCaptured = await invoicePdfElementToCanvasWithFallback(catalogElement);
    report(82, 'Building catalog pages...');
    appendRasterCanvasToPdf(doc, catalogCaptured.canvas, 0.93, (sliceRatio) => {
      report(82 + (10 * sliceRatio), 'Assembling catalog pages...');
    }, { startOnNewPage: true, bands: catalogCaptured.bands });
    report(92, 'Catalog added');
  }

  report(94, 'Finalizing PDF file...');

  return { doc, filename: `Invoice_${displayInvoiceNumber || 'document'}.pdf` };
}

export async function downloadInvoicePdf(displayInvoiceNumber, onProgress) {
  const { doc, filename } = await generateInvoicePdf(displayInvoiceNumber, onProgress);
  if (typeof onProgress === 'function') {
    onProgress(96, 'Saving to your downloads folder...');
  }
  await new Promise((resolve) => {
    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(resolve);
    });
  });
  doc.save(filename);
  if (typeof onProgress === 'function') {
    onProgress(100, 'Download complete');
  }
  return filename;
}

export async function buildInvoicePdfDataUri(onProgress) {
  const { doc } = await generateInvoicePdf('', onProgress);
  return doc.output('datauristring');
}
