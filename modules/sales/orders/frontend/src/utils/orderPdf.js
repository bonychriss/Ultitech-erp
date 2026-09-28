function orderPdfJsPdfCtor() {
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

export function orderPdfCaptureElements() {
  const wrap = document.getElementById('order-content');
  if (!wrap) return [];

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

function orderPdfPrepareCloneForCapture(clonedDoc) {
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

const ORDER_PDF_BLOCK_SELECTOR = [
  '.spare-table thead tr',
  '.spare-table tbody tr',
  '.truck-table thead tr',
  '.truck-table tbody tr',
  '.o-table thead tr',
  '.o-table tbody tr',
  '.ov-catalog-table thead tr',
  '.ov-catalog-table tbody tr',
  '.spare-totals-table tr',
  '.truck-totals-table tr',
  '.o-totals-table tr',
  '.spare-footer-area',
  '.spare-bottom-info',
  '.totals-area',
  '.quot-bank-details',
  '.avoid-break',
].join(', ');

const ORDER_PDF_TABLE_SELECTOR = '.spare-table, .truck-table, .o-table, .ov-catalog-table';

export function orderPdfNeedsDesktopCapture() {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false;
  return window.matchMedia('(max-width: 900px)').matches;
}

function orderPdfNextFrame() {
  return new Promise((resolve) => {
    requestAnimationFrame(() => {
      requestAnimationFrame(resolve);
    });
  });
}

function orderPdfRelativeBox(node, rootTop) {
  const rect = node.getBoundingClientRect();
  const top = rect.top - rootTop;
  const bottom = rect.bottom - rootTop;
  if (bottom - top < 2) return null;
  return { top, bottom };
}

export function orderPdfMeasureLayout(el) {
  const rootRect = el.getBoundingClientRect();
  const rootTop = rootRect.top;
  const cssHeight = Math.max(rootRect.height, 1);
  const bands = [];

  el.querySelectorAll(ORDER_PDF_BLOCK_SELECTOR).forEach((node) => {
    const box = orderPdfRelativeBox(node, rootTop);
    if (box) bands.push(box);
  });

  bands.sort((a, b) => a.top - b.top || a.bottom - b.bottom);

  const tables = [];
  el.querySelectorAll(ORDER_PDF_TABLE_SELECTOR).forEach((table) => {
    const thead = table.tHead || table.querySelector('thead');
    const tbody = (table.tBodies && table.tBodies[0]) || table.querySelector('tbody');
    if (!thead || !tbody) return;
    const header = orderPdfRelativeBox(thead, rootTop);
    const body = orderPdfRelativeBox(tbody, rootTop);
    if (!header || !body) return;
    tables.push({
      headerTop: header.top,
      headerBottom: header.bottom,
      bodyTop: body.top,
      bodyBottom: body.bottom,
    });
  });

  return { cssHeight, bands, tables };
}

export function choosePdfSliceHeight(sourceY, maxSlice, totalHeight, bands) {
  const hardEnd = Math.min(sourceY + maxSlice, totalHeight);
  if (hardEnd >= totalHeight - 0.5) {
    return Math.max(1, totalHeight - sourceY);
  }

  let end = hardEnd;
  const minAdvance = Math.max(24, maxSlice * 0.28);

  for (let i = 0; i < bands.length; i += 1) {
    const band = bands[i];
    if (band.bottom <= sourceY + 1) continue;
    if (band.top >= hardEnd - 2) break;
    const cutsBand = band.top < hardEnd - 2 && band.bottom > hardEnd + 2;
    if (!cutsBand) continue;
    if (band.top - sourceY >= minAdvance) {
      end = Math.min(end, band.top);
    }
  }

  const height = end - sourceY;
  if (height < 1) return Math.max(1, hardEnd - sourceY);
  return height;
}

function orderPdfScaleBands(bands, pixelScale) {
  return bands
    .map((band) => ({
      top: band.top * pixelScale,
      bottom: band.bottom * pixelScale,
    }))
    .filter((band) => band.bottom > band.top + 0.5)
    .sort((a, b) => a.top - b.top);
}

function orderPdfContinuationHeader(sourceY, pixelScale, tables) {
  const yCss = sourceY / pixelScale;
  for (let i = 0; i < tables.length; i += 1) {
    const table = tables[i];
    if (yCss >= table.bodyTop - 0.5 && yCss < table.bodyBottom - 1) {
      const sh = (table.headerBottom - table.headerTop) * pixelScale;
      if (sh < 4) return null;
      return {
        sy: table.headerTop * pixelScale,
        sh,
      };
    }
  }
  return null;
}

function orderPdfCaptureScale(el) {
  const cssHeight = Math.max(el.scrollHeight, el.getBoundingClientRect().height, 1);
  const cssWidth = Math.max(el.scrollWidth, el.getBoundingClientRect().width, 1);
  let scale = 2;
  const maxDim = orderPdfNeedsDesktopCapture() ? 4096 : 16384;
  if (cssWidth * scale > maxDim) scale = maxDim / cssWidth;
  if (cssHeight * scale > maxDim) scale = Math.min(scale, maxDim / cssHeight);
  return Math.max(0.85, Math.min(2, scale));
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

async function orderPdfWaitForAssets() {
  if (document.fonts && document.fonts.ready) {
    try {
      await document.fonts.ready;
    } catch (_) {
      /* ignore font load errors */
    }
  }

  const imgs = document.querySelectorAll('#order-content img, #catalog-content img');
  await Promise.all([...imgs].map((img) => {
    if (img.complete) return Promise.resolve();
    return new Promise((resolve) => {
      img.addEventListener('load', resolve, { once: true });
      img.addEventListener('error', resolve, { once: true });
    });
  }));
}

async function orderPdfElementToCanvas(el, options = {}) {
  const stripImages = options.stripImages === true;
  if (!el) throw new Error('Nothing to capture for PDF.');

  el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  await new Promise((resolve) => {
    requestAnimationFrame(() => { requestAnimationFrame(resolve); });
  });

  if (typeof window.html2canvas !== 'function') {
    throw new Error('PDF library not loaded.');
  }

  await orderPdfWaitForAssets();

  const desktopCapture = document.documentElement.classList.contains('ov-pdf-desktop-capture');
  const html2canvasOpts = {
    scale: options.scale || orderPdfCaptureScale(el),
    logging: false,
    backgroundColor: '#ffffff',
    scrollX: 0,
    scrollY: -window.scrollY,
    windowWidth: desktopCapture ? Math.max(el.scrollWidth, 1100) : el.scrollWidth,
    windowHeight: desktopCapture ? Math.max(el.scrollHeight, el.offsetHeight, 1400) : el.scrollHeight,
    onclone: (clonedDoc) => {
      orderPdfPrepareCloneForCapture(clonedDoc);
      if (desktopCapture) {
        clonedDoc.documentElement.classList.add('ov-pdf-desktop-capture');
        clonedDoc.querySelectorAll('.sheet-container, .spare-sheet, .truck-sheet, .truck-sheet-second').forEach((node) => {
          node.style.width = '210mm';
          node.style.maxWidth = '210mm';
          node.style.minWidth = '210mm';
        });
      }
      if (stripImages) {
        clonedDoc.querySelectorAll('img').forEach((img) => img.remove());
      }
    },
  };

  if (stripImages) {
    html2canvasOpts.useCORS = false;
  } else {
    html2canvasOpts.useCORS = true;
  }

  return window.html2canvas(el, html2canvasOpts);
}

async function orderPdfElementToCanvasWithFallback(el) {
  const layout = orderPdfMeasureLayout(el);
  try {
    const canvas = await orderPdfElementToCanvas(el);
    return { canvas, layout };
  } catch (firstErr) {
    try {
      const canvas = await orderPdfElementToCanvas(el, { stripImages: true, scale: 2 });
      return { canvas, layout };
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

function appendRasterCanvasToPdf(doc, canvas, jpegQuality, onSliceProgress, options = {}) {
  const layout = options.layout || { cssHeight: canvas.height, bands: [], tables: [] };
  const pixelScale = layout.cssHeight > 0 ? canvas.height / layout.cssHeight : 1;
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
  const bandsPx = orderPdfScaleBands(layout.bands || [], pixelScale);
  const tables = layout.tables || [];
  const totalSlices = Math.max(1, Math.ceil(totalHeightInPixels / sliceHeightInPixels));

  let sourceY = 0;
  let pageNum = 0;
  let guard = 0;

  while (sourceY < totalHeightInPixels - 0.5 && guard < 200) {
    guard += 1;
    const header = orderPdfContinuationHeader(sourceY, pixelScale, tables);
    let headerReserve = header ? header.sh : 0;
    if (headerReserve > sliceHeightInPixels * 0.45) {
      headerReserve = 0;
    }
    const maxBody = Math.max(1, sliceHeightInPixels - headerReserve);
    let bodyHeight = choosePdfSliceHeight(sourceY, maxBody, totalHeightInPixels, bandsPx);
    if (bodyHeight < 1) bodyHeight = Math.min(maxBody, totalHeightInPixels - sourceY);

    const useHeader = headerReserve > 0 ? header : null;
    const headerH = useHeader ? Math.max(1, Math.round(useHeader.sh)) : 0;
    const bodyH = Math.max(1, Math.round(bodyHeight));
    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = trimmedCanvas.width;
    tempCanvas.height = headerH + bodyH;

    const tempCtx = tempCanvas.getContext('2d');
    tempCtx.fillStyle = '#ffffff';
    tempCtx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);
    if (useHeader) {
      tempCtx.drawImage(
        trimmedCanvas,
        0, useHeader.sy, trimmedCanvas.width, useHeader.sh,
        0, 0, trimmedCanvas.width, headerH,
      );
    }
    tempCtx.drawImage(
      trimmedCanvas,
      0, sourceY, trimmedCanvas.width, bodyHeight,
      0, headerH, trimmedCanvas.width, bodyH,
    );

    const nextY = sourceY + bodyHeight;
    sourceY = nextY <= sourceY ? Math.min(totalHeightInPixels, sourceY + maxBody) : nextY;

    if (canvasSliceIsMostlyBlank(tempCanvas)) {
      continue;
    }

    const needsNewPage = pageNum > 0
      || (options.startOnNewPage === true && pageNum === 0 && doc.internal.getNumberOfPages() > 0);
    if (needsNewPage) {
      doc.addPage();
    }

    const sliceDataUrl = canvasSliceToDataUrl(tempCanvas, jpegQuality);
    const destHeightMm = (tempCanvas.height * innerWmm) / trimmedCanvas.width;
    doc.addImage(sliceDataUrl, 'JPEG', marginMm, marginMm, innerWmm, destHeightMm);

    pageNum += 1;
    if (typeof onSliceProgress === 'function') {
      onSliceProgress(Math.min(1, pageNum / totalSlices));
    }
  }
}

async function appendOrderElementsToPdf(doc, elements, jpegQuality, onProgress, rangeStart, rangeEnd) {
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

    const captured = await orderPdfElementToCanvasWithFallback(el);

    if (onProgress) {
      onProgress(captureDone, `Building PDF page ${index + 1} of ${total}...`);
    }

    appendRasterCanvasToPdf(doc, captured.canvas, jpegQuality, (sliceRatio) => {
      if (!onProgress) return;
      const slicePct = captureDone + ((blockEnd - captureDone) * sliceRatio);
      onProgress(slicePct, `Assembling page ${index + 1} of ${total}...`);
    }, { startOnNewPage: index > 0, layout: captured.layout });

    if (onProgress) {
      onProgress(blockEnd, `Page ${index + 1} of ${total} ready`);
    }
  }
}

export async function generateOrderPdf(displayOrderNumber, onProgress) {
  const report = (percent, message) => {
    if (typeof onProgress === 'function') {
      onProgress(Math.max(0, Math.min(100, percent)), message);
    }
  };

  const JsPDF = orderPdfJsPdfCtor();
  if (!JsPDF) throw new Error('PDF library not loaded.');

  report(3, 'Preparing document...');
  await orderPdfWaitForAssets();
  report(8, 'Document assets loaded');

  const doc = new JsPDF({ orientation: 'p', unit: 'mm', format: 'a4' });
  const mobileLayout = orderPdfNeedsDesktopCapture();
  if (mobileLayout) {
    document.documentElement.classList.add('ov-pdf-desktop-capture');
    await orderPdfNextFrame();
  }

  try {
    const elements = orderPdfCaptureElements();
    if (!elements.length) {
      throw new Error('Nothing to capture for PDF.');
    }

    const catalogElement = document.getElementById('catalog-content');
    const bodyEnd = catalogElement ? 72 : 88;

    await appendOrderElementsToPdf(doc, elements, 0.93, report, 10, bodyEnd);

    if (catalogElement && catalogElement.offsetHeight > 0) {
      report(74, 'Capturing product catalog...');
      const catalogCaptured = await orderPdfElementToCanvasWithFallback(catalogElement);
      report(82, 'Building catalog pages...');
      appendRasterCanvasToPdf(doc, catalogCaptured.canvas, 0.93, (sliceRatio) => {
        report(82 + (10 * sliceRatio), 'Assembling catalog pages...');
      }, { startOnNewPage: true, layout: catalogCaptured.layout });
      report(92, 'Catalog added');
    }
  } finally {
    if (mobileLayout) {
      document.documentElement.classList.remove('ov-pdf-desktop-capture');
    }
  }

  report(94, 'Finalizing PDF file...');

  return { doc, filename: `Order_${displayOrderNumber || 'document'}.pdf` };
}

export async function downloadOrderPdf(displayOrderNumber, onProgress) {
  const { doc, filename } = await generateOrderPdf(displayOrderNumber, onProgress);
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

export async function buildOrderPdfDataUri(onProgress) {
  const { doc } = await generateOrderPdf('', onProgress);
  return doc.output('datauristring');
}
