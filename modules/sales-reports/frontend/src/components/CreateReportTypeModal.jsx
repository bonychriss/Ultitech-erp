import { useEffect, useMemo, useRef, useState } from 'react'
import { CFG, apiUrl } from '../config.js'
import { buildMonthlyDefaults, currentMonthRange, formatDateRangeLabel } from '../lib/reportPeriodDefaults.js'

export default function CreateReportTypeModal({ open, onClose, onSelect, initialDomainKey = '' }) {
  const [step, setStep] = useState('')
  const [selectedDomain, setSelectedDomain] = useState(null)
  const [monthlyOption, setMonthlyOption] = useState(null)
  const [startDate, setStartDate] = useState('')
  const [endDate, setEndDate] = useState('')
  const [rangeError, setRangeError] = useState('')
  const [filterDefs, setFilterDefs] = useState([])
  const [filterOptions, setFilterOptions] = useState({})
  const [filters, setFilters] = useState({})
  const [filtersLoading, setFiltersLoading] = useState(false)
  const [skipDomainPicker, setSkipDomainPicker] = useState(false)

  const domains = useMemo(() => CFG.reportDomains || [], [])
  const salesOptions = CFG.reportPeriodOptions || []
  const user = CFG.user || {}
  const initForOpenRef = useRef(false)

  function applyDomain(domain) {
    setSelectedDomain(domain)
    if (domain.key === 'sales') {
      setStep('sales-type')
      return
    }
    const range = currentMonthRange()
    setStartDate(range.start_date)
    setEndDate(range.end_date)
    setStep('configure')
  }

  useEffect(() => {
    if (!open) {
      initForOpenRef.current = false
      setStep('')
      setSelectedDomain(null)
      setMonthlyOption(null)
      setRangeError('')
      setFilterDefs([])
      setFilterOptions({})
      setFilters({})
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
    const domainKey = initialDomainKey || params.get('create') || params.get('report_domain') || ''
    const domain = domains.find((d) => d.key === domainKey)
    if (domain) {
      setSkipDomainPicker(true)
      applyDomain(domain)
    } else {
      setSkipDomainPicker(false)
      setStep('domain')
    }
  }, [open, initialDomainKey, domains])

  useEffect(() => {
    if (!open || !selectedDomain || selectedDomain.key === 'sales') return
    setFiltersLoading(true)
    fetch(apiUrl('domain-meta.php', { domain: selectedDomain.key }), { credentials: 'same-origin' })
      .then((r) => r.json())
      .then((payload) => {
        if (payload?.success) {
          setFilterDefs(payload.filters?.filters || [])
          setFilterOptions(payload.filters?.options || {})
        }
      })
      .catch(() => {})
      .finally(() => setFiltersLoading(false))
  }, [open, selectedDomain])

  const previewDefaults = useMemo(() => {
    if (!monthlyOption) return null
    return buildMonthlyDefaults(startDate, endDate, monthlyOption.defaults || {}, user)
  }, [monthlyOption, startDate, endDate, user])

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

  function handleBackFromSalesType() {
    if (skipDomainPicker) {
      handleClose()
    } else {
      setStep('domain')
      setSelectedDomain(null)
    }
  }

  function handleBackFromConfigure() {
    if (skipDomainPicker) {
      handleClose()
    } else {
      setStep('domain')
      setSelectedDomain(null)
    }
  }

  function handleSalesTypeSelect(option) {
    if (option.key === 'monthly') {
      const range = currentMonthRange()
      setStartDate(range.start_date)
      setEndDate(range.end_date)
      setMonthlyOption(option)
      setStep('monthly-range')
      setRangeError('')
      return
    }
    onSelect({ ...option, report_domain: 'sales' })
  }

  function handleBusinessConfirm(e) {
    e.preventDefault()
    if (!selectedDomain) return
    if (!startDate || !endDate || startDate > endDate) {
      setRangeError('Choose a valid date range.')
      return
    }
    const defaults = {
      report_domain: selectedDomain.key,
      template_key: 'standard',
      report_type: 'management',
      start_date: startDate,
      end_date: endDate,
      report_name: `${formatDateRangeLabel(startDate, endDate)} ${selectedDomain.label}`,
      prepared_by: user.name || '',
      department: user.department || selectedDomain.department_default || '',
      filters,
    }
    onSelect({
      key: selectedDomain.key,
      domain: selectedDomain.key,
      label: selectedDomain.label,
      defaults,
      date_range: formatDateRangeLabel(startDate, endDate),
    })
  }

  function handleMonthlyConfirm(e) {
    e.preventDefault()
    const defaults = buildMonthlyDefaults(startDate, endDate, monthlyOption?.defaults || {}, user)
    if (!defaults) {
      setRangeError('Choose a valid date range.')
      return
    }
    onSelect({
      ...monthlyOption,
      report_domain: 'sales',
      defaults: { ...defaults, report_domain: 'sales' },
      date_range: formatDateRangeLabel(defaults.start_date, defaults.end_date),
    })
  }

  function renderFilterField(def) {
    const value = filters[def.key] ?? ''
    if (def.type === 'select') {
      let options = def.options || []
      if (def.options_source && filterOptions[def.options_source]) {
        const emptyLabel = def.empty_label || def.label
        options = [{ value: '', label: emptyLabel }, ...filterOptions[def.options_source]]
      }
      return (
        <label className="sr-field" key={def.key}>
          <span>{def.label}</span>
          <select
            className="sr-input"
            value={value}
            onChange={(e) => setFilters((prev) => ({ ...prev, [def.key]: e.target.value }))}
          >
            {options.map((opt) => (
              <option key={`${def.key}-${opt.value}`} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </select>
        </label>
      )
    }
    return null
  }

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

        {step === 'sales-type' && (
          <>
            <header className="sr-modal-header">
              <div>
                {!skipDomainPicker && (
                  <button type="button" className="sr-create-type-back" onClick={handleBackFromSalesType}>
                    <i className="bi bi-arrow-left" aria-hidden="true" /> Back
                  </button>
                )}
                <h2 id="sr-create-type-title">Sales Report Period</h2>
              </div>
              <button type="button" className="sr-modal-close" onClick={handleClose} aria-label="Close">
                <i className="bi bi-x-lg" aria-hidden="true" />
              </button>
            </header>
            <div className="sr-create-type-grid">
              {salesOptions.map((option) => (
                <button
                  key={option.key}
                  type="button"
                  className="sr-create-type-card"
                  onClick={() => handleSalesTypeSelect(option)}
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

        {step === 'configure' && selectedDomain && (
          <>
            <header className="sr-modal-header">
              <div>
                {!skipDomainPicker && (
                  <button type="button" className="sr-create-type-back" onClick={handleBackFromConfigure}>
                    <i className="bi bi-arrow-left" aria-hidden="true" /> Back
                  </button>
                )}
                <h2 id="sr-create-type-title">{selectedDomain.label}</h2>
                <p className="sr-muted">
                  {filterDefs.length > 0
                    ? 'Set the reporting period and optional filters, then generate.'
                    : 'Set the reporting period, then generate.'}
                </p>
              </div>
              <button type="button" className="sr-modal-close" onClick={handleClose} aria-label="Close">
                <i className="bi bi-x-lg" aria-hidden="true" />
              </button>
            </header>
            <form className="sr-create-range-form" onSubmit={handleBusinessConfirm}>
              <div className="sr-create-range-fields">
                <label className="sr-field">
                  <span>From</span>
                  <input type="date" className="sr-input sr-date-input" value={startDate} onChange={(e) => { setStartDate(e.target.value); setRangeError('') }} required />
                </label>
                <label className="sr-field">
                  <span>To</span>
                  <input type="date" className="sr-input sr-date-input" value={endDate} min={startDate || undefined} onChange={(e) => { setEndDate(e.target.value); setRangeError('') }} required />
                </label>
              </div>
              {filterDefs.length > 0 && (
                <div className="sr-create-filter-grid">
                  {filtersLoading ? <p className="sr-muted">Loading filters…</p> : filterDefs.map(renderFilterField)}
                </div>
              )}
              {rangeError && <p className="sr-create-range-error">{rangeError}</p>}
              <div className="sr-modal-footer">
                <button type="button" className="sr-btn sr-btn-ghost" onClick={handleClose}>Cancel</button>
                <button type="submit" className="sr-btn sr-btn-primary">Generate Report</button>
              </div>
            </form>
          </>
        )}

        {step === 'monthly-range' && (
          <>
            <header className="sr-modal-header">
              <div>
                <button type="button" className="sr-create-type-back" onClick={() => setStep('sales-type')}>
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
