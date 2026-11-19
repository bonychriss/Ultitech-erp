import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { ApiError } from '../lib/api'
import { useAuth } from '../lib/auth'
import { Button, Card, ErrorBanner, Input, Label } from '../components/ui'

export function LoginPage() {
  const { token, login } = useAuth()
  const [email, setEmail] = useState('admin@ultitech.local')
  const [password, setPassword] = useState('password')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  if (token) return <Navigate to="/" replace />

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setLoading(true)
    setError('')
    try {
      await login(email, password)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to sign in')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center px-4">
      <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(91,45,142,0.18),transparent_40%),radial-gradient(circle_at_bottom_right,rgba(91,45,142,0.12),transparent_35%)]" />
      <Card className="relative w-full max-w-md">
        <div className="mb-6">
          <div className="text-xs uppercase tracking-[0.24em] text-[var(--brand)]">UltiTech</div>
          <div className="mt-2 ua-page-title font-normal ua-page-title">Accounting</div>
          <p className="mt-2 text-sm text-[var(--muted)]">Sign in to the multi-company financial platform.</p>
        </div>
        {error ? <ErrorBanner message={error} /> : null}
        <form className="space-y-4" onSubmit={onSubmit}>
          <div>
            <Label>Email</Label>
            <Input value={email} onChange={(e) => setEmail(e.target.value)} type="email" required />
          </div>
          <div>
            <Label>Password</Label>
            <Input value={password} onChange={(e) => setPassword(e.target.value)} type="password" required />
          </div>
          <Button className="w-full" disabled={loading}>
            {loading ? 'Signing in…' : 'Sign in'}
          </Button>
        </form>
      </Card>
    </div>
  )
}
