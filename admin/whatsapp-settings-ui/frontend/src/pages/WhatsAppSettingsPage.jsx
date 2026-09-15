import { useEffect, useMemo, useRef, useState } from 'react'
import {
  ArrowLeft,
  ArrowRight,
  Building2,
  Check,
  Copy,
  ExternalLink,
  Eye,
  EyeOff,
  Hash,
  KeyRound,
  Link2,
  Loader2,
  MessageCircle,
  Phone,
  Shield,
} from 'lucide-react'

const STEP_LABELS = {
  1: 'Business identity',
  2: 'Cloud API credentials',
  3: 'Webhook & automation',
}

const FLAG_BASE = 'https://flagcdn.com/w40/'

// Unique dial codes (longest first for parsing)
const PHONE_COUNTRIES = [
  { dial: '+255', iso: 'tz', label: 'TZ +255' },
  { dial: '+254', iso: 'ke', label: 'KE +254' },
  { dial: '+256', iso: 'ug', label: 'UG +256' },
  { dial: '+250', iso: 'rw', label: 'RW +250' },
  { dial: '+27', iso: 'za', label: 'ZA +27' },
  { dial: '+234', iso: 'ng', label: 'NG +234' },
  { dial: '+233', iso: 'gh', label: 'GH +233' },
  { dial: '+971', iso: 'ae', label: 'AE +971' },
  { dial: '+91', iso: 'in', label: 'IN +91' },
  { dial: '+44', iso: 'gb', label: 'UK +44' },
  { dial: '+1', iso: 'us', label: 'US +1' },
]

function flagUrl(iso) {
  return `${FLAG_BASE}${String(iso || 'un').toLowerCase()}.png`
}

function splitPhone(raw) {
  const cleaned = String(raw || '').trim()
  const digitsPrefixed = cleaned.replace(/[^\d+]/g, '')
  const withPlus = digitsPrefixed.startsWith('+')
    ? digitsPrefixed
    : digitsPrefixed
      ? `+${digitsPrefixed}`
      : ''
  const sorted = [...PHONE_COUNTRIES].sort((a, b) => b.dial.length - a.dial.length)
  for (const c of sorted) {
    if (withPlus.startsWith(c.dial)) {
      return {
        countryCode: c.dial,
        national: withPlus.slice(c.dial.length).replace(/\D/g, ''),
      }
    }
  }
  // Also match without plus: 2557...
  const onlyDigits = cleaned.replace(/\D/g, '')
  for (const c of sorted) {
    const dialDigits = c.dial.replace(/\D/g, '')
    if (onlyDigits.startsWith(dialDigits)) {
      return {
        countryCode: c.dial,
        national: onlyDigits.slice(dialDigits.length),
      }
    }
  }
  return { countryCode: '+255', national: onlyDigits }
}

function joinPhone(countryCode, national) {
  const n = String(national || '').replace(/\D/g, '')
  if (!n) return ''
  return `${countryCode} ${n}`.trim()
}

function getCfg() {
  return window.__WHATSAPP_SETTINGS_CFG__ || {}
}

