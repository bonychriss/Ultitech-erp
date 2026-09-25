import { useMemo, useState } from 'react'
import {
  AlertTriangle,
  Banknote,
  Bell,
  Boxes,
  Check,
  ClipboardList,
  Clock,
  FileText,
  Gauge,
  LineChart,
  Mail,
  Package,
  Receipt,
  Settings,
  Shield,
  Sparkles,
  Truck,
  Users,
  Wallet,
  Wrench,
  Trash2,
} from 'lucide-react'

function getCfg() {
  return window.__NOTIFICATIONS_CFG__ || {}
}

const ICON_COLORS = {
  voucher: { bg: '#fff7ed', fg: '#ea580c' },
  payroll: { bg: '#eff6ff', fg: '#3b82f6' },
  sales: { bg: '#f0fdfa', fg: '#14b8a6' },
  stock: { bg: '#f0f9ff', fg: '#0ea5e9' },
  deliveries: { bg: '#eff6ff', fg: '#3b82f6' },
  driver_kpi: { bg: '#f0fdfa', fg: '#14b8a6' },
  attendance: { bg: '#f0fdf4', fg: '#22c55e' },
  letter: { bg: '#f0f9ff', fg: '#38bdf8' },
  finance: { bg: '#f0fdf4', fg: '#10b981' },
  suggest: { bg: '#fffbeb', fg: '#f59e0b' },
  admin: { bg: '#f8fafc', fg: '#64748b' },
  tasks: { bg: '#eef2ff', fg: '#6366f1' },
  system: { bg: '#f5f3ff', fg: '#8b5cf6' },
  general: { bg: '#f8fafc', fg: '#64748b' },
}

function iconStyle(item) {
  if (item.icon === 'check') return { background: '#f0fdf4', color: '#22c55e' }
  if (item.icon === 'alert') return { background: '#fff1f2', color: '#f43f5e' }
  const c = ICON_COLORS[item.module] || ICON_COLORS.general
  return { background: c.bg, color: c.fg }
}

function IconFor({ icon, color }) {
  const props = {
    size: 18,
    strokeWidth: 1.75,
    'aria-hidden': true,
    color: color || 'currentColor',
  }
  switch (icon) {
    case 'voucher':
      return <Receipt {...props} />
    case 'payroll':
      return <Wallet {...props} />
    case 'sales':
      return <LineChart {...props} />
    case 'stock':
      return <Boxes {...props} />
    case 'package':
      return <Package {...props} />
    case 'truck':
      return <Truck {...props} />
    case 'kpi':
      return <Gauge {...props} />
    case 'clock':
      return <Clock {...props} />
    case 'mail':
      return <Mail {...props} />
    case 'money':
      return <Banknote {...props} />
    case 'spark':
      return <Sparkles {...props} />
    case 'shield':
      return <Shield {...props} />
    case 'tasks':
      return <ClipboardList {...props} />
    case 'system':
      return <Wrench {...props} />
    case 'file':
      return <FileText {...props} />
    case 'users':
      return <Users {...props} />
    case 'check':
      return <Check {...props} />
    case 'alert':
      return <AlertTriangle {...props} />
    default:
      return <Bell {...props} />
  }
}

async function postNotifAction(apiUrl, fields) {
  const body = new URLSearchParams(fields)
  const res = await fetch(apiUrl, {
    method: 'POST',
    body,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })
  if (!res.ok) throw new Error('request failed')
  return res.json().catch(() => ({ ok: true }))
}

function markOneRead(apiUrl, id) {
  if (!apiUrl || !id) return
  const sep = apiUrl.includes('?') ? '&' : '?'
  fetch(`${apiUrl}${sep}action=read&id=${encodeURIComponent(id)}`, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  }).catch(() => {})
}

function removeItemFromSections(sections, id) {
  const next = {}
  for (const key of Object.keys(sections)) {
    next[key] = (sections[key] || []).filter((row) => row.id !== id)
  }
  return next
}

function countUnreadInSections(sections) {
  let n = 0
  for (const key of Object.keys(sections)) {
    for (const row of sections[key] || []) {
      if (row.isUnread) n += 1
    }
  }
  return n
}

function countReadInSections(sections) {
  let n = 0
  for (const key of Object.keys(sections)) {
    for (const row of sections[key] || []) {
      if (!row.isUnread) n += 1
    }
  }
  return n
}

const SECTION_META = [
  { key: 'today', label: 'Today' },
  { key: 'yesterday', label: 'Yesterday' },
  { key: 'earlier', label: 'Earlier' },
]

