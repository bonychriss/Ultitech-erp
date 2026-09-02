import { useEffect, useMemo, useState } from 'react'
import {
  BookOpen,
  Boxes,
  ChartColumn,
  ClipboardCheck,
  Coins,
  FileText,
  HardDrive,
  Inbox,
  Lightbulb,
  ListChecks,
  Mail,
  Monitor,
  Download,
  Palette,
  PieChart,
  Receipt,
  Scale,
  Send,
  Server,
  Settings,
  Truck,
  Wallet,
  Warehouse,
  Banknote,
  Star,
} from 'lucide-react'

function getConfig() {
  if (typeof window !== 'undefined' && window.__SELECT_MODULE_CFG__ && typeof window.__SELECT_MODULE_CFG__ === 'object') {
    return window.__SELECT_MODULE_CFG__
  }
  return {}
}

function storageKey(kind, version, userId) {
  return `ultitech_mail_update_${kind}_${version || 'v'}_${userId || 0}`
}

function readFlag(kind, version, userId) {
  try {
    return localStorage.getItem(storageKey(kind, version, userId)) === '1'
  } catch {
    return false
  }
}

function writeFlag(kind, version, userId) {
  try {
    localStorage.setItem(storageKey(kind, version, userId), '1')
  } catch {
    /* ignore */
  }
}

function PerformanceIcon() {
  return (
    <svg className="sm-icon sm-icon--performance" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <rect x="5" y="29" width="7" height="11" rx="1.5" fill="#ef4444" stroke="#0f172a" strokeWidth="1.6" />
      <rect x="14" y="24" width="7" height="16" rx="1.5" fill="#eab308" stroke="#0f172a" strokeWidth="1.6" />
      <rect x="23" y="17" width="7" height="23" rx="1.5" fill="#22c55e" stroke="#0f172a" strokeWidth="1.6" />
      <rect x="32" y="9" width="7" height="31" rx="1.5" fill="#3b82f6" stroke="#0f172a" strokeWidth="1.6" />
      <path d="M7 24 L18 16 L33 9" fill="none" stroke="#ef4444" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
      <path d="M29 9 L33 9 L33 13" fill="none" stroke="#ef4444" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
      <circle cx="38" cy="11" r="7.5" fill="#22c55e" stroke="#0f172a" strokeWidth="1.6" />
      <path d="M35.2 11 L37.4 13.4 L41.2 8.8" fill="none" stroke="#0f172a" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" />
      <circle cx="36" cy="36" r="6" fill="#eab308" stroke="#0f172a" strokeWidth="1.6" />
      <path d="M36 30.5v2.2M36 39.3v2.2M30.5 36h2.2M39.3 36h2.2M31.9 31.9l1.6 1.6M38.5 38.5l1.6 1.6M31.9 40.1l1.6-1.6M38.5 33.5l1.6-1.6" stroke="#0f172a" strokeWidth="1.8" strokeLinecap="round" />
      <circle cx="36" cy="36" r="2.2" fill="#fef08a" stroke="#0f172a" strokeWidth="1.2" />
    </svg>
  )
}

function SalesIcon() {
  return (
    <svg className="sm-icon sm-icon--sales" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <line x1="3" y1="16" x2="12" y2="16" stroke="currentColor" strokeWidth="2.8" strokeLinecap="round" />
      <line x1="3" y1="23" x2="9" y2="23" stroke="currentColor" strokeWidth="2.8" strokeLinecap="round" />
      <line x1="3" y1="30" x2="14" y2="30" stroke="currentColor" strokeWidth="2.8" strokeLinecap="round" />
      <path
        d="M16 13.5 L40 13.5 L36.5 26.5 L19.5 26.5 Z"
        fill="none"
        stroke="currentColor"
        strokeWidth="2.6"
        strokeLinejoin="round"
      />
      <line x1="18" y1="17.5" x2="38" y2="17.5" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
      <line x1="18.8" y1="21" x2="37.2" y2="21" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
      <line x1="23" y1="13.8" x2="21.2" y2="26.5" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
      <line x1="27.5" y1="13.7" x2="26.2" y2="26.5" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
      <line x1="32" y1="13.6" x2="31.2" y2="26.5" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
      <path
        d="M16 13.5 C13 13.5 9.5 10.5 7 8.5"
        fill="none"
        stroke="currentColor"
        strokeWidth="2.8"
        strokeLinecap="round"
      />
      <path
        d="M19.5 26.5 L18 34.5 M18 34.5 L36 34.5"
        fill="none"
        stroke="currentColor"
        strokeWidth="2.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <circle cx="18" cy="37.5" r="3.2" fill="currentColor" />
      <circle cx="36" cy="37.5" r="3.2" fill="currentColor" />
    </svg>
  )
}

