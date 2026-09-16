import { useEffect, useMemo, useRef, useState } from 'react'
import { CFG } from '../config.js'
import { buildMonthlyDefaults, currentMonthRange, formatDateRangeLabel } from '../lib/reportPeriodDefaults.js'

function periodDescriptions(domainKey, domainLabel) {
  const short = (domainLabel || 'Report').replace(/\s+Report$/i, '') || 'Report'
  if (domainKey === 'sales') {
    return {
      monthly: 'Pick your own start and end dates for the monthly report.',
      quarterly: 'Department sales report for the current quarter (matches the PDF template).',
      annual: 'Full-year sales summary for the current calendar year.',
    }
  }
  return {
    monthly: `Pick your own start and end dates for the monthly ${short.toLowerCase()} report.`,
    quarterly: `${short} report for the current quarter.`,
    annual: `Full-year ${short.toLowerCase()} summary for the current calendar year.`,
  }
}

function buildDomainPeriodDefaults(option, domain, user = {}) {
  const base = option.defaults || {}
  const start = base.start_date || ''
  const end = base.end_date || ''
  const periodLabel = base.period_label || formatDateRangeLabel(start, end)
  const year = String(end || start).slice(0, 4)
  const label = domain.label || 'Report'

  if (domain.key === 'sales') {
    return {
      ...base,
      report_domain: 'sales',
      prepared_by: base.prepared_by ?? '',
      department: user.department || base.department || 'Sales',
    }
  }

  return {
    report_domain: domain.key,
    report_name: `${periodLabel} ${label}${year ? ` ${year}` : ''}`.trim(),
    report_type: option.key === 'monthly' ? 'monthly' : (option.key || 'management'),
    template_key: 'standard',
    start_date: start,
    end_date: end,
    period_label: periodLabel,
    prepared_by: user.name || '',
    department: user.department || domain.department_default || '',
    filters: {},
  }
}

