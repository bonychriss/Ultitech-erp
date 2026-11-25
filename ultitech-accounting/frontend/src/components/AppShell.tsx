import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../lib/auth'
import { syncErpHostBreadcrumb } from '../lib/erpBreadcrumb'

type NavItem = {
  to?: string
  label: string
  icon: string
  end?: boolean
  children?: Array<{ to: string; label: string }>
  externalHref?: string
}

const mainNav: NavItem[] = [
  { to: '/', label: 'Dashboard', icon: 'grid', end: true },
  { to: '/banking', label: 'Balances', icon: 'wallet' },
  { to: '/revenue', label: 'Revenue', icon: 'banknote' },
  { to: '/expenses', label: 'Expenses', icon: 'receipt' },
  { to: '/purchases', label: 'Purchases', icon: 'cart' },
  { to: '/journals', label: 'Journal Entries', icon: 'book' },
  { to: '/tax', label: 'VAT Control', icon: 'percent' },
  {
    label: 'Financial Statements',
    icon: 'file',
    children: [
      { to: '/trial-balance', label: 'Trial Balance' },
      { to: '/financial-statements', label: 'Statements' },
      { to: '/accounts', label: 'General Ledger / CoA' },
    ],
  },
  { to: '/reconciliation', label: 'Reconciliation', icon: 'link' },
  { to: '/assets', label: 'Fixed Assets', icon: 'landmark' },
  { to: '/inventory', label: 'Inventory', icon: 'scale' },
  { to: '/budgets', label: 'Budgets', icon: 'calc' },
  { to: '/erp', label: 'ERP Link', icon: 'sync' },
  { to: '/settings', label: 'Accounting Settings', icon: 'gear' },
]

const moreNav: NavItem[] = [
  { to: '/standards', label: 'Standards', icon: 'spark' },
  { to: '/advanced', label: 'Advanced', icon: 'building' },
  { to: '/governance', label: 'Governance', icon: 'receipt' },
]

function Icon({ name }: { name: string }) {
  const common = {
    width: 18,
    height: 18,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.85,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    className: 'erp-nav-ico',
    'aria-hidden': true as const,
  }

  const paths: Record<string, ReactNode> = {
    grid: (
      <>
        <rect x="3" y="3" width="7" height="7" rx="1" />
        <rect x="14" y="3" width="7" height="7" rx="1" />
        <rect x="3" y="14" width="7" height="7" rx="1" />
        <rect x="14" y="14" width="7" height="7" rx="1" />
      </>
    ),
    wallet: (
      <>
        <path d="M3 7h16a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z" />
        <path d="M3 7V5a2 2 0 0 1 2-2h12" />
        <circle cx="17" cy="13" r="1" />
      </>
    ),
    banknote: (
      <>
        <rect x="2" y="6" width="20" height="12" rx="2" />
        <circle cx="12" cy="12" r="2.5" />
        <path d="M6 12h.01M18 12h.01" />
      </>
    ),
    cart: (
      <>
        <circle cx="9" cy="20" r="1" />
        <circle cx="18" cy="20" r="1" />
        <path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h8.4a2 2 0 0 0 2-1.6L21 8H7" />
      </>
    ),
    book: (
      <>
        <path d="M4 5a2 2 0 0 1 2-2h12v18H6a2 2 0 0 1-2-2V5z" />
        <path d="M8 7h8M8 11h8" />
      </>
    ),
    percent: (
      <>
        <path d="M19 5 5 19" />
        <circle cx="7" cy="7" r="2" />
        <circle cx="17" cy="17" r="2" />
      </>
    ),
    file: (
      <>
        <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
        <path d="M14 3v5h5M8 13h8M8 17h6" />
      </>
    ),
    link: (
      <>
        <path d="M10 13a5 5 0 0 0 7.07 0l1.41-1.41a5 5 0 0 0-7.07-7.07L10 5.93" />
        <path d="M14 11a5 5 0 0 0-7.07 0L5.52 12.4a5 5 0 0 0 7.07 7.07L14 18.07" />
      </>
    ),
    landmark: <path d="M3 21h18M4 10h16M12 3l9 7H3zM6 10v7M10 10v7M14 10v7M18 10v7" />,
    scale: <path d="M12 3v18M5 7h14M7 7l-3 7h6l-3-7zm10 0l-3 7h6l-3-7z" />,
    calc: (
      <>
        <rect x="5" y="3" width="14" height="18" rx="2" />
        <path d="M8 7h8M8 12h2M12 12h2M16 12h1M8 16h2M12 16h2M16 16h1" />
      </>
    ),
    sync: (
      <>
        <path d="M21 12a9 9 0 0 0-15.5-6.4M3 4v5h5" />
        <path d="M3 12a9 9 0 0 0 15.5 6.4M21 20v-5h-5" />
      </>
    ),
    gear: (
      <>
        <circle cx="12" cy="12" r="3" />
        <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9c.3.6.9 1 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" />
      </>
    ),
    spark: <path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8" />,
    building: <path d="M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16M4 21h16M10 9h2M10 13h2M10 17h2M16 21V11h4v10" />,
    receipt: (
      <>
        <path d="M6 3h12v18l-2-1-2 1-2-1-2 1-2-1-2 1V3z" />
        <path d="M9 8h6M9 12h6M9 16h4" />
      </>
    ),
    logout: (
      <>
        <path d="M10 17v1a2 2 0 0 0 2 2h7a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-7a2 2 0 0 0-2 2v1" />
        <path d="M15 12H3M6 9l-3 3 3 3" />
      </>
    ),
    chevron: <path d="M9 6l6 6-6 6" />,
    chevronDown: <path d="M6 9l6 6 6-6" />,
  }

  return <svg {...common}>{paths[name] || paths.grid}</svg>
}