const ICONS = {
  voucher: FileText,
  attendance: ClipboardCheck,
  deliveries: Truck,
  outstanding: FileText,
  email: Mail,
  expenses: Receipt,
  petty_cash: Wallet,
  payroll: Banknote,
  revenue: Coins,
  accounting: BookOpen,
  balances: Scale,
  budgets: ChartColumn,
  stock: Boxes,
  warehouses: Warehouse,
  sales: SalesIcon,
  statement: FileText,
  dispatch: Truck,
  todo: ListChecks,
  performance: PerformanceIcon,
  settings_admin: Monitor,
  settings: Settings,
  suggestions: Lightbulb,
  analytics: PieChart,
  backup: HardDrive,
  inbox: Inbox,
  letter: Send,
  layout: Palette,
}

function ModuleIcon({ name, color }) {
  if (name === 'performance') {
    return (
      <div className="sm-icon-box">
        <PerformanceIcon />
      </div>
    )
  }
  if (name === 'sales') {
    return (
      <div className="sm-icon-box" style={{ color }}>
        <SalesIcon />
      </div>
    )
  }
  const Icon = ICONS[name] || FileText
  return (
    <div className="sm-icon-box" style={{ color }}>
      <Icon className="sm-icon" strokeWidth={1.25} aria-hidden="true" />
    </div>
  )
}

function MailGuidelineModal({ open, campaign, onDismiss, onReview }) {
  if (!open || !campaign) return null
  const guide = campaign.guideline || {}

  return (
    <div className="sm-modal-backdrop sm-modal-backdrop--guide" role="presentation" onClick={onDismiss}>
      <div
        className="sm-modal sm-modal--guide"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sm-mail-guide-title"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="sm-guide-orb" aria-hidden="true">
          <span className="sm-guide-orb-ring" />
          <span className="sm-guide-orb-ring sm-guide-orb-ring--delay" />
          <Mail className="sm-guide-orb-icon" strokeWidth={2} />
        </div>
        <div className="sm-modal-kicker">New update</div>
        <h2 id="sm-mail-guide-title" className="sm-modal-title">{guide.title || 'Mail app update'}</h2>
        <p className="sm-modal-body">{guide.body || 'Take a quick look at the latest Mail improvements.'}</p>
        <div className="sm-modal-actions sm-modal-actions--stack">
          <button type="button" className="sm-modal-btn sm-modal-btn--primary" onClick={onReview}>
            {guide.cta || 'Review Mail'}
          </button>
          <button type="button" className="sm-modal-btn sm-modal-btn--ghost" onClick={onDismiss}>
            {guide.dismiss || 'Maybe later'}
          </button>
        </div>
      </div>
    </div>
  )
}