export default function NotificationsPage() {
  const cfg = getCfg()
  const apiUrl = cfg.markAllApi || '/includes/notifications_api.php'
  const initialSections = cfg.sections || { today: [], yesterday: [], earlier: [] }
  const [sections, setSections] = useState(initialSections)
  const [countUnread, setCountUnread] = useState(Number(cfg.countUnread || 0))
  const [busy, setBusy] = useState(false)

  const totalItems = useMemo(() => {
    return (
      (sections.today?.length || 0) +
      (sections.yesterday?.length || 0) +
      (sections.earlier?.length || 0)
    )
  }, [sections])

  const readCount = useMemo(() => countReadInSections(sections), [sections])

  const onMarkAll = async () => {
    if (countUnread <= 0 || busy) return
    setBusy(true)
    try {
      await postNotifAction(apiUrl, { action: 'mark_all_read' })
      setSections((prev) => {
        const next = {}
        for (const key of Object.keys(prev)) {
          next[key] = (prev[key] || []).map((item) => ({ ...item, isUnread: false }))
        }
        return next
      })
      setCountUnread(0)
    } catch {
      /* ignore */
    } finally {
      setBusy(false)
    }
  }

  const onClearRead = async () => {
    if (readCount <= 0 || busy) return
    setBusy(true)
    try {
      await postNotifAction(apiUrl, { action: 'clear_read' })
      setSections((prev) => {
        const next = {}
        for (const key of Object.keys(prev)) {
          next[key] = (prev[key] || []).filter((item) => item.isUnread)
        }
        return next
      })
    } catch {
      /* ignore */
    } finally {
      setBusy(false)
    }
  }

  const onDismiss = async (event, item) => {
    event.preventDefault()
    event.stopPropagation()
    if (busy || !item?.id) return
    setBusy(true)
    const prevSections = sections
    const nextSections = removeItemFromSections(sections, item.id)
    setSections(nextSections)
    if (item.isUnread) {
      setCountUnread((n) => Math.max(0, n - 1))
    }
    try {
      await postNotifAction(apiUrl, { action: 'dismiss', id: item.id })
    } catch {
      setSections(prevSections)
      setCountUnread(countUnreadInSections(prevSections))
    } finally {
      setBusy(false)
    }
  }

  const onItemActivate = (item) => {
    if (item.isUnread) {
      markOneRead(cfg.markReadApi || '/api/get_notifications.php', item.id)
      setSections((prev) => {
        const next = {}
        for (const key of Object.keys(prev)) {
          next[key] = (prev[key] || []).map((row) =>
            row.id === item.id ? { ...row, isUnread: false } : row,
          )
        }
        return next
      })
      setCountUnread((n) => Math.max(0, n - 1))
    }
  }

  return (
    <div className="ncr-shell">
      <div className="ncr-main">
        <header className="ncr-topbar">
          <h1 className="ncr-title">Notifications</h1>
          <div className="ncr-topbar-actions">
            {readCount > 0 ? (
              <button
                type="button"
                className="ncr-clear-read"
                disabled={busy}
                onClick={onClearRead}
              >
                Clear read
              </button>
            ) : null}
            {countUnread > 0 ? (
              <button
                type="button"
                className="ncr-mark-all"
                disabled={busy}
                onClick={onMarkAll}
              >
                {busy ? 'Working...' : 'Mark all as read'}
              </button>
            ) : (
              <span className="ncr-mark-all is-disabled">Mark all as read</span>
            )}
            {cfg.settingsUrl ? (
              <a
                href={cfg.settingsUrl}
                className="ncr-settings"
                title="Settings"
                aria-label="Settings"
              >
                <Settings size={18} strokeWidth={1.75} aria-hidden />
              </a>
            ) : null}
          </div>
        </header>

        <div className="ncr-list">
          {totalItems === 0 ? (
            <div className="ncr-empty">
              <div className="ncr-empty-icon" aria-hidden>
                <Bell size={28} strokeWidth={1.75} />
              </div>
              <p className="ncr-empty-title">You're all caught up</p>
              <p className="ncr-empty-sub">No notifications to show right now.</p>
            </div>
          ) : (
            SECTION_META.map(({ key, label }) => {
              const items = sections[key] || []
              if (!items.length) return null
              return (
                <section key={key} className="ncr-section" aria-label={label}>
                  <h2 className="ncr-section-heading">{label}</h2>
                  <div className="ncr-section-list">
                    {items.map((item) => {
                      const mod = String(item.module || 'general').replace(/[^a-z0-9_]/gi, '')
                      const statusClass =
                        item.icon === 'check'
                          ? 'nc-status-check'
                          : item.icon === 'alert'
                            ? 'nc-status-alert'
                            : ''
                      const className = [
                        'ncr-row',
                        item.isUnread ? 'is-unread' : '',
                      ]
                        .filter(Boolean)
                        .join(' ')
                      const iconClass = [
                        'nc-r-icon',
                        mod ? `nc-mod-${mod}` : '',
                        statusClass,
                      ]
                        .filter(Boolean)
                        .join(' ')
                      const colors = iconStyle(item)

                      const openItem = () => {
                        onItemActivate(item)
                        if (item.href) {
                          window.location.href = item.href
                        }
                      }

                      return (
                        <article key={item.id} className={className}>
                          <button
                            type="button"
                            className="ncr-row-main"
                            onClick={openItem}
                          >
                            <span
                              className={iconClass}
                              style={colors}
                              aria-hidden
                            >
                              <IconFor icon={item.icon} color={colors.color} />
                            </span>
                            <div className="ncr-row-body">
                              <div className="ncr-row-title-row">
                                <h3 className="ncr-row-title">{item.title}</h3>
                                <div className="ncr-row-meta">
                                  {item.timeLabel ? (
                                    <time className="ncr-row-time">{item.timeLabel}</time>
                                  ) : null}
                                  {item.isUnread ? (
                                    <span className="ncr-unread-dot" aria-label="Unread" />
                                  ) : null}
                                </div>
                              </div>
                              {item.message ? (
                                <p className="ncr-row-message">{item.message}</p>
                              ) : null}
                            </div>
                          </button>
                          <button
                            type="button"
                            className="ncr-dismiss"
                            title="Dismiss"
                            aria-label="Dismiss notification"
                            onClick={(e) => onDismiss(e, item)}
                          >
                            <Trash2 size={15} strokeWidth={1.75} aria-hidden />
                          </button>
                        </article>
                      )
                    })}
                  </div>
                </section>
              )
            })
          )}
        </div>
      </div>
    </div>
  )
}