function NavRow({ item, onNavigate }: { item: NavItem; onNavigate?: () => void }) {
  const location = useLocation()
  const childActive = Boolean(item.children?.some((c) => location.pathname === c.to))
  const [open, setOpen] = useState(childActive)

  useEffect(() => {
    if (childActive) setOpen(true)
  }, [childActive])

  if (item.externalHref) {
    return (
      <a className="erp-nav-link" href={item.externalHref} onClick={() => onNavigate?.()}>
        <Icon name={item.icon} />
        <span className="erp-nav-text">{item.label}</span>
      </a>
    )
  }

  if (item.children?.length) {
    return (
      <div className={`erp-nav-group${open || childActive ? ' is-open' : ''}`}>
        <button
          type="button"
          className={`erp-nav-link erp-nav-parent${childActive ? ' active' : ''}`}
          onClick={() => setOpen((v) => !v)}
        >
          <Icon name={item.icon} />
          <span className="erp-nav-text">{item.label}</span>
          <span className="erp-nav-chevron">
            <Icon name={open ? 'chevronDown' : 'chevron'} />
          </span>
        </button>
        {open ? (
          <div className="erp-nav-sub">
            {item.children.map((child) => (
              <NavLink
                key={child.to}
                to={child.to}
                onClick={() => onNavigate?.()}
                className={({ isActive }) => `erp-nav-link erp-nav-sublink${isActive ? ' active' : ''}`}
              >
                <span className="erp-nav-dot" aria-hidden="true" />
                <span className="erp-nav-text">{child.label}</span>
              </NavLink>
            ))}
          </div>
        ) : null}
      </div>
    )
  }

  return (
    <NavLink
      to={item.to || '/'}
      end={item.end}
      onClick={() => onNavigate?.()}
      className={({ isActive }) => `erp-nav-link${isActive ? ' active' : ''}`}
    >
      <Icon name={item.icon} />
      <span className="erp-nav-text">{item.label}</span>
    </NavLink>
  )
}

