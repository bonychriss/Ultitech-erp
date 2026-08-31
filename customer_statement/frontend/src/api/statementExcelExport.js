const COL_COUNT = 8;

const COL_WIDTHS = [20, 14, 12, 16, 12, 16, 14, 16];

const MONEY_FORMAT = '#,##0.00';

const BORDER_THIN = {
  top: { style: 'thin', color: { argb: 'FF000000' } },
  left: { style: 'thin', color: { argb: 'FF000000' } },
  bottom: { style: 'thin', color: { argb: 'FF000000' } },
  right: { style: 'thin', color: { argb: 'FF000000' } },
};

function cellOrDash(value) {
  const text = value == null ? '' : String(value).trim();
  return text || '-';
}

function dueColumn(row) {
  if (row?.is_opening) return '-';
  const rel = String(row?.due_relative ?? '').trim();
  return rel || '-';
}

function buildCustomerLabel(selectedCustomers = []) {
  const names = selectedCustomers
    .map((c) => String(c?.company_name ?? '').trim())
    .filter(Boolean);

  if (names.length === 0) return '';
  if (names.length === 1) {
    const code = String(selectedCustomers[0]?.customer_code ?? '').trim();
    return code ? `${names[0]} (${code})` : names[0];
  }
  return names.join('; ');
}

function safeFilenamePart(value, fallback = 'Customer') {
  const cleaned = String(value ?? '').replace(/[^A-Za-z0-9_.-]+/g, '_').replace(/^_+|_+$/g, '');
  return cleaned || fallback;
}

export function buildStatementExcelFilename(selectedCustomers = []) {
  const safeName = selectedCustomers.length === 1
    ? safeFilenamePart(selectedCustomers[0]?.customer_code, 'Customer')
    : 'Multiple_Customers';
  const stamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
  return `Customer_Statement_${safeName}_${stamp}.xlsx`;
}

function mergeRow(sheet, rowNumber, fromCol, toCol) {
  sheet.mergeCells(rowNumber, fromCol, rowNumber, toCol);
}

function styleRowCells(row, style) {
  row.eachCell({ includeEmpty: true }, (cell, colNumber) => {
    if (colNumber > COL_COUNT) return;
    if (style.font) cell.font = style.font;
    if (style.fill) cell.fill = style.fill;
    if (style.alignment) cell.alignment = style.alignment;
  });
}

function applyTableBorders(sheet, startRow, endRow) {
  for (let r = startRow; r <= endRow; r += 1) {
    const row = sheet.getRow(r);
    for (let c = 1; c <= COL_COUNT; c += 1) {
      const cell = row.getCell(c);
      cell.border = BORDER_THIN;
    }
  }
}

function rowFontColor(row) {
  if (row?.is_opening) return 'FF374151';
  if (row?.is_paid) return 'FF2563EB';
  return 'FFDC2626';
}

function triggerBlobDownload(blob, filename) {
  const objectUrl = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = objectUrl;
  anchor.download = filename;
  anchor.style.display = 'none';
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(objectUrl);
}

/**
 * Build and download a styled customer statement workbook.
 *
 * @param {{ companyName?: string, statement?: object }} payload
 * @returns {Promise<string>} downloaded filename
 */
