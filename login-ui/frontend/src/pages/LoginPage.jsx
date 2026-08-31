import { useMemo, useState } from 'react'
import {
  PersonIcon,
  LockIcon,
  EyeIcon,
  EyeSlashIcon,
} from '../components/icons.jsx'
import './login.css'

function getLoginConfig() {
  const cfg = window.__LOGIN_CFG__
  if (cfg && typeof cfg === 'object') {
    return cfg
  }
  return {
    title: 'Sign up',
    welcomeTitle: 'Welcome',
    subtitle: 'Here you log in securely',
    illustrationUrl: '',
    registerUrl: '#',
    loginActionUrl: '',
    companySlug: '',
    next: '',
    error: '',
    notice: '',
    companyLogoUrl: '',
  }
}

export default function LoginPage() {
  const cfg = useMemo(() => getLoginConfig(), [])
  const [submitting, setSubmitting] = useState(false)
  const [showPassword, setShowPassword] = useState(false)

  const handleSubmit = () => {
    setSubmitting(true)
  }

  const welcomeTitle = cfg.welcomeTitle || 'Welcome'
  const subtitle = cfg.subtitle || 'Here you log in securely'

  return (
    <div className="main">
      <div className="container">
        <div className="signin-content">
          <header className="mobile-welcome-header">
            {cfg.companyLogoUrl ? (
              <img src={cfg.companyLogoUrl} alt="" className="mobile-welcome-logo" />
            ) : null}
            <h1 className="mobile-welcome-title">{welcomeTitle}</h1>
            <p className="mobile-welcome-subtitle">{subtitle}</p>
          </header>

          <div className="signin-image">
            <figure>
              {cfg.illustrationUrl ? (
                <img src={cfg.illustrationUrl} alt="sign in" />
              ) : null}
            </figure>
            <a href={cfg.registerUrl} className="signup-image-link">Create an account</a>
          </div>

          <div className="signin-form">
            <h2 className="form-title">{cfg.title || 'Sign up'}</h2>

            {cfg.companyLogoUrl ? (
              <div className="company-logo-wrap">
                <img src={cfg.companyLogoUrl} alt={cfg.title || 'Company'} />
              </div>
            ) : null}

            {cfg.error ? <div className="alert alert-error">{cfg.error}</div> : null}
            {cfg.notice ? <div className="alert alert-info">{cfg.notice}</div> : null}

            <form
              method="post"
              action={cfg.loginActionUrl || undefined}
              className="register-form login-form-panel"
              id="login-form"
              onSubmit={handleSubmit}
            >
              <input type="hidden" name="company_slug" value={cfg.companySlug || ''} />
              {cfg.next ? <input type="hidden" name="next" value={cfg.next} /> : null}

              <div className="mobile-login-stack">
                <div className="mobile-form-fields">
                  <div className="form-group">
                    <label htmlFor="user">
                      <PersonIcon />
                    </label>
                    <input
                      type="text"
                      name="user"
                      id="user"
                      placeholder="Your Name"
                      autoComplete="username"
                      required
                    />
                  </div>

                  <div className="form-group form-group-password">
                    <label htmlFor="password">
                      <LockIcon />
                    </label>
                    <input
                      type={showPassword ? 'text' : 'password'}
                      name="password"
                      id="password"
                      placeholder="Password"
                      autoComplete="current-password"
                      required
                    />
                    <button
                      type="button"
                      className="toggle-password"
                      onClick={() => setShowPassword((prev) => !prev)}
                      aria-label={showPassword ? 'Hide password' : 'Show password'}
                    >
                      {showPassword ? <EyeIcon /> : <EyeSlashIcon />}
                    </button>
                  </div>

                  <div className="form-group remember-mobile">
                    <input type="checkbox" name="remember" id="remember-me" className="agree-term" value="1" />
                    <label htmlFor="remember-me" className="label-agree-term">
                      <span></span>
                      Remember me
                    </label>
                  </div>

                  <div className="mobile-form-actions">
                    <button
                      type="submit"
                      name="signin"
                      id="signin"
                      className="form-submit mobile-pill-btn mobile-pill-outline"
                      disabled={submitting}
                    >
                      {submitting ? 'Logging in...' : 'Log in'}
                    </button>
                  </div>
                </div>

                <a href={cfg.registerUrl} className="mobile-signup-link">Sign Up</a>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  )
}
