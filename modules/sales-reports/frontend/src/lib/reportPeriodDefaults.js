/** Build report defaults for a monthly period from start/end dates. */
export function buildMonthlyDefaults(startDate, endDate, baseDefaults = {}, user = {}) {
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

  return {
    ...baseDefaults,
    report_name: `${periodLabel} Sales Report ${year}`,
    report_type: 'monthly',
    template_key: 'monthly',
    start_date: start,
    end_date: end,
    period_label: periodLabel,
    prepared_by: '',
    department: user.department || baseDefaults.department || 'Sales',
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
  const isSalesPeriod = salesPeriods.includes(option.key)

  if (domain && domain !== 'sales' && !isSalesPeriod) {
    url.searchParams.set('report_domain', domain)
    if (defaults?.start_date) url.searchParams.set('start_date', defaults.start_date)
    if (defaults?.end_date) url.searchParams.set('end_date', defaults.end_date)
  } else {
    url.searchParams.set('period', option.key)
    if (defaults?.start_date) url.searchParams.set('start_date', defaults.start_date)
    if (defaults?.end_date) url.searchParams.set('end_date', defaults.end_date)
  }

  window.location.href = `${url.pathname}${url.search}`
}
