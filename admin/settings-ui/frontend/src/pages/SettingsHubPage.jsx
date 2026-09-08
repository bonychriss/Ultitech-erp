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
} from 'lucide-react'
import { executeFactoryReset, getSettingsCfg, saveSystemFont } from '../api/settingsHub.js'

const ICON_MAP = {
  building: Building2,
  type: Type,
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

function loadGoogleFont(googleFamily) {
  if (!googleFamily || typeof document === 'undefined') return
  const id = `ash-google-font-${googleFamily.replace(/\s+/g, '-')}`
  if (document.getElementById(id)) return
  const link = document.createElement('link')
  link.id = id
  link.rel = 'stylesheet'
  link.href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(googleFamily)}:wght@400;500;600;700&display=swap`
  document.head.appendChild(link)
}

export default function SettingsHubPage() {
  const cfg = useMemo(() => getSettingsCfg(), [])
  const [fontKey, setFontKey] = useState(cfg.font?.current || 'dm_sans')
  const [flash, setFlash] = useState(cfg.flash || null)
  const [fontBusy, setFontBusy] = useState(false)
  const [fontOpen, setFontOpen] = useState(false)
  const [resetOpen, setResetOpen] = useState(false)
  const [resetConfirm, setResetConfirm] = useState('')
  const [resetBusy, setResetBusy] = useState(false)
  const [resetPhase, setResetPhase] = useState('confirm') // confirm | cleaning | done
  const [resetMessage, setResetMessage] = useState('')
  const [registerOpen, setRegisterOpen] = useState(false)

  const catalog = cfg.font?.catalog || []
  const selectedFont = catalog.find((f) => f.id === fontKey) || {
    id: fontKey,
    label: cfg.font?.label || 'Poppins',
    stack: cfg.font?.stack || "'Poppins', sans-serif",
    google: '',
  }

  useEffect(() => {
    if (selectedFont.google) loadGoogleFont(selectedFont.google)
  }, [selectedFont.google])

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
            const content = (
              <article
                className={`ash-card${card.danger ? ' ash-card--danger' : ''}`}
                style={{ '--ash-accent': card.accent || '#2563eb' }}
              >
                <div className="ash-card-icon-wrap" aria-hidden="true">
                  <Icon className="ash-card-icon" strokeWidth={1.75} />
                </div>
                <div className="ash-card-body">
                  <div className="ash-card-label">
                    {card.title}
                    {card.badge ? <span className="ash-badge">{card.badge}</span> : null}
                  </div>
                  <div className="ash-card-value">{card.title}</div>
                  <div className="ash-card-helper">{card.description}</div>
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
              <div className="ash-font-preview" style={{ fontFamily: selectedFont.stack }}>
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
                  onClick={() => setFontOpen(false)}
                  disabled={fontBusy}
                >
                  Cancel
                </button>
                <button type="submit" className="ash-btn-primary" disabled={fontBusy}>
                  {fontBusy ? 'Saving…' : 'Apply font'}
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
