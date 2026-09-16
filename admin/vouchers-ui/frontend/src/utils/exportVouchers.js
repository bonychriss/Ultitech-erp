import * as XLSX from 'xlsx'
import { jsPDF } from 'jspdf'
import autoTable from 'jspdf-autotable'

function safeFilePart(value) {
  const cleaned = String(value || '')
    .trim()
    .replace(/[<>:"/\\|?*\u0000-\u001f]+/g, '-')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^\.+|\.+$/g, '')
  return cleaned || 'export'
}

function stamp(prefix, ext) {
  const now = new Date()
  const pad = (n) => String(n).padStart(2, '0')
  return `${safeFilePart(prefix)}-${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}-${pad(now.getHours())}${pad(now.getMinutes())}.${ext}`
}

function cleanText(value) {
  return String(value || '')
    .replace(/\uFFFD/g, '')
    .replace(/[\u2013\u2014\u2212]/g, '-')
    .replace(/[\u00B7\u2022\u2027\u22C5]/g, '-')
    .replace(/[^\S\r\n]+/g, ' ')
    .replace(/\s{2,}/g, ' ')
    .trim()
}

function formatDate(dateStr) {
  if (!dateStr) return '-'
  const normalized = String(dateStr).includes('T') ? String(dateStr) : String(dateStr).replace(' ', 'T')
  const d = new Date(normalized)
  if (Number.isNaN(d.getTime())) return String(dateStr)
  const dd = String(d.getDate()).padStart(2, '0')
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  return `${dd}/${mm}/${d.getFullYear()}`
}

function voucherStatusLabel(row) {
  if (row.is_posted) return 'Posted'
  if (row.is_paid) return 'Paid'
  if (row.display_status) return String(row.display_status)
  const s = String(row.display_status_key || row.derived_status || row.status || '').toLowerCase()
  if (!s) return 'Pending'
  return s.charAt(0).toUpperCase() + s.slice(1)
}

function dateOnly(dateStr) {
  if (!dateStr) return ''
  return String(dateStr).slice(0, 10)
}

export function filterVouchersByDateRange(vouchers, range) {
  if (!range || range.allTime) return vouchers
  const from = range.startDate || ''
  const to = range.endDate || ''
  return vouchers.filter((v) => {
    const d = dateOnly(v.date_created)
    if (from && d < from) return false
    if (to && d > to) return false
    return true
  })
}

function toRows(vouchers) {
  return vouchers.map((v) => ({
    'S/N': v.sn ?? '',
    'Voucher No.': v.voucher_no || '',
    Payee: v.payee_name || '',
    'Prepared By': v.prepared_by || '',
    Department: v.department || '',
    Description: v.description || '',
    Currency: v.currency || '',
    Amount: Number(v.total_amount || 0),
    'Date Created': formatDate(v.date_created),
    Status: voucherStatusLabel(v),
  }))
}

export async function exportVouchersExcel(vouchers) {
  if (!vouchers.length) {
    throw new Error('No vouchers found for the selected date range.')
  }
  const rows = toRows(vouchers)
  const worksheet = XLSX.utils.json_to_sheet(rows)
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, worksheet, 'Vouchers')
  XLSX.writeFile(workbook, stamp('vouchers', 'xlsx'))
}

export async function exportVouchersPdf(vouchers, options = {}) {
  if (!vouchers.length) {
    throw new Error('No vouchers found for the selected date range.')
  }

  const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' })
  const marginX = 36
  let y = 40

  doc.setFont('helvetica', 'bold')
  doc.setFontSize(16)
  doc.setTextColor(15, 23, 42)
  doc.text(options.title || 'Vouchers report', marginX, y)
  y += 18

  if (options.subtitle) {
    doc.setFont('helvetica', 'normal')
    doc.setFontSize(10)
    doc.setTextColor(100, 116, 139)
    doc.text(cleanText(options.subtitle), marginX, y)
    y += 14
  }

  y += 8

  const body = vouchers.map((v) => [
    String(v.sn ?? ''),
    cleanText(v.voucher_no || '-') || '-',
    cleanText(v.payee_name || '(No payee)') || '(No payee)',
    cleanText(v.prepared_by || '-') || '-',
    cleanText(v.description || '-') || '-',
    `${cleanText(v.currency || '')} ${Number(v.total_amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim(),
    formatDate(v.date_created),
    voucherStatusLabel(v),
  ])

  autoTable(doc, {
    startY: y,
    head: [['S/N', 'Voucher No.', 'Payee', 'Prepared By', 'Description', 'Amount', 'Date', 'Status']],
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
      2: { cellWidth: 110 },
      3: { cellWidth: 90 },
      4: { cellWidth: 150 },
      5: { cellWidth: 80, halign: 'right' },
      6: { cellWidth: 70 },
      7: { cellWidth: 70 },
    },
    margin: { left: marginX, right: marginX },
  })

  doc.save(stamp('vouchers', 'pdf'))
}