function emptyForm() {
  return {
    displayPhone: '',
    businessAccountId: '',
    phoneNumberId: '',
    accessToken: '',
    accessTokenSet: false,
    accessTokenMasked: '',
    webhookVerifyToken: '',
    groupLink: '',
    autoReplyEnabled: false,
    autoReplyText: 'Thanks - our team will get back to you shortly.',
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

export default function WhatsAppSettingsPage() {
  const cfg = useMemo(() => getCfg(), [])
  const apiBase = String(cfg.apiBase || '').replace(/\/$/, '')
  const [form, setForm] = useState(() => ({
    ...emptyForm(),
    ...(cfg.initial?.form || {}),
  }))
  const [links, setLinks] = useState(cfg.initial?.links || {})
  const [meta, setMeta] = useState(cfg.initial?.meta || {})
  const [step, setStep] = useState(1)
  const [loading, setLoading] = useState(!cfg.initial)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [copied, setCopied] = useState('')
  const [showToken, setShowToken] = useState(false)
  const [countryCode, setCountryCode] = useState(() => splitPhone(form.displayPhone).countryCode)
  const [nationalPhone, setNationalPhone] = useState(() => splitPhone(form.displayPhone).national)
  const [countryOpen, setCountryOpen] = useState(false)
  const countryMenuRef = useRef(null)

  const selectedCountry =
    PHONE_COUNTRIES.find((c) => c.dial === countryCode) || PHONE_COUNTRIES[0]

  useEffect(() => {
    if (!countryOpen) return undefined
    function onPointerDown(event) {
      if (!countryMenuRef.current?.contains(event.target)) {
        setCountryOpen(false)
      }
    }
    function onEscape(event) {
      if (event.key === 'Escape') setCountryOpen(false)
    }
    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onEscape)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onEscape)
    }
  }, [countryOpen])

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
        const nextForm = { ...emptyForm(), ...(next.form || {}) }
        setForm(nextForm)
        const parsed = splitPhone(nextForm.displayPhone)
        setCountryCode(parsed.countryCode)
        setNationalPhone(parsed.national)
        setLinks(next.links || {})
        setMeta(next.meta || {})
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

  function updatePhone(nextCountry, nextNational) {
    const national = String(nextNational || '').replace(/\D/g, '')
    setCountryCode(nextCountry)
    setNationalPhone(national)
    patch('displayPhone', joinPhone(nextCountry, national))
  }

  function validateStep(s) {
    if (s === 1) {
      if (!nationalPhone.trim() && !form.displayPhone.trim()) {
        return 'Enter the WhatsApp business phone number.'
      }
      return null
    }
    if (s === 2) {
      if (!form.phoneNumberId.trim()) return 'Enter the Phone Number ID from Meta.'
      if (!form.accessTokenSet && !form.accessToken.trim()) {
        return 'Enter a Meta Cloud API access token.'
      }
      return null
    }
    if (!form.webhookVerifyToken.trim()) return 'Enter a webhook verify token.'
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
    const msg = validateStep(3)
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
      const nextForm = { ...emptyForm(), ...(next.form || {}), accessToken: '' }
      setForm(nextForm)
      const parsed = splitPhone(nextForm.displayPhone)
      setCountryCode(parsed.countryCode)
      setNationalPhone(parsed.national)
      setLinks(next.links || links)
      setMeta(next.meta || meta)
      setNotice(data.message || 'WhatsApp registered.')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Save failed')
    } finally {
      setSaving(false)
    }
  }

  async function copyText(label, value) {
    if (!value) return
    try {
      await navigator.clipboard.writeText(value)
      setCopied(label)
      setTimeout(() => setCopied(''), 1600)
    } catch {
      setError('Could not copy to clipboard.')
    }
  }

  if (loading) {
    return (
      <div className="wa-page wa-loading">
        <Loader2 className="wa-spin" size={18} />
        <span>Loading WhatsApp registration...</span>
      </div>
    )
  }

  return (
    <div className="wa-page">
      <div className="wa-topbar">
        <a className="wa-back" href={links.backUrl || '#'}>
          <ArrowLeft size={16} />
          Settings
        </a>
        {meta.configured ? (
          <span className="wa-badge wa-badge-ok">
            <Check size={14} /> Connected
          </span>
        ) : (
          <span className="wa-badge">Setup required</span>
        )}
      </div>

      {error ? (
        <div className="wa-flash wa-flash-error" role="alert">
          {error}
        </div>
      ) : null}
      {notice ? (
        <div className="wa-flash wa-flash-ok" role="status">
          {notice}
        </div>
      ) : null}

      <div className="wizard">
        <header className="wizard-top">
          <div className="wizard-mark" aria-hidden>
            <MessageCircle size={22} />
          </div>
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

        <form
          className="wizard-body"
          onSubmit={(e) => {
            if (step < 3) {
              e.preventDefault()
              goNext()
              return
            }
            void handleSave(e)
          }}
        >
          <div className="wizard-title-row">
            <h1>{STEP_LABELS[step]}</h1>
            {links.botUrl ? (
              <a className="wizard-cancel" href={links.botUrl} target="_blank" rel="noreferrer">
                Open bot console
                <ExternalLink size={14} />
              </a>
            ) : null}
          </div>

          {step === 1 ? (
            <>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>
                    Display phone<span className="req">*</span>
                  </h2>
                  <p>The public WhatsApp Business number customers will message.</p>
                </div>
                <div className="wizard-auth-fields">
                  <div className="field-line field-line--phone">
                    <Phone size={18} aria-hidden />
                    <div className="phone-row">
                      <div
                        className={`phone-country${countryOpen ? ' is-open' : ''}`}
                        ref={countryMenuRef}
                      >
                        <button
                          type="button"
                          className="phone-country-trigger"
                          aria-haspopup="listbox"
                          aria-expanded={countryOpen}
                          aria-label="Country code"
                          onClick={() => setCountryOpen((v) => !v)}
                        >
                          <img
                            className="country-flag-img"
                            src={flagUrl(selectedCountry.iso)}
                            alt=""
                            width={22}
                            height={16}
                          />
                          <span className="country-dial">{countryCode}</span>
                          <span className="country-chevron" aria-hidden="true" />
                        </button>
                        {countryOpen ? (
                          <ul className="phone-country-menu" role="listbox">
                            {PHONE_COUNTRIES.map((c) => (
                              <li key={c.dial} role="option" aria-selected={c.dial === countryCode}>
                                <button
                                  type="button"
                                  className={`phone-country-option${c.dial === countryCode ? ' is-selected' : ''}`}
                                  onClick={() => {
                                    updatePhone(c.dial, nationalPhone)
                                    setCountryOpen(false)
                                  }}
                                >
                                  <img
                                    className="country-flag-img"
                                    src={flagUrl(c.iso)}
                                    alt=""
                                    width={22}
                                    height={16}
                                  />
                                  <span>{c.label}</span>
                                </button>
                              </li>
                            ))}
                          </ul>
                        ) : null}
                      </div>
                      <span className="phone-divider" aria-hidden="true" />
                      <input
                        type="tel"
                        value={nationalPhone}
                        onChange={(e) => updatePhone(countryCode, e.target.value)}
                        placeholder="7XX XXX XXX"
                        autoComplete="tel-national"
                        inputMode="tel"
                        aria-label="Display phone"
                      />
                    </div>
                  </div>
                </div>
              </div>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>Business Account ID</h2>
                  <p>Optional WABA ID from Meta Business Manager.</p>
                </div>
                <div className="wizard-auth-fields">
                  <div className="field-line">
                    <Building2 size={18} aria-hidden />
                    <input
                      type="text"
                      value={form.businessAccountId}
                      onChange={(e) => patch('businessAccountId', e.target.value)}
                      placeholder="WABA ID (optional)"
                      autoComplete="off"
                      aria-label="Business Account ID"
                    />
                  </div>
                </div>
              </div>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>Staff group link</h2>
                  <p>Invite link used when sharing vouchers to a WhatsApp group.</p>
                </div>
                <div className="wizard-auth-fields">
                  <div className="field-line">
                    <Link2 size={18} aria-hidden />
                    <input
                      type="url"
                      value={form.groupLink}
                      onChange={(e) => patch('groupLink', e.target.value)}
                      placeholder="https://chat.whatsapp.com/..."
                      autoComplete="off"
                      aria-label="Group invitation URL"
                    />
                  </div>
                </div>
              </div>
            </>
          ) : null}

          {step === 2 ? (
            <>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>
                    Phone Number ID<span className="req">*</span>
                  </h2>
                  <p>From Meta WhatsApp - API Setup. Required to send messages.</p>
                </div>
                <div className="wizard-auth-fields">
                  <div className="field-line">
                    <Hash size={18} aria-hidden />
                    <input
                      type="text"
                      value={form.phoneNumberId}
                      onChange={(e) => patch('phoneNumberId', e.target.value)}
                      placeholder="Phone Number ID"
                      autoComplete="off"
                      aria-label="Phone Number ID"
                    />
                  </div>
                </div>
              </div>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>
                    Access token<span className="req">*</span>
                  </h2>
                  <p>Temporary or system user token with whatsapp_business_messaging.</p>
                </div>
                <div className="wizard-auth-fields">
                  <div className="field-line">
                    <KeyRound size={18} aria-hidden />
                    <input
                      type={showToken ? 'text' : 'password'}
                      value={form.accessToken}
                      onChange={(e) => patch('accessToken', e.target.value)}
                      placeholder={form.accessTokenSet ? 'Leave blank to keep saved token' : 'Access token'}
                      autoComplete="new-password"
                      aria-label="Access token"
                    />
                    <button
                      type="button"
                      className="field-eye"
                      onClick={() => setShowToken((v) => !v)}
                      aria-label={showToken ? 'Hide token' : 'Show token'}
                    >
                      {showToken ? <EyeOff size={16} /> : <Eye size={16} />}
                    </button>
                  </div>
                  {form.accessTokenSet ? (
                    <p className="field-hint">Saved token: {form.accessTokenMasked || '****'}</p>
                  ) : null}
                </div>
              </div>
              {links.metaDocs ? (
                <p className="wizard-note">
                  Need the values?{' '}
                  <a href={links.metaDocs} target="_blank" rel="noreferrer">
                    Meta Cloud API docs
                  </a>
                </p>
              ) : null}
            </>
          ) : null}

          {step === 3 ? (
            <>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>
                    Verify token<span className="req">*</span>
                  </h2>
                  <p>Paste the same string into Meta webhook configuration.</p>
                </div>
                <div className="wizard-auth-fields">
                  <div className="field-line">
                    <Shield size={18} aria-hidden />
                    <input
                      type="text"
                      value={form.webhookVerifyToken}
                      onChange={(e) => patch('webhookVerifyToken', e.target.value)}
                      placeholder="Webhook verify token"
                      autoComplete="off"
                      aria-label="Verify token"
                    />
                  </div>
                  <button
                    type="button"
                    className="wa-copy"
                    onClick={() => copyText('token', form.webhookVerifyToken)}
                  >
                    <Copy size={14} />
                    {copied === 'token' ? 'Copied' : 'Copy token'}
                  </button>
                </div>
              </div>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>Webhook URL</h2>
                  <p>Point Meta Callback URL here so inbound messages reach the bot.</p>
                </div>
                <div className="wizard-auth-fields">
                  <div className="setup-summary">
                    <code>{links.webhookUrl || '-'}</code>
                  </div>
                  <button
                    type="button"
                    className="wa-copy"
                    onClick={() => copyText('webhook', links.webhookUrl || '')}
                  >
                    <Copy size={14} />
                    {copied === 'webhook' ? 'Copied' : 'Copy webhook URL'}
                  </button>
                </div>
              </div>
              <div className="wizard-row">
                <div className="wizard-aside">
                  <h2>Auto-reply</h2>
                  <p>Optional reply when someone messages your business number.</p>
                </div>
                <div className="wizard-auth-fields">
                  <label className="same-pass">
                    <input
                      type="checkbox"
                      checked={Boolean(form.autoReplyEnabled)}
                      onChange={(e) => patch('autoReplyEnabled', e.target.checked)}
                    />
                    Enable auto-reply
                  </label>
                  <div className={`field-line field-line--textarea ${form.autoReplyEnabled ? '' : 'is-disabled'}`}>
                    <MessageCircle size={18} aria-hidden />
                    <textarea
                      rows={3}
                      value={form.autoReplyText}
                      onChange={(e) => patch('autoReplyText', e.target.value)}
                      disabled={!form.autoReplyEnabled}
                      placeholder="Auto-reply message"
                      aria-label="Auto-reply text"
                    />
                  </div>
                </div>
              </div>
              <div className="setup-summary">
                <strong>Ready to register</strong>
                <ul>
                  <li>Phone: {form.displayPhone || '-'}</li>
                  <li>Phone Number ID: {form.phoneNumberId || '-'}</li>
                  <li>
                    Token:{' '}
                    {form.accessToken ? 'new value' : form.accessTokenSet ? 'keep saved' : 'missing'}
                  </li>
                  <li>Group link: {form.groupLink ? 'set' : 'optional'}</li>
                </ul>
              </div>
            </>
          ) : null}

          <footer className="wizard-footer">
            <p className="wizard-note">Step {step} of 3 - same flow as mailbox registration.</p>
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
              {step < 3 ? (
                <button type="submit" className="wizard-btn-next">
                  Continue
                  <ArrowRight size={16} />
                </button>
              ) : (
                <button type="submit" className="wizard-btn-next" disabled={saving}>
                  {saving ? <Loader2 className="wa-spin" size={16} /> : <Check size={16} />}
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
