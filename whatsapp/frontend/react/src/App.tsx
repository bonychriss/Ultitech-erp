import { useEffect, useState } from 'react'
import { api, type Bootstrap } from './api'
import { LoginPage } from './components/LoginPage'
import { WhatsAppApp } from './components/WhatsAppApp'

export default function App() {
  const [boot, setBoot] = useState<Bootstrap | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    api
      .bootstrap()
      .then(setBoot)
      .catch((err) => setError(err instanceof Error ? err.message : 'Failed to start'))
  }, [])

  if (error) return <div className="loading">{error}</div>
  if (!boot) return <div className="loading">Loading WhatsApp Bot...</div>

  if (!boot.authenticated || !boot.user) {
    return <LoginPage onLoggedIn={setBoot} />
  }

  return (
    <WhatsAppApp
      user={boot.user}
      settingsConfigured={boot.settingsConfigured}
      stats={boot.stats}
      onLogout={() =>
        setBoot({
          ...boot,
          authenticated: false,
          user: null,
          settingsConfigured: false,
          stats: { customers: 0, staff: 0, sentToday: 0 },
        })
      }
      onStatsRefresh={async () => {
        const next = await api.bootstrap()
        setBoot(next)
      }}
    />
  )
}