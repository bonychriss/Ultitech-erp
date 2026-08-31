import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';
import type { StockMovement } from '../types';
import { movementStatus } from '../components/MovementDetail';

const PRODUCT_COL = 1;
const THUMB_SIZE = 28;

type LoadedImage = { dataUrl: string; width: number; height: number };

function cleanText(value: string): string {
  return value
    .replace(/\uFFFD/g, '')
    .replace(/[\u2013\u2014\u2212]/g, '-')
    .replace(/[\u00B7\u2022\u2027\u22C5]/g, '-')
    .replace(/[^\S\r\n]+/g, ' ')
    .replace(/\s{2,}/g, ' ')
    .trim();
}

function formatWhen(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso || '-';
  return d.toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function stampFilename(prefix: string): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${prefix}-${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}-${pad(now.getHours())}${pad(now.getMinutes())}.pdf`;
}

async function loadImagePng(url: string): Promise<LoadedImage | null> {
  const trimmed = url.trim();
  if (!trimmed) return null;

  const abs = new URL(trimmed, window.location.href).href;

  const fromImage = async (): Promise<LoadedImage | null> => {
    try {
      const img = await new Promise<HTMLImageElement>((resolve, reject) => {
        const el = new Image();
        el.onload = () => resolve(el);
        el.onerror = () => reject(new Error('Failed to decode image'));
        el.src = abs;
      });

      const width = img.naturalWidth || img.width;
      const height = img.naturalHeight || img.height;
      if (width <= 0 || height <= 0) return null;

      const canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext('2d');
      if (!ctx) return null;
      ctx.drawImage(img, 0, 0);
      return { dataUrl: canvas.toDataURL('image/png'), width, height };
    } catch {
      return null;
    }
  };

  const fromFetch = async (): Promise<LoadedImage | null> => {
    try {
      const res = await fetch(abs, { credentials: 'same-origin' });
      if (!res.ok) return null;
      const blob = await res.blob();
      const objectUrl = URL.createObjectURL(blob);

      try {
        const img = await new Promise<HTMLImageElement>((resolve, reject) => {
          const el = new Image();
          el.onload = () => resolve(el);
          el.onerror = () => reject(new Error('Failed to decode image'));
          el.src = objectUrl;
        });

        const width = img.naturalWidth || img.width;
        const height = img.naturalHeight || img.height;
        if (width <= 0 || height <= 0) return null;

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        if (!ctx) return null;
        ctx.drawImage(img, 0, 0);
        return { dataUrl: canvas.toDataURL('image/png'), width, height };
      } finally {
        URL.revokeObjectURL(objectUrl);
      }
    } catch {
      return null;
    }
  };

  return (await fromImage()) ?? (await fromFetch());
}

async function loadRowImages(rows: StockMovement[]): Promise<(LoadedImage | null)[]> {
  const cache = new Map<string, LoadedImage | null>();
  const uniqueUrls = [...new Set(rows.map((m) => m.imageUrl?.trim() || '').filter(Boolean))];

  await Promise.all(
    uniqueUrls.map(async (url) => {
      cache.set(url, await loadImagePng(url));
    })
  );

  return rows.map((m) => {
    const url = m.imageUrl?.trim() || '';
    return url ? cache.get(url) ?? null : null;
  });
}

export async function exportMovementsPdf(options: {
  movements: StockMovement[];
  warehouseName?: string;
  companyName?: string;
  companyLogoUrl?: string;
  filterLabel?: string;
  searchTerm?: string;
}): Promise<void> {
  const rows = options.movements.filter((m) => m.movementType === 'in' || m.movementType === 'out');
  if (rows.length === 0) {
    throw new Error('No results to export.');
  }

  const [logo, rowImages] = await Promise.all([
    options.companyLogoUrl ? loadImagePng(options.companyLogoUrl) : Promise.resolve(null),
    loadRowImages(rows),
  ]);

  const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
  const marginX = 36;
  const pageWidth = doc.internal.pageSize.getWidth();
  let y = 40;
  let headerBottom = y;

  if (logo) {
    const maxH = 52;
    const maxW = 140;
    const scale = Math.min(maxW / logo.width, maxH / logo.height, 1);
    const w = logo.width * scale;
    const h = logo.height * scale;
    const logoX = pageWidth - marginX - w;
    const logoY = 24;
    doc.addImage(logo.dataUrl, 'PNG', logoX, logoY, w, h);
    headerBottom = Math.max(headerBottom, logoY + h);
  }

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(16);
  doc.setTextColor(15, 23, 42);
  doc.text('Store report', marginX, y);
  y += 18;

  headerBottom = Math.max(headerBottom, y);
  y = headerBottom + 12;

  const body = rows.map((m) => {
    const isIn = m.movementType === 'in';
    const product = cleanText(m.productName || '-') || '-';
    const sku = cleanText(m.productSku || '');
    const category = cleanText(m.categoryName || '');
    const productLine = [product, [sku, category].filter(Boolean).join(' - ')].filter(Boolean).join('\n');
    const notes = cleanText(m.notes || '');
    return [
      formatWhen(m.createdAt),
      productLine,
      isIn ? 'In' : 'Out',
      movementStatus(m),
      `${isIn ? '+' : '-'}${m.quantity}`,
      notes || '-',
    ];
  });

  autoTable(doc, {
    startY: y,
    head: [['When', 'Product', 'Type', 'Status', 'Qty', 'Notes']],
    body,
    styles: {
      font: 'helvetica',
      fontSize: 8,
      cellPadding: 5,
      valign: 'middle',
      overflow: 'linebreak',
      textColor: [15, 23, 42],
    },
    headStyles: {
      fillColor: [30, 41, 59],
      textColor: 255,
      fontStyle: 'bold',
      fontSize: 8,
    },
    alternateRowStyles: {
      fillColor: [248, 250, 252],
    },
    columnStyles: {
      0: { cellWidth: 90 },
      1: { cellWidth: 190, valign: 'middle' },
      2: { cellWidth: 40 },
      3: { cellWidth: 80 },
      4: { cellWidth: 45, halign: 'right' },
      5: { cellWidth: 'auto' },
    },
    margin: { left: marginX, right: marginX },
    didParseCell: (data) => {
      if (data.section !== 'body') return;

      if (data.column.index === PRODUCT_COL) {
        const img = rowImages[data.row.index];
        if (img) {
          data.cell.styles.cellPadding = {
            top: 6,
            right: 5,
            bottom: 6,
            left: THUMB_SIZE + 10,
          };
          data.cell.styles.minCellHeight = THUMB_SIZE + 10;
        }
      }

      if (data.column.index === 2) {
        const value = String(data.cell.raw || '');
        data.cell.styles.textColor = value === 'In' ? [5, 150, 105] : [225, 29, 72];
        data.cell.styles.fontStyle = 'bold';
      }
      if (data.column.index === 4) {
        const value = String(data.cell.raw || '');
        data.cell.styles.textColor = value.startsWith('+') ? [5, 150, 105] : [225, 29, 72];
        data.cell.styles.fontStyle = 'bold';
      }
    },
    didDrawCell: (data) => {
      if (data.section !== 'body' || data.column.index !== PRODUCT_COL) return;
      const img = rowImages[data.row.index];
      if (!img) return;

      const pdf = data.doc;
      const page = (data as { pageNumber?: number }).pageNumber;
      if (page) pdf.setPage(page);

      const x = data.cell.x + 5;
      const yPos = data.cell.y + (data.cell.height - THUMB_SIZE) / 2;
      pdf.addImage(img.dataUrl, 'PNG', x, yPos, THUMB_SIZE, THUMB_SIZE);
    },
  });

  const pageCount = doc.getNumberOfPages();
  for (let i = 1; i <= pageCount; i += 1) {
    doc.setPage(i);
    doc.setFontSize(8);
    doc.setTextColor(120);
    doc.text(
      `Page ${i} of ${pageCount}`,
      doc.internal.pageSize.getWidth() - marginX,
      doc.internal.pageSize.getHeight() - 18,
      { align: 'right' }
    );
  }

  doc.save(stampFilename('store-movements-report'));
}
