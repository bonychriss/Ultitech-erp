import SiteChrome from '../components/SiteChrome.jsx'
import {
  ArrowRightIcon,
  ClockIcon,
  InstagramIcon,
  MailIcon,
  MapPinIcon,
  PhoneIcon,
  WhatsAppIcon,
} from '../components/Icons.jsx'
import { formatPhone, getCfg, getContact, getTexts, useAos } from '../siteConfig.js'

export default function ContactPage() {
  useAos()
  const cfg = getCfg()
  const contact = getContact()
  const text = getTexts()
  const trialUrl = cfg.trialUrl || 'free-trial.php'

  const channels = [
    contact.phone_tel && {
      id: 'call',
      label: text.call_label,
      value: formatPhone(contact.phone),
      action: text.call_action,
      href: `tel:${contact.phone_tel}`,
      Icon: PhoneIcon,
    },
    contact.whatsapp_url && {
      id: 'whatsapp',
      label: text.whatsapp_label,
      value: formatPhone(contact.whatsapp || contact.phone),
      action: text.whatsapp_action,
      href: `${contact.whatsapp_url}?text=${encodeURIComponent(text.whatsapp_greeting)}`,
      Icon: WhatsAppIcon,
      external: true,
    },
    contact.email && {
      id: 'email',
      label: text.email_label,
      value: contact.email,
      action: text.email_action,
      href: `mailto:${contact.email}?subject=${encodeURIComponent(text.email_subject)}`,
      Icon: MailIcon,
    },
    contact.instagram_url && {
      id: 'instagram',
      label: text.instagram_label,
      value: `@${contact.instagram_handle}`,
      action: text.instagram_action,
      href: contact.instagram_url,
      Icon: InstagramIcon,
      external: true,
    },
  ].filter(Boolean)

  return (
    <SiteChrome active="contact">
      <main className="erp-info-page">
        <header className="erp-info-hero" data-aos="fade-up">
          <p className="erp-info-eyebrow">{text.contact_eyebrow}</p>
          <h1 className="erp-info-title">{text.contact_title}</h1>
          <p className="erp-info-lead">{text.contact_lead}</p>
        </header>

        <section className="erp-contact-grid" aria-label="Ways to reach us">
          {channels.map(({ id, label, value, action, href, Icon, external }, index) => (
            <a
              key={id}
              href={href}
              className={`erp-contact-card erp-contact-card--${id}`}
              data-aos="fade-up"
              data-aos-delay={index * 80}
              {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
            >
              <span className="erp-contact-icon">
                <Icon className="erp-contact-icon-svg" />
              </span>
              <span className="erp-contact-label">{label}</span>
              <span className="erp-contact-value">
                {id === 'email' && value.includes('@') ? (
                  <>
                    {value.slice(0, value.indexOf('@') + 1)}
                    <wbr />
                    {value.slice(value.indexOf('@') + 1)}
                  </>
                ) : (
                  value
                )}
              </span>
              <span className="erp-contact-action">
                {action}
                <ArrowRightIcon className="erp-contact-arrow" />
              </span>
            </a>
          ))}
        </section>

        <section className="erp-contact-details" data-aos="fade-up">
          {contact.address ? (
            <div className="erp-contact-detail">
              <MapPinIcon className="erp-contact-detail-icon" />
              <div>
                <p className="erp-contact-detail-label">{text.location_label}</p>
                <p className="erp-contact-detail-value">{contact.address}</p>
              </div>
            </div>
          ) : null}
          {contact.hours ? (
            <div className="erp-contact-detail">
              <ClockIcon className="erp-contact-detail-icon" />
              <div>
                <p className="erp-contact-detail-label">{text.hours_label}</p>
                <p className="erp-contact-detail-value">{contact.hours}</p>
              </div>
            </div>
          ) : null}
        </section>

        <section className="erp-info-cta" data-aos="fade-up">
          <div>
            <h2>{text.cta_title}</h2>
            <p>{text.cta_text}</p>
          </div>
          <a href={trialUrl} className="erp-info-btn erp-info-btn--primary">
            {text.cta_button}
          </a>
        </section>
      </main>
    </SiteChrome>
  )
}
