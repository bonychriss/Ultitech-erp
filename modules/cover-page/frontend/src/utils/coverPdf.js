import { PDFDocument, StandardFonts, rgb } from 'pdf-lib';
import templateUrl from '../assets/cover-template.pdf?url';
import { coverTitle, documentNameWithoutFile } from './title.js';

// Coordinates below are measured from the top-left of the template's visible (crop) box, in PDF points.
const NAVY = rgb(11 / 255, 27 / 255, 63 / 255);
const GOLD = rgb(217 / 255, 165 / 255, 20 / 255);

const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

// Title lives between the two short gold bars and must stay left of the photo ring.
// twoLineCapTop keeps a wrapped name clear of the gold bar above the title (bar ends at 221.5).
const TITLE = { x: 61.2, maxWidth: 300, navyBaseline: 290.4, goldBaseline: 377.1, twoLineCapTop: 240 };
const CAP_HEIGHT = 0.716;

const DETAILS = { x: 123.8, maxWidth: 144, size: 19, fileBaseline: 579.6, docsBaseline: 653.8, periodBaseline: 727.9 };

// Bottom-right corner tab traced from the template (top-left origin). The template draws it in
// yellow; it is repainted per file type and stroked slightly so the yellow edge does not show.
const CORNER_TAB_PATH = 'M 663.886 1053.649 L 742 1053.649 L 742 1110 L 612.785 1110 L 612.785 1104.75 '
  + 'C 612.785 1076.528 635.664 1053.649 663.886 1053.649 Z';

const CORNER_COLORS = {
  revenue: rgb(34 / 255, 160 / 255, 90 / 255),
  expenses: rgb(242 / 255, 129 / 255, 29 / 255),
  logs: rgb(30 / 255, 111 / 255, 217 / 255),
};

const SIGN_LINES = {
  preparedName: { x0: 95.3, x1: 231.4, y: 969.0 },
  preparedDesignation: { x0: 122.6, x1: 231.4, y: 995.6 },
  preparedDate: { x0: 89.5, x1: 231.4, y: 1023.6 },
  reviewedName: { x0: 311.3, x1: 451.0, y: 970.4 },
  reviewedDesignation: { x0: 339.4, x1: 451.0, y: 997.7 },
};

let templateBytesPromise = null;

function loadTemplateBytes() {
  if (!templateBytesPromise) {
    templateBytesPromise = fetch(templateUrl).then((res) => {
      if (!res.ok) throw new Error('Could not load the cover page design.');
      return res.arrayBuffer();
    });
    templateBytesPromise.catch(() => {
      templateBytesPromise = null;
    });
  }
  return templateBytesPromise;
}

export function pad2(n) {
  return String(n).padStart(2, '0');
}

/** Standard PDF fonts only cover Latin characters; swap anything else for "?" instead of failing. */
function safeText(text, font) {
  let out = '';
  for (const ch of String(text ?? '')) {
    try {
      font.encodeText(ch);
      out += ch;
    } catch {
      out += '?';
    }
  }
  return out;
}

function fitSize(text, font, preferred, maxWidth) {
  const width = font.widthOfTextAtSize(text, preferred);
  return width <= maxWidth ? preferred : Math.max(1, (preferred * maxWidth) / width);
}

function draw(page, text, { x, baseline, size, font, color }) {
  const box = page.getCropBox();
  page.drawText(text, { x: box.x + x, y: box.y + box.height - baseline, size, font, color });
}

/** Split words into two lines with the most even widths. */
function splitTwoLines(words, font) {
  let best = [words.join(' '), ''];
  let bestWidth = Infinity;
  for (let i = 1; i < words.length; i += 1) {
    const a = words.slice(0, i).join(' ');
    const b = words.slice(i).join(' ');
    const w = Math.max(font.widthOfTextAtSize(a, 1), font.widthOfTextAtSize(b, 1));
    if (w < bestWidth) {
      bestWidth = w;
      best = [a, b];
    }
  }
  return best;
}

function drawTitle(page, name, bold) {
  draw(page, 'FILE', { x: TITLE.x, baseline: TITLE.goldBaseline, size: 74, font: bold, color: GOLD });

  const words = safeText(documentNameWithoutFile(name), bold).toUpperCase().split(/\s+/).filter(Boolean);
  if (words.length === 0) return;

  const navy = words.join(' ');
  const oneLineSize = fitSize(navy, bold, 52, TITLE.maxWidth);
  if (oneLineSize >= 34 || words.length === 1) {
    draw(page, navy, { x: TITLE.x, baseline: TITLE.navyBaseline, size: oneLineSize, font: bold, color: NAVY });
    return;
  }

  const [lineA, lineB] = splitTwoLines(words, bold);
  const size = Math.min(36, fitSize(lineA, bold, 52, TITLE.maxWidth), fitSize(lineB, bold, 52, TITLE.maxWidth));
  const firstBaseline = TITLE.twoLineCapTop + size * CAP_HEIGHT;
  draw(page, lineA, { x: TITLE.x, baseline: firstBaseline, size, font: bold, color: NAVY });
  draw(page, lineB, { x: TITLE.x, baseline: firstBaseline + size * 1.12, size, font: bold, color: NAVY });
}

