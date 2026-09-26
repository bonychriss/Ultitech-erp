import { useEffect, useMemo, useRef, useState } from 'react'
import {
  ArrowLeft,
  Banknote,
  Bell,
  Boxes,
  Check,
  ClipboardList,
  Clock,
  Gauge,
  LineChart,
  Mail,
  Receipt,
  Shield,
  Sparkles,
  Truck,
  Wallet,
  Wrench,
} from 'lucide-react'

function getCfg() {
  return window.__NOTIFICATIONS_CFG__ || {}
}

const MODULE_ICONS = {
  voucher: Receipt,
  payroll: Wallet,
  sales: LineChart,
  stock: Boxes,
  deliveries: Truck,
  driver_kpi: Gauge,
  attendance: Clock,
  letter: Mail,
  finance: Banknote,
  suggest: Sparkles,
  admin: Shield,
  tasks: ClipboardList,
  system: Wrench,
  general: Bell,
}

function ModuleIcon({ id, color }) {
  const Icon = MODULE_ICONS[id] || Bell
  return (
    <span className="ncr-settings-module-icon" style={{ color: color || '#64748b' }} aria-hidden>
      <Icon size={18} strokeWidth={1.75} />
    </span>
  )
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
  const saveTimer = useRef(null)
  const savedTimer = useRef(null)
  const busyRef = useRef(false)

  useEffect(() => {
    return () => {
      if (saveTimer.current) clearTimeout(saveTimer.current)
      if (savedTimer.current) clearTimeout(savedTimer.current)
    }
  }, [])

  const enabledCount = useMemo(
    () => Object.values(modules).filter(Boolean).length,
    [modules]
  )

  async function persist(nextModules, nextEmail) {
    if (!cfg.prefsApi || busyRef.current) return false
    busyRef.current = true
    setBusy(true)
    setError('')
    setSaved(false)
    if (savedTimer.current) clearTimeout(savedTimer.current)
    try {
      const res = await fetch(cfg.prefsApi, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          action: 'save_prefs',
          modules: nextModules,
          emailAlerts: !!nextEmail,
        }),
      })
      const text = await res.text()
      let data = null
      try {
        data = text ? JSON.parse(text) : null
      } catch {
        data = null
      }
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || `Save failed (${res.status})`)
      }
      setSaved(true)
      savedTimer.current = setTimeout(() => setSaved(false), 1600)
      return true
    } catch (e) {
      setError(e?.message || 'Could not save settings')
      return false
    } finally {
      busyRef.current = false
      setBusy(false)
    }
  }

  function queueSave(nextModules, nextEmail) {
    if (saveTimer.current) clearTimeout(saveTimer.current)
    saveTimer.current = setTimeout(() => {
      persist(nextModules, nextEmail)
    }, 350)
  }

  function toggleModule(id) {
    setModules((prev) => {
      const next = { ...prev, [id]: !prev[id] }
      queueSave(next, emailAlerts)
      return next
    })
  }

  function setAll(on) {
    const next = {}
    moduleOptions.forEach((m) => {
      next[m.id] = on
    })
    setModules(next)
    queueSave(next, emailAlerts)
  }

  function toggleEmail() {
    setEmailAlerts((prev) => {
      const next = !prev
      queueSave(modules, next)
      return next
    })
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
                Choose which modules can send you notifications. Changes save automatically.
              </p>
            </div>
          </div>
          <div className="ncr-topbar-actions">
            {busy || saved ? (
              <span
                className={`ncr-save-status${busy ? ' is-busy' : ''}${saved && !busy ? ' is-saved' : ''}`}
                aria-live="polite"
              >
                {busy ? (
                  'Saving...'
                ) : (
                  <>
                    <Check size={15} strokeWidth={2.25} aria-hidden /> Saved
                  </>
                )}
              </span>
            ) : null}
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
            <li className="ncr-settings-module ncr-settings-module--head" aria-hidden>
              <div className="ncr-settings-module-label">
                <span className="ncr-settings-col-name">Module</span>
                <span className="ncr-settings-col-desc">Description</span>
              </div>
              <span className="ncr-settings-col-toggle">Alerts</span>
            </li>
            {moduleOptions.map((m) => {
              const on = modules[m.id] !== false
              return (
                <li key={m.id} className="ncr-settings-module">
                  <div className="ncr-settings-module-label">
                    <ModuleIcon id={m.id} color={m.color} />
                    <div className="ncr-settings-module-copy">
                      <span className="ncr-settings-module-name">{m.label}</span>
                      {m.description ? (
                        <span className="ncr-settings-module-desc">{m.description}</span>
                      ) : null}
                    </div>
                  </div>
                  <button
                    type="button"
                    role="switch"
                    aria-checked={on}
                    aria-label={`${m.label} notifications`}
                    className={`ncr-switch${on ? ' is-on' : ''}`}
                    onClick={() => toggleModule(m.id)}
                  >
                    <span className="ncr-switch-knob" aria-hidden />
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
              aria-label="Email alerts"
              className={`ncr-switch${emailAlerts ? ' is-on' : ''}`}
              onClick={toggleEmail}
            >
              <span className="ncr-switch-knob" aria-hidden />
            </button>
          </div>
        </section>
      </div>
    </div>
  )
}
