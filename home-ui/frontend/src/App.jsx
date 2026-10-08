import HomePage from './pages/HomePage.jsx'
import PricingPage from './pages/PricingPage.jsx'
import ContactPage from './pages/ContactPage.jsx'
import AboutPage from './pages/AboutPage.jsx'

const PAGES = { pricing: PricingPage, contact: ContactPage, about: AboutPage }

function getPage() {
  const cfg = window.__HOME_CFG__ || {}
  const page = String(cfg.page || 'home').toLowerCase()
  if (PAGES[page]) return page
  const path = String(window.location?.pathname || '').toLowerCase()
  return Object.keys(PAGES).find((key) => path.includes(key)) || 'home'
}

export default function App() {
  const Page = PAGES[getPage()] || HomePage
  return <Page />
}
