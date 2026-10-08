import { useState } from 'react'
import { ClockIcon, InstagramIcon, MailIcon, PhoneIcon } from './Icons.jsx'
import { formatPhone, getCfg, getContact, getTexts } from '../siteConfig.js'

export default function SiteChrome({ active = 'home', children }) {
  const cfg = getCfg()
  const contact = getContact()
  const homeUrl = cfg.homeUrl || './'
  const pricingUrl = cfg.pricingUrl || 'pricing.php'
  const aboutUrl = cfg.aboutUrl || 'about.php'
  const contactUrl = cfg.contactUrl || 'contact.php'
  const loginUrl = cfg.loginUrl || 'login.php'
  const trialUrl = cfg.trialUrl || 'free-trial.php'
  const year = cfg.year || new Date().getFullYear()
  const [navOpen, setNavOpen] = useState(false)
  const navLink = (key) =>
    `px-4 py-2 mt-2 text-sm md:mt-8 md:ml-4 hover:text-gray-900 erp-nav-link${active === key ? ' font-semibold text-gray-900' : ''}`

  return (
    <div className="sk-page antialiased text-gray-700">
      <header className="w-full erp-nav">
        <div className="flex flex-col max-w-screen-xl px-8 mx-auto md:items-center md:justify-between md:flex-row">
          <div className="flex flex-row items-center justify-between py-6">
            <a
              href={homeUrl}
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
            <a className={navLink('home')} href={homeUrl}>
              Home
            </a>
            <a className={navLink('modules')} href={`${homeUrl}#modules`}>
              Modules
            </a>
            <a className={navLink('product')} href={`${homeUrl}#product`}>
              Product
            </a>
            <a className={navLink('pricing')} href={pricingUrl}>
              Pricing
            </a>
            <a className={navLink('about')} href={aboutUrl}>
              About
            </a>
            <a className={navLink('contact')} href={contactUrl}>
              Contact
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

      {children}

      <footer className="erp-footer mt-10">
        <div className="max-w-screen-xl mx-auto px-8 py-12 flex flex-col md:flex-row md:justify-between gap-8">
          <div>
            <a href={homeUrl} className="font-bold text-darken text-lg">
              UltiTech
            </a>
            <p className="text-gray-500 mt-3 max-w-sm">{getTexts().footer_tagline}</p>
          </div>
          <div className="erp-footer-cols text-sm">
            <div className="flex flex-col gap-2">
              <strong className="text-darken">Product</strong>
              <a href={`${homeUrl}#modules`} className="text-gray-500 hover:text-gray-900">
                Modules
              </a>
              <a href={`${homeUrl}#product`} className="text-gray-500 hover:text-gray-900">
                Product
              </a>
              <a href={pricingUrl} className="text-gray-500 hover:text-gray-900">
                Pricing
              </a>
            </div>
            <div className="flex flex-col gap-2">
              <strong className="text-darken">Company</strong>
              <a href={aboutUrl} className="text-gray-500 hover:text-gray-900">
                About us
              </a>
              <a href={contactUrl} className="text-gray-500 hover:text-gray-900">
                Contact us
              </a>
            </div>
            <div className="flex flex-col gap-2">
              <strong className="text-darken">Get started</strong>
              <a href={trialUrl} className="text-gray-500 hover:text-gray-900">
                Free trial
              </a>
              <a href={loginUrl} className="text-gray-500 hover:text-gray-900">
                Login
              </a>
            </div>
            <div className="flex flex-col gap-2 erp-footer-contact">
              <strong className="text-darken">Contact</strong>
              {contact.phone_tel ? (
                <a href={`tel:${contact.phone_tel}`} className="text-gray-500 hover:text-gray-900">
                  <PhoneIcon className="erp-footer-icon" />
                  {formatPhone(contact.phone)}
                </a>
              ) : null}
              {contact.email ? (
                <a href={`mailto:${contact.email}`} className="text-gray-500 hover:text-gray-900">
                  <MailIcon className="erp-footer-icon" />
                  {contact.email}
                </a>
              ) : null}
              {contact.hours ? (
                <span className="text-gray-500">
                  <ClockIcon className="erp-footer-icon" />
                  {contact.hours}
                </span>
              ) : null}
              {contact.instagram_url ? (
                <a
                  href={contact.instagram_url}
                  className="text-gray-500 hover:text-gray-900"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  <InstagramIcon className="erp-footer-icon" />@{contact.instagram_handle}
                </a>
              ) : null}
            </div>
          </div>
        </div>
        <p className="text-center text-sm text-gray-500 py-6 border-t border-gray-200">
          UltiTech &copy; {year}
        </p>
      </footer>
    </div>
  )
}
