import SiteChrome from '../components/SiteChrome.jsx'
import { getCfg, useAos } from '../siteConfig.js'

export default function FaqPage() {
  useAos()
  const cfg = getCfg()
  const trialUrl = cfg.trialUrl || 'free-trial.php'
  const contactUrl = cfg.contactUrl || 'contact.php'
  const items = Array.isArray(cfg.faq) ? cfg.faq : []

  const groups = []
  items.forEach((item) => {
    const name = item.group || 'General'
    let group = groups.find((g) => g.name === name)
    if (!group) {
      group = { name, items: [] }
      groups.push(group)
    }
    group.items.push(item)
  })

  return (
    <SiteChrome active="faq">
      <main className="erp-info-page">
        <header className="erp-info-hero" data-aos="fade-up">
          <p className="erp-info-eyebrow">FAQ</p>
          <h1 className="erp-info-title">Frequently asked questions</h1>
          <p className="erp-info-lead">
            Quick answers about the free trial, modules, users and support. Can&apos;t find what you need?{' '}
            <a href={contactUrl}>Contact the UltiTech team</a>.
          </p>
        </header>

        {groups.map((group) => (
          <section key={group.name} className="erp-faq-group" data-aos="fade-up">
            <h2 className="erp-faq-group-title">{group.name}</h2>
            <div className="erp-faq-list">
              {group.items.map((item) => (
                <details key={item.question} className="erp-faq-item">
                  <summary className="erp-faq-question">{item.question}</summary>
                  <p className="erp-faq-answer">{item.answer}</p>
                </details>
              ))}
            </div>
          </section>
        ))}

        <section className="erp-info-cta" data-aos="fade-up">
          <div>
            <h2>Still have questions?</h2>
            <p>Talk to our team, or start a 14-day free trial of the full suite. No card required.</p>
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
