import { useEffect, useMemo, useState } from 'react'
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
      if (!form.displayPhone.trim()) return 'Enter the WhatsApp business phone number.'
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
      setForm({ ...emptyForm(), ...(next.form || {}), accessToken: '' })
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
                  <div className="field-line">
                    <Phone size={18} aria-hidden />
                    <input
                      type="tel"
                      value={form.displayPhone}
                      onChange={(e) => patch('displayPhone', e.target.value)}
                      placeholder="Phone number (+255 7XX XXX XXX)"
                      autoComplete="tel"
                      aria-label="Display phone"
                    />
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
