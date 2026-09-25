import NotificationsPage from './pages/NotificationsPage.jsx'
import NotificationsSettingsPage from './pages/NotificationsSettingsPage.jsx'

function getCfg() {
  return window.__NOTIFICATIONS_CFG__ || {}
}

export default function App() {
  const page = String(getCfg().page || 'list').toLowerCase()
  if (page === 'settings') {
    return <NotificationsSettingsPage />
  }
  return <NotificationsPage />
}
