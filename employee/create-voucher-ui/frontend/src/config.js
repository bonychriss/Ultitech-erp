const RAW = (typeof window !== 'undefined' && window.__CV_CFG__) || {}

export const CFG = {
  mode: RAW.mode === 'edit' ? 'edit' : 'create',
  postUrl: RAW.postUrl || 'create-voucher.php',
  cancelUrl: RAW.cancelUrl || 'my-vouchers.php',
  viewUrl: RAW.viewUrl || 'view-voucher.php',
  deleteAttachmentUrl: RAW.deleteAttachmentUrl || '../delete_attachment.php',
  proxyPdfUrl: RAW.proxyPdfUrl || '../proxy_pdf.php',
  module: RAW.module || 'voucher',
  preparedBy: RAW.preparedBy || '',
  today: RAW.today || new Date().toISOString().slice(0, 10),
  canRestrict: !!RAW.canRestrict,
  limitedEdit: !!RAW.limitedEdit,
  voucherId: RAW.voucherId || 0,
  voucherNo: RAW.voucherNo || '',
  statusLabel: RAW.statusLabel || '',
  currencies: RAW.currencies || ['TZS', 'USD', 'CNY'],
  purposes: RAW.purposes || [
    { value: 'general', label: 'General Payment' },
    { value: 'stock_purchase', label: 'Stock Purchase' },
  ],
  paymentTypes: RAW.paymentTypes || ['Bank Transfer', 'Cash Payment', 'Cheque', 'Mobile Payment'],
  budgetTypes: RAW.budgetTypes || [
    'Operational Expenses',
    'Procurement & Supplies',
    'Employee Costs',
    'Sales & Marketing',
    'Logistics & Delivery',
    'Administration & Management',
    'Projects & Capital Expenditure (CAPEX)',
    'Financial Obligations',
    'Tax & Compliance',
    'Others / Miscellaneous',
  ],
  payees: RAW.payees || [],
  users: RAW.users || [],
  financeUsers: RAW.financeUsers || [],
  salesOrders: RAW.salesOrders || [],
  initial: RAW.initial || null,
  attachments: Array.isArray(RAW.attachments) ? RAW.attachments : [],
  flash: RAW.flash || null,
  error: RAW.error || '',
}

export const IS_EDIT = CFG.mode === 'edit'
export const IS_LIMITED = IS_EDIT && CFG.limitedEdit

export const CURRENCIES = [
  { code: 'TZS', name: 'Tanzanian Shilling', flag: 'tz' },
  { code: 'USD', name: 'US Dollar', flag: 'us' },
  { code: 'EUR', name: 'Euro', flag: 'eu' },
  { code: 'GBP', name: 'British Pound', flag: 'gb' },
  { code: 'CNY', name: 'Chinese Yuan', flag: 'cn' },
  { code: 'KES', name: 'Kenyan Shilling', flag: 'ke' },
  { code: 'UGX', name: 'Ugandan Shilling', flag: 'ug' },
  { code: 'RWF', name: 'Rwandan Franc', flag: 'rw' },
  { code: 'BIF', name: 'Burundian Franc', flag: 'bi' },
  { code: 'ZAR', name: 'South African Rand', flag: 'za' },
  { code: 'AED', name: 'UAE Dirham', flag: 'ae' },
  { code: 'SAR', name: 'Saudi Riyal', flag: 'sa' },
  { code: 'INR', name: 'Indian Rupee', flag: 'in' },
  { code: 'JPY', name: 'Japanese Yen', flag: 'jp' },
  { code: 'CAD', name: 'Canadian Dollar', flag: 'ca' },
  { code: 'AUD', name: 'Australian Dollar', flag: 'au' },
]

const CURRENCY_SYMBOLS = { USD: '$' }
export function currencySymbol(code) {
  return CURRENCY_SYMBOLS[code] || code || ''
}

export function currencyMeta(code) {
  return CURRENCIES.find((c) => c.code === code) || { code, name: code, flag: 'tz' }
}

export function formatMoney(code, amount) {
  const n = Number(amount) || 0
  return `${currencySymbol(code)} ${n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`
}