function cornerColorFor(name) {
  const key = documentNameWithoutFile(name).toLowerCase();
  if (key.includes('revenue')) return CORNER_COLORS.revenue;
  if (key.includes('expense')) return CORNER_COLORS.expenses;
  if (key.includes('log') || key.includes('bank')) return CORNER_COLORS.logs;
  return null;
}

function drawCornerTab(page, name) {
  const color = cornerColorFor(name);
  if (!color) return;
  const box = page.getCropBox();
  page.drawSvgPath(CORNER_TAB_PATH, {
    x: box.x,
    y: box.y + box.height,
    color,
    borderColor: color,
    borderWidth: 1.2,
  });
}

function drawDetail(page, text, baseline, bold) {
  const value = safeText(text, bold);
  draw(page, value, {
    x: DETAILS.x,
    baseline,
    size: fitSize(value, bold, DETAILS.size, DETAILS.maxWidth),
    font: bold,
    color: NAVY,
  });
}

function drawOnLine(page, text, line, font) {
  const value = safeText(String(text || '').trim(), font);
  if (!value) return;
  const maxWidth = line.x1 - line.x0 - 6;
  draw(page, value, {
    x: line.x0 + 3,
    baseline: line.y - 2.5,
    size: fitSize(value, font, 9.5, maxWidth),
    font,
    color: NAVY,
  });
}

function formatPreparedDate(value) {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
  if (!m) return String(value || '');
  return `${m[3]} ${MONTHS[Number(m[2]) - 1]?.slice(0, 3) || ''} ${m[1]}`;
}

async function buildCoverDocument(cover) {
  const doc = await PDFDocument.load(await loadTemplateBytes());
  const page = doc.getPage(0);
  const bold = await doc.embedFont(StandardFonts.HelveticaBold);

  drawCornerTab(page, cover.document_name);
  drawTitle(page, cover.document_name, bold);
  drawDetail(page, `${pad2(cover.file_no)} / ${pad2(cover.file_total)}`, DETAILS.fileBaseline, bold);
  drawDetail(page, `${pad2(cover.doc_from)} \u2013 ${pad2(cover.doc_to)}`, DETAILS.docsBaseline, bold);
  drawDetail(page, `${(MONTHS[cover.period_month - 1] || '').toUpperCase()} ${cover.period_year}`, DETAILS.periodBaseline, bold);

  drawOnLine(page, cover.prepared_by_name, SIGN_LINES.preparedName, bold);
  drawOnLine(page, cover.prepared_by_designation, SIGN_LINES.preparedDesignation, bold);
  drawOnLine(page, formatPreparedDate(cover.prepared_date), SIGN_LINES.preparedDate, bold);
  drawOnLine(page, cover.reviewed_by_name, SIGN_LINES.reviewedName, bold);
  drawOnLine(page, cover.reviewed_by_designation, SIGN_LINES.reviewedDesignation, bold);

  doc.setTitle(`${coverTitle(cover.document_name)} - Cover Page`);
  doc.setCreator('Ultitech ERP');
  return doc;
}

function safeFileName(text) {
  return String(text || 'Cover Page').replace(/[\\/:*?"<>|]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120) || 'Cover Page';
}

function saveBlob(bytes, filename) {
  const blob = new Blob([bytes], { type: 'application/pdf' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 30000);
}

function coverFileName(cover) {
  return `${safeFileName(`${coverTitle(cover.document_name)} - ${pad2(cover.file_no)} of ${pad2(cover.file_total)} - Cover Page`)}.pdf`;
}

export async function downloadCoverPdf(cover) {
  const doc = await buildCoverDocument(cover);
  saveBlob(await doc.save(), coverFileName(cover));
}

/** Object URL of the cover PDF for on-screen viewing; the caller must revoke it. */
export async function coverPreviewUrl(cover) {
  const doc = await buildCoverDocument(cover);
  const blob = new Blob([await doc.save()], { type: 'application/pdf' });
  return URL.createObjectURL(blob);
}

/** Put the cover in front of the user's PDF and download one combined file. */
export async function downloadCoverWithDocument(cover, file) {
  let source;
  try {
    source = await PDFDocument.load(await file.arrayBuffer());
  } catch (err) {
    if (String(err?.message || '').toLowerCase().includes('encrypt')) {
      throw new Error('This PDF is password-protected. Remove the password and try again.');
    }
    throw new Error('This file could not be read as a PDF.');
  }

  const coverDoc = await buildCoverDocument(cover);
  const [coverPage] = await source.copyPages(coverDoc, [0]);
  source.insertPage(0, coverPage);

  const base = safeFileName(String(file.name || 'Document').replace(/\.pdf$/i, ''));
  saveBlob(await source.save(), `${base} - with cover.pdf`);
}
