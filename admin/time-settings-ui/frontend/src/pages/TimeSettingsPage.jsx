import { useEffect, useMemo, useRef, useState } from 'react'
import {
  ArrowLeft,
  ArrowRight,
  Check,
  Clock3,
  Globe2,
  Loader2,
  Fingerprint,
  MapPin,
  Network,
  Plus,
  ShieldCheck,
  Trash2,
} from 'lucide-react'

const STEP_LABELS = {
  1: 'Timezone & format',
  2: 'Working hours',
  3: 'Network & location',
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
    startTime: '09:00',
    endTime: '17:00',
    gracePeriodMinutes: 15,
    officeIps: [''],
    geofenceEnabled: true,
    latitude: '',
    longitude: '',
    radiusMeters: 100,
    biometricRequired: true,
  }
}

function normalizeForm(raw) {
  const base = emptyForm()
  const next = { ...base, ...(raw || {}) }
  next.officeIps = Array.isArray(raw?.officeIps) && raw.officeIps.length
    ? raw.officeIps.map(String)
    : ['']
  next.latitude = raw?.latitude != null && raw.latitude !== '' ? String(raw.latitude) : ''
  next.longitude = raw?.longitude != null && raw.longitude !== '' ? String(raw.longitude) : ''
  next.gracePeriodMinutes = Number(raw?.gracePeriodMinutes ?? 15)
  next.radiusMeters = Number(raw?.radiusMeters ?? 100) || 100
  next.biometricRequired = raw?.biometricRequired == null
    ? true
    : !!raw.biometricRequired
  return next
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
  const embedded = !!(
    cfg.embedded
    || (typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('embed') === '1')
  )
  const fixedStep = useMemo(() => {
    const fromCfg = Number(cfg.timeStep || 0)
    if (fromCfg >= 1 && fromCfg <= 3) return fromCfg
    if (typeof window === 'undefined') return 0
    const fromUrl = Number(new URLSearchParams(window.location.search).get('time_step') || 0)
    return fromUrl >= 1 && fromUrl <= 3 ? fromUrl : 0
  }, [cfg.timeStep])
  const singleStep = fixedStep > 0
  const [form, setForm] = useState(() => normalizeForm(cfg.initial?.form))
  const [links, setLinks] = useState(cfg.initial?.links || {})
  const [meta, setMeta] = useState(cfg.initial?.meta || {})
  const [options, setOptions] = useState(cfg.initial?.options || {
    timezones: [],
    formats: [],
  })
  const [step, setStep] = useState(() => (fixedStep > 0 ? fixedStep : 1))
  const [loading, setLoading] = useState(!cfg.initial)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  function applyPayload(next) {
    setForm(normalizeForm(next?.form))
    setLinks(next?.links || {})
    setMeta(next?.meta || {})
    setOptions(next?.options || { timezones: [], formats: [] })
  }

  useEffect(() => {
    if (cfg.initial) return undefined
    let cancelled = false
    ;(async () => {
      try {
        const res = await fetch(`${apiBase}/init.php`, { credentials: 'same-origin' })
        const data = await parseJson(res)
        if (!res.ok || data.success === false) throw new Error(data.error || 'Failed to load')
        if (cancelled) return
        applyPayload(data.data || {})
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

  const pageRef = useRef(null)

  useEffect(() => {
    if (!embedded || typeof window === 'undefined' || window.parent === window) return undefined

    const publishHeight = () => {
      const node = pageRef.current
      if (!node) return
      const height = Math.ceil(Math.max(node.scrollHeight, node.getBoundingClientRect().height, 0))
      if (height < 1) return
      window.parent.postMessage(
        { type: 'erp-embed-resize', source: 'time-settings', height: height + 4 },
        '*',
      )
    }

    publishHeight()
    const frame = window.requestAnimationFrame(publishHeight)
    const timer = window.setTimeout(publishHeight, 80)
    let observer
    if (typeof ResizeObserver !== 'undefined' && pageRef.current) {
      observer = new ResizeObserver(() => publishHeight())
      observer.observe(pageRef.current)
    }

    return () => {
      window.cancelAnimationFrame(frame)
      window.clearTimeout(timer)
      observer?.disconnect()
    }
  }, [
    embedded,
    loading,
    step,
    form.geofenceEnabled,
    form.officeIps.length,
    form.overrideEnabled,
    error,
    notice,
  ])

  function patch(key, value) {
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  function updateIp(index, value) {
    setForm((prev) => {
      const next = [...prev.officeIps]
      next[index] = value
      return { ...prev, officeIps: next }
    })
  }

  function addIpRow() {
    setForm((prev) => ({ ...prev, officeIps: [...prev.officeIps, ''] }))
  }

  function removeIpRow(index) {
    setForm((prev) => {
      const next = prev.officeIps.filter((_, i) => i !== index)
      return { ...prev, officeIps: next.length ? next : [''] }
    })
  }

  function validateStep(s) {
    if (s === 1) {
      if (!form.timezone) return 'Select a system timezone.'
      if (form.overrideEnabled && !form.overrideTime) return 'Enter a target system time for the manual override.'
      return null
    }
    if (s === 2) {
      if (!form.startTime) return 'Enter work start time.'
      if (!form.endTime) return 'Enter work end time.'
      if (Number.isNaN(Number(form.gracePeriodMinutes)) || form.gracePeriodMinutes < 0) {
        return 'Enter a valid grace period.'
      }
      return null
    }
    if (form.geofenceEnabled) {
      if (form.latitude === '' || form.longitude === '') {
        return 'Enter office latitude and longitude, or turn off geofencing.'
      }
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
    if (step < 3) setStep((n) => n + 1)
  }

  function goPrevious() {
    setError('')
    setNotice('')
    if (step > 1) setStep((n) => n - 1)
  }

  async function handleSave(event) {
    event.preventDefault()
    const msg = validateStep(singleStep ? step : 3)
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
        body: JSON.stringify({
          ...form,
          latitude: form.latitude === '' ? null : Number(form.latitude),
          longitude: form.longitude === '' ? null : Number(form.longitude),
        }),
      })
      const data = await parseJson(res)
      if (!res.ok || data.success === false) throw new Error(data.error || 'Save failed')
      applyPayload(data.data || {})
      setNotice(data.message || 'Time & attendance settings saved.')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Save failed')
    } finally {
      setSaving(false)
    }
  }

  async function addCurrentIp() {
    setSaving(true)
    setError('')
    setNotice('')
    try {
      const res = await fetch(`${apiBase}/save.php`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add_current_ip' }),
      })
      const data = await parseJson(res)
      if (!res.ok || data.success === false) throw new Error(data.error || 'Could not add IP')
      const currentIp = String(data.data?.meta?.currentIp || '').trim()
      if (!currentIp || !data.data?.form?.officeIps?.includes(currentIp)) {
        throw new Error('The server did not confirm the current office IP.')
      }
      setForm((prev) => ({
        ...prev,
        officeIps: [...new Set([...prev.officeIps, currentIp])],
      }))
      setMeta(data.data?.meta || {})
      setNotice(data.message || 'Current office IP added.')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not add IP')
    } finally {
      setSaving(false)
    }
  }

  function useMyLocation() {
    if (!navigator.geolocation) {
      setError('Geolocation is not available in this browser.')
      return
    }
    setNotice('Detecting location...')
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        patch('latitude', String(pos.coords.latitude))
        patch('longitude', String(pos.coords.longitude))
        setNotice('Location detected.')
      },
      () => setError('Could not read your location.'),
      { enableHighAccuracy: true, timeout: 15000 },
    )
  }

  if (loading) {
    const skeletonRows = singleStep ? 3 : 4
    return (
      <div
        ref={pageRef}
        className={`ts-page${embedded ? ' ts-page--embed' : ''} ts-page--skeleton`}
        aria-busy="true"
        aria-live="polite"
      >
        <span className="sr-only">Loading time & attendance settings...</span>
        {!embedded && !singleStep ? (
          <div className="ts-skel-topbar">
            <span className="ts-skel-bar ts-skel-bar--sm" />
            <span className="ts-skel-pill" />
          </div>
        ) : null}
        <div className="wizard wizard--skeleton">
          {!singleStep ? (
            <div className="ts-skel-title">
              <span className="ts-skel-bar ts-skel-bar--md" />
            </div>
          ) : null}
          {Array.from({ length: skeletonRows }).map((_, index) => (
            <div className="ts-skel-row" key={`skel-row-${index}`}>
              <span className="ts-skel-bar ts-skel-bar--label" />
              <span className="ts-skel-field" />
            </div>
          ))}
          <div className="ts-skel-footer">
            <span className="ts-skel-btn" />
          </div>
        </div>
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
    <div ref={pageRef} className={`ts-page${embedded ? ' ts-page--embed' : ''}`}>
      {!embedded ? (
        <div className="ts-topbar">
          <a className="ts-back" href={links.backUrl || '#'}>
            <ArrowLeft size={16} />
            Settings
          </a>
          {form.geofenceEnabled ? (
            <span className="ts-badge">Geofence on</span>
          ) : (
            <span className="ts-badge">Time & attendance</span>
          )}
        </div>
      ) : null}

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

      <div className={`wizard${singleStep ? ' wizard--single' : ''}`}>
        {!singleStep ? (
          <header className="wizard-top">
            {!embedded ? (
              <div className="wizard-mark" aria-hidden>
                <Clock3 size={18} />
              </div>
            ) : (
              <span className={`ts-badge${form.geofenceEnabled ? ' ts-badge-ok' : ''}`}>
                {form.geofenceEnabled ? 'Geofence on' : 'Time & attendance'}
              </span>
            )}
            <nav className="wizard-stepper" aria-label={`Step ${step} of 3`}>
              {[1, 2, 3].map((n, i) => (
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
        ) : null}

        <form
          className="wizard-body"
          onSubmit={(e) => {
            if (!singleStep && step < 3) {
              e.preventDefault()
              goNext()
              return
            }
            void handleSave(e)
          }}
        >
          {!singleStep ? (
            <div className="wizard-title-row">
              <h1>{STEP_LABELS[step]}</h1>
            </div>
          ) : null}

          {step === 1 ? (
            <>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>
                    System timezone<span className="req">*</span>
                  </h2>
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
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>Manual override</h2>
                </div>
                <div className="wizard-auth-fields">
                  <label className="ts-override-toggle">
                    <input
                      type="checkbox"
                      checked={form.overrideEnabled}
                      onChange={(e) => patch('overrideEnabled', e.target.checked)}
                    />
                    Activate manual time override
                  </label>
                  {form.overrideEnabled ? (
                    <div className="field-line">
                      <Clock3 size={18} aria-hidden />
                      <input
                        type="datetime-local"
                        value={form.overrideTime}
                        onChange={(e) => patch('overrideTime', e.target.value)}
                        aria-label="Target system time"
                      />
                    </div>
                  ) : null}
                </div>
              </div>
            </>
          ) : null}

          {step === 2 ? (
            <>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>
                    Work start<span className="req">*</span>
                  </h2>
                </div>
                <div className="wizard-auth-fields">
                  <div className="field-line">
                    <Clock3 size={18} aria-hidden />
                    <input
                      type="time"
                      lang={form.timeFormat === '12' ? 'en-US' : 'en-GB'}
                      value={form.startTime}
                      onChange={(e) => patch('startTime', e.target.value)}
                      aria-label="Work start time"
                    />
                  </div>
                </div>
              </div>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>
                    Work end<span className="req">*</span>
                  </h2>
                </div>
                <div className="wizard-auth-fields">
                  <div className="field-line">
                    <Clock3 size={18} aria-hidden />
                    <input
                      type="time"
                      lang={form.timeFormat === '12' ? 'en-US' : 'en-GB'}
                      value={form.endTime}
                      onChange={(e) => patch('endTime', e.target.value)}
                      aria-label="Work end time"
                    />
                  </div>
                </div>
              </div>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>Grace period</h2>
                </div>
                <div className="wizard-auth-fields">
                  <div className="field-line">
                    <Clock3 size={18} aria-hidden />
                    <input
                      type="number"
                      min={0}
                      value={form.gracePeriodMinutes}
                      onChange={(e) => patch('gracePeriodMinutes', Number(e.target.value))}
                      aria-label="Grace period minutes"
                    />
                    <span className="field-unit">minutes</span>
                  </div>
                </div>
              </div>
            </>
          ) : null}

          {step === 3 ? (
            <>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>Office IPs</h2>
                </div>
                <div className="wizard-auth-fields">
                  {form.officeIps.map((ip, index) => (
                    <div className="field-line field-line--ip" key={`ip-${index}`}>
                      <Network size={18} aria-hidden />
                      <input
                        type="text"
                        value={ip}
                        onChange={(e) => updateIp(index, e.target.value)}
                        placeholder="e.g. 102.205.250.0/24"
                        aria-label={`Office IP ${index + 1}`}
                        size={18}
                      />
                      <button
                        type="button"
                        className="field-eye field-eye--danger"
                        onClick={() => removeIpRow(index)}
                        aria-label="Remove IP"
                      >
                        <Trash2 size={15} aria-hidden />
                      </button>
                    </div>
                  ))}
                  <div className="ts-inline-actions">
                    <button type="button" className="ts-chip" onClick={addIpRow}>
                      <Plus size={14} /> Add IP
                    </button>
                    <button type="button" className="ts-chip" disabled={saving} onClick={() => void addCurrentIp()}>
                      <Network size={14} />
                      Add my IP{meta.currentIp ? ` (${meta.currentIp})` : ''}
                    </button>
                  </div>
                </div>
              </div>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>Geofencing</h2>
                </div>
                <div className="wizard-auth-fields">
                  <label className="ts-switch-row">
                    <span
                      className={`ts-switch${form.geofenceEnabled ? ' is-on' : ''}`}
                      aria-hidden="true"
                    >
                      <span className="ts-switch-knob" />
                    </span>
                    <span className="ts-switch-label">Enable geofencing fallback</span>
                    <input
                      type="checkbox"
                      className="ts-switch-input"
                      checked={Boolean(form.geofenceEnabled)}
                      onChange={(e) => patch('geofenceEnabled', e.target.checked)}
                      aria-label="Enable geofencing fallback"
                    />
                  </label>
                  {form.geofenceEnabled ? (
                    <>
                      <p className="ts-geo-hint">
                        Set the office map point and how far staff can be from it when clocking in.
                        Use <strong>Use my location</strong> if you are at the office now.
                      </p>
                      <div className="ts-geo-coords">
                        <label className="ts-field-block">
                          <span className="ts-field-label">Latitude</span>
                          <span className="ts-field-help">North/South (e.g. -6.7685)</span>
                          <div className="field-line">
                            <MapPin size={18} aria-hidden />
                            <input
                              type="number"
                              step="any"
                              value={form.latitude}
                              onChange={(e) => patch('latitude', e.target.value)}
                              placeholder="-6.76849251"
                              aria-label="Office latitude"
                            />
                          </div>
                        </label>
                        <label className="ts-field-block">
                          <span className="ts-field-label">Longitude</span>
                          <span className="ts-field-help">East/West (e.g. 39.1933)</span>
                          <div className="field-line">
                            <MapPin size={18} aria-hidden />
                            <input
                              type="number"
                              step="any"
                              value={form.longitude}
                              onChange={(e) => patch('longitude', e.target.value)}
                              placeholder="39.19332033"
                              aria-label="Office longitude"
                            />
                          </div>
                        </label>
                      </div>
                      <label className="ts-field-block">
                        <span className="ts-field-label">Allowed radius</span>
                        <span className="ts-field-help">How far from the office pin staff may clock in</span>
                        <div className="field-line">
                          <MapPin size={18} aria-hidden />
                          <input
                            type="number"
                            min={1}
                            value={form.radiusMeters}
                            onChange={(e) => patch('radiusMeters', Number(e.target.value))}
                            placeholder="100"
                            aria-label="Allowed radius in meters"
                          />
                          <span className="field-unit">meters</span>
                        </div>
                      </label>
                      <button type="button" className="ts-chip" onClick={useMyLocation}>
                        <MapPin size={14} /> Use my location
                      </button>
                    </>
                  ) : null}
                </div>
              </div>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>Biometric clock-in</h2>
                </div>
                <div className="wizard-auth-fields">
                  <label className="ts-switch-row">
                    <span
                      className={`ts-switch${form.biometricRequired ? ' is-on' : ''}`}
                      aria-hidden="true"
                    >
                      <span className="ts-switch-knob" />
                    </span>
                    <span className="ts-switch-label">
                      <Fingerprint size={15} aria-hidden />
                      Require phone unlock verification
                    </span>
                    <input
                      type="checkbox"
                      className="ts-switch-input"
                      checked={Boolean(form.biometricRequired)}
                      onChange={(e) => patch('biometricRequired', e.target.checked)}
                      aria-label="Require phone unlock verification"
                    />
                  </label>
                </div>
              </div>
              {!singleStep ? (
                <div className="setup-summary">
                  <strong>Ready to save</strong>
                  <ul>
                    <li>Timezone: {form.timezone}</li>
                    <li>Hours: {form.startTime} - {form.endTime} (grace {form.gracePeriodMinutes}m)</li>
                    <li>Geofence: {form.geofenceEnabled ? 'on' : 'off'}</li>
                    <li>Biometric clock-in: {form.biometricRequired ? 'on' : 'off'}</li>
                  </ul>
                </div>
              ) : null}
            </>
          ) : null}

          <footer className="wizard-footer">
            {!singleStep ? (
              <p className="wizard-note">Step {step} of 3 - time format and attendance in one place.</p>
            ) : (
              <span className="wizard-note" aria-hidden="true" />
            )}
            <div className="wizard-footer-actions">
              {!singleStep ? (
                <button
                  type="button"
                  className="wizard-btn-prev"
                  onClick={goPrevious}
                  disabled={step === 1 || saving}
                >
                  <ArrowLeft size={16} />
                  Back
                </button>
              ) : null}
              {!singleStep && step < 3 ? (
                <button type="submit" className="wizard-btn-next">
                  Continue
                  <ArrowRight size={16} />
                </button>
              ) : (
                <button type="submit" className="wizard-btn-next" disabled={saving}>
                  {saving ? <Loader2 className="ts-spin" size={16} /> : <Check size={16} />}
                  {singleStep ? 'Save' : 'Save configuration'}
                </button>
              )}
            </div>
          </footer>
        </form>
      </div>
    </div>
  )
}
