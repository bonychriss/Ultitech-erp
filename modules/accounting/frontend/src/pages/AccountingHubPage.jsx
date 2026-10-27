import { useEffect, useMemo, useState } from 'react'
import { ArrowLeft, Search } from 'lucide-react'
import balancesIcon from '../assets/balances-icon.png'
import revenueIcon from '../assets/revenue-icon.png'
import expensesIcon from '../assets/expenses-icon.png'
import purchasesIcon from '../assets/purchases-icon.png'
import journalIcon from '../assets/journal-icon.png'
import vatIcon from '../assets/vat-icon.png'
import settingsIcon from '../assets/settings-icon.png'
import journalConfigIcon from '../assets/journal-config-icon.png'
import trialBalanceIcon from '../assets/trial-balance-icon.png'
import reconciliationIcon from '../assets/reconciliation-icon.png'

const SECTION_ICONS = {
  balances: balancesIcon,
  revenue: revenueIcon,
  expenses: expensesIcon,
  purchases: purchasesIcon,
  'journal-entries': journalIcon,
  'vat-control': vatIcon,
  settings: settingsIcon,
  'journal-configuration': journalConfigIcon,
  'financial-statements': trialBalanceIcon,
  'trial-balance': trialBalanceIcon,
  reconciliation: reconciliationIcon,
}

function readBoot() {
  if (typeof window !== 'undefined' && window.__ACCOUNTING_CFG__) {
    return window.__ACCOUNTING_CFG__
  }
  try {
    const el = document.getElementById('accounting-boot-config')
    if (el) return JSON.parse(el.textContent || '{}') || {}
  } catch {
    /* ignore */
  }
  return {}
}

function readHashFolder() {
  if (typeof window === 'undefined') return ''
  const raw = String(window.location.hash || '').replace(/^#/, '').trim()
  return raw
}

function hasChildren(section) {
  return Array.isArray(section?.children) && section.children.length > 0
}

function SectionIcon({ id, iconUrl, title, tone }) {
  const bootUrl = typeof iconUrl === 'string' ? iconUrl.trim() : ''
  const src = SECTION_ICONS[id] || bootUrl || ''
  if (!src) return null
  const toneClass = typeof tone === 'string' && tone.trim() ? ` acct-ico--${tone.trim()}` : ''
  return (
    <span
      className={`acct-ico acct-ico--img${toneClass}`}
      style={{ '--acct-ico-mask': `url(${src})` }}
      title={title || ''}
      aria-hidden="true"
    />
  )
}

function SectionCard({ section }) {
  const folder = hasChildren(section)
  const href = folder ? `#${section.id}` : section.href
  return (
    <a
      key={section.id || section.title}
      className={`acct-card${section.tone ? ` acct-card--${section.tone}` : ''}`}
      href={href || '#'}
    >
      <SectionIcon
        id={section.id}
        iconUrl={section.iconUrl}
        title={section.title}
        tone={section.tone}
      />
      <h2 className="acct-card-label">{section.title}</h2>
    </a>
  )
}

export default function AccountingHubPage() {
  const boot = readBoot()
  const sections = Array.isArray(boot.sections) ? boot.sections : []
  const backUrl = typeof boot.backUrl === 'string' ? boot.backUrl.trim() : ''
  const [query, setQuery] = useState('')
  const [folderId, setFolderId] = useState(readHashFolder)

  useEffect(() => {
    const onHash = () => setFolderId(readHashFolder())
    window.addEventListener('hashchange', onHash)
    return () => window.removeEventListener('hashchange', onHash)
  }, [])

  const activeFolder = useMemo(
    () => sections.find((s) => s.id === folderId && hasChildren(s)) || null,
    [sections, folderId]
  )

  const visibleSections = activeFolder ? activeFolder.children : sections

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return visibleSections
    return visibleSections.filter((s) => {
      const childHay = hasChildren(s)
        ? s.children.map((c) => [c.title, c.desc, c.id].filter(Boolean).join(' ')).join(' ')
        : ''
      const hay = [s.title, s.desc, s.id, childHay].filter(Boolean).join(' ').toLowerCase()
      return hay.includes(q)
    })
  }, [visibleSections, query])

  const pageBackHref = activeFolder ? '#' : backUrl
  const pageBackLabel = activeFolder ? 'Back' : 'Back'
  const pageTitle = activeFolder ? activeFolder.title : 'Accounting'
  const searchPlaceholder = activeFolder
    ? `Search ${activeFolder.title.toLowerCase()}…`
    : 'Search sections…'
  const gridLabel = activeFolder ? activeFolder.title : 'Accounting sections'

  return (
    <div className="acct-page">
      <header className="acct-hero">
        {pageBackHref ? (
          <a
            className="acct-back"
            href={pageBackHref || '#'}
            onClick={(e) => {
              if (activeFolder) {
                e.preventDefault()
                window.location.hash = ''
                setFolderId('')
                setQuery('')
              }
            }}
          >
            <ArrowLeft size={16} strokeWidth={2.25} aria-hidden="true" />
            {pageBackLabel}
          </a>
        ) : null}
        <h1>{pageTitle}</h1>
      </header>

      {visibleSections.length > 0 ? (
        <label className="acct-search">
          <Search size={16} strokeWidth={2.25} aria-hidden="true" />
          <input
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={searchPlaceholder}
            aria-label={searchPlaceholder}
            autoComplete="off"
          />
        </label>
      ) : null}

      {visibleSections.length === 0 ? (
        <div className="acct-empty">No accounting sections available.</div>
      ) : filtered.length === 0 ? (
        <div className="acct-empty">No sections match “{query.trim()}”.</div>
      ) : (
        <section className="acct-grid" aria-label={gridLabel}>
          {filtered.map((s) => (
            <SectionCard key={s.id || s.title} section={s} />
          ))}
        </section>
      )}
    </div>
  )
}
