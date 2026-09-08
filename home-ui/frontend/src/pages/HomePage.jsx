import ErpOrbitDiagram, { ERP_MODULES } from '../components/ErpOrbitDiagram.jsx'

import logoIcon from '../assets/ui/dashboard.png'

function getCfg() {
  return window.__HOME_CFG__ || {}
}

const MODULE_POINTS = {
  finance: ['Budgets & cashflow', 'Reconciliations', 'Expense control'],
  sales: ['Quotations & orders', 'Invoicing', 'Price lists'],
  inventory: ['Stock levels', 'Warehouses', 'Stocktaking'],
  hr: ['Attendance', 'Payroll', 'Staff records'],
  voucher: ['Create & review', 'Approvals', 'Audit trail'],
  logistics: ['Dispatch notes', 'Delivery routes', 'Handover tracking'],
  reports: ['Live KPIs', 'Department views', 'Export-ready'],
  ops: ['Workflows', 'Approvals', 'Cross-team tasks'],
}

const INDUSTRIES = [
  { title: 'Retail & Trading', text: 'Counter sales, stock, and invoicing in one flow.' },
  { title: 'Business Services', text: 'Quotes, jobs, and collections without extra tools.' },
  { title: 'Logistics', text: 'Dispatch, delivery notes, and shipment follow-up.' },
  { title: 'Manufacturing', text: 'Materials, stock movement, and shop-floor control.' },
  { title: 'Construction', text: 'Project costs, vouchers, and site operations.' },
  { title: 'Automotive', text: 'Parts inventory, workshop jobs, and customer billing.' },
  { title: 'Hospitality', text: 'Bookings, expenses, and day-to-day cash control.' },
  { title: 'Education', text: 'Fees, staff payroll, and operational reporting.' },
]

const FEATURE_BANDS = [
  {
    id: 'sales',
    kicker: 'Sales',
    title: 'Track orders, invoices, and payments',
    text: 'Move from quote to cash without switching systems. Teams see the same numbers in sales, stock, and finance.',
    bullets: ['Quotes convert to invoices', 'Live balances and collections', 'Customer history in one place'],
    cta: 'Start selling',
  },
  {
    id: 'finance',
    kicker: 'Finance',
    title: 'Run books without a second system',
    text: 'Accounting stays connected to sales, vouchers, and payroll so reports match what actually happened.',
    bullets: ['Balances, revenue, and journals', 'Expense and petty-cash control', 'Trial balance and reconciliation'],
    cta: 'Manage finance',
    flip: true,
  },
  {
    id: 'ops',
    kicker: 'Inventory',
    title: 'Stock, people, and delivery on one desk',
    text: 'Warehouse health, restock alerts, and purchasing stay linked to the same product records.',
    bullets: ['Live stock health', 'Restock alerts', 'Purchases and suppliers'],
    cta: 'Explore stock',
  },
]

const WHY = [
  {
    title: 'One platform, all modules',
    text: 'Sales, finance, stock, HR, and logistics share one database. No extra licenses per app.',
  },
  {
    title: 'Secure by design',
    text: 'Role-based access, company isolation, and encrypted sessions keep tenant data separate.',
  },
  {
    title: 'Work from anywhere',
    text: 'Sign in from the office or the field. The same live records follow every user.',
  },
  {
    title: 'Setup that stays simple',
    text: 'Start with the modules you need, then add workflows as the team grows.',
  },
]

const TRUST = [
  'Free 14-Day Trial',
  'No Credit Card Required',
  'Easy Setup',
  'All Modules Included',
]

