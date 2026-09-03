import { useEffect, useMemo, useState } from 'react'
import {
  Coins,
  FileText,
  Inbox,
  Mail,
  Monitor,
  Download,
  Palette,
  Receipt,
  Server,
  Star,
} from 'lucide-react'
import crmIconSrc from '../assets/crm-icon.png'
import storeIconSrc from '../assets/store-icon.png'
import stockIconSrc from '../assets/stock-icon.png'
import salesIconSrc from '../assets/sales-icon.png'
import accountingIconSrc from '../assets/accounting-icon.png'
import payrollIconSrc from '../assets/payroll-icon.png'
import pettyCashIconSrc from '../assets/petty-cash-icon.png'
import mailIconSrc from '../assets/mail-icon.png'
import outstandingIconSrc from '../assets/outstanding-icon.png'
import deliveryIconSrc from '../assets/delivery-icon.png'
import attendanceIconSrc from '../assets/attendance-icon.png'
import budgetsIconSrc from '../assets/budgets-icon.png'
import statementIconSrc from '../assets/statement-icon.png'
import dispatchIconSrc from '../assets/dispatch-icon.png'
import todoIconSrc from '../assets/todo-icon.png'
import settingsIconSrc from '../assets/settings-icon.png'
import backupIconSrc from '../assets/backup-icon.png'
import suggestionIconSrc from '../assets/suggestion-icon.png'
import reportIconSrc from '../assets/report-icon.png'
import letterIconSrc from '../assets/letter-icon.png'
import performanceIconSrc from '../assets/performance-icon.png'

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
    <img
      src={performanceIconSrc}
      alt=""
      className="sm-icon sm-icon--performance"
      aria-hidden="true"
    />
  )
}

function SalesIcon() {
  return (
    <img
      src={salesIconSrc}
      alt=""
      className="sm-icon sm-icon--sales"
      aria-hidden="true"
    />
  )
}

function CrmIcon() {
  return (
    <img
      src={crmIconSrc}
      alt=""
      className="sm-icon sm-icon--crm"
      aria-hidden="true"
    />
  )
}

function StoreIcon() {
  return (
    <img
      src={storeIconSrc}
      alt=""
      className="sm-icon sm-icon--store"
      aria-hidden="true"
    />
  )
}

function StockIcon() {
  return (
    <img
      src={stockIconSrc}
      alt=""
      className="sm-icon sm-icon--stock"
      aria-hidden="true"
    />
  )
}

function AccountingIcon() {
  return (
    <img
      src={accountingIconSrc}
      alt=""
      className="sm-icon sm-icon--accounting"
      aria-hidden="true"
    />
  )
}

function PayrollIcon() {
  return (
    <img
      src={payrollIconSrc}
      alt=""
      className="sm-icon sm-icon--payroll"
      aria-hidden="true"
    />
  )
}

function PettyCashIcon() {
  return (
    <img
      src={pettyCashIconSrc}
      alt=""
      className="sm-icon sm-icon--petty-cash"
      aria-hidden="true"
    />
  )
}

function MailIcon() {
  return (
    <img
      src={mailIconSrc}
      alt=""
      className="sm-icon sm-icon--mail"
      aria-hidden="true"
    />
  )
}

function OutstandingIcon() {
  return (
    <img
      src={outstandingIconSrc}
      alt=""
      className="sm-icon sm-icon--outstanding"
      aria-hidden="true"
    />
  )
}

function DeliveryIcon() {
  return (
    <img
      src={deliveryIconSrc}
      alt=""
      className="sm-icon sm-icon--delivery"
      aria-hidden="true"
    />
  )
}

function AttendanceIcon() {
  return (
    <img
      src={attendanceIconSrc}
      alt=""
      className="sm-icon sm-icon--attendance"
      aria-hidden="true"
    />
  )
}

function BudgetsIcon() {
  return (
    <img
      src={budgetsIconSrc}
      alt=""
      className="sm-icon sm-icon--budgets"
      aria-hidden="true"
    />
  )
}

function StatementIcon() {
  return (
    <img
      src={statementIconSrc}
      alt=""
      className="sm-icon sm-icon--statement"
      aria-hidden="true"
    />
  )
}

function DispatchIcon() {
  return (
    <img
      src={dispatchIconSrc}
      alt=""
      className="sm-icon sm-icon--dispatch"
      aria-hidden="true"
    />
  )
}

function TodoIcon() {
  return (
    <img
      src={todoIconSrc}
      alt=""
      className="sm-icon sm-icon--todo"
      aria-hidden="true"
    />
  )
}

