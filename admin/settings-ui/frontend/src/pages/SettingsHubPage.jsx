import { useEffect, useMemo, useState } from 'react'
import {
  Building2,
  CalendarCheck,
  Clock3,
  Mail,
  Network,
  PlusSquare,
  RefreshCw,
  Bot,
  UserPlus,
  Users,
  ShoppingCart,
  FileSpreadsheet,
  Trash2,
  MessageCircle,
  Type,
  Globe,
} from 'lucide-react'
import { executeFactoryReset, getSettingsCfg, saveSiteContact, saveSystemFont } from '../api/settingsHub.js'

const ICON_MAP = {
  building: Building2,
  type: Type,
  globe: Globe,
  'plus-square': PlusSquare,
  sitemap: Network,
  users: Users,
  sync: RefreshCw,
  robot: Bot,
  'user-plus': UserPlus,
  whatsapp: MessageCircle,
  clock: Clock3,
  'calendar-check': CalendarCheck,
  cart: ShoppingCart,
  invoice: FileSpreadsheet,
  mail: Mail,
  trash: Trash2,
}

const SITE_TEXT_SECTIONS = [
  {
    title: 'Page heading',
    fields: [
      { name: 'contact_eyebrow', label: 'Small label above the title' },
      { name: 'contact_title', label: 'Title' },
      { name: 'contact_lead', label: 'Introduction', long: true },
    ],
  },
  {
    title: 'Call card',
    fields: [
      { name: 'call_label', label: 'Card label' },
      { name: 'call_action', label: 'Button text' },
    ],
  },
  {
    title: 'WhatsApp card',
    fields: [
      { name: 'whatsapp_label', label: 'Card label' },
      { name: 'whatsapp_action', label: 'Button text' },
      { name: 'whatsapp_greeting', label: 'Message typed for the visitor when the chat opens', long: true },
    ],
  },
  {
    title: 'Email card',
    fields: [
      { name: 'email_label', label: 'Card label' },
      { name: 'email_action', label: 'Button text' },
      { name: 'email_subject', label: 'Email subject line', wide: true },
    ],
  },
  {
    title: 'Instagram card',
    fields: [
      { name: 'instagram_label', label: 'Card label' },
      { name: 'instagram_action', label: 'Button text' },
    ],
  },
  {
    title: 'Location and hours boxes',
    fields: [
      { name: 'location_label', label: 'Location label' },
      { name: 'hours_label', label: 'Opening hours label' },
    ],
  },
  {
    title: 'Free trial banner',
    fields: [
      { name: 'cta_title', label: 'Title' },
      { name: 'cta_button', label: 'Button text' },
      { name: 'cta_text', label: 'Text', long: true },
    ],
  },
  {
    title: 'Site footer',
    fields: [{ name: 'footer_tagline', label: 'Line under the UltiTech name (all public pages)', long: true }],
  },
]

function loadGoogleFont(google) {
  if (!google || typeof document === 'undefined') return

  const href = /^https?:\/\//i.test(String(google))
    ? String(google)
    : `https://fonts.googleapis.com/css2?family=${encodeURIComponent(String(google))}:wght@400;500;600;700&display=swap`

  const id = `ash-google-font-${href.replace(/[^a-zA-Z0-9]+/g, '-').slice(0, 120)}`
  if (document.getElementById(id)) return

  const link = document.createElement('link')
  link.id = id
  link.rel = 'stylesheet'
  link.href = href
  document.head.appendChild(link)
}