function MailRatingModal({ open, campaign, onSkip, onSubmitted }) {
  const [rating, setRating] = useState(0)
  const [hover, setHover] = useState(0)
  const [comment, setComment] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  if (!open || !campaign) return null
  const rate = campaign.rating || {}

  const submit = async () => {
    if (rating < 1) {
      setError('Choose a star rating first.')
      return
    }
    setBusy(true)
    setError('')
    try {
      const res = await fetch(campaign.rateApi || '', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          rating,
          comment: comment.trim(),
          version: campaign.version,
        }),
      })
      const json = await res.json().catch(() => null)
      if (!json || json.status !== 'success') {
        setError((json && json.message) || 'Could not save rating.')
        return
      }
      onSubmitted()
    } catch (e) {
      setError(e.message || 'Network error')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="sm-modal-backdrop sm-modal-backdrop--rate" role="presentation">
      <div
        className="sm-modal sm-modal--rate"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sm-mail-rate-title"
      >
        <div className="sm-modal-kicker">Quick feedback</div>
        <h2 id="sm-mail-rate-title" className="sm-modal-title">
          {rate.title || 'How was the Mail update?'}
        </h2>
        <p className="sm-modal-body">{rate.body}</p>
        <div className="sm-stars" role="group" aria-label="Star rating">
          {[1, 2, 3, 4, 5].map((n) => {
            const active = (hover || rating) >= n
            return (
              <button
                key={n}
                type="button"
                className={`sm-star${active ? ' is-active' : ''}`}
                aria-label={`${n} star${n === 1 ? '' : 's'}`}
                aria-pressed={rating === n}
                disabled={busy}
                onMouseEnter={() => setHover(n)}
                onMouseLeave={() => setHover(0)}
                onFocus={() => setHover(n)}
                onBlur={() => setHover(0)}
                onClick={() => setRating(n)}
              >
                <Star
                  className="sm-star-icon"
                  strokeWidth={1.75}
                  fill={active ? 'currentColor' : 'none'}
                  aria-hidden="true"
                />
              </button>
            )
          })}
        </div>
        <textarea
          className="sm-modal-textarea"
          rows={3}
          placeholder="Optional comment"
          value={comment}
          disabled={busy}
          onChange={(e) => setComment(e.target.value)}
        />
        {error ? <p className="sm-modal-error">{error}</p> : null}
        <div className="sm-modal-actions sm-modal-actions--rate">
          <button type="button" className="sm-modal-btn sm-modal-btn--ghost" onClick={onSkip} disabled={busy}>
            {rate.skip || 'Not now'}
          </button>
          <button type="button" className="sm-modal-btn sm-modal-btn--primary" onClick={submit} disabled={busy}>
            {busy ? 'Saving\u2026' : rate.submit || 'Submit rating'}
          </button>
        </div>
      </div>
    </div>
  )
}

