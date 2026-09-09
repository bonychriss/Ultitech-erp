import html2canvas from 'html2canvas';
import { jsPDF } from 'jspdf';

function safeFilename(name) {
  const base = String(name || 'letter')
    .replace(/[<>:"/\\|?*\u0000-\u001f]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 80);
  return (base || 'letter') + '.pdf';
}

function prepareEdits(root) {
  root.querySelectorAll('.lh-edit').forEach((node) => {
    node.style.border = 'none';
    node.style.background = 'transparent';
    node.style.boxShadow = 'none';
    node.style.outline = 'none';
    if (!String(node.value || '').trim()) {
      node.style.color = 'transparent';
    }
  });
}

async function captureElement(el) {
  return html2canvas(el, {
    scale: 2,
    useCORS: true,
    allowTaint: true,
    backgroundColor: '#ffffff',
    logging: false,
    imageTimeout: 15000,
    onclone: (clonedDoc, clonedEl) => {
      prepareEdits(clonedEl);
      const pageNum = clonedEl.querySelector('.lh-page-number');
      if (pageNum) pageNum.style.display = 'none';
    },
  });
}

function canvasToJpeg(canvas, quality = 0.95) {
  return canvas.toDataURL('image/jpeg', quality);
}

function sliceCanvas(source, srcY, srcHeight) {
  const y = Math.max(0, Math.floor(srcY));
  const h = Math.max(1, Math.min(Math.ceil(srcHeight), source.height - y));
  const slice = document.createElement('canvas');
  slice.width = source.width;
  slice.height = h;
  const ctx = slice.getContext('2d');
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, slice.width, slice.height);
  ctx.drawImage(source, 0, y, source.width, h, 0, 0, slice.width, h);
  return slice;
}

/**
 * Build an A4 PDF with letterhead header + footer + page numbers on every page.
 * @param {HTMLElement | null} pageEl
 * @param {{ filename?: string }} [opts]
 */
export async function downloadLetterAsPdf(pageEl, opts = {}) {
  const page = pageEl || document.querySelector('.lh-page');
  if (!page) {
    throw new Error('Letter preview not found.');
  }

  const header = page.querySelector('.lh-template-header');
  const content = page.querySelector('.lh-content');
  const footer = page.querySelector('.lh-template-footer');
  if (!header || !content || !footer) {
    throw new Error('Letter letterhead sections not found.');
  }

  const filename = safeFilename(opts.filename || 'letter');
  page.classList.add('is-pdf-capture');

  try {
    const [headerCanvas, contentCanvas, footerCanvas] = await Promise.all([
      captureElement(header),
      captureElement(content),
      captureElement(footer),
    ]);

    const pdf = new jsPDF({
      orientation: 'portrait',
      unit: 'mm',
      format: 'a4',
      compress: true,
    });

    const pageW = pdf.internal.pageSize.getWidth();
    const pageH = pdf.internal.pageSize.getHeight();
    const pageNumBand = 6;

    const headerH = (headerCanvas.height * pageW) / headerCanvas.width;
    const footerH = (footerCanvas.height * pageW) / footerCanvas.width;
    const contentTop = headerH;
    const contentBottom = pageH - footerH - pageNumBand;
    const contentAreaH = Math.max(40, contentBottom - contentTop);

    const contentFullH = (contentCanvas.height * pageW) / contentCanvas.width;
    const totalPages = Math.max(1, Math.ceil(contentFullH / contentAreaH - 0.001));

    const headerImg = canvasToJpeg(headerCanvas);
    const footerImg = canvasToJpeg(footerCanvas);
    const pxPerMm = contentCanvas.width / pageW;

    for (let i = 0; i < totalPages; i += 1) {
      if (i > 0) pdf.addPage();

      pdf.setFillColor(255, 255, 255);
      pdf.rect(0, 0, pageW, pageH, 'F');

      pdf.addImage(headerImg, 'JPEG', 0, 0, pageW, headerH, undefined, 'FAST');

      const srcY = i * contentAreaH * pxPerMm;
      const srcH = Math.min(contentAreaH * pxPerMm, contentCanvas.height - srcY);
      if (srcH > 0.5) {
        const slice = sliceCanvas(contentCanvas, srcY, srcH);
        const sliceH = (slice.height * pageW) / slice.width;
        pdf.addImage(canvasToJpeg(slice), 'JPEG', 0, contentTop, pageW, sliceH, undefined, 'FAST');
      }

      pdf.addImage(footerImg, 'JPEG', 0, pageH - footerH, pageW, footerH, undefined, 'FAST');

      pdf.setFont('times', 'normal');
      pdf.setFontSize(9);
      pdf.setTextColor(70, 70, 70);
      pdf.text(
        `Page ${i + 1} of ${totalPages}`,
        pageW / 2,
        pageH - footerH - 2,
        { align: 'center' }
      );
    }

    pdf.save(filename);
  } finally {
    page.classList.remove('is-pdf-capture');
  }
}
