/** Build report defaults for a monthly period from start/end dates. */
export function buildMonthlyDefaults(startDate, endDate, baseDefaults = {}, user = {}, reportLabel = 'Sales Report') {
  const start = String(startDate || '').slice(0, 10)
  const end = String(endDate || '').slice(0, 10)
  if (!start || !end) return null

  const startDt = new Date(`${start}T12:00:00`)
  const endDt = new Date(`${end}T12:00:00`)
  if (Number.isNaN(startDt.getTime()) || Number.isNaN(endDt.getTime()) || endDt < startDt) {
    return null
  }

  const sameMonth = startDt.getFullYear() === endDt.getFullYear() && startDt.getMonth() === endDt.getMonth()
  const startLabel = startDt.toLocaleString('en-US', { month: 'long' }).toUpperCase()
  const endLabel = endDt.toLocaleString('en-US', { month: 'long' }).toUpperCase()
  const periodLabel = sameMonth ? startLabel : `${startLabel}-${endLabel}`
  const year = endDt.getFullYear()
  const titleLabel = String(reportLabel || baseDefaults.report_label || 'Sales Report').trim() || 'Sales Report'

  return {
    ...baseDefaults,
    report_name: `${periodLabel} ${titleLabel} ${year}`,
    report_type: 'monthly',
    template_key: 'monthly',
    start_date: start,
    end_date: end,
    period_label: periodLabel,
    prepared_by: '',
    department: baseDefaults.department || 'Sales',
  }
}

export function formatDateRangeLabel(startDate, endDate) {
  const start = String(startDate || '').slice(0, 10)
  const end = String(endDate || '').slice(0, 10)
  if (!start || !end) return ''
  const startDt = new Date(`${start}T12:00:00`)
  const endDt = new Date(`${end}T12:00:00`)
  if (Number.isNaN(startDt.getTime()) || Number.isNaN(endDt.getTime())) return `${start} - ${end}`
  const fmt = (dt) => dt.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
  return `${fmt(startDt)} - ${fmt(endDt)}`
}

const QUARTER_PERIOD_LABELS = ['JANUARY-MARCH', 'APRIL-JUNE', 'JULY-SEPTEMBER', 'OCTOBER-DECEMBER']

export const QUARTER_CHOICES = [
  { quarter: 1, label: 'Q1', months: 'January - March' },
  { quarter: 2, label: 'Q2', months: 'April - June' },
  { quarter: 3, label: 'Q3', months: 'July - September' },
  { quarter: 4, label: 'Q4', months: 'October - December' },
]

export function quarterYearOptions(now = new Date()) {
  const year = now.getFullYear()
  return [0, 1, 2, 3, 4, 5].map((offset) => year - offset)
}

export function quarterDateRange(year, quarter) {
  const y = Number(year)
  const q = Number(quarter)
  if (!Number.isInteger(y) || y < 2000 || y > 2100 || q < 1 || q > 4) return null
  const startMonth = (q - 1) * 3
  const end = new Date(y, startMonth + 3, 0)
  const pad = (n) => String(n).padStart(2, '0')
  return {
    start_date: `${y}-${pad(startMonth + 1)}-01`,
    end_date: `${y}-${pad(end.getMonth() + 1)}-${pad(end.getDate())}`,
    period_label: QUARTER_PERIOD_LABELS[q - 1],
  }
}

export function buildQuarterDefaults(year, quarter, baseDefaults = {}, user = {}, reportLabel = 'Sales Report') {
  const range = quarterDateRange(year, quarter)
  if (!range) return null
  const titleLabel = String(reportLabel || baseDefaults.report_label || 'Sales Report').trim() || 'Sales Report'
  return {
    ...baseDefaults,
    report_name: `${range.period_label} ${titleLabel} ${year}`.replace(/\s+/g, ' ').trim(),
    report_type: 'quarterly',
    template_key: baseDefaults.template_key || 'department_quarterly',
    start_date: range.start_date,
    end_date: range.end_date,
    period_label: range.period_label,
    prepared_by: baseDefaults.prepared_by ?? '',
    department: baseDefaults.department || 'Sales',
  }
}

export function currentMonthRange() {
  const now = new Date()
  const y = now.getFullYear()
  const m = String(now.getMonth() + 1).padStart(2, '0')
  const lastDay = new Date(y, now.getMonth() + 1, 0).getDate()
  return {
    start_date: `${y}-${m}-01`,
    end_date: `${y}-${m}-${String(lastDay).padStart(2, '0')}`,
  }
}

export function navigateToNewReport(option, defaults, cfg = {}) {
  const editorBase = cfg.urls?.editor || 'editor.php'
  const url = new URL(editorBase, window.location.origin)
  url.searchParams.set('new', '1')
  url.searchParams.set('module', cfg.module || 'analytics')

  const domain = defaults?.report_domain || option.domain || option.report_domain || ''
  const salesPeriods = ['monthly', 'quarterly', 'annual']
  const periodKey = salesPeriods.includes(option.key) ? option.key : ''

  if (domain && domain !== 'sales') {
    url.searchParams.set('report_domain', domain)
  } else {
    url.searchParams.delete('report_domain')
  }

  if (periodKey) {
    url.searchParams.set('period', periodKey)
  }

  if (defaults?.start_date) url.searchParams.set('start_date', defaults.start_date)
  if (defaults?.end_date) url.searchParams.set('end_date', defaults.end_date)

  window.location.href = `${url.pathname}${url.search}`
}
