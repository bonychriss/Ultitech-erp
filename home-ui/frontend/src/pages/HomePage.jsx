import { useEffect, useState } from 'react'
import salesIcon from '../assets/modules/sales-icon.png'
import accountingIcon from '../assets/modules/accounting-icon.png'
import stockIcon from '../assets/modules/stock-icon.png'
import payrollIcon from '../assets/modules/payroll-icon.png'
import deliveryIcon from '../assets/modules/delivery-icon.png'
import statementIcon from '../assets/modules/statement-icon.png'
import heroHandCards from '../assets/hero/hand-cards.png'
import featureSalesBg from '../assets/features/sales-invoice.jpg'
import featureFinanceBg from '../assets/features/finance.png'
import featureOperationsBg from '../assets/features/operations.png'
import productOwnersBg from '../assets/features/product-owners.png'
import productTeamsBg from '../assets/features/product-teams.png'
import stockScreen from '../assets/screens/inventory-stock.jpg'
import payrollScreen from '../assets/screens/hr-payroll.jpg'

function getCfg() {
  return window.__HOME_CFG__ || {}
}

const PRODUCT_CARDS = [
  {
    label: 'For owners',
    title: 'Accounting hub',
    text: 'Balances, expenses, journal, and reconciliation in one place.',
    image: productOwnersBg,
    alt: 'UltiTech accounting and finance illustration',
    cta: 'See finance',
    href: 'trial',
  },
  {
    label: 'For teams',
    title: 'Sales desk',
    text: 'Invoices, orders, targets, and collections on one screen.',
    image: productTeamsBg,
    alt: 'UltiTech sales team illustration',
    cta: 'Open sales desk',
    href: 'login',
    teams: true,
  },
]

const MODULES = [
  { title: 'Sales & invoices', icon: salesIcon, tint: '#fee2e2' },
  { title: 'Finance & balances', icon: accountingIcon, tint: '#fce7f3' },
  { title: 'Stock control', icon: stockIcon, tint: '#ccfbf1' },
  { title: 'HR & payroll', icon: payrollIcon, tint: '#dbeafe' },
  { title: 'Delivery', icon: deliveryIcon, tint: '#ffedd5' },
  { title: 'Live reports', icon: statementIcon, tint: '#fef9c3' },
]

const FEATURE_CARDS = [
  {
    title: 'Sales, invoicing & collections',
    text: 'Quotes, orders, and invoices in one flow so cash in stays tied to the books.',
    bg: featureSalesBg,
  },
  {
    title: 'Finance, expenses & cash',
    text: 'Balances, expenses, VAT, and cash books that match what your teams actually post.',
    bg: featureFinanceBg,
  },
  {
    title: 'Stock, HR & operations',
    text: 'Inventory, payroll, attendance, and delivery stay connected to the same company data.',
    bg: featureOperationsBg,
  },
]

const WHY_POINTS = [
  'One platform for every department - no extra app per team.',
  'Role-based access and company isolation keep tenant data separate.',
  'Start with what you need, then grow into the rest of the suite.',
]