export function AppShell() {
  const { user, companies, companyId, setCompanyId, logout, embed, hostedInErp } = useAuth()
  const location = useLocation()
  const erpMode = embed.mode === 'erp' || hostedInErp
  const [mobileOpen, setMobileOpen] = useState(false)
  const [logoFailed, setLogoFailed] = useState(false)

  useEffect(() => {
    document.documentElement.dataset.erpEmbed = '1'
    if (hostedInErp) document.documentElement.dataset.erpHosted = '1'
    return () => {
      document.documentElement.removeAttribute('data-erp-embed')
      document.documentElement.removeAttribute('data-erp-hosted')
    }
  }, [hostedInErp])

  useEffect(() => {
    setMobileOpen(false)
  }, [location.pathname])

  useEffect(() => {
    if (!hostedInErp) return
    syncErpHostBreadcrumb(location.pathname)
  }, [hostedInErp, location.pathname])

  useEffect(() => {
    setLogoFailed(false)
  }, [embed.companyLogoUrl])

  const companyLabel = useMemo(() => {
    return (
      companies.find((c) => c.id === companyId)?.legal_name ||
      embed.erpCompanyName ||
      'Company'
    )
  }, [companies, companyId, embed.erpCompanyName])

  // Hosted inside ERP: native sidebar + PV-style mobile header chrome.
  if (hostedInErp) {
    return (
      <div className="erp-hosted-main">
        <main className="erp-content erp-hosted-content">
          <Outlet />
        </main>
      </div>
    )
  }

  const roleLabel = erpMode ? 'Accounting' : 'Staff'

  return (
    <div className={`erp-layout${mobileOpen ? ' sidebar-open' : ''}`}>
      <button type="button" className="erp-sidebar-overlay" aria-label="Close menu" onClick={() => setMobileOpen(false)} />

      <aside className="erp-sidebar" id="native-sidebar-clone">
        <div className="erp-sidebar-brand">
          {embed.companyLogoUrl && !logoFailed ? (
            <div className="erp-logo-img-wrap">
              <img
                className="erp-logo-img"
                src={embed.companyLogoUrl}
                alt={`${companyLabel} logo`}
                onError={() => setLogoFailed(true)}
              />
            </div>
          ) : (
            <div className="erp-logo-mark" aria-hidden="true">
              {(companyLabel || 'U').slice(0, 1).toUpperCase()}
            </div>
          )}
          <div className="erp-brand-copy">
            <div className="erp-brand-name">{companyLabel}</div>
            <div className="erp-brand-sub">Accounting</div>
          </div>
        </div>

        <button type="button" className="erp-user-card" title={user?.email || ''}>
          <div className="erp-user-avatar" aria-hidden="true">
            {(user?.name || 'U').slice(0, 1).toUpperCase()}
          </div>
          <div className="erp-user-meta">
            <div className="erp-user-name">{user?.name || 'User'}</div>
            <div className="erp-user-role">{roleLabel}</div>
          </div>
          <span className="erp-user-chevron">
            <Icon name="chevron" />
          </span>
        </button>

        {!embed.lockedCompany ? (
          <div className="erp-company-switch">
            <label htmlFor="erp-company-select">Company</label>
            <select
              id="erp-company-select"
              value={companyId ?? ''}
              onChange={(e) => setCompanyId(Number(e.target.value))}
            >
              {companies.map((company) => (
                <option key={company.id} value={company.id}>
                  {company.code} — {company.legal_name}
                </option>
              ))}
            </select>
          </div>
        ) : null}

        <div className="erp-section-label">Main</div>
        <nav className="erp-nav">
          {mainNav.map((item) => (
            <NavRow key={item.to || item.label} item={item} onNavigate={() => setMobileOpen(false)} />
          ))}
        </nav>

        <div className="erp-section-label">More</div>
        <nav className="erp-nav">
          {moreNav.map((item) => (
            <NavRow key={item.to || item.label} item={item} onNavigate={() => setMobileOpen(false)} />
          ))}
        </nav>

        <div className="erp-section-label">Quick</div>
        <nav className="erp-nav erp-nav-quick">
          <button type="button" className="erp-nav-link erp-nav-danger" onClick={() => void logout()}>
            <Icon name="logout" />
            <span className="erp-nav-text">Logout</span>
          </button>
        </nav>
      </aside>

      <div className="erp-main">
        <header className="erp-topbar">
          <button type="button" className="erp-menu-btn" onClick={() => setMobileOpen((v) => !v)}>
            Menu
          </button>
          <div>
            <div className="erp-topbar-kicker">UltiTech Accounting</div>
            <h1 className="erp-topbar-title">Accounting</h1>
          </div>
        </header>
        <main className="erp-content">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
