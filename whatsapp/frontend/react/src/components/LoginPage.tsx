import { useState, type FormEvent } from 'react'
import { MdWhatsapp } from 'react-icons/md'
import { api, type Bootstrap } from '../api'

type Props = {
  onLoggedIn: (boot: Bootstrap) => void
}

export function LoginPage({ onLoggedIn }: Props) {
  const [username, setUsername] = useState('admin')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      const data = await api.login(username, password)
      onLoggedIn(data)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Login failed')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="login-page">
      <form className="login-card" onSubmit={onSubmit}>
        <div className="brand" style={{ border: 'none', margin: 0, padding: '0 0 1rem' }}>
          <div className="brand-mark">
            <MdWhatsapp />
          </div>
          <div>
            <strong>WhatsApp Bot</strong>
            <span>Customers and staff messaging</span>
          </div>
        </div>
        <h1>Sign in</h1>
        <p>Send updates to customers and your team from one console.</p>
        {error ? <div className="error-text">{error}</div> : null}
        <div className="field">
          <label htmlFor="username">Username</label>
          <input
            id="username"
            value={username}
            onChange={(e) => setUsername(e.target.value)}
            autoComplete="username"
            required
          />
        </div>
        <div className="field">
          <label htmlFor="password">Password</label>
          <input
            id="password"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="current-password"
            required
          />
        </div>
        <button className="btn btn-primary" type="submit" disabled={busy}>
          {busy ? 'Signing in...' : 'Sign in'}
        </button>
      </form>
    </div>
  )
}