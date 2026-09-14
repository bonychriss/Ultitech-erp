import { useEffect } from 'react'
import SiteChrome from '../components/SiteChrome.jsx'
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
import newsFeatured from '../assets/news/featured.png'
import newsFinance from '../assets/news/finance.png'
import newsPayroll from '../assets/news/payroll.png'
import newsStock from '../assets/news/stock.png'
import testimonialPortrait from '../assets/testimonials/portrait.png'

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

const FEATURED_NEWS = {
  tag: 'NEWS',
  title: 'UltiTech unifies finance, sales, and stock in one cloud ERP workspace',
  excerpt:
    'Companies are consolidating invoices, expenses, cash books, and inventory into UltiTech so every team posts to the same live books...',
  image: newsFeatured,
}

const NEWS_ITEMS = [
  {
    tag: 'PRESS RELEASE',
    title: 'UltiTech opens a 14-day full-suite free trial with no card required',
    excerpt: 'Try finance, sales, HR, and operations together before committing to a paid plan...',
    image: newsFinance,
  },
  {
    tag: 'NEWS',
    title: 'Payroll and payments now post straight into UltiTech cash books',
    excerpt: 'Run payroll and collect payments without exporting data into another tool...',
    image: newsPayroll,
  },
  {
    tag: 'NEWS',
    title: 'Live stock and delivery reports keep operations aligned with the books',
    excerpt: 'Warehouse moves and delivery status feed the same reports finance uses at month-end...',
    image: newsStock,
  },
]

export default function HomePage() {
  const cfg = getCfg()
  const loginUrl = cfg.loginUrl || 'login.php'
  const trialUrl = cfg.trialUrl || 'free-trial.php'

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
    <SiteChrome active="home">
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

      <div className="container px-4 lg:px-8 mx-auto max-w-screen-xl">
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

        <div id="testimonials" className="erp-testimonial mt-24 mb-16">
          <div data-aos="zoom-in-right" className="erp-testimonial-copy">
            <div className="erp-testimonial-label">
              <span className="erp-testimonial-rule" aria-hidden="true" />
              <p>TESTIMONIAL</p>
            </div>
            <h2 className="font-semibold text-darken text-2xl">What They Say?</h2>
            <p className="text-gray-500 my-5">
              UltiTech has got more than 100k positive ratings from our users around the world.
            </p>
            <p className="text-gray-500 my-5">
              Some of the owners and teams were greatly helped by UltiTech.
            </p>
            <p className="text-gray-500 my-5">Are you too? Please give your assessment</p>
            <a href={trialUrl} className="erp-testimonial-cta">
              <span>Write your assessment</span>
              <span className="erp-testimonial-cta-icon" aria-hidden="true">
                <svg className="w-5 h-5" viewBox="0 0 26 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path
                    d="M25.7071 8.70711C26.0976 8.31658 26.0976 7.68342 25.7071 7.2929L19.3431 0.928934C18.9526 0.538409 18.3195 0.538409 17.9289 0.928934C17.5384 1.31946 17.5384 1.95262 17.9289 2.34315L23.5858 8L17.9289 13.6569C17.5384 14.0474 17.5384 14.6805 17.9289 15.0711C18.3195 15.4616 18.9526 15.4616 19.3431 15.0711L25.7071 8.70711ZM-8.74228e-08 9L25 9L25 7L8.74228e-08 7L-8.74228e-08 9Z"
                    fill="currentColor"
                  />
                </svg>
              </span>
            </a>
          </div>
          <div data-aos="zoom-in-left" className="erp-testimonial-media">
            <div className="erp-testimonial-card">
              <div className="erp-testimonial-photo">
                <img src={testimonialPortrait} alt="UltiTech customer" />
                <button type="button" className="erp-testimonial-next" aria-label="Next testimonial">
                  <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path
                      fillRule="evenodd"
                      d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z"
                      clipRule="evenodd"
                    />
                  </svg>
                </button>
              </div>
              <blockquote className="erp-testimonial-quote">
                <p>
                  Thank you so much for your help. It&apos;s exactly what I&apos;ve been looking for.
                  You won&apos;t regret it. It really saves me time and effort. UltiTech is exactly
                  what our business has been lacking.
                </p>
                <footer>
                  <cite>Gloria Rose</cite>
                  <div className="erp-testimonial-rating">
                    <span aria-hidden="true">★★★★★</span>
                    <small>12 reviews from UltiTech customers</small>
                  </div>
                </footer>
              </blockquote>
            </div>
          </div>
        </div>

        <div id="news" className="erp-news">
          <div data-aos="zoom-in" className="erp-news-header">
            <h2 className="text-darken text-2xl font-semibold">Latest News and Resources</h2>
            <p className="text-gray-500 my-5">
              See the developments that have occurred to UltiTech in the world
            </p>
          </div>

          <div data-aos="zoom-in-up" className="erp-news-grid">
            <article className="erp-news-featured">
              <img src={FEATURED_NEWS.image} alt="" />
              <span className="erp-news-tag">{FEATURED_NEWS.tag}</span>
              <h3>{FEATURED_NEWS.title}</h3>
              <p>{FEATURED_NEWS.excerpt}</p>
              <a href={trialUrl}>Read more</a>
            </article>

            <div className="erp-news-list">
              {NEWS_ITEMS.map((item) => (
                <article key={item.title} className="erp-news-item">
                  <div className="erp-news-item-media">
                    <img className="rounded-xl" src={item.image} alt="" />
                    <span className="erp-news-tag erp-news-tag--overlay">{item.tag}</span>
                  </div>
                  <div className="erp-news-item-copy">
                    <h3>{item.title}</h3>
                    <p>{item.excerpt}</p>
                  </div>
                </article>
              ))}
            </div>
          </div>
        </div>
      </div>
    </SiteChrome>
  )
}
