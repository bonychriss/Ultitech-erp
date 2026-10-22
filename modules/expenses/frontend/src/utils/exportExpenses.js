import * as XLSX from 'xlsx';
import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';

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
  if (!dateStr) return '-';
  const normalized = String(dateStr).includes('T') ? String(dateStr) : String(dateStr).replace(' ', 'T');
  const d = new Date(normalized);
  if (Number.isNaN(d.getTime())) return String(dateStr);
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  return `${dd}/${mm}/${d.getFullYear()}`;
}

function expenseStatusLabel(row) {
  if (row.is_posted) return 'Posted';
  if (row.is_paid) return 'Paid';
  const display = String(row.display_status || '').toLowerCase();
  if (display) {
    return display.charAt(0).toUpperCase() + display.slice(1);
  }
  const s = String(row.status || '').toLowerCase();
  if (!s) return 'Draft';
  return s.charAt(0).toUpperCase() + s.slice(1);
}

function expensePayee(row) {
  const payee = String(row?.payee ?? '').trim();
  if (payee) return payee;
  return String(row?.description ?? row?.display_name ?? '').trim() || '(No payee)';
}

function toRows(expenses) {
  return expenses.map((row, index) => ({
    'S/N': index + 1,
    Reference: row.expense_number || `#${row.id}`,
    Date: formatDate(row.date),
    Payee: expensePayee(row),
    Account: row.category_name || row.main_account_name || '',
    Payment: row.payment_method_label || '',
    Currency: String(row.currency_code || 'TZS').replace(/^TSh$/i, 'TZS'),
    Amount: Number(row.amount || 0),
    Status: expenseStatusLabel(row),
  }));
}

export async function exportExpensesExcel(expenses) {
  if (!expenses.length) {
    throw new Error('No expenses found for the selected date range.');
  }
  const rows = toRows(expenses);
  const worksheet = XLSX.utils.json_to_sheet(rows);
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, worksheet, 'Expenses');
  XLSX.writeFile(workbook, stamp('expenses', 'xlsx'));
}

export async function exportExpensesPdf(expenses, options = {}) {
  if (!expenses.length) {
    throw new Error('No expenses found for the selected date range.');
  }

  const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
  const marginX = 36;
  let y = 40;

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(16);
  doc.setTextColor(15, 23, 42);
  doc.text(options.title || 'Expenses report', marginX, y);
  y += 18;

  if (options.subtitle) {
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.setTextColor(100, 116, 139);
    doc.text(cleanText(options.subtitle), marginX, y);
    y += 14;
  }

  y += 8;

  const body = expenses.map((row, index) => [
    String(index + 1),
    cleanText(row.expense_number || `#${row.id}`) || '-',
    formatDate(row.date),
    cleanText(expensePayee(row)),
    cleanText(row.category_name || row.main_account_name || '-') || '-',
    cleanText(row.payment_method_label || '-') || '-',
    `${cleanText(String(row.currency_code || 'TZS').replace(/^TSh$/i, 'TZS'))} ${Number(row.amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim(),
    expenseStatusLabel(row),
  ]);

  autoTable(doc, {
    startY: y,
    head: [['S/N', 'Reference', 'Date', 'Payee', 'Account', 'Payment', 'Amount', 'Status']],
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
      0: { cellWidth: 36 },
      1: { cellWidth: 90 },
      2: { cellWidth: 70 },
      3: { cellWidth: 110 },
      4: { cellWidth: 110 },
      5: { cellWidth: 80 },
      6: { cellWidth: 90, halign: 'right' },
      7: { cellWidth: 70 },
    },
    margin: { left: marginX, right: marginX },
  });

  doc.save(stamp('expenses', 'pdf'));
}
