import { useMemo, useState } from 'react'
import { ArrowLeft, Bell, Check } from 'lucide-react'

function getCfg() {
  return window.__NOTIFICATIONS_CFG__ || {}
}

export default function NotificationsSettingsPage() {
  const cfg = getCfg()
  const moduleOptions = Array.isArray(cfg.moduleOptions) ? cfg.moduleOptions : []
  const initial = cfg.preferences || {}
  const [modules, setModules] = useState(() => {
    const base = {}
    moduleOptions.forEach((m) => {
      base[m.id] = initial.modules?.[m.id] !== false
    })
    return base
  })
  const [emailAlerts, setEmailAlerts] = useState(!!initial.emailAlerts)
  const [busy, setBusy] = useState(false)
  const [saved, setSaved] = useState(false)
  const [error, setError] = useState('')

  const enabledCount = useMemo(
    () => Object.values(modules).filter(Boolean).length,
    [modules]
  )

  function toggleModule(id) {
    setModules((prev) => ({ ...prev, [id]: !prev[id] }))
    setSaved(false)
  }

  function setAll(on) {
    const next = {}
    moduleOptions.forEach((m) => {
      next[m.id] = on
    })
    setModules(next)
    setSaved(false)
  }

  async function onSave() {
    if (!cfg.prefsApi || busy) return
    setBusy(true)
    setError('')
    setSaved(false)
    try {
      const body = new URLSearchParams()
      body.set('action', 'save_prefs')
      body.set('modules', JSON.stringify(modules))
      body.set('emailAlerts', emailAlerts ? '1' : '0')
      const res = await fetch(cfg.prefsApi, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
      const data = await res.json().catch(() => null)
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || 'Save failed')
      }
      if (data.preferences?.modules) {
        setModules((prev) => ({ ...prev, ...data.preferences.modules }))
      }
      setEmailAlerts(!!data.preferences?.emailAlerts)
      setSaved(true)
    } catch (e) {
      setError(e?.message || 'Could not save settings')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="ncr-shell ncr-settings-page">
      <div className="ncr-main">
        <header className="ncr-topbar">
          <div className="ncr-settings-heading">
            {cfg.listUrl ? (
              <a href={cfg.listUrl} className="ncr-back" title="Back to notifications">
                <ArrowLeft size={18} strokeWidth={1.75} aria-hidden />
              </a>
            ) : null}
            <div>
              <h1 className="ncr-title">Notification settings</h1>
              <p className="ncr-settings-sub">
                Choose which modules can send you notifications.
              </p>
            </div>
          </div>
          <div className="ncr-topbar-actions">
            <button
              type="button"
              className="ncr-mark-all"
              disabled={busy}
              onClick={onSave}
            >
              {busy ? 'Saving...' : saved ? (
                <>
                  <Check size={15} strokeWidth={2.25} aria-hidden /> Saved
                </>
              ) : (
                'Save changes'
              )}
            </button>
          </div>
        </header>

        {error ? <p className="ncr-settings-error">{error}</p> : null}

        <section className="ncr-settings-card" aria-label="Module notifications">
          <div className="ncr-settings-card-head">
            <div className="ncr-settings-card-title-row">
              <span className="ncr-settings-card-icon" aria-hidden>
                <Bell size={18} strokeWidth={1.75} />
              </span>
              <div>
                <h2 className="ncr-settings-card-title">Modules</h2>
                <p className="ncr-settings-card-sub">
                  {enabledCount} of {moduleOptions.length} enabled
                </p>
              </div>
            </div>
            <div className="ncr-settings-bulk">
              <button type="button" className="ncr-clear-read" onClick={() => setAll(true)}>
                Enable all
              </button>
              <button type="button" className="ncr-clear-read" onClick={() => setAll(false)}>
                Disable all
              </button>
            </div>
          </div>

          <ul className="ncr-settings-modules">
            {moduleOptions.map((m) => {
              const on = modules[m.id] !== false
              return (
                <li key={m.id} className="ncr-settings-module">
                  <div className="ncr-settings-module-label">
                    <span
                      className="ncr-settings-module-dot"
                      style={{ background: m.color || '#64748b' }}
                      aria-hidden
                    />
                    <span>{m.label}</span>
                  </div>
                  <button
                    type="button"
                    role="switch"
                    aria-checked={on}
                    className={`ncr-switch${on ? ' is-on' : ''}`}
                    onClick={() => toggleModule(m.id)}
                  >
                    <span className="ncr-switch-knob" />
                  </button>
                </li>
              )
            })}
          </ul>
        </section>

        <section className="ncr-settings-card" aria-label="Email alerts">
          <div className="ncr-settings-module ncr-settings-module--solo">
            <div className="ncr-settings-module-label">
              <div>
                <div className="ncr-settings-card-title">Email alerts</div>
                <p className="ncr-settings-card-sub">
                  Also send important notification alerts to your account email.
                </p>
              </div>
            </div>
            <button
              type="button"
              role="switch"
              aria-checked={emailAlerts}
              className={`ncr-switch${emailAlerts ? ' is-on' : ''}`}
              onClick={() => {
                setEmailAlerts((v) => !v)
                setSaved(false)
              }}
            >
              <span className="ncr-switch-knob" />
            </button>
          </div>
        </section>
      </div>
    </div>
  )
}