export default function HomePage() {
  const cfg = getCfg()
  const loginUrl = cfg.loginUrl || 'login.php'
  const trialUrl = cfg.trialUrl || 'free-trial.php'
  const year = cfg.year || new Date().getFullYear()
  const [navOpen, setNavOpen] = useState(false)

  useEffect(() => {
    let cancelled = false
    ;(async () => {
      try {
        if (!window.AOS) {
          await new Promise((resolve, reject) => {
            const s = document.createElement('script')
            s.src = 'https://unpkg.com/aos@next/dist/aos.js'
            s.onload = resolve
            s.onerror = reject
            document.body.appendChild(s)
          })
        }
        if (!cancelled && window.AOS) {
          window.AOS.init({ once: true, duration: 700, easing: 'ease-out-cubic' })
        }
      } catch {
        // Animation library is optional.
      }
    })()
    return () => {
      cancelled = true
    }
  }, [])

  return (
    <div className="sk-page antialiased text-gray-700">
      <header className="w-full erp-nav">
        <div className="flex flex-col max-w-screen-xl px-8 mx-auto md:items-center md:justify-between md:flex-row">
          <div className="flex flex-row items-center justify-between py-6">
            <a
              href={cfg.homeUrl || './'}
              className="text-lg font-bold tracking-wide text-gray-900 rounded-lg focus:outline-none"
            >
              UltiTech
            </a>
            <button
              type="button"
              className="rounded-lg md:hidden focus:outline-none"
              aria-label="Menu"
              onClick={() => setNavOpen((o) => !o)}
            >
              <svg fill="currentColor" viewBox="0 0 20 20" className="w-6 h-6">
                {navOpen ? (
                  <path
                    fillRule="evenodd"
                    d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                    clipRule="evenodd"
                  />
                ) : (
                  <path
                    fillRule="evenodd"
                    d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM9 15a1 1 0 011-1h6a1 1 0 110 2h-6a1 1 0 01-1-1z"
                    clipRule="evenodd"
                  />
                )}
              </svg>
            </button>
          </div>
          <nav
            className={`h-0 md:h-auto flex flex-col flex-grow md:items-center pb-4 md:pb-0 md:flex md:justify-end md:flex-row origin-top duration-300 ${
              navOpen ? 'h-full scale-y-100' : 'scale-y-0 md:scale-y-100'
            }`}
          >
            <a className="px-4 py-2 mt-2 text-sm md:mt-8 md:ml-4 hover:text-gray-900" href="#home">
              Home
            </a>
            <a className="px-4 py-2 mt-2 text-sm md:mt-8 md:ml-4 hover:text-gray-900" href="#modules">
              Modules
            </a>
            <a className="px-4 py-2 mt-2 text-sm md:mt-8 md:ml-4 hover:text-gray-900" href="#product">
              Product
            </a>
            <a className="px-4 py-2 mt-2 text-sm md:mt-8 md:ml-4 hover:text-gray-900" href="#pricing">
              Pricing
            </a>
            <a
              className="px-4 py-1.5 mt-2 text-xs text-center bg-white text-gray-800 rounded-full md:mt-8 md:ml-4 erp-btn-ghost erp-nav-btn"
              href={loginUrl}
            >
              Login
            </a>
            <a
              className="px-4 py-1.5 mt-2 text-xs text-center bg-yellow-500 rounded-full md:mt-8 md:ml-4 erp-btn-primary erp-nav-btn"
              href={trialUrl}
            >
              Free trial
            </a>
          </nav>
        </div>
      </header>

      <section
        id="home"
        className="erp-hero erp-hero--bleed"
        style={{ '--hero-bg-image': `url(${heroHandCards})` }}
      >
        <div className="erp-hero-media" aria-hidden="true" />
        <div className="erp-hero-scrim" aria-hidden="true" />
        <div className="erp-hero-copy max-w-screen-xl px-8 mx-auto">
          <div className="max-w-xl text-left erp-hero-copy-panel">
            <h1 data-aos="fade-up" className="erp-brand-mark erp-brand-mark--on-media erp-hero-title">
              One platform. Every part of your business.
            </h1>
            <p data-aos="fade-up" data-aos-delay="150" className="leading-normal erp-hero-lead mt-3 mb-6 text-gray-600">
              Connect finance, sales, inventory, payroll, and operations in one system built to keep
              your business moving.
            </p>
            <div
              data-aos="fade-up"
              data-aos-delay="300"
              className="flex flex-col sm:flex-row items-start sm:items-center gap-3"
            >
              <a
                href={trialUrl}
                className="inline-block bg-yellow-500 text-base font-bold rounded-full py-3 px-7 erp-btn-primary focus:outline-none transform transition hover:scale-105 duration-300 ease-in-out"
              >
                Start free trial
              </a>
              <a
                href={loginUrl}
                className="inline-block px-6 py-3 text-base font-semibold text-darken erp-link-login focus:outline-none transform transition hover:scale-105 duration-300"
              >
                Sign in to your company
              </a>
            </div>
          </div>
        </div>
      </section>

      <div className="text-white -mt-1 z-40 relative">
        <svg className="xl:h-40 xl:w-full" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 120" preserveAspectRatio="none">
          <path
            d="M600,112.77C268.63,112.77,0,65.52,0,7.23V120H1200V7.23C1200,65.52,931.37,112.77,600,112.77Z"
            fill="currentColor"
          />
        </svg>
        <div className="bg-white w-full h-12 -mt-px" />
      </div>

      <div className="container px-4 lg:px-8 mx-auto max-w-screen-xl overflow-x-hidden">
        <div className="max-w-4xl mx-auto">
          <h2 className="text-center mb-6 text-gray-400 font-medium">Built for every department</h2>
          <div className="grid grid-cols-3 lg:grid-cols-6 gap-4 justify-items-center">
            {MODULES.map((mod) => (
              <div key={mod.title} className="erp-module-chip" style={{ background: mod.tint }}>
                <img src={mod.icon} alt="" className="h-7 w-7" />
                <span>{mod.title}</span>
              </div>
            ))}
          </div>
        </div>

        <div id="modules" data-aos="flip-up" className="max-w-xl mx-auto text-center mt-24">
          <h2 className="font-bold text-darken my-3 text-2xl">
            All-In-One <span className="text-yellow-500">Cloud ERP.</span>
          </h2>
          <p className="leading-relaxed text-gray-500">
            One suite for finance, sales, stock, and people - without juggling spreadsheets or
            disconnected tools.
          </p>
        </div>

        <div className="erp-feature-grid mt-20">
          {FEATURE_CARDS.map((card, index) => (
            <article
              key={card.title}
              data-aos="fade-up"
              data-aos-delay={index * 150}
              className="erp-feature"
              style={{ '--feature-bg': `url(${card.bg})` }}
            >
              <div className="erp-feature-bg" aria-hidden="true" />
              <div className="erp-feature-scrim" aria-hidden="true" />
              <div className="erp-feature-content text-center">
                <h3 className="font-medium text-xl mb-3 text-darken">{card.title}</h3>
                <p className="text-gray-600">{card.text}</p>
              </div>
            </article>
          ))}
        </div>

        <div id="product" className="mt-28">
          <div data-aos="flip-down" className="text-center max-w-screen-md mx-auto">
            <h2 className="text-3xl font-bold mb-4">
              What is <span className="text-yellow-500">UltiTech?</span>
            </h2>
            <p className="text-gray-500">
              UltiTech ERP is a multi-company platform for recording sales, expenses, cash movements,
              stock, payroll, and reports - so owners and teams see the same truth in real time.
            </p>
          </div>

          <div className="erp-product-two mt-12">
            {PRODUCT_CARDS.map((item, index) => (
              <article
                key={item.title}
                data-aos={index === 0 ? 'fade-right' : 'fade-left'}
                className={`erp-product-card erp-product-card--illustrated${item.teams ? ' erp-product-card--teams' : ''}`}
                style={{ '--product-bg': `url(${item.image})` }}
              >
                <div className="erp-product-bg" aria-hidden="true" />
                <div className="erp-product-scrim" aria-hidden="true" />
                <div className="erp-product-content">
                  <p
                    className={`erp-product-column-label${item.teams ? ' erp-product-column-label--teams' : ''}`}
                  >
                    {item.label}
                  </p>
                  <h3 className="erp-product-title">{item.title}</h3>
                  <p className="erp-product-text">{item.text}</p>
                  <a
                    href={item.href === 'login' ? loginUrl : trialUrl}
                    className={`erp-product-link${item.teams ? ' erp-product-link--teams' : ''}`}
                  >
                    {item.cta}
                  </a>
                </div>
              </article>
            ))}
          </div>
        </div>

        <div className="sm:flex items-center sm:space-x-8 mt-36">
          <div data-aos="fade-right" className="sm:w-1/2 relative">
            <div className="bg-yellow-500 rounded-full absolute w-12 h-12 z-0 -left-4 -top-3 animate-pulse" />
            <h2 className="font-semibold text-2xl relative z-50 text-darken lg:pr-10">
              Everything you used to do in separate tools,{' '}
              <span className="text-yellow-500">you can do in UltiTech</span>
            </h2>
            <p className="py-5 lg:pr-32 text-gray-500">
              Manage invoices, expenses, cash books, stock, and payroll in one secure cloud workspace -
              with reports that stay in sync with day-to-day posting.
            </p>
            <ul className="space-y-3 text-gray-600">
              {WHY_POINTS.map((point) => (
                <li key={point} className="flex gap-3">
                  <span className="text-yellow-500 font-bold">•</span>
                  <span>{point}</span>
                </li>
              ))}
            </ul>
          </div>
          <div data-aos="fade-left" className="sm:w-1/2 relative mt-10 sm:mt-0">
            <div className="erp-accent-block floating w-24 h-24 absolute rounded-lg z-0 -top-3 -left-3" />
            <img className="rounded-xl z-40 relative w-full erp-shot" src={stockScreen} alt="Stock control dashboard" />
            <div className="bg-yellow-500 w-40 h-40 floating absolute rounded-lg z-10 -bottom-3 -right-3" />
          </div>
        </div>

        <div className="sm:flex items-center sm:space-x-8 mt-36 flex-row-reverse">
          <div data-aos="fade-left" className="sm:w-1/2 relative">
            <h2 className="font-semibold text-2xl text-darken lg:pl-6">
              Payroll, payments, and reports that{' '}
              <span className="text-yellow-500">match the books</span>
            </h2>
            <p className="py-5 lg:pl-6 text-gray-500">
              Run payroll, collect payments, and review live KPIs without exporting to another tool.
            </p>
          </div>
          <div data-aos="fade-right" className="sm:w-1/2 relative mt-10 sm:mt-0">
            <img className="rounded-xl w-full erp-shot" src={payrollScreen} alt="Payroll runs in UltiTech" />
          </div>
        </div>

        <div id="pricing" data-aos="zoom-in" className="mt-28 mb-10 text-center max-w-3xl mx-auto erp-pricing">
          <h2 className="text-darken text-2xl font-semibold">
            All modules. <span className="text-yellow-500">One free trial.</span>
          </h2>
          <p className="text-gray-500 my-5">
            Use the full platform for 14 days. No card up front - finance, sales, stock, HR, and
            operations are included.
          </p>
          <a
            href={trialUrl}
            className="inline-block px-8 py-4 bg-yellow-500 font-semibold rounded-full erp-btn-primary transform transition hover:scale-105 duration-300"
          >
            Start now - it&apos;s free
          </a>
        </div>
      </div>

      <footer className="erp-footer mt-10">
        <p className="text-center text-sm text-gray-500 py-8">
          UltiTech &copy; {year} Ultimate General Trading
        </p>
      </footer>
    </div>
  )
}
