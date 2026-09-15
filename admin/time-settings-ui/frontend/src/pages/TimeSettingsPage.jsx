import { useEffect, useMemo, useState } from 'react'
import {
  ArrowLeft,
  ArrowRight,
  Check,
  Clock3,
  Globe2,
  Loader2,
  TriangleAlert,
} from 'lucide-react'

const STEP_LABELS = {
  1: 'Timezone & format',
  2: 'Manual override',
}

function getCfg() {
  return window.__TIME_SETTINGS_CFG__ || {}
}

function emptyForm() {
  return {
    timezone: 'Africa/Dar_es_Salaam',
    timeFormat: '24',
    overrideEnabled: false,
    overrideTime: '',
  }
}

async function parseJson(res) {
  const text = await res.text()
  try {
    return JSON.parse(text)
  } catch {
    throw new Error(text.slice(0, 180) || `Request failed (${res.status})`)
  }
}

export default function TimeSettingsPage() {
  const cfg = useMemo(() => getCfg(), [])
  const apiBase = String(cfg.apiBase || '').replace(/\/$/, '')
  const [form, setForm] = useState(() => ({
    ...emptyForm(),
    ...(cfg.initial?.form || {}),
  }))
  const [links, setLinks] = useState(cfg.initial?.links || {})
  const [meta, setMeta] = useState(cfg.initial?.meta || {})
  const [options, setOptions] = useState(cfg.initial?.options || {
    timezones: [],
    formats: [],
  })
  const [step, setStep] = useState(1)
  const [loading, setLoading] = useState(!cfg.initial)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  useEffect(() => {
    if (cfg.initial) return undefined
    let cancelled = false
    ;(async () => {
      try {
        const res = await fetch(`${apiBase}/init.php`, { credentials: 'same-origin' })
        const data = await parseJson(res)
        if (!res.ok || data.success === false) throw new Error(data.error || 'Failed to load')
        if (cancelled) return
        const next = data.data || {}
        setForm({ ...emptyForm(), ...(next.form || {}) })
        setLinks(next.links || {})
        setMeta(next.meta || {})
        setOptions(next.options || { timezones: [], formats: [] })
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Failed to load settings')
      } finally {
        if (!cancelled) setLoading(false)
      }
    })()
    return () => {
      cancelled = true
    }
  }, [apiBase, cfg.initial])

  function patch(key, value) {
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  function validateStep(s) {
    if (s === 1) {
      if (!form.timezone) return 'Select a system timezone.'
      return null
    }
    if (form.overrideEnabled && !form.overrideTime) {
      return 'Enter the override timestamp, or turn off manual override.'
    }
    return null
  }

  function goNext() {
    const msg = validateStep(step)
    if (msg) {
      setError(msg)
      return
    }
    setError('')
    setNotice('')
    if (step < 2) setStep((n) => n + 1)
  }

  function goPrevious() {
    setError('')
    setNotice('')
    if (step > 1) setStep((n) => n - 1)
  }

  async function handleSave(event) {
    event.preventDefault()
    const msg = validateStep(2)
    if (msg) {
      setError(msg)
      return
    }
    setSaving(true)
    setError('')
    setNotice('')
    try {
      const res = await fetch(`${apiBase}/save.php`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(form),
      })
      const data = await parseJson(res)
      if (!res.ok || data.success === false) throw new Error(data.error || 'Save failed')
      const next = data.data || {}
      setForm({ ...emptyForm(), ...(next.form || {}) })
      setLinks(next.links || links)
      setMeta(next.meta || meta)
      setOptions(next.options || options)
      setNotice(data.message || 'Time settings saved.')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Save failed')
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return (
      <div className="ts-page ts-loading">
        <Loader2 className="ts-spin" size={18} />
        <span>Loading time settings...</span>
      </div>
    )
  }

  const timezones = options.timezones?.length
    ? options.timezones
    : [{ value: 'Africa/Dar_es_Salaam', label: 'Africa/Dar es Salaam (EAT, GMT+3)' }]
  const formats = options.formats?.length
    ? options.formats
    : [
        { value: '24', label: '24-Hour (e.g. 14:30)' },
        { value: '12', label: '12-Hour (e.g. 2:30 PM)' },
      ]

  return (
    <div className="ts-page">
      <div className="ts-topbar">
        <a className="ts-back" href={links.backUrl || '#'}>
          <ArrowLeft size={16} />
          Settings
        </a>
        {form.overrideEnabled ? (
          <span className="ts-badge ts-badge-warn">Override active</span>
        ) : (
          <span className="ts-badge">Live clock</span>
        )}
      </div>

      {error ? (
        <div className="ts-flash ts-flash-error" role="alert">
          {error}
        </div>
      ) : null}
      {notice ? (
        <div className="ts-flash ts-flash-ok" role="status">
          {notice}
        </div>
      ) : null}

      <div className="wizard">
        <header className="wizard-top">
          <div className="wizard-mark" aria-hidden>
            <Clock3 size={18} />
          </div>
          <nav className="wizard-stepper" aria-label={`Step ${step} of 2`}>
            {[1, 2].map((n, i) => (
              <div key={n} className="wizard-step-wrap">
                {i > 0 ? <div className={`wizard-line ${n <= step ? 'on' : ''}`} /> : null}
                <button
                  type="button"
                  className={`wizard-step ${n === step ? 'active' : ''} ${n < step ? 'done' : ''}`}
                  onClick={() => {
                    if (n < step) {
                      setError('')
                      setStep(n)
                    }
                  }}
                >
                  <span className="wizard-num">{n}</span>
                  <span className="wizard-label">{STEP_LABELS[n]}</span>
                </button>
              </div>
            ))}
          </nav>
        </header>

        <form
          className="wizard-body"
          onSubmit={(e) => {
            if (step < 2) {
              e.preventDefault()
              goNext()
              return
            }
            void handleSave(e)
          }}
        >
          <div className="wizard-title-row">
            <h1>{STEP_LABELS[step]}</h1>
            {meta.serverNow ? (
              <span className="wizard-cancel" style={{ cursor: 'default' }}>
                Server: {meta.serverNow}
              </span>
            ) : null}
          </div>

          {step === 1 ? (
            <>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>
                    System timezone<span className="req">*</span>
                  </h2>
                  <p>Used for attendance logs, vouchers, and reports.</p>
                </div>
                <div className="wizard-auth-fields">
                  <div className="field-line">
                    <Globe2 size={18} aria-hidden />
                    <select
                      value={form.timezone}
                      onChange={(e) => patch('timezone', e.target.value)}
                      aria-label="System timezone"
                    >
                      {timezones.map((tz) => (
                        <option key={tz.value} value={tz.value}>
                          {tz.label}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
              </div>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>Display format</h2>
                  <p>How times appear across the ERP.</p>
                </div>
                <div className="wizard-auth-fields">
                  <div className="field-line">
                    <Clock3 size={18} aria-hidden />
                    <select
                      value={form.timeFormat}
                      onChange={(e) => patch('timeFormat', e.target.value)}
                      aria-label="Display format"
                    >
                      {formats.map((fmt) => (
                        <option key={fmt.value} value={fmt.value}>
                          {fmt.label}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
              </div>
            </>
          ) : null}

          {step === 2 ? (
            <>
              <div className="ts-warn">
                <div className="ts-warn-head">
                  <TriangleAlert size={16} />
                  <span>Use with caution</span>
                </div>
                <p>
                  Enabling this forces a fixed manual time for all attendance records.
                  Use only for emergencies or testing.
                </p>
              </div>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>Manual override</h2>
                  <p>Freeze attendance timestamps to a chosen datetime.</p>
                </div>
                <div className="wizard-auth-fields">
                  <label className="same-pass">
                    <input
                      type="checkbox"
                      checked={Boolean(form.overrideEnabled)}
                      onChange={(e) => patch('overrideEnabled', e.target.checked)}
                    />
                    Activate manual time override
                  </label>
                  <div className={`field-line ${form.overrideEnabled ? '' : 'is-disabled'}`}>
                    <Clock3 size={18} aria-hidden />
                    <input
                      type="datetime-local"
                      value={form.overrideTime}
                      onChange={(e) => patch('overrideTime', e.target.value)}
                      disabled={!form.overrideEnabled}
                      aria-label="Override timestamp"
                    />
                  </div>
                </div>
              </div>
              <div className="setup-summary">
                <strong>Ready to save</strong>
                <ul>
                  <li>Timezone: {form.timezone}</li>
                  <li>Format: {form.timeFormat === '12' ? '12-hour' : '24-hour'}</li>
                  <li>Override: {form.overrideEnabled ? form.overrideTime || 'on' : 'off'}</li>
                </ul>
              </div>
            </>
          ) : null}

          <footer className="wizard-footer">
            <p className="wizard-note">Step {step} of 2 - same flow as mailbox registration.</p>
            <div className="wizard-footer-actions">
              <button
                type="button"
                className="wizard-btn-prev"
                onClick={goPrevious}
                disabled={step === 1 || saving}
              >
                <ArrowLeft size={16} />
                Back
              </button>
              {step < 2 ? (
                <button type="submit" className="wizard-btn-next">
                  Continue
                  <ArrowRight size={16} />
                </button>
              ) : (
                <button type="submit" className="wizard-btn-next" disabled={saving}>
                  {saving ? <Loader2 className="ts-spin" size={16} /> : <Check size={16} />}
                  Save configuration
                </button>
              )}
            </div>
          </footer>
        </form>
      </div>
    </div>
  )
}
