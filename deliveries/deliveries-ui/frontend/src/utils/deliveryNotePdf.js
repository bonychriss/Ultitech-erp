function pdfJsPdfCtor() {
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

  return 'Failed to generate PDF.';
}

function captureElements() {
  const wrap = document.getElementById('delivery-note-content');
  if (!wrap) return [];

  const page = wrap.querySelector('.page-container');
  if (page && page.offsetWidth > 0 && page.offsetHeight > 0) {
    return [page];
  }

  if (wrap.offsetWidth > 0 && wrap.offsetHeight > 0) {
    return [wrap];
  }

  return [];
}

async function waitForAssets() {
  if (document.fonts && document.fonts.ready) {
    try {
      await document.fonts.ready;
    } catch (_) {
      /* ignore */
    }
  }

  const imgs = document.querySelectorAll('#delivery-note-content img');
  await Promise.all([...imgs].map((img) => {
    if (img.complete) return Promise.resolve();
    return new Promise((resolve) => {
      img.addEventListener('load', resolve, { once: true });
      img.addEventListener('error', resolve, { once: true });
    });
  }));
}

async function elementToCanvas(el, options = {}) {
  if (!el) throw new Error('Nothing to capture for PDF.');

  el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  await new Promise((resolve) => {
    requestAnimationFrame(() => { requestAnimationFrame(resolve); });
  });

  if (typeof window.html2canvas !== 'function') {
    throw new Error('PDF library not loaded.');
  }

  await waitForAssets();

  const html2canvasOpts = {
    scale: options.scale || 2,
    logging: false,
    backgroundColor: '#ffffff',
    scrollX: 0,
    scrollY: -window.scrollY,
    windowWidth: el.scrollWidth,
    windowHeight: el.scrollHeight,
    onclone: (clonedDoc) => {
      clonedDoc.querySelectorAll('.page-container').forEach((node) => {
        node.style.minHeight = 'auto';
        node.style.height = 'auto';
        node.style.marginTop = '0';
        node.style.boxShadow = 'none';
      });
      if (options.stripImages) {
        clonedDoc.querySelectorAll('img').forEach((img) => img.remove());
      }
    },
  };

  if (options.stripImages) {
    html2canvasOpts.useCORS = false;
  } else {
    html2canvasOpts.useCORS = true;
  }

  return window.html2canvas(el, html2canvasOpts);
}

async function elementToCanvasWithFallback(el) {
  try {
    return await elementToCanvas(el);
  } catch (firstErr) {
    try {
      return await elementToCanvas(el, { stripImages: true, scale: 2 });
    } catch (secondErr) {
      throw secondErr || firstErr;
    }
  }
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

function appendRasterCanvasToPdf(doc, canvas, jpegQuality, onSliceProgress) {
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
  const totalSlices = Math.max(1, Math.ceil(totalHeightInPixels / sliceHeightInPixels));

  let sourceY = 0;
  let pageNum = 0;

  while (sourceY < totalHeightInPixels) {
    const currentSliceHeight = Math.min(sliceHeightInPixels, totalHeightInPixels - sourceY);
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

    if (pageNum > 0) {
      doc.addPage();
    }

    const sliceDataUrl = tempCanvas.toDataURL('image/jpeg', jpegQuality);
    const destHeightMm = (currentSliceHeight * innerWmm) / trimmedCanvas.width;
    doc.addImage(sliceDataUrl, 'JPEG', marginMm, marginMm, innerWmm, destHeightMm);

    pageNum += 1;
    if (typeof onSliceProgress === 'function') {
      onSliceProgress(Math.min(1, pageNum / totalSlices));
    }
  }
}

export async function downloadDeliveryNotePdf(displayNumber, onProgress) {
  const report = (percent, message) => {
    if (typeof onProgress === 'function') {
      onProgress(Math.max(0, Math.min(100, percent)), message);
    }
  };

  const JsPDF = pdfJsPdfCtor();
  if (!JsPDF) throw new Error('PDF library not loaded.');

  report(3, 'Preparing document...');
  await waitForAssets();
  report(8, 'Document assets loaded');

  const doc = new JsPDF({ orientation: 'p', unit: 'mm', format: 'a4' });
  const elements = captureElements();
  if (!elements.length) {
    throw new Error('Nothing to capture for PDF.');
  }

  report(10, 'Capturing delivery note...');
  const canvas = await elementToCanvasWithFallback(elements[0]);
  report(60, 'Building PDF...');
  appendRasterCanvasToPdf(doc, canvas, 0.93, (ratio) => {
    report(60 + (30 * ratio), 'Assembling pages...');
  });

  report(94, 'Finalizing PDF file...');
  const filename = `DeliveryNote_${displayNumber || 'document'}.pdf`;
  report(96, 'Saving to your downloads folder...');
  await new Promise((resolve) => {
    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(resolve);
    });
  });
  doc.save(filename);
  report(100, 'Download complete');
  return filename;
}
