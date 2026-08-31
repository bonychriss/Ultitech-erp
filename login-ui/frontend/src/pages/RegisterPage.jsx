import { useMemo, useState } from 'react'
import {
  PersonIcon,
  LockIcon,
  EmailIcon,
  EyeIcon,
  EyeSlashIcon,
  scorePassword,
} from '../components/icons.jsx'
import './login.css'

function getRegisterConfig() {
  const cfg = window.__REGISTER_CFG__
  if (cfg && typeof cfg === 'object') {
    return cfg
  }
  return {
    title: 'Create account',
    subtitle: '',
    illustrationUrl: '',
    loginUrl: '#',
    registerActionUrl: '',
    companySlug: '',
    error: '',
    success: '',
    companyLogoUrl: '',
    departments: [],
    values: {
      fullName: '',
      email: '',
      department: '',
    },
  }
}

export default function RegisterPage() {
  const cfg = useMemo(() => getRegisterConfig(), [])
  const [submitting, setSubmitting] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [password, setPassword] = useState('')

  const strength = scorePassword(password)

  const handleSubmit = () => {
    setSubmitting(true)
  }

  return (
    <div className="main">
      <div className="container">
        <div className="signin-content">
          <div className="signin-image">
            <figure>
              {cfg.illustrationUrl ? (
                <img src={cfg.illustrationUrl} alt="sign up" />
              ) : null}
            </figure>
            <a href={cfg.loginUrl} className="signup-image-link">I am already member</a>
          </div>

          <div className="signin-form">
            <h2 className="form-title">{cfg.title || 'Create account'}</h2>
            {cfg.subtitle ? <p className="form-subtitle">{cfg.subtitle}</p> : null}

            {cfg.companyLogoUrl ? (
              <div className="company-logo-wrap">
                <img src={cfg.companyLogoUrl} alt={cfg.title || 'Company'} />
              </div>
            ) : null}

            {cfg.error ? <div className="alert alert-error">{cfg.error}</div> : null}
            {cfg.success ? <div className="alert alert-success">{cfg.success}</div> : null}

            <form
              method="post"
              action={cfg.registerActionUrl || undefined}
              className="register-form"
              id="register-form"
              onSubmit={handleSubmit}
            >
              <input type="hidden" name="company_slug" value={cfg.companySlug || ''} />

              <div className="form-group">
                <label htmlFor="full_name">
                  <PersonIcon />
                </label>
                <input
                  type="text"
                  name="full_name"
                  id="full_name"
                  placeholder="Full Name"
                  autoComplete="name"
                  required
                  autoFocus
                  defaultValue={cfg.values?.fullName || ''}
                />
              </div>

              <div className="form-group">
                <label htmlFor="email">
                  <EmailIcon />
                </label>
                <input
                  type="email"
                  name="email"
                  id="email"
                  placeholder="Email Address"
                  autoComplete="email"
                  required
                  defaultValue={cfg.values?.email || ''}
                />
              </div>

              <div className="form-group form-group-select">
                <select
                  id="reg_department"
                  name="department"
                  className="form-select"
                  required
                  defaultValue={cfg.values?.department || ''}
                >
                  <option value="" disabled>Select department</option>
                  {(cfg.departments || []).map((dept) => (
                    <option key={dept} value={dept}>{dept}</option>
                  ))}
                </select>
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
                  autoComplete="new-password"
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
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

              {strength ? (
                <div className="strength-wrap">
                  <div className="strength-meter">
                    <div
                      className="strength-bar"
                      style={{ width: strength.width, background: strength.color }}
                    />
                  </div>
                  <div className="strength-text" style={{ color: strength.color }}>
                    {strength.label}
                  </div>
                </div>
              ) : null}

              <div className="form-group form-button">
                <button type="submit" className="form-submit" disabled={submitting}>
                  {submitting ? 'Signing up...' : 'Sign Up'}
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  )
}
