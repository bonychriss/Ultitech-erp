import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';
import { formatDateDigits, formatVoucherRef } from './formatDisplay';

function safeFilePart(value) {
  const cleaned = String(value || '')
    .trim()
    .replace(/[<>:"/\\|?*\u0000-\u001f]+/g, '-')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^\.+|\.+$/g, '');
  return cleaned || 'export';
}

function stamp(prefix, ext) {
  const now = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  return `${safeFilePart(prefix)}-${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}-${pad(now.getHours())}${pad(now.getMinutes())}.${ext}`;
}

function cleanText(value) {
  return String(value || '')
    .replace(/\uFFFD/g, '')
    .replace(/[\u2013\u2014\u2212]/g, '-')
    .replace(/[\u00B7\u2022\u2027\u22C5]/g, '-')
    .replace(/[^\S\r\n]+/g, ' ')
    .replace(/\s{2,}/g, ' ')
    .trim();
}

function formatDate(dateStr) {
  return formatDateDigits(dateStr);
}

function formatMoney(value) {
  return Number(value || 0).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

/**
 * @param {Array<Record<string, unknown>>} entries
 * @param {{ title?: string, subtitle?: string }} [options]
 */
export async function exportRevenuePdf(entries, options = {}) {
  if (!entries.length) {
    throw new Error('No revenue entries found for the selected date range.');
  }

  const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
  const marginX = 36;
  let y = 40;

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(16);
  doc.setTextColor(15, 23, 42);
  doc.text(options.title || 'Revenue report', marginX, y);
  y += 18;

  if (options.subtitle) {
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.setTextColor(100, 116, 139);
    doc.text(cleanText(options.subtitle), marginX, y);
    y += 14;
  }

  y += 8;

  const body = entries.map((row, index) => [
    String(index + 1),
    cleanText(formatVoucherRef(row.voucher_number, '-')) || '-',
    formatDate(row.entry_date),
    cleanText(row.customer_display || row.customer_name || '-') || '-',
    cleanText(row.type_label || row.entry_type || '-') || '-',
    formatMoney(row.amount_exclusive),
    formatMoney(row.vat_amount),
    formatMoney(row.amount_total),
    cleanText(row.status_label || row.payment_status || '-') || '-',
    cleanText(row.payment_mode || '-') || '-',
  ]);

  autoTable(doc, {
    startY: y,
    head: [['S/N', 'Voucher', 'Date', 'Customer', 'Type', 'Net', 'VAT', 'Total', 'Status', 'Payment']],
    body,
    styles: {
      font: 'helvetica',
      fontSize: 7.5,
      cellPadding: 4,
      valign: 'middle',
      overflow: 'linebreak',
      textColor: [15, 23, 42],
    },
    headStyles: {
      fillColor: [30, 41, 59],
      textColor: 255,
      fontStyle: 'bold',
      fontSize: 7.5,
    },
    alternateRowStyles: {
      fillColor: [248, 250, 252],
    },
    columnStyles: {
      0: { cellWidth: 28 },
      1: { cellWidth: 78 },
      2: { cellWidth: 58 },
      3: { cellWidth: 110 },
      4: { cellWidth: 70 },
      5: { cellWidth: 62, halign: 'right' },
      6: { cellWidth: 52, halign: 'right' },
      7: { cellWidth: 62, halign: 'right' },
      8: { cellWidth: 58 },
      9: { cellWidth: 58 },
    },
    margin: { left: marginX, right: marginX },
  });

  doc.save(stamp('revenue', 'pdf'));
}
