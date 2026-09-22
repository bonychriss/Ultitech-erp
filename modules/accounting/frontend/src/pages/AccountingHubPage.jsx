import {
  BookOpen,
  Coins,
  Link2,
  Percent,
  Scale,
  Settings,
  SlidersHorizontal,
  Table2,
} from 'lucide-react'

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

function SectionIcon({ icon, iconUrl, tone }) {
  if (iconUrl) {
    return (
      <span className={`acct-ico acct-ico--img tone-${tone}`} aria-hidden="true">
        <img src={iconUrl} alt="" />
      </span>
    )
  }

  const map = {
    scale: Scale,
    coins: Coins,
    book: BookOpen,
    percent: Percent,
    sliders: SlidersHorizontal,
    gear: Settings,
    table: Table2,
    link: Link2,
  }
  const Icon = map[icon] || BookOpen
  return (
    <span className={`acct-ico tone-${tone}`} aria-hidden="true">
      <Icon size={20} strokeWidth={2.1} />
    </span>
  )
}

export default function AccountingHubPage() {
  const boot = readBoot()
  const sections = Array.isArray(boot.sections) ? boot.sections : []

  return (
    <div className="acct-page">
      <header className="acct-hero">
        <h1>Accounting</h1>
        <p>Choose a section to configure or work with balances, revenue, VAT, journals, and reports.</p>
      </header>

      {sections.length === 0 ? (
        <div className="acct-empty">No accounting sections available.</div>
      ) : (
        <section className="acct-grid" aria-label="Accounting sections">
          {sections.map((s) => (
            <a key={s.id || s.title} className="acct-card" href={s.href}>
              <SectionIcon icon={s.icon} iconUrl={s.iconUrl} tone={s.tone || 'blue'} />
              <h2>{s.title}</h2>
              <p>{s.desc}</p>
            </a>
          ))}
        </section>
      )}
    </div>
  )
}