function SettingsIcon() {
  return (
    <img
      src={settingsIconSrc}
      alt=""
      className="sm-icon sm-icon--settings"
      aria-hidden="true"
    />
  )
}

function BackupIcon() {
  return (
    <img
      src={backupIconSrc}
      alt=""
      className="sm-icon sm-icon--backup"
      aria-hidden="true"
    />
  )
}

function SuggestionIcon() {
  return (
    <img
      src={suggestionIconSrc}
      alt=""
      className="sm-icon sm-icon--suggestion"
      aria-hidden="true"
    />
  )
}

function ReportIcon() {
  return (
    <img
      src={reportIconSrc}
      alt=""
      className="sm-icon sm-icon--report"
      aria-hidden="true"
    />
  )
}

function LetterIcon() {
  return (
    <img
      src={letterIconSrc}
      alt=""
      className="sm-icon sm-icon--letter"
      aria-hidden="true"
    />
  )
}

const ICONS = {
  voucher: FileText,
  attendance: AttendanceIcon,
  deliveries: DeliveryIcon,
  outstanding: OutstandingIcon,
  email: MailIcon,
  expenses: Receipt,
  petty_cash: PettyCashIcon,
  payroll: PayrollIcon,
  revenue: Coins,
  accounting: AccountingIcon,
  balances: AccountingIcon,
  budgets: BudgetsIcon,
  stock: StockIcon,
  warehouses: StoreIcon,
  sales: SalesIcon,
  crm: CrmIcon,
  statement: StatementIcon,
  dispatch: DispatchIcon,
  todo: TodoIcon,
  performance: PerformanceIcon,
  settings_admin: Monitor,
  settings: SettingsIcon,
  suggestions: SuggestionIcon,
  analytics: ReportIcon,
  backup: BackupIcon,
  inbox: Inbox,
  letter: LetterIcon,
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
      <div className="sm-icon-box">
        <SalesIcon />
      </div>
    )
  }
  if (name === 'crm') {
    return (
      <div className="sm-icon-box">
        <CrmIcon />
      </div>
    )
  }
  if (name === 'warehouses') {
    return (
      <div className="sm-icon-box">
        <StoreIcon />
      </div>
    )
  }
  if (name === 'stock') {
    return (
      <div className="sm-icon-box">
        <StockIcon />
      </div>
    )
  }
  if (name === 'accounting' || name === 'balances') {
    return (
      <div className="sm-icon-box">
        <AccountingIcon />
      </div>
    )
  }
  if (name === 'payroll') {
    return (
      <div className="sm-icon-box">
        <PayrollIcon />
      </div>
    )
  }
  if (name === 'petty_cash') {
    return (
      <div className="sm-icon-box">
        <PettyCashIcon />
      </div>
    )
  }
  if (name === 'email') {
    return (
      <div className="sm-icon-box">
        <MailIcon />
      </div>
    )
  }
  if (name === 'outstanding') {
    return (
      <div className="sm-icon-box">
        <OutstandingIcon />
      </div>
    )
  }
  if (name === 'deliveries') {
    return (
      <div className="sm-icon-box">
        <DeliveryIcon />
      </div>
    )
  }
  if (name === 'attendance') {
    return (
      <div className="sm-icon-box">
        <AttendanceIcon />
      </div>
    )
  }
  if (name === 'budgets') {
    return (
      <div className="sm-icon-box">
        <BudgetsIcon />
      </div>
    )
  }
  if (name === 'statement') {
    return (
      <div className="sm-icon-box">
        <StatementIcon />
      </div>
    )
  }
  if (name === 'dispatch') {
    return (
      <div className="sm-icon-box">
        <DispatchIcon />
      </div>
    )
  }
  if (name === 'todo') {
    return (
      <div className="sm-icon-box">
        <TodoIcon />
      </div>
    )
  }
  if (name === 'settings') {
    return (
      <div className="sm-icon-box">
        <SettingsIcon />
      </div>
    )
  }
  if (name === 'backup') {
    return (
      <div className="sm-icon-box">
        <BackupIcon />
      </div>
    )
  }
  if (name === 'suggestions') {
    return (
      <div className="sm-icon-box">
        <SuggestionIcon />
      </div>
    )
  }
  if (name === 'analytics') {
    return (
      <div className="sm-icon-box">
        <ReportIcon />
      </div>
    )
  }
  if (name === 'letter') {
    return (
      <div className="sm-icon-box">
        <LetterIcon />
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