export default function CreateReportTypeModal({ open, onClose, onSelect, initialDomainKey = '' }) {
  const [step, setStep] = useState('')
  const [selectedDomain, setSelectedDomain] = useState(null)
  const [monthlyOption, setMonthlyOption] = useState(null)
  const [startDate, setStartDate] = useState('')
  const [endDate, setEndDate] = useState('')
  const [rangeError, setRangeError] = useState('')
  const [skipDomainPicker, setSkipDomainPicker] = useState(false)

  const domains = useMemo(() => CFG.reportDomains || [], [])
  const salesOptions = CFG.reportPeriodOptions || []
  const user = CFG.user || {}
  const initForOpenRef = useRef(false)

  const periodOptions = useMemo(() => {
    if (!selectedDomain) return []
    const descriptions = periodDescriptions(selectedDomain.key, selectedDomain.label)
    return salesOptions.map((option) => ({
      ...option,
      description: descriptions[option.key] || option.description,
      date_range: option.date_range,
    }))
  }, [selectedDomain, salesOptions])

  function applyDomain(domain) {
    setSelectedDomain(domain)
    setStep('period-type')
  }

  useEffect(() => {
    if (!open) {
      initForOpenRef.current = false
      setStep('')
      setSelectedDomain(null)
      setMonthlyOption(null)
      setRangeError('')
      setSkipDomainPicker(false)
      const { start_date, end_date } = currentMonthRange()
      setStartDate(start_date)
      setEndDate(end_date)
      return
    }

    if (initForOpenRef.current) {
      return
    }
    initForOpenRef.current = true

    const params = new URLSearchParams(window.location.search)
    let domainKey = initialDomainKey || params.get('create') || params.get('report_domain') || ''
    if (domainKey === 'stock' || domainKey === 'store') {
      domainKey = 'store_warehouse'
    }
    const domain = domains.find((d) => d.key === domainKey)
    if (domain) {
      setSkipDomainPicker(true)
      applyDomain(domain)
    } else {
      setSkipDomainPicker(false)
      setStep('domain')
    }
  }, [open, initialDomainKey, domains])

  const previewDefaults = useMemo(() => {
    if (!monthlyOption || !selectedDomain) return null
    return buildMonthlyDefaults(
      startDate,
      endDate,
      monthlyOption.defaults || {},
      user,
      selectedDomain.label || 'Sales Report',
    )
  }, [monthlyOption, selectedDomain, startDate, endDate, user])

  if (!open || !step) return null

  function handleClose() {
    const url = new URL(window.location.href)
    if (url.searchParams.has('create')) {
      url.searchParams.delete('create')
      window.history.replaceState({}, '', url.pathname + url.search + url.hash)
    }
    onClose()
  }

  function handleDomainSelect(domain) {
    setSkipDomainPicker(false)
    applyDomain(domain)
  }

  function handleBackFromPeriodType() {
    if (skipDomainPicker) {
      handleClose()
    } else {
      setStep('domain')
      setSelectedDomain(null)
    }
  }

  function handlePeriodTypeSelect(option) {
    if (option.key === 'monthly') {
      const range = currentMonthRange()
      setStartDate(range.start_date)
      setEndDate(range.end_date)
      setMonthlyOption(option)
      setStep('monthly-range')
      setRangeError('')
      return
    }
    if (!selectedDomain) return
    const defaults = buildDomainPeriodDefaults(option, selectedDomain, user)
    onSelect({
      ...option,
      report_domain: selectedDomain.key,
      domain: selectedDomain.key,
      label: selectedDomain.label,
      defaults,
      date_range: option.date_range || formatDateRangeLabel(defaults.start_date, defaults.end_date),
    })
  }

  function handleMonthlyConfirm(e) {
    e.preventDefault()
    if (!selectedDomain || !monthlyOption) return
    const defaults = buildMonthlyDefaults(
      startDate,
      endDate,
      monthlyOption.defaults || {},
      user,
      selectedDomain.label || 'Sales Report',
    )
    if (!defaults) {
      setRangeError('Choose a valid date range.')
      return
    }
    const withDomain = {
      ...defaults,
      report_domain: selectedDomain.key,
      template_key: selectedDomain.key === 'sales' ? (defaults.template_key || 'monthly') : 'standard',
      report_type: selectedDomain.key === 'sales' ? (defaults.report_type || 'monthly') : 'monthly',
      prepared_by: selectedDomain.key === 'sales' ? (defaults.prepared_by ?? '') : (user.name || ''),
      department: user.department || selectedDomain.department_default || defaults.department || '',
      filters: {},
    }
    onSelect({
      ...monthlyOption,
      report_domain: selectedDomain.key,
      domain: selectedDomain.key,
      label: selectedDomain.label,
      defaults: withDomain,
      date_range: formatDateRangeLabel(withDomain.start_date, withDomain.end_date),
    })
  }

  const periodTitle = selectedDomain
    ? `${selectedDomain.label.replace(/\s+Report$/i, '')} Report Period`
    : 'Report Period'

  return (
    <div className="sr-modal-backdrop" role="presentation" onClick={handleClose}>
      <div
        className="sr-modal sr-create-type-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sr-create-type-title"
        onClick={(e) => e.stopPropagation()}
      >
        {step === 'domain' && (
          <>
            <header className="sr-modal-header">
              <div>
                <h2 id="sr-create-type-title">Create Report</h2>
                <p className="sr-muted">Choose a report type to continue.</p>
              </div>
              <button type="button" className="sr-modal-close" onClick={handleClose} aria-label="Close">
                <i className="bi bi-x-lg" aria-hidden="true" />
              </button>
            </header>
            <div className="sr-create-type-grid">
              {domains.map((domain) => (
                <button
                  key={domain.key}
                  type="button"
                  className="sr-create-type-card"
                  onClick={() => handleDomainSelect(domain)}
                >
                  <span className="sr-create-type-icon" style={{ color: domain.color }}>
                    <i className={`bi ${domain.icon || 'bi-file-earmark-text'}`} aria-hidden="true" />
                  </span>
                  <span className="sr-create-type-label">{domain.label}</span>
                  <span className="sr-create-type-desc">{domain.description}</span>
                </button>
              ))}
            </div>
          </>
        )}

        {step === 'period-type' && selectedDomain && (
          <>
            <header className="sr-modal-header">
              <div>
                {!skipDomainPicker && (
                  <button type="button" className="sr-create-type-back" onClick={handleBackFromPeriodType}>
                    <i className="bi bi-arrow-left" aria-hidden="true" /> Back
                  </button>
                )}
                <h2 id="sr-create-type-title">{periodTitle}</h2>
              </div>
              <button type="button" className="sr-modal-close" onClick={handleClose} aria-label="Close">
                <i className="bi bi-x-lg" aria-hidden="true" />
              </button>
            </header>
            <div className="sr-create-type-grid">
              {periodOptions.map((option) => (
                <button
                  key={option.key}
                  type="button"
                  className="sr-create-type-card"
                  onClick={() => handlePeriodTypeSelect(option)}
                >
                  <span className="sr-create-type-icon">
                    <i className={`bi ${option.icon || 'bi-file-earmark-text'}`} aria-hidden="true" />
                  </span>
                  <span className="sr-create-type-label">{option.label}</span>
                  <span className="sr-create-type-range">{option.date_range}</span>
                  <span className="sr-create-type-desc">{option.description}</span>
                </button>
              ))}
            </div>
          </>
        )}

        {step === 'monthly-range' && (
          <>
            <header className="sr-modal-header">
              <div>
                <button type="button" className="sr-create-type-back" onClick={() => setStep('period-type')}>
                  <i className="bi bi-arrow-left" aria-hidden="true" /> Back
                </button>
                <h2 id="sr-create-type-title">Select date range</h2>
              </div>
              <button type="button" className="sr-modal-close" onClick={handleClose} aria-label="Close">
                <i className="bi bi-x-lg" aria-hidden="true" />
              </button>
            </header>
            <form className="sr-create-range-form" onSubmit={handleMonthlyConfirm}>
              <div className="sr-create-range-fields">
                <label className="sr-field">
                  <span>Start date</span>
                  <input type="date" className="sr-input sr-date-input" value={startDate} onChange={(e) => { setStartDate(e.target.value); setRangeError('') }} required />
                </label>
                <label className="sr-field">
                  <span>End date</span>
                  <input type="date" className="sr-input sr-date-input" value={endDate} min={startDate || undefined} onChange={(e) => { setEndDate(e.target.value); setRangeError('') }} required />
                </label>
              </div>
              {previewDefaults?.report_name && (
                <p className="sr-create-range-preview sr-muted">Title: {previewDefaults.report_name}</p>
              )}
              {rangeError && <p className="sr-create-range-error">{rangeError}</p>}
              <div className="sr-modal-footer">
                <button type="button" className="sr-btn sr-btn-ghost" onClick={handleClose}>Cancel</button>
                <button type="submit" className="sr-btn sr-btn-primary">Create report</button>
              </div>
            </form>
          </>
        )}
      </div>
    </div>
  )
}