export default function HomePage() {
  const cfg = getCfg()
  const loginUrl = cfg.loginUrl || 'login.php'
  const trialUrl = cfg.trialUrl || 'free-trial.php'
  const year = cfg.year || new Date().getFullYear()

  return (
    <div className="home-page">
      <div className="float-shape shape-1" aria-hidden="true" />
      <div className="float-shape shape-2" aria-hidden="true" />
      <div className="float-shape shape-3" aria-hidden="true" />

      <header className="home-nav">
        <a href={cfg.homeUrl || './'} className="home-logo">
          <img src={logoIcon} alt="" className="ui-icon ui-icon-logo" aria-hidden="true" /> UltiTech ERP
        </a>
        <nav className="home-nav-menu">
          <a href="#modules">Modules</a>
          <a href="#industries">Industries</a>
          <a href="#features">Features</a>
          <a href="#pricing">Pricing</a>
          <a href={loginUrl}>Login</a>
        </nav>
        <a href={trialUrl} className="btn-account">
          Try for Free
        </a>
      </header>

      <section className="home-hero">
        <p className="home-hero-kicker">Cloud ERP for growing companies</p>
        <h1 className="home-hero-title">All your business on one platform</h1>
        <p className="home-hero-subtitle">
          UltiTech ERP brings finance, sales, inventory, HR, logistics, and operations together so
          every team works from the same live numbers.
        </p>
        <div className="home-hero-actions">
          <a href={trialUrl} className="start-trial-btn">
            Start Free Trial <span className="btn-arrow" aria-hidden="true">→</span>
          </a>
          <a href={loginUrl} className="ghost-btn">
            Sign in
          </a>
        </div>
        <div className="home-trust">
          {TRUST.map((label) => (
            <div key={label} className="item">
              {label}
            </div>
          ))}
        </div>
      </section>

      <section className="home-platform">
        <h2>Simple, connected, and ready to run</h2>
        <p className="sub">
          One workspace for every department — with real-time visibility across the organization.
        </p>
        <ErpOrbitDiagram />
      </section>

      <section id="modules" className="home-section">
        <div className="home-section-head">
          <p className="kicker">Modules</p>
          <h2>Apps for every part of the business</h2>
          <p>Pick up where each team already works. Everything posts back to the same company books.</p>
        </div>
        <div className="module-grid">
          {ERP_MODULES.map((mod) => (
            <article key={mod.key} className="module-card">
              <div className="module-card-icon" style={{ background: `${mod.color}14`, borderColor: `${mod.color}33` }}>
                <img src={mod.icon} alt="" />
              </div>
              <h3>{mod.label}</h3>
              <p>{mod.detail}</p>
              <ul>
                {(MODULE_POINTS[mod.key] || []).map((point) => (
                  <li key={point}>{point}</li>
                ))}
              </ul>
              <a href={trialUrl}>
                Open module <span aria-hidden="true">→</span>
              </a>
            </article>
          ))}
        </div>
      </section>

      <section id="industries" className="home-band">
        <div className="home-section">
          <div className="home-section-head">
            <p className="kicker">Industries</p>
            <h2>Built around how your sector actually operates</h2>
            <p>Start with a setup that matches your workflow, then grow into the rest of the platform.</p>
          </div>
          <div className="industry-grid">
            {INDUSTRIES.map((item) => (
              <article key={item.title} className="industry-card">
                <h3>{item.title}</h3>
                <p>{item.text}</p>
              </article>
            ))}
          </div>
        </div>
      </section>

      <section id="features" className="home-section home-features">
        <div className="home-section-head">
          <p className="kicker">Features</p>
          <h2>Level up everyday work</h2>
          <p>Less re-entry, faster handoffs, and reports that stay in sync with the floor.</p>
        </div>
        {FEATURE_BANDS.map((band) => (
          <article key={band.id} className={`feature-band${band.flip ? ' is-flip' : ''}`}>
            <div className="feature-band-copy">
              <p className="kicker">{band.kicker}</p>
              <h3>{band.title}</h3>
              <p>{band.text}</p>
              <ul className="feature-bullets">
                {band.bullets.map((bullet) => (
                  <li key={bullet}>{bullet}</li>
                ))}
              </ul>
              <a href={trialUrl} className="text-link">
                {band.cta} <span aria-hidden="true">→</span>
              </a>
            </div>
          </article>
        ))}
      </section>

      <section className="home-band">
        <div className="home-section">
          <div className="home-section-head">
            <p className="kicker">Why UltiTech</p>
            <h2>Enterprise software, without the extra weight</h2>
          </div>
          <div className="why-grid">
            {WHY.map((item) => (
              <article key={item.title} className="why-card">
                <h3>{item.title}</h3>
                <p>{item.text}</p>
              </article>
            ))}
          </div>
        </div>
      </section>

      <section id="pricing" className="home-section">
        <div className="pricing-card">
          <p className="kicker">Pricing</p>
          <h2>All modules. One trial. No surprises.</h2>
          <p>
            Use the full platform for 14 days. No card up front, no per-app upsell during the trial —
            finance, sales, stock, HR, and operations are included.
          </p>
          <ul>
            <li>All core modules included</li>
            <li>Company users and roles</li>
            <li>Live reports from day one</li>
          </ul>
          <a href={trialUrl} className="start-trial-btn pricing-cta">
            Start now — it&apos;s free
          </a>
        </div>
      </section>

      <section className="home-cta">
        <h2>Unleash the next stage of your operations</h2>
        <p>Create an account, pick your company, and start posting real work in minutes.</p>
        <a href={trialUrl} className="start-trial-btn">
          Get started for free
        </a>
      </section>

      <footer className="home-footer">
        <div className="home-footer-grid">
          <div>
            <a href={cfg.homeUrl || './'} className="home-logo">
              <img src={logoIcon} alt="" className="ui-icon ui-icon-logo" aria-hidden="true" /> UltiTech ERP
            </a>
            <p>One platform for finance, sales, stock, people, and delivery.</p>
          </div>
          <div>
            <strong>Product</strong>
            <a href="#modules">Modules</a>
            <a href="#industries">Industries</a>
            <a href="#features">Features</a>
            <a href="#pricing">Pricing</a>
          </div>
          <div>
            <strong>Account</strong>
            <a href={loginUrl}>Login</a>
            <a href={trialUrl}>Try for free</a>
          </div>
        </div>
        <p className="home-foot">&copy; {year} Ultimate General Trading</p>
      </footer>
    </div>
  )
}
