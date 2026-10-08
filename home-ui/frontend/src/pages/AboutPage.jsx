import SiteChrome from '../components/SiteChrome.jsx'
import { getCfg, getContact, useAos } from '../siteConfig.js'

const MODULES = [
  'Sales & invoices',
  'Finance & balances',
  'Stock control',
  'HR & payroll',
  'Delivery',
  'Live reports',
]

const PRINCIPLES = [
  {
    title: 'One source of truth',
    text: 'Every sale, payment and stock movement is recorded once and shows up everywhere it matters, so departments stop reconciling separate spreadsheets.',
  },
  {
    title: 'Built around your team',
    text: 'Multi-company setup, role-based access and approval steps let each person see and do exactly what their job needs.',
  },
  {
    title: 'Simple to start',
    text: 'A 14-day free trial of the full suite with no card required, so you can test UltiTech ERP with your own data before you commit.',
  },
]

export default function AboutPage() {
  useAos()
  const cfg = getCfg()
  const contact = getContact()
  const trialUrl = cfg.trialUrl || 'free-trial.php'
  const contactUrl = cfg.contactUrl || 'contact.php'

  return (
    <SiteChrome active="about">
      <main className="erp-info-page">
        <header className="erp-info-hero" data-aos="fade-up">
          <p className="erp-info-eyebrow">About us</p>
          <h1 className="erp-info-title">Business software that keeps every department in step</h1>
          <p className="erp-info-lead">
            UltiTech builds UltiTech ERP, a cloud system that brings finance, sales, stock, people and delivery
            into one place, so the whole business works from the same live numbers.
          </p>
        </header>

        <section className="erp-about-story" data-aos="fade-up">
          <div>
            <h2 className="erp-about-heading">What we do</h2>
            <p>
              Growing businesses often run on paper vouchers, spreadsheets and separate apps that never quite agree.
              UltiTech ERP replaces them with one secure workspace for invoicing, stock control, expenses and cash
              books, payroll, deliveries and reporting.
            </p>
            <p>
              Managers get live reports without waiting for month end, and staff spend less time copying figures
              from one system to another.
            </p>
          </div>
          <ul className="erp-about-modules" aria-label="UltiTech ERP modules">
            {MODULES.map((name) => (
              <li key={name}>{name}</li>
            ))}
          </ul>
        </section>

        <section className="erp-about-principles" aria-label="How we work">
          {PRINCIPLES.map((item, index) => (
            <article key={item.title} className="erp-about-principle" data-aos="fade-up" data-aos-delay={index * 80}>
              <span className="erp-about-principle-num">{String(index + 1).padStart(2, '0')}</span>
              <h3>{item.title}</h3>
              <p>{item.text}</p>
            </article>
          ))}
        </section>

        <section className="erp-info-cta" data-aos="fade-up">
          <div>
            <h2>{contact.address ? `Based in ${contact.address}` : 'Talk to us'}</h2>
            <p>We are happy to walk you through UltiTech ERP and answer your questions.</p>
          </div>
          <div className="erp-info-cta-actions">
            <a href={contactUrl} className="erp-info-btn erp-info-btn--ghost">
              Contact us
            </a>
            <a href={trialUrl} className="erp-info-btn erp-info-btn--primary">
              Start free trial
            </a>
          </div>
        </section>
      </main>
    </SiteChrome>
  )
}
