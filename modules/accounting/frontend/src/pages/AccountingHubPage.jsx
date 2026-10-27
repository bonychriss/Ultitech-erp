import { useMemo, useState } from 'react'
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

function SectionIcon({ id, iconUrl, title }) {
  const bootUrl = typeof iconUrl === 'string' ? iconUrl.trim() : ''
  const src = SECTION_ICONS[id] || bootUrl || ''
  if (!src) return null
  return (
    <span className="acct-ico acct-ico--img" aria-hidden="true">
      <img src={src} alt="" title={title || ''} />
    </span>
  )
}

export default function AccountingHubPage() {
  const boot = readBoot()
  const sections = Array.isArray(boot.sections) ? boot.sections : []
  const backUrl = typeof boot.backUrl === 'string' ? boot.backUrl.trim() : ''
  const [query, setQuery] = useState('')

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return sections
    return sections.filter((s) => {
      const hay = [s.title, s.desc, s.id].filter(Boolean).join(' ').toLowerCase()
      return hay.includes(q)
    })
  }, [sections, query])

  return (
    <div className="acct-page">
      <header className="acct-hero">
        {backUrl ? (
          <a className="acct-back" href={backUrl}>
            <ArrowLeft size={16} strokeWidth={2.25} aria-hidden="true" />
            Back
          </a>
        ) : null}
        <h1>Accounting</h1>
      </header>

      {sections.length > 0 ? (
        <label className="acct-search">
          <Search size={16} strokeWidth={2.25} aria-hidden="true" />
          <input
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search sections…"
            aria-label="Search accounting sections"
            autoComplete="off"
          />
        </label>
      ) : null}

      {sections.length === 0 ? (
        <div className="acct-empty">No accounting sections available.</div>
      ) : filtered.length === 0 ? (
        <div className="acct-empty">No sections match “{query.trim()}”.</div>
      ) : (
        <section className="acct-grid" aria-label="Accounting sections">
          {filtered.map((s) => (
            <a key={s.id || s.title} className="acct-card" href={s.href}>
              <SectionIcon id={s.id} iconUrl={s.iconUrl} title={s.title} />
              <h2 className="acct-card-label">{s.title}</h2>
            </a>
          ))}
        </section>
      )}
    </div>
  )
}