export async function exportStatementExcel({ companyName = 'Company', statement = {} }) {
  const ExcelJS = (await import('exceljs')).default;
  const selectedCustomers = statement.selected_customers || [];
  const monthly = statement.monthly || [];
  const customerLabel = buildCustomerLabel(selectedCustomers);
  const filename = buildStatementExcelFilename(selectedCustomers);

  const workbook = new ExcelJS.Workbook();
  workbook.creator = 'ERP Customer Statement';
  workbook.created = new Date();

  const sheet = workbook.addWorksheet('Customer Statement', {
    views: [{ state: 'frozen', ySplit: 0 }],
  });

  COL_WIDTHS.forEach((width, index) => {
    sheet.getColumn(index + 1).width = width;
  });

  let rowNum = 1;

  mergeRow(sheet, rowNum, 1, COL_COUNT);
  const titleRow = sheet.getRow(rowNum);
  titleRow.height = 28;
  titleRow.getCell(1).value = 'CUSTOMER STATEMENT';
  titleRow.getCell(1).font = { name: 'Arial', size: 16, bold: true, color: { argb: 'FF111827' } };
  titleRow.getCell(1).alignment = { horizontal: 'center', vertical: 'middle' };
  rowNum += 1;

  if (customerLabel) {
    mergeRow(sheet, rowNum, 1, COL_COUNT);
    const customerRow = sheet.getRow(rowNum);
    customerRow.getCell(1).value = `Customer: ${customerLabel}`;
    customerRow.getCell(1).font = { name: 'Arial', size: 12, bold: true, color: { argb: 'FF111827' } };
    customerRow.getCell(1).alignment = { horizontal: 'center', vertical: 'middle' };
    rowNum += 1;
  }

  mergeRow(sheet, rowNum, 1, COL_COUNT);
  const periodRow = sheet.getRow(rowNum);
  periodRow.getCell(1).value = `${companyName} - Period: ${statement.date_from || ''} to ${statement.date_to || ''}`;
  periodRow.getCell(1).font = { name: 'Arial', size: 11, color: { argb: 'FF4B5563' } };
  periodRow.getCell(1).alignment = { horizontal: 'center', vertical: 'middle' };
  rowNum += 1;

  mergeRow(sheet, rowNum, 1, COL_COUNT);
  const totalsRow = sheet.getRow(rowNum);
  totalsRow.getCell(1).value = {
    richText: [
      { font: { name: 'Arial', size: 11, bold: true, color: { argb: 'FF374151' } }, text: 'Totals - Invoiced: ' },
      { font: { name: 'Arial', size: 11, color: { argb: 'FF374151' } }, text: formatMoney(statement.grand_total) },
      { font: { name: 'Arial', size: 11, bold: true, color: { argb: 'FF374151' } }, text: '   Paid: ' },
      { font: { name: 'Arial', size: 11, color: { argb: 'FF374151' } }, text: formatMoney(statement.sum_paid) },
      { font: { name: 'Arial', size: 11, bold: true, color: { argb: 'FF374151' } }, text: '   Balance: ' },
      { font: { name: 'Arial', size: 11, color: { argb: 'FF374151' } }, text: formatMoney(statement.sum_balance) },
    ],
  };
  totalsRow.getCell(1).alignment = { horizontal: 'center', vertical: 'middle' };
  rowNum += 2;

  monthly.forEach((month) => {
    mergeRow(sheet, rowNum, 1, COL_COUNT);
    const monthRow = sheet.getRow(rowNum);
    monthRow.height = 22;
    monthRow.getCell(1).value = String(month.label || '').toUpperCase();
    monthRow.getCell(1).font = { name: 'Arial', size: 13, bold: true, color: { argb: 'FF111827' } };
    monthRow.getCell(1).alignment = { horizontal: 'center', vertical: 'middle' };
    rowNum += 1;

    const headerRow = sheet.getRow(rowNum);
    headerRow.values = [
      'Invoice #',
      'Invoice date',
      'Due (days)',
      'Order status',
      'Status',
      'Total',
      'Paid',
      'Balance',
    ];
    styleRowCells(headerRow, {
      font: { name: 'Arial', size: 10, bold: true, color: { argb: 'FF111827' } },
      fill: { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFF3D58A' } },
      alignment: { vertical: 'middle' },
    });
    headerRow.getCell(3).alignment = { horizontal: 'center', vertical: 'middle' };
    [6, 7, 8].forEach((col) => {
      headerRow.getCell(col).alignment = { horizontal: 'right', vertical: 'middle' };
    });
    headerRow.height = 22;
    const tableStart = rowNum;
    rowNum += 1;

    const rows = month.rows || [];
    if (rows.length === 0) {
      mergeRow(sheet, rowNum, 1, COL_COUNT);
      const emptyRow = sheet.getRow(rowNum);
      emptyRow.getCell(1).value = 'No invoices found in this period.';
      emptyRow.getCell(1).alignment = { horizontal: 'center', vertical: 'middle' };
      emptyRow.getCell(1).font = { name: 'Arial', size: 11, italic: true, color: { argb: 'FF6B7280' } };
      applyTableBorders(sheet, tableStart, rowNum);
      rowNum += 2;
      return;
    }

    rows.forEach((entry) => {
      const dataRow = sheet.getRow(rowNum);
      dataRow.values = [
        cellOrDash(entry.invoice_number),
        cellOrDash(entry.invoice_date_fmt),
        dueColumn(entry),
        entry.order_status || '',
        entry.payment_status_label || '',
        Number(entry.total_amount) || 0,
        Number(entry.amount_paid) || 0,
        Number(entry.line_balance) || 0,
      ];
      const color = rowFontColor(entry);
      dataRow.font = { name: 'Arial', size: 11, color: { argb: color } };
      dataRow.getCell(3).alignment = { horizontal: 'center', vertical: 'middle' };
      [6, 7, 8].forEach((col) => {
        const cell = dataRow.getCell(col);
        cell.numFmt = MONEY_FORMAT;
        cell.alignment = { horizontal: 'right', vertical: 'middle' };
        cell.font = {
          name: 'Arial',
          size: 11,
          bold: col === 8,
          color: { argb: color },
        };
      });
      rowNum += 1;
    });

    const totalRow = sheet.getRow(rowNum);
    totalRow.getCell(1).value = 'Month total';
    sheet.mergeCells(rowNum, 1, rowNum, 5);
    totalRow.getCell(6).value = Number(month.total) || 0;
    totalRow.getCell(7).value = Number(month.total_paid) || 0;
    totalRow.getCell(8).value = Number(month.total_balance) || 0;
    styleRowCells(totalRow, {
      font: { name: 'Arial', size: 11, bold: true, color: { argb: 'FF111827' } },
      fill: { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFE5E7EB' } },
    });
    [6, 7, 8].forEach((col) => {
      const cell = totalRow.getCell(col);
      cell.numFmt = MONEY_FORMAT;
      cell.alignment = { horizontal: 'right', vertical: 'middle' };
    });
    applyTableBorders(sheet, tableStart, rowNum);
    rowNum += 2;
  });

  const buffer = await workbook.xlsx.writeBuffer();
  const blob = new Blob(
    [buffer],
    { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' },
  );
  triggerBlobDownload(blob, filename);
  return filename;
}

function formatMoney(value) {
  return new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Number(value) || 0);
}