export default function SelectModulePage() {
  const cfg = getConfig()
  const companyName = cfg.companyName || 'Company'
  const logoUrl = cfg.logoUrl || ''
  const homeUrl = cfg.homeUrl || '#'
  const logoutUrl = cfg.logoutUrl || 'logout.php'
  const statusUrl = cfg.statusUrl || ''
  const showStatus = Boolean(cfg.showStatus)
  const enabledLabels = Array.isArray(cfg.enabledModuleLabels) ? cfg.enabledModuleLabels : []
  const modules = Array.isArray(cfg.modules) ? cfg.modules : []
  const mailUpdate = cfg.mailUpdate && typeof cfg.mailUpdate === 'object' ? cfg.mailUpdate : null
  const desktopAppDownloadUrl = cfg.desktopAppDownloadUrl || ''
  const showDesktopAppDownload = Boolean(cfg.showDesktopAppDownload && desktopAppDownloadUrl)
  const enabledPreview = enabledLabels.slice(0, 4).join(', ')
  const enabledMore = enabledLabels.length > 4

  const version = mailUpdate?.version || ''
  const userId = mailUpdate?.userId || 0

  const [showGuide, setShowGuide] = useState(false)
  const [showRate, setShowRate] = useState(false)
  const [rateDone, setRateDone] = useState(false)

  useEffect(() => {
    if (!mailUpdate?.active) return
    const rated = readFlag('rated', version, userId)
    const guided = readFlag('guideline', version, userId)
    const askRate = Boolean(mailUpdate?.rating?.ask) && !rated

    if (askRate) {
      setShowRate(true)
      setShowGuide(false)
      return
    }
    if (!guided && !rated) {
      setShowGuide(true)
    }
  }, [mailUpdate, version, userId])

  useEffect(() => {
    if (!rateDone) return undefined
    const t = window.setTimeout(() => setRateDone(false), 3200)
    return () => window.clearTimeout(t)
  }, [rateDone])

  const mailHref = useMemo(() => {
    if (mailUpdate?.mailHref) return mailUpdate.mailHref
    const mailMod = modules.find((m) => m.id === 'email')
    return mailMod?.href || '#'
  }, [mailUpdate, modules])

  const dismissGuide = () => {
    writeFlag('guideline', version, userId)
    setShowGuide(false)
  }

  const reviewMail = () => {
    writeFlag('guideline', version, userId)
    setShowGuide(false)
    window.location.href = mailHref
  }

  const skipRate = () => {
    writeFlag('rated', version, userId)
    setShowRate(false)
    setRateDone(true)
  }

  const onRated = () => {
    writeFlag('rated', version, userId)
    writeFlag('guideline', version, userId)
    setShowRate(false)
    setRateDone(true)
  }

  return (
    <div className="sm-page">
      <header className="sm-topbar">
        <a href={homeUrl} className="sm-logo-link" title={companyName}>
          {logoUrl ? (
            <img
              src={logoUrl}
              alt={companyName}
              className="sm-logo-img"
              onError={(e) => {
                e.currentTarget.remove()
              }}
            />
          ) : null}
          <span className="sm-logo-name">{companyName}</span>
        </a>

        <div className="sm-topbar-right">
          <span className="sm-chip">
            <strong>{companyName}</strong>
            {enabledPreview ? ` ? ${enabledPreview}${enabledMore ? '...' : ''}` : null}
          </span>
          {showStatus && statusUrl ? (
            <a href={statusUrl} className="sm-status-btn">
              <Server size={10} aria-hidden="true" /> Status
            </a>
          ) : null}
          <a href={logoutUrl} className="sm-logout">Log Out</a>
        </div>
      </header>

      <main className="sm-container">
        <div className="sm-grid">
          {modules.map((mod) => (
            <a
              key={mod.id}
              href={mod.href}
              className="sm-card"
              style={{ '--module-glow-color': mod.color || '#111' }}
              onClick={() => {
                if (mod.id === 'email' && mailUpdate?.active) {
                  writeFlag('guideline', version, userId)
                }
              }}
            >
              {mod.badge ? (
                <span className={`sm-badge${mod.id === 'email' ? ' sm-badge--mail' : ''}`} title="Recently updated">
                  {mod.badge}
                </span>
              ) : null}
              <ModuleIcon name={mod.icon} color={mod.color || '#111'} />
              <span className="sm-label">{mod.label}</span>
              <span className="sm-desc">{mod.desc}</span>
            </a>
          ))}
        </div>
      </main>

      {rateDone ? (
        <div className="sm-toast" role="status">
          Thanks. Your Mail feedback was saved.
        </div>
      ) : null}

      <MailGuidelineModal
        open={showGuide}
        campaign={mailUpdate}
        onDismiss={dismissGuide}
        onReview={reviewMail}
      />
      <MailRatingModal
        open={showRate}
        campaign={mailUpdate}
        onSkip={skipRate}
        onSubmitted={onRated}
      />

      {showDesktopAppDownload ? (
        <a
          href={desktopAppDownloadUrl}
          className="sm-desktop-download"
          title="Download UltiTech ERP for Windows"
        >
          <Monitor className="sm-desktop-download-icon" strokeWidth={1.75} aria-hidden="true" />
          <span className="sm-desktop-download-text">Desktop app</span>
          <Download className="sm-desktop-download-arrow" strokeWidth={2} aria-hidden="true" />
        </a>
      ) : null}
    </div>
  )
}