export default function SettingsHubPage() {
  const cfg = useMemo(() => getSettingsCfg(), [])
  const [fontKey, setFontKey] = useState(cfg.font?.current || 'dm_sans')
  const [flash, setFlash] = useState(cfg.flash || null)
  const [fontBusy, setFontBusy] = useState(false)
  const [fontOpen, setFontOpen] = useState(false)
  const [fontPreviewTick, setFontPreviewTick] = useState(0)
  const [resetOpen, setResetOpen] = useState(false)
  const [resetConfirm, setResetConfirm] = useState('')
  const [resetBusy, setResetBusy] = useState(false)
  const [resetPhase, setResetPhase] = useState('confirm') // confirm | cleaning | done
  const [resetMessage, setResetMessage] = useState('')
  const [registerOpen, setRegisterOpen] = useState(false)
  const [siteContact, setSiteContact] = useState(cfg.siteContact || {})
  const [contactForm, setContactForm] = useState(cfg.siteContact || {})
  const [contactOpen, setContactOpen] = useState(false)
  const [contactBusy, setContactBusy] = useState(false)
  const [contactError, setContactError] = useState('')
  const [contactTab, setContactTab] = useState('details')
  const [siteTexts, setSiteTexts] = useState(cfg.siteTexts?.values || {})
  const [textForm, setTextForm] = useState(cfg.siteTexts?.values || {})

  const catalog = cfg.font?.catalog || []
  const selectedFont = catalog.find((f) => f.id === fontKey) || {
    id: fontKey,
    label: cfg.font?.label || 'Poppins',
    stack: cfg.font?.stack || "'Poppins', sans-serif",
    google: '',
  }

  useEffect(() => {
    if (selectedFont.google) loadGoogleFont(selectedFont.google)
  }, [selectedFont.google, selectedFont.id])

  useEffect(() => {
    if (!fontOpen || !selectedFont.stack) return undefined
    const family = String(selectedFont.stack).split(',')[0].replace(/['"]/g, '').trim()
    if (!family || family === 'system-ui') return undefined
    let cancelled = false
    const refresh = () => {
      if (!cancelled) setFontPreviewTick((n) => n + 1)
    }
    if (document.fonts?.load) {
      document.fonts.load(`400 16px "${family}"`).then(refresh).catch(() => {})
      document.fonts.load(`700 16px "${family}"`).then(refresh).catch(() => {})
    }
    const t = window.setTimeout(refresh, 350)
    return () => {
      cancelled = true
      window.clearTimeout(t)
    }
  }, [fontOpen, selectedFont.stack, selectedFont.id])

  useEffect(() => {
    if (!flash) return undefined
    const t = setTimeout(() => setFlash(null), 5000)
    return () => clearTimeout(t)
  }, [flash])

  const onApplyFont = async (e) => {
    e.preventDefault()
    setFontBusy(true)
    try {
      const data = await saveSystemFont(cfg.api?.saveFont, fontKey)
      setFlash({ type: 'success', message: data.message || 'System font updated successfully.' })
      if (data.font?.stack) {
        document.documentElement.style.setProperty('--erp-font-family', data.font.stack)
      }
      setFontOpen(false)
    } catch (err) {
      setFlash({ type: 'error', message: err.message || 'Could not save font.' })
    } finally {
      setFontBusy(false)
    }
  }

  const onFactoryReset = async (e) => {
    e.preventDefault()
    setResetBusy(true)
    setResetPhase('cleaning')
    try {
      const data = await executeFactoryReset(cfg.api?.factoryReset, resetConfirm)
      setResetMessage(data.message || 'Factory reset completed.')
      setResetPhase('done')
      setFlash({ type: 'success', message: data.message })
    } catch (err) {
      setResetPhase('confirm')
      setFlash({ type: 'error', message: err.message || 'Factory reset failed.' })
    } finally {
      setResetBusy(false)
    }
  }

  const openContact = () => {
    setContactForm(siteContact)
    setTextForm(siteTexts)
    setContactTab('details')
    setContactError('')
    setContactOpen(true)
  }

  const onSaveContact = async (e) => {
    e.preventDefault()
    setContactBusy(true)
    setContactError('')
    try {
      const data = await saveSiteContact(cfg.api?.saveSiteContact, { ...contactForm, texts: textForm })
      setSiteContact(data.contact || contactForm)
      setSiteTexts(data.texts || textForm)
      setFlash({ type: 'success', message: data.message || 'Contact page updated.' })
      setContactOpen(false)
    } catch (err) {
      setContactError(err.message || 'Could not save contact details.')
    } finally {
      setContactBusy(false)
    }
  }

  const contactField = (name) => ({
    name,
    value: contactForm[name] ?? '',
    onChange: (e) => setContactForm((f) => ({ ...f, [name]: e.target.value })),
  })

  const textField = (name) => ({
    id: `ash-text-${name}`,
    name,
    value: textForm[name] ?? '',
    placeholder: cfg.siteTexts?.defaults?.[name] || '',
    maxLength: cfg.siteTexts?.max?.[name] || undefined,
    onChange: (e) => setTextForm((f) => ({ ...f, [name]: e.target.value })),
  })

  const closeReset = () => {
    if (resetBusy) return
    setResetOpen(false)
    setResetConfirm('')
    setResetPhase('confirm')
    setResetMessage('')
  }

  return (
    <div className="ash-page">
      <header className="ash-sticky">
        <div className="ash-sticky-meta">
          <span>{cfg.todayLabel || ''}</span>
          <span className="ash-meta-sep">|</span>
          <span>System configuration - {cfg.companyName || 'Company'}</span>
        </div>
      </header>

      <div className="ash-body">
        {flash ? (
          <div className={`ash-alert ash-alert--${flash.type === 'error' ? 'error' : 'success'}`} role="status">
            {flash.message}
            <button type="button" className="ash-alert-close" onClick={() => setFlash(null)} aria-label="Dismiss">
              x
            </button>
          </div>
        ) : null}

        <p className="ash-lead">Manage system configurations across departments.</p>

        <div className="ash-grid">
          {(cfg.cards || []).map((card) => {
            const Icon = ICON_MAP[card.icon] || Building2
            const isReset = card.action === 'factory_reset'
            const isRegister = card.action === 'register_company'
            const isFont = card.action === 'system_font'
            const isContact = card.action === 'site_contact'
            const content = (
              <article
                className={`ash-card${card.danger ? ' ash-card--danger' : ''}`}
                style={{ '--ash-accent': card.accent || '#2563eb' }}
              >
                <div className="ash-card-icon-wrap" aria-hidden="true">
                  <Icon className="ash-card-icon" strokeWidth={1.75} />
                </div>
                <div className="ash-card-body">
                  <div className="ash-card-value">
                    {card.title}
                    {card.badge ? <span className="ash-badge">{card.badge}</span> : null}
                  </div>
                </div>
              </article>
            )

            if (isFont) {
              return (
                <button
                  key={card.id}
                  type="button"
                  className="ash-card-link"
                  onClick={() => setFontOpen(true)}
                >
                  {content}
                </button>
              )
            }

            if (isContact) {
              return (
                <button key={card.id} type="button" className="ash-card-link" onClick={openContact}>
                  {content}
                </button>
              )
            }

            if (isReset) {
              return (
                <button
                  key={card.id}
                  type="button"
                  className="ash-card-link"
                  onClick={() => {
                    setResetOpen(true)
                    setResetPhase('confirm')
                    setResetConfirm('')
                  }}
                >
                  {content}
                </button>
              )
            }

            if (isRegister) {
              return (
                <button
                  key={card.id}
                  type="button"
                  className="ash-card-link"
                  onClick={() => setRegisterOpen(true)}
                >
                  {content}
                </button>
              )
            }

            return (
              <a key={card.id} href={card.href} className="ash-card-link">
                {content}
              </a>
            )
          })}
        </div>
      </div>

      {fontOpen ? (
        <div
          className="ash-modal-backdrop"
          role="presentation"
          onClick={() => !fontBusy && setFontOpen(false)}
        >
          <div
            className="ash-modal ash-modal--font"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ash-font-title"
            onClick={(e) => e.stopPropagation()}
          >
            <h2 id="ash-font-title">System font</h2>
            <p className="ash-reset-lead">
              Applies across modules, sidebars, forms, and dashboards for this company.
            </p>
            <form className="ash-font-form" onSubmit={onApplyFont}>
              <label className="ash-label" htmlFor="ash-system-font">
                Font family
              </label>
              <div className="ash-font-row">
                <select
                  id="ash-system-font"
                  className="ash-select"
                  value={fontKey}
                  onChange={(e) => setFontKey(e.target.value)}
                  autoFocus
                >
                  {catalog.map((font) => (
                    <option key={font.id} value={font.id}>
                      {font.label}
                    </option>
                  ))}
                </select>
              </div>
              <div
                key={`${selectedFont.id}-${fontPreviewTick}`}
                className="ash-font-preview"
                style={{ fontFamily: selectedFont.stack }}
              >
                <p className="ash-font-preview-title">Preview - {selectedFont.label}</p>
                <p className="ash-font-preview-body">
                  The quick brown fox jumps over the lazy dog. 0123456789 - Payment voucher #1042 -
                  Jumatano, 28 May 2026
                </p>
              </div>
              <div className="ash-modal-actions">
                <button
                  type="button"
                  className="ash-btn-ghost"
                  style={{ borderRadius: 9999 }}
                  onClick={() => setFontOpen(false)}
                  disabled={fontBusy}
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="ash-btn-primary"
                  style={{ borderRadius: 9999 }}
                  disabled={fontBusy}
                >
                  {fontBusy ? 'Saving…' : 'Apply font'}
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}

      {contactOpen ? (
        <div
          className="ash-modal-backdrop"
          role="presentation"
          onClick={() => !contactBusy && setContactOpen(false)}
        >
          <div
            className="ash-modal ash-modal--contact"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ash-contact-title"
            onClick={(e) => e.stopPropagation()}
          >
            <h2 id="ash-contact-title">Website contact page</h2>
            <p className="ash-reset-lead">
              Edit the ultitech.io Contact page. Contact details also appear on the About page, the site footer and in
              Google search details.
            </p>
            <div className="ash-contact-tabs" role="tablist">
              <button
                type="button"
                role="tab"
                aria-selected={contactTab === 'details'}
                className={`ash-contact-tab${contactTab === 'details' ? ' is-active' : ''}`}
                onClick={() => setContactTab('details')}
              >
                Contact details
              </button>
              <button
                type="button"
                role="tab"
                aria-selected={contactTab === 'text'}
                className={`ash-contact-tab${contactTab === 'text' ? ' is-active' : ''}`}
                onClick={() => setContactTab('text')}
              >
                Page text
              </button>
            </div>
            <form onSubmit={onSaveContact}>
              {contactTab === 'text' ? (
                <div className="ash-text-sections">
                  {SITE_TEXT_SECTIONS.map((section) => (
                    <fieldset key={section.title} className="ash-text-section">
                      <legend>{section.title}</legend>
                      <div className="ash-contact-grid">
                        {section.fields.map((field) => (
                          <div
                            key={field.name}
                            className={field.long || field.wide ? 'ash-contact-field--wide' : undefined}
                          >
                            <label className="ash-label" htmlFor={`ash-text-${field.name}`}>
                              {field.label}
                            </label>
                            {field.long ? (
                              <textarea className="ash-input ash-textarea" rows={2} {...textField(field.name)} />
                            ) : (
                              <input className="ash-input" {...textField(field.name)} />
                            )}
                          </div>
                        ))}
                      </div>
                    </fieldset>
                  ))}
                  <p className="ash-contact-hint">An empty field goes back to the default wording when you save.</p>
                </div>
              ) : (
              <div className="ash-contact-grid">
                <div>
                  <label className="ash-label" htmlFor="ash-contact-phone">
                    Phone number
                  </label>
                  <input
                    id="ash-contact-phone"
                    className="ash-input"
                    type="tel"
                    placeholder="0785653817"
                    required
                    autoFocus
                    {...contactField('phone')}
                  />
                </div>
                <div>
                  <label className="ash-label" htmlFor="ash-contact-whatsapp">
                    WhatsApp number
                  </label>
                  <input
                    id="ash-contact-whatsapp"
                    className="ash-input"
                    type="tel"
                    placeholder="Same as phone"
                    {...contactField('whatsapp')}
                  />
                  <p className="ash-contact-hint">Leave empty to use the phone number.</p>
                </div>
                <div className="ash-contact-field--wide">
                  <label className="ash-label" htmlFor="ash-contact-email">
                    Email
                  </label>
                  <input
                    id="ash-contact-email"
                    className="ash-input"
                    type="email"
                    placeholder="name@example.com"
                    required
                    {...contactField('email')}
                  />
                </div>
                <div>
                  <label className="ash-label" htmlFor="ash-contact-hours">
                    Opening hours
                  </label>
                  <input
                    id="ash-contact-hours"
                    className="ash-input"
                    maxLength={120}
                    placeholder="8:00 AM - 5:00 PM"
                    {...contactField('hours')}
                  />
                </div>
                <div>
                  <label className="ash-label" htmlFor="ash-contact-address">
                    Location
                  </label>
                  <input
                    id="ash-contact-address"
                    className="ash-input"
                    maxLength={120}
                    placeholder="Dar es Salaam, Tanzania"
                    {...contactField('address')}
                  />
                </div>
                <div className="ash-contact-field--wide">
                  <label className="ash-label" htmlFor="ash-contact-instagram">
                    Instagram
                  </label>
                  <input
                    id="ash-contact-instagram"
                    className="ash-input"
                    placeholder="official_ace84 or profile link"
                    {...contactField('instagram')}
                  />
                  <p className="ash-contact-hint">Leave a field empty to hide it on the website.</p>
                </div>
              </div>
              )}
              {contactError ? (
                <p className="ash-contact-error" role="alert">
                  {contactError}
                </p>
              ) : null}
              <div className="ash-modal-actions">
                {contactTab === 'text' ? (
                  <button
                    type="button"
                    className="ash-btn-ghost ash-btn-left"
                    onClick={() => setTextForm(cfg.siteTexts?.defaults || {})}
                    disabled={contactBusy}
                  >
                    Restore default text
                  </button>
                ) : null}
                {cfg.siteContactPageUrl ? (
                  <a className="ash-btn-ghost" href={cfg.siteContactPageUrl} target="_blank" rel="noopener noreferrer">
                    View page
                  </a>
                ) : null}
                <button
                  type="button"
                  className="ash-btn-ghost"
                  onClick={() => setContactOpen(false)}
                  disabled={contactBusy}
                >
                  Cancel
                </button>
                <button type="submit" className="ash-btn-primary" disabled={contactBusy}>
                  {contactBusy ? 'Saving\u2026' : 'Save changes'}
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}

      {registerOpen ? (
        <div
          className="ash-modal-backdrop"
          role="presentation"
          onClick={() => setRegisterOpen(false)}
        >
          <div
            className="ash-modal ash-modal--register"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ash-register-title"
            onClick={(e) => e.stopPropagation()}
          >
            <h2 id="ash-register-title">Register New Company</h2>
            <p className="ash-reset-lead">Create a company tenant with default module setup.</p>
            <form
              method="post"
              action={cfg.registerCompany?.formAction || 'management.php'}
              className="ash-register-form"
            >
              <input type="hidden" name="create_company" value="1" />
              <input
                type="hidden"
                name="return_to"
                value={cfg.registerCompany?.returnTo || 'settings.php'}
              />

              <label className="ash-label" htmlFor="ash-company-name">
                Company Name
              </label>
              <input
                id="ash-company-name"
                name="company_name"
                className="ash-input"
                placeholder="e.g. Acme Corp"
                required
                autoFocus
              />

              <label className="ash-label" htmlFor="ash-subdomain">
                Subdomain
              </label>
              <input
                id="ash-subdomain"
                name="subdomain"
                className="ash-input"
                placeholder="e.g. acme"
              />

              <label className="ash-label" htmlFor="ash-db-name">
                Database
              </label>
              <input
                id="ash-db-name"
                name="db_name"
                className="ash-input"
                placeholder="e.g. acme_db"
              />

              <label className="ash-label" htmlFor="ash-currency">
                Currency
              </label>
              <select id="ash-currency" name="base_currency" className="ash-select" defaultValue="TZS">
                <option value="TZS">TZS</option>
                <option value="USD">USD</option>
              </select>

              <label className="ash-label" htmlFor="ash-timezone">
                Timezone
              </label>
              <select
                id="ash-timezone"
                name="timezone"
                className="ash-select"
                defaultValue="Africa/Dar_es_Salaam"
              >
                <option value="Africa/Dar_es_Salaam">Tanzania</option>
                <option value="UTC">UTC</option>
              </select>

              <label className="ash-label" htmlFor="ash-industry">
                Industry
              </label>
              <select id="ash-industry" name="industry_type" className="ash-select" defaultValue="trading">
                <option value="trading">Trading</option>
                <option value="logistics">Logistics</option>
              </select>

              <div className="ash-modal-actions">
                <button type="button" className="ash-btn-ghost" onClick={() => setRegisterOpen(false)}>
                  Cancel
                </button>
                {cfg.registerCompany?.companiesUrl ? (
                  <a className="ash-btn-ghost" href={cfg.registerCompany.companiesUrl}>
                    View companies
                  </a>
                ) : null}
                <button type="submit" className="ash-btn-primary">
                  Create Instance
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}

      {resetOpen ? (
        <div className="ash-modal-backdrop" role="presentation" onClick={closeReset}>
          <div
            className="ash-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ash-reset-title"
            onClick={(e) => e.stopPropagation()}
          >
            {resetPhase === 'cleaning' ? (
              <div className="ash-reset-cleaning">
                <div className="ash-cleaning-ring" aria-hidden="true" />
                <p className="ash-cleaning-title">Cleaning company data…</p>
                <p className="ash-cleaning-sub">Please wait. Do not close this window.</p>
              </div>
            ) : null}

            {resetPhase === 'done' ? (
              <div className="ash-reset-done">
                <h2 id="ash-reset-title">Reset complete</h2>
                <p>{resetMessage}</p>
                <button type="button" className="ash-btn-primary" onClick={closeReset}>
                  Close
                </button>
              </div>
            ) : null}

            {resetPhase === 'confirm' ? (
              <form onSubmit={onFactoryReset}>
                <h2 id="ash-reset-title" className="ash-reset-title">
                  DANGER: Factory Reset
                </h2>
                <p className="ash-reset-lead">
                  You are about to perform a Factory Reset for <strong>{cfg.companyName || 'this company'}</strong>.
                  This is an irreversible operation that will physically wipe all operating data.
                </p>
                <div className="ash-reset-box">
                  <div className="ash-reset-box-title danger">What will be permanently deleted:</div>
                  <ul>
                    <li>All payment vouchers and line items</li>
                    <li>All sales orders, quotations, and invoices</li>
                    <li>All inventory categories, stock logs, and products</li>
                    <li>All financial account ledgers and expense rows</li>
                    <li>All attendance check-in/out records</li>
                    <li>All physically uploaded images, invoices, and vouchers</li>
                  </ul>
                  <div className="ash-reset-box-title keep">What will be kept:</div>
                  <ul>
                    <li>Your administrator login accounts and user settings</li>
                    <li>Your company profile settings, colors, and module options</li>
                  </ul>
                </div>
                <label className="ash-label" htmlFor="ash-confirm-reset">
                  To confirm, type exactly <span className="ash-mono">RESET</span> below:
                </label>
                <input
                  id="ash-confirm-reset"
                  className="ash-input"
                  value={resetConfirm}
                  onChange={(e) => setResetConfirm(e.target.value)}
                  placeholder="RESET"
                  autoComplete="off"
                  required
                />
                <div className="ash-modal-actions">
                  <button type="button" className="ash-btn-ghost" onClick={closeReset}>
                    Cancel
                  </button>
                  <button type="submit" className="ash-btn-danger" disabled={resetBusy}>
                    Yes, Delete All Data
                  </button>
                </div>
              </form>
            ) : null}
          </div>
        </div>
      ) : null}
    </div>
  )
}
