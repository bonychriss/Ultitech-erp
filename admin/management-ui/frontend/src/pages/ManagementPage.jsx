import { useEffect, useMemo, useState } from 'react'
import {
  ArrowRight,
  Boxes,
  Building2,
  CheckCircle2,
  Clipboard,
  Check,
  ExternalLink,
  PlusSquare,
  Search,
  Settings,
  Trash2,
  Users,
  X,
  BadgeDollarSign,
} from 'lucide-react'

function getCfg() {
  return window.__MANAGEMENT_CFG__ || {}
}

function shouldOpenRegister(cfg) {
  if (cfg.openRegister) return true
  const hash = (window.location.hash || '').replace(/^#/, '')
  return hash === 'register-company'
}

function Alert({ message, tone, onClose }) {
  if (!message) return null
  return (
    <div className={`mgmt-alert mgmt-alert--${tone === 'danger' ? 'danger' : 'success'}`} role="status">
      <span>{message}</span>
      <button type="button" className="mgmt-alert-close" onClick={onClose} aria-label="Dismiss">
        �
      </button>
    </div>
  )
}

function CopyUrlButton({ url }) {
  const [copied, setCopied] = useState(false)
  if (!url) return null
  return (
    <button
      type="button"
      className="mgmt-icon-btn"
      onClick={async () => {
        try {
          await navigator.clipboard.writeText(url)
          setCopied(true)
          setTimeout(() => setCopied(false), 1500)
        } catch {
          /* ignore */
        }
      }}
      title={copied ? 'Copied' : 'Copy access URL'}
      aria-label={copied ? 'Copied' : 'Copy access URL'}
    >
      {copied ? <Check size={16} strokeWidth={1.75} /> : <Clipboard size={16} strokeWidth={1.75} />}
    </button>
  )
}

function RegisterCompanyModal({ open, onClose, formAction, returnTo }) {
  useEffect(() => {
    if (!open) return undefined
    const onKey = (e) => {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null

  return (
    <div className="mgmt-modal-backdrop" role="presentation" onClick={onClose}>
      <div
        className="mgmt-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="mgmt-register-title"
        onClick={(e) => e.stopPropagation()}
      >
        <header className="mgmt-modal-head">
          <div>
            <h2 id="mgmt-register-title">Register New Company</h2>
            <p>Create a company tenant with default module setup.</p>
          </div>
          <button type="button" className="mgmt-modal-close" onClick={onClose} aria-label="Close">
            <X size={18} />
          </button>
        </header>

        <form method="post" action={formAction} className="mgmt-form-stack">
          <input type="hidden" name="create_company" value="1" />
          {returnTo ? <input type="hidden" name="return_to" value={returnTo} /> : null}

          <div className="mgmt-field">
            <label htmlFor="company_name">Company Name</label>
            <input
              id="company_name"
              name="company_name"
              className="mgmt-input"
              placeholder="e.g. Acme Corp"
              required
              autoFocus
            />
          </div>

          <div className="mgmt-field">
            <label htmlFor="subdomain">Subdomain</label>
            <input id="subdomain" name="subdomain" className="mgmt-input" placeholder="e.g. acme" />
          </div>

          <div className="mgmt-field">
            <label htmlFor="db_name">Database</label>
            <input id="db_name" name="db_name" className="mgmt-input" placeholder="e.g. acme_db" />
          </div>

          <div className="mgmt-field">
            <label htmlFor="base_currency">Currency</label>
            <select id="base_currency" name="base_currency" className="mgmt-input" defaultValue="TZS">
              <option value="TZS">TZS</option>
              <option value="USD">USD</option>
            </select>
          </div>

          <div className="mgmt-field">
            <label htmlFor="timezone">Timezone</label>
            <select
              id="timezone"
              name="timezone"
              className="mgmt-input"
              defaultValue="Africa/Dar_es_Salaam"
            >
              <option value="Africa/Dar_es_Salaam">Tanzania</option>
              <option value="UTC">UTC</option>
            </select>
          </div>

          <div className="mgmt-field">
            <label htmlFor="industry_type">Industry</label>
            <select id="industry_type" name="industry_type" className="mgmt-input" defaultValue="trading">
              <option value="trading">Trading</option>
              <option value="logistics">Logistics</option>
            </select>
          </div>

          <div className="mgmt-form-actions">
            <button type="button" className="mgmt-link-btn" onClick={onClose}>
              Cancel
            </button>
            <button type="submit" className="mgmt-btn-primary">
              <PlusSquare size={16} /> Create Instance
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

export default function ManagementPage() {
  const cfg = useMemo(() => getCfg(), [])
  const [flash, setFlash] = useState(cfg.message || '')
  const [search, setSearch] = useState('')
  const [registerOpen, setRegisterOpen] = useState(() => shouldOpenRegister(cfg))

  const stats = cfg.stats || {}
  const companies = cfg.companies || []
  const formAction = cfg.formAction || 'management.php'
  const returnTo = cfg.returnTo || formAction
  const activeCompanyId = Number(cfg.activeCompanyId || 0)
  const canMarkPaid = !!cfg.canMarkPaid

  useEffect(() => {
    if (!flash) return undefined
    const t = setTimeout(() => setFlash(''), 6000)
    return () => clearTimeout(t)
  }, [flash])

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    return companies.filter((c) => {
      if (!q) return true
      const hay = [c.company_name, c.subdomain, c.company_slug, c.db_name, c.plan_status]
        .map((v) => String(v || '').toLowerCase())
        .join(' ')
      return hay.includes(q)
    })
  }, [companies, search])

  return (
    <div className="mgmt-page">
      <div className="mgmt-topbar" id="registered-companies">
        <div className="mgmt-topbar-search">
          <div className="mgmt-search">
            <Search size={14} className="mgmt-search-icon" aria-hidden="true" />
            <input
              className="mgmt-input"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search company..."
              aria-label="Search companies"
            />
          </div>
        </div>
        <div className="mgmt-topbar-actions">
          <a
            className="mgmt-icon-btn"
            href={cfg.listUsersUrl || '#'}
            title="Company users & emails"
            aria-label="Company users & emails"
          >
            <Users size={20} strokeWidth={1.75} />
          </a>
          <button
            type="button"
            className="mgmt-icon-btn mgmt-icon-btn--primary"
            onClick={() => setRegisterOpen(true)}
            title="Register New Company"
            aria-label="Register New Company"
          >
            <PlusSquare size={20} strokeWidth={1.75} />
          </button>
        </div>
      </div>

      <Alert message={flash} tone={cfg.messageTone || 'success'} onClose={() => setFlash('')} />

      <div className="mgmt-stats">
        <div className="mgmt-stat">
          <div className="mgmt-stat-icon" aria-hidden="true">
            <Building2 size={18} strokeWidth={1.75} />
          </div>
          <div className="mgmt-stat-body">
            <p className="mgmt-stat-label">Total Companies</p>
            <p className="mgmt-stat-value">{stats.totalCompanies ?? 0}</p>
            <p className="mgmt-stat-foot">All registered companies</p>
          </div>
        </div>
        <div className="mgmt-stat">
          <div className="mgmt-stat-icon mgmt-stat-icon--green" aria-hidden="true">
            <CheckCircle2 size={18} strokeWidth={1.75} />
          </div>
          <div className="mgmt-stat-body">
            <p className="mgmt-stat-label">Active Tenants</p>
            <p className="mgmt-stat-value">{stats.activeCount ?? 0}</p>
            <p className="mgmt-stat-foot">Currently active companies</p>
          </div>
        </div>
        <div className="mgmt-stat">
          <div className="mgmt-stat-icon mgmt-stat-icon--sky" aria-hidden="true">
            <Users size={18} strokeWidth={1.75} />
          </div>
          <div className="mgmt-stat-body">
            <p className="mgmt-stat-label">Total Staff</p>
            <p className="mgmt-stat-value">{stats.totalStaff ?? 0}</p>
            <p className="mgmt-stat-foot">Across all companies</p>
          </div>
        </div>
        <div className="mgmt-stat">
          <div className="mgmt-stat-icon mgmt-stat-icon--orange" aria-hidden="true">
            <Boxes size={18} strokeWidth={1.75} />
          </div>
          <div className="mgmt-stat-body">
            <p className="mgmt-stat-label">Modules Enabled</p>
            <p className="mgmt-stat-value">{stats.modulesEnabledCount ?? 9}</p>
            <p className="mgmt-stat-foot">Default modules for new companies</p>
          </div>
        </div>
      </div>

      <div className="mgmt-table-wrap">
        <table className="mgmt-table">
          <thead>
            <tr>
              <th>Company</th>
              <th>Database</th>
              <th>Staff</th>
              <th>Status</th>
              <th className="mgmt-th-actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            {filtered.length === 0 ? (
              <tr>
                <td colSpan={5}>
                  <div className="mgmt-empty">No companies match your filters.</div>
                </td>
              </tr>
            ) : (
              filtered.map((c) => {
                const isActiveCtx = activeCompanyId === Number(c.id)
                const status = String(c.status || 'active').toLowerCase()
                const planStatus = String(c.plan_status || 'paid').toLowerCase()
                const initial = String(c.company_name || '?').charAt(0).toUpperCase()
                return (
                  <tr key={c.id}>
                    <td>
                      <div className="mgmt-company-cell">
                        <div className="mgmt-avatar">{initial}</div>
                        <div className="mgmt-company-info">
                          <div className="mgmt-company-name">{c.company_name}</div>
                          <div className="mgmt-company-meta">
                            {c.company_slug || c.subdomain || '-'}
                            {c.registeredOn ? ` - ${c.registeredOn}` : ''}
                          </div>
                          <span className={`mgmt-badge${isActiveCtx ? ' mgmt-badge--active' : ''}`}>
                            {c.base_currency || 'TZS'}
                            {isActiveCtx ? ' - Active' : ''}
                          </span>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div className="mgmt-db" title={c.db_name || 'System Default'}>
                        {c.db_name || 'System Default'}
                      </div>
                    </td>
                    <td>
                      <span className="mgmt-staff">{Number(c.user_count || 0)}</span>
                    </td>
                    <td>
                      <span
                        className={`mgmt-status${status !== 'active' ? ' mgmt-status--inactive' : ''}`}
                      >
                        <span className="mgmt-status-dot" />
                        {status}
                      </span>
                      {planStatus && planStatus !== 'paid' ? (
                        <div className="mgmt-plan-meta">
                          Plan: {planStatus}
                          {c.trial_ends_label ? ` · ends ${c.trial_ends_label}` : ''}
                        </div>
                      ) : null}
                    </td>
                    <td>
                      <div className="mgmt-actions">
                        <a
                          className="mgmt-icon-btn"
                          href={c.accessUrl || '#'}
                          target="_blank"
                          rel="noreferrer"
                          title="Open workspace"
                          aria-label="Open workspace"
                        >
                          <ExternalLink size={16} strokeWidth={1.75} />
                        </a>
                        <CopyUrlButton url={c.fullAccessUrl} />
                        <a
                          className="mgmt-icon-btn"
                          href={c.switchUrl || '#'}
                          title="Switch company"
                          aria-label="Switch company"
                        >
                          <ArrowRight size={16} strokeWidth={1.75} />
                        </a>
                        <a
                          className="mgmt-icon-btn"
                          href={c.settingsUrl || '#'}
                          title="Company settings"
                          aria-label="Company settings"
                        >
                          <Settings size={16} strokeWidth={1.75} />
                        </a>
                        {canMarkPaid && c.can_mark_paid ? (
                          <form method="post" action={formAction} className="mgmt-danger-form">
                            <input type="hidden" name="mark_company_paid" value="1" />
                            <input type="hidden" name="company_id" value={c.id} />
                            {returnTo ? <input type="hidden" name="return_to" value={returnTo} /> : null}
                            <button
                              type="submit"
                              className="mgmt-icon-btn mgmt-icon-btn--primary"
                              title="Mark paid (unlock on shared DB)"
                              aria-label={`Mark ${c.company_name || 'company'} paid`}
                            >
                              <BadgeDollarSign size={16} strokeWidth={1.75} />
                            </button>
                          </form>
                        ) : null}
                        <form
                          method="post"
                          action={formAction}
                          className="mgmt-danger-form"
                          onSubmit={(e) => {
                            if (
                              !window.confirm(
                                `Permanently delete "${c.company_name}"? Users will lose company association. This cannot be undone.`,
                              )
                            ) {
                              e.preventDefault()
                            }
                          }}
                        >
                          <input type="hidden" name="delete_company" value="1" />
                          <input type="hidden" name="company_id" value={c.id} />
                          <input type="hidden" name="confirm_slug" value={c.company_slug || ''} />
                          <button
                            type="submit"
                            className="mgmt-icon-btn mgmt-icon-btn--danger"
                            title="Remove company"
                            aria-label={`Remove ${c.company_name || 'company'}`}
                          >
                            <Trash2 size={16} strokeWidth={1.75} />
                          </button>
                        </form>
                      </div>
                    </td>
                  </tr>
                )
              })
            )}
          </tbody>
        </table>
        <div className="mgmt-footer">
          Showing {filtered.length} of {companies.length} companies
        </div>
      </div>

      <RegisterCompanyModal
        open={registerOpen}
        onClose={() => setRegisterOpen(false)}
        formAction={formAction}
        returnTo={cfg.returnTo || cfg.companiesListUrl || formAction}
      />
    </div>
  )
}
