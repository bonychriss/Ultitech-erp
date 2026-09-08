function pdfJsPdfCtor() {
  if (window.jspdf && window.jspdf.jsPDF) return window.jspdf.jsPDF;
  return window.jsPDF;
}

function ensureFontStylesheets(markup) {
  if (!markup || typeof markup !== 'string') return [];
  const template = document.createElement('template');
  template.innerHTML = markup.trim();
  const links = [];
  template.content.querySelectorAll('link[rel="stylesheet"]').forEach((node) => {
    const href = node.getAttribute('href');
    if (!href) return;
    const existing = document.querySelector(`link[data-dn-offscreen-font="${href}"]`);
    if (existing) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    link.dataset.dnOffscreenFont = href;
    document.head.appendChild(link);
    links.push(link);
  });
  return links;
}

async function waitForFontsAndImages(root) {
  if (document.fonts && document.fonts.ready) {
    try {
      await document.fonts.ready;
    } catch (_) {
      /* ignore */
    }
  }

  const imgs = [...root.querySelectorAll('img')];
  await Promise.all(imgs.map((img) => {
    if (img.complete) return Promise.resolve();
    return new Promise((resolve) => {
      img.addEventListener('load', resolve, { once: true });
      img.addEventListener('error', resolve, { once: true });
      window.setTimeout(resolve, 5000);
    });
  }));
}

async function elementToCanvas(el, options = {}) {
  if (typeof window.html2canvas !== 'function') {
    throw new Error('PDF library not loaded.');
  }

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

  html2canvasOpts.useCORS = !options.stripImages;
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
  const isWhite = (idx) => data[idx] > 250 && data[idx + 1] > 250 && data[idx + 2] > 250;

  outer: for (; bottom >= 0; bottom -= 1) {
    for (let x = 0; x < width; x += 1) {
      const i = (bottom * width + x) * 4;
      if (!isWhite(i)) break outer;
    }
  }

  const trimmedHeight = Math.min(height, bottom + 24);
  if (trimmedHeight >= height - 2) return canvas;

  const out = document.createElement('canvas');
  out.width = width;
  out.height = Math.max(1, trimmedHeight);
  out.getContext('2d').drawImage(canvas, 0, 0);
  return out;
}

function appendRasterCanvasToPdf(doc, canvas, jpegQuality, onSliceProgress) {
  const trimmedCanvas = trimCanvasBottomWhitespace(canvas);
  const pageWmm = doc.internal.pageSize.getWidth();
  const pageHmm = doc.internal.pageSize.getHeight();
  const marginMm = 0;
  const innerWmm = pageWmm - (marginMm * 2);
  const innerHmm = pageHmm - (marginMm * 2);

  const pxPerMm = trimmedCanvas.width / innerWmm;
  const pageHeightPx = Math.max(1, Math.floor(innerHmm * pxPerMm));
  const totalSlices = Math.max(1, Math.ceil(trimmedCanvas.height / pageHeightPx));

  let sourceY = 0;
  let pageNum = 0;

  while (sourceY < trimmedCanvas.height) {
    const currentSliceHeight = Math.min(pageHeightPx, trimmedCanvas.height - sourceY);
    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = trimmedCanvas.width;
    tempCanvas.height = currentSliceHeight;
    tempCanvas.getContext('2d').drawImage(
      trimmedCanvas,
      0,
      sourceY,
      trimmedCanvas.width,
      currentSliceHeight,
      0,
      0,
      trimmedCanvas.width,
      currentSliceHeight,
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

/**
 * Mount server-rendered delivery-note HTML off-screen and save a PDF.
 *
 * @param {{ html: string, displayNumber?: string, fontStylesheets?: string }} payload
 * @param {(percent: number, message?: string) => void} [onProgress]
 */
export async function downloadDeliveryNotePdfFromHtml(payload, onProgress) {
  const report = (percent, message) => {
    if (typeof onProgress === 'function') {
      onProgress(Math.max(0, Math.min(100, percent)), message);
    }
  };

  const html = String(payload?.html || '').trim();
  if (!html) throw new Error('Delivery note document is empty.');

  const JsPDF = pdfJsPdfCtor();
  if (!JsPDF) throw new Error('PDF library not loaded.');

  report(4, 'Preparing delivery note...');
  ensureFontStylesheets(payload?.fontStylesheets || '');

  const wrapper = document.createElement('div');
  wrapper.setAttribute('aria-hidden', 'true');
  wrapper.style.cssText = 'position:fixed;left:-10000px;top:0;width:210mm;background:#fff;z-index:-1;';
  wrapper.innerHTML = html;
  document.body.appendChild(wrapper);

  try {
    const captureRoot = wrapper.querySelector('.page-container')
      || wrapper.querySelector('#delivery-note-content')
      || wrapper;

    report(12, 'Loading images and fonts...');
    await waitForFontsAndImages(wrapper);
    await new Promise((resolve) => {
      window.requestAnimationFrame(() => { window.requestAnimationFrame(resolve); });
    });

    report(28, 'Capturing delivery note...');
    const canvas = await elementToCanvasWithFallback(captureRoot);

    report(62, 'Building PDF...');
    const doc = new JsPDF({ orientation: 'p', unit: 'mm', format: 'a4' });
    appendRasterCanvasToPdf(doc, canvas, 0.93, (ratio) => {
      report(62 + (28 * ratio), 'Assembling pages...');
    });

    const filename = `DeliveryNote_${payload?.displayNumber || 'document'}.pdf`;
    report(95, 'Saving to your downloads folder...');
    await new Promise((resolve) => {
      window.requestAnimationFrame(() => { window.requestAnimationFrame(resolve); });
    });
    doc.save(filename);
    report(100, 'Download complete');
    return filename;
  } finally {
    wrapper.remove();
  }
}
