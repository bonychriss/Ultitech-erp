import { useEffect } from 'react'
import SiteChrome from '../components/SiteChrome.jsx'

function getCfg() {
  return window.__HOME_CFG__ || {}
}

const TRIAL_FEATURES = [
  'Full access to finance, sales, stock, HR, and operations',
  'Multi-company setup with role-based access',
  'Invoices, expenses, cash books, and live reports',
  'No card required to start',
  '14 days to try the full platform',
]

export default function PricingPage() {
  const cfg = getCfg()
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
    <SiteChrome active="pricing">
      <main className="erp-pricing-page">
        <section data-aos="zoom-in" className="erp-pricing-section">
          <div className="erp-pricing-header text-center max-w-2xl mx-auto">
            <h1 className="text-darken text-3xl font-bold">Pricing</h1>
            <p className="text-gray-500 mt-3">
              Start with a free trial of the full UltiTech ERP suite - no card up front.
            </p>
          </div>

          <article className="erp-price-card erp-price-card--featured">
            <div className="erp-price-card-top">
              <h2 className="erp-price-card-name">Free trial</h2>
              <p className="erp-price-card-desc">Everything you need to run your business in one place.</p>
              <div className="erp-price-card-amount">
                <span className="erp-price-card-value">Free</span>
                <span className="erp-price-card-cadence">/ 14 days</span>
              </div>
            </div>
            <div className="erp-price-card-sep" />
            <p className="erp-price-card-features-label">What&apos;s included</p>
            <ul className="erp-price-card-features">
              {TRIAL_FEATURES.map((feature) => (
                <li key={feature}>
                  <svg className="erp-price-check" viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" strokeWidth="1.75" />
                    <path
                      d="M8.5 12.5l2.2 2.2 4.8-5"
                      fill="none"
                      stroke="currentColor"
                      strokeWidth="1.75"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                    />
                  </svg>
                  <span>{feature}</span>
                </li>
              ))}
            </ul>
            <a href={trialUrl} className="erp-price-card-cta">
              Start free trial
            </a>
          </article>
        </section>
      </main>
    </SiteChrome>
  )
}
