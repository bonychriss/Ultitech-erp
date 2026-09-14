import HomePage from './pages/HomePage.jsx'
import PricingPage from './pages/PricingPage.jsx'

function getPage() {
  const cfg = window.__HOME_CFG__ || {}
  const page = String(cfg.page || 'home').toLowerCase()
  if (page === 'pricing') return 'pricing'
  const path = String(window.location?.pathname || '').toLowerCase()
  if (path.includes('pricing')) return 'pricing'
  return 'home'
}

export default function App() {
  return getPage() === 'pricing' ? <PricingPage /> : <HomePage />
}
