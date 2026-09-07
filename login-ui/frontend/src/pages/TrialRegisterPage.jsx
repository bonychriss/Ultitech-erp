import { useMemo, useState } from 'react'
import {
  PersonIcon,
  LockIcon,
  EmailIcon,
  PhoneIcon,
  EyeIcon,
  EyeSlashIcon,
  scorePassword,
} from '../components/icons.jsx'
import './login.css'

function getTrialConfig() {
  const cfg = window.__TRIAL_CFG__
  if (cfg && typeof cfg === 'object') {
    return cfg
  }
  return {
    title: 'Start your free trial',
    subtitle: 'Create your company workspace - 14 days, all modules included.',
    illustrationUrl: '',
    loginUrl: 'login.php',
    homeUrl: './',
    trialActionUrl: 'free-trial.php',
    error: '',
    success: '',
    values: {
      email: '',
      company_name: '',
      phone: '',
      country_code: '+255',
    },
  }
}

export default function TrialRegisterPage() {
  const cfg = useMemo(() => getTrialConfig(), [])
  const [submitting, setSubmitting] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [password, setPassword] = useState('')
  const [countryCode, setCountryCode] = useState(cfg.values?.country_code || '+255')

  const strength = scorePassword(password)
  const countryCodes = useMemo(() => {
    const fallback = {
      '+255': { iso: 'TZ', flagUrl: '', label: 'TZ +255', display: 'TZ +255' },
      '+254': { iso: 'KE', flagUrl: '', label: 'KE +254', display: 'KE +254' },
      '+256': { iso: 'UG', flagUrl: '', label: 'UG +256', display: 'UG +256' },
      '+250': { iso: 'RW', flagUrl: '', label: 'RW +250', display: 'RW +250' },
      '+1': { iso: 'US', flagUrl: '', label: 'US +1', display: 'US +1' },
      '+44': { iso: 'GB', flagUrl: '', label: 'UK +44', display: 'UK +44' },
    }
    const raw =
      cfg.countryCodes && typeof cfg.countryCodes === 'object' && Object.keys(cfg.countryCodes).length > 0
        ? cfg.countryCodes
        : fallback

    const normalized = {}
    Object.entries(raw).forEach(([code, value]) => {
      if (value && typeof value === 'object') {
        normalized[code] = {
          iso: value.iso || '',
          flagUrl: value.flagUrl || '',
          label: value.label || code,
          display: value.display || value.label || code,
        }
      } else {
        normalized[code] = {
          iso: '',
          flagUrl: '',
          label: String(value),
          display: String(value),
        }
      }
    })
    return normalized
  }, [cfg.countryCodes])

  const selectedCountry = countryCodes[countryCode] || {}
  const selectedFlagUrl = selectedCountry.flagUrl || ''

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
                <img src={cfg.illustrationUrl} alt="Start free trial" />
              ) : null}
            </figure>
            <a href={cfg.loginUrl} className="signup-image-link">
              Already have an account? Sign in
            </a>
            <a href={cfg.homeUrl || './'} className="signup-image-link trial-home-link">
              Back to home
            </a>
          </div>

          <div className="signin-form">
            <h2 className="form-title">{cfg.title || 'Start your free trial'}</h2>
            {cfg.subtitle ? <p className="form-subtitle">{cfg.subtitle}</p> : null}

            {cfg.error ? <div className="alert alert-error">{cfg.error}</div> : null}
            {cfg.success ? <div className="alert alert-success">{cfg.success}</div> : null}

            <form
              method="post"
              action={cfg.trialActionUrl || undefined}
              className="register-form"
              id="trial-form"
              onSubmit={handleSubmit}
            >
              <div className="form-group">
                <label htmlFor="company_name">
                  <PersonIcon />
                </label>
                <input
                  type="text"
                  name="company_name"
                  id="company_name"
                  placeholder="Business name"
                  autoComplete="organization"
                  required
                  autoFocus
                  defaultValue={cfg.values?.company_name || ''}
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
                  placeholder="Work email"
                  autoComplete="email"
                  required
                  defaultValue={cfg.values?.email || ''}
                />
              </div>

              <div className="form-group form-group-phone">
                <label htmlFor="phone">
                  <PhoneIcon />
                </label>
                <div className="phone-row">
                  <div className="phone-country">
                    {selectedFlagUrl ? (
                      <img
                        className="country-flag-img"
                        src={selectedFlagUrl}
                        alt=""
                        width={22}
                        height={16}
                      />
                    ) : (
                      <span className="country-flag-fallback" aria-hidden="true">
                        {(selectedCountry.iso || 'TZ').slice(0, 2)}
                      </span>
                    )}
                    <span className="country-dial">{countryCode}</span>
                    <span className="country-chevron" aria-hidden="true" />
                    <select
                      id="country_code"
                      name="country_code"
                      className="country-code-select"
                      required
                      value={countryCode}
                      onChange={(e) => setCountryCode(e.target.value)}
                      aria-label="Country code"
                    >
                      {Object.entries(countryCodes).map(([code, item]) => (
                        <option key={code} value={code}>
                          {item.display}
                        </option>
                      ))}
                    </select>
                  </div>
                  <span className="phone-divider" aria-hidden="true" />
                  <input
                    type="tel"
                    name="phone"
                    id="phone"
                    placeholder="Mobile number"
                    autoComplete="tel-national"
                    required
                    inputMode="tel"
                    defaultValue={cfg.values?.phone || ''}
                  />
                </div>
              </div>

              <div className="form-group form-group-password">
                <label htmlFor="password">
                  <LockIcon />
                </label>
                <input
                  type={showPassword ? 'text' : 'password'}
                  name="password"
                  id="password"
                  placeholder="Create a password"
                  autoComplete="new-password"
                  required
                  minLength={8}
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

              <p className="trial-note">
                Free 14-day trial. No credit card required. All modules included.
              </p>

              <div className="form-group form-button">
                <button type="submit" className="form-submit" disabled={submitting}>
                  {submitting ? 'Creating workspace...' : 'Start Free Trial'}
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  )
}
