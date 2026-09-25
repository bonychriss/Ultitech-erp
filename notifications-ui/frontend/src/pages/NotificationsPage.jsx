import { useMemo, useState } from 'react'
import {
  AlertTriangle,
  Bell,
  Check,
  FileText,
  Mail,
  Package,
  Settings,
  ArrowLeftRight,
  Wallet,
} from 'lucide-react'

function getCfg() {
  return window.__NOTIFICATIONS_CFG__ || {}
}

const TONE_CLASS = {
  blue: 'nc-tone-blue',
  green: 'nc-tone-green',
  teal: 'nc-tone-teal',
  sky: 'nc-tone-sky',
  amber: 'nc-tone-amber',
  rose: 'nc-tone-rose',
  slate: 'nc-tone-slate',
}

function IconFor({ icon, tone }) {
  const props = { size: 18, strokeWidth: 2, 'aria-hidden': true }
  switch (icon) {
    case 'check':
      return <Check {...props} />
    case 'file':
      return <FileText {...props} />
    case 'money':
      return <Wallet {...props} />
    case 'swap':
      return <ArrowLeftRight {...props} />
    case 'alert':
      return <AlertTriangle {...props} />
    case 'mail':
      return <Mail {...props} />
    case 'package':
      return <Package {...props} />
    default:
      return <Bell {...props} />
  }
}

async function markAllRead(apiUrl) {
  const body = new URLSearchParams({ action: 'mark_all_read' })
  await fetch(apiUrl, {
    method: 'POST',
    body,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })
}

function markOneRead(apiUrl, id) {
  if (!apiUrl || !id) return
  const sep = apiUrl.includes('?') ? '&' : '?'
  fetch(`${apiUrl}${sep}action=read&id=${encodeURIComponent(id)}`, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  }).catch(() => {})
}

const SECTION_META = [
  { key: 'today', label: 'Today' },
  { key: 'yesterday', label: 'Yesterday' },
  { key: 'earlier', label: 'Earlier' },
]

export default function NotificationsPage() {
  const cfg = getCfg()
  const initialSections = cfg.sections || { today: [], yesterday: [], earlier: [] }
  const [sections, setSections] = useState(initialSections)
  const [countUnread, setCountUnread] = useState(Number(cfg.countUnread || 0))
  const [marking, setMarking] = useState(false)

  const totalItems = useMemo(() => {
    return (
      (sections.today?.length || 0) +
      (sections.yesterday?.length || 0) +
      (sections.earlier?.length || 0)
    )
  }, [sections])

  const onMarkAll = async () => {
    if (countUnread <= 0 || marking) return
    setMarking(true)
    try {
      await markAllRead(cfg.markAllApi || '/includes/notifications_api.php')
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
      setMarking(false)
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
    <div className="nc-page nc-page--wide">
      <div className="nc-panel nc-panel--wide">
        <header className="nc-page-header nc-page-header--wide">
          <h1 className="nc-page-title">Notifications</h1>
          <div className="nc-page-header-actions">
            {countUnread > 0 ? (
              <button
                type="button"
                className="nc-mark-all-btn"
                disabled={marking}
                onClick={onMarkAll}
              >
                {marking ? 'Marking…' : 'Mark all as read'}
              </button>
            ) : (
              <span className="nc-mark-all-btn is-disabled">Mark all as read</span>
            )}
            {cfg.settingsUrl ? (
              <a
                href={cfg.settingsUrl}
                className="nc-settings-btn"
                title="Settings"
                aria-label="Settings"
              >
                <Settings size={18} strokeWidth={2} aria-hidden />
              </a>
            ) : null}
          </div>
        </header>

        <div className="nc-list nc-list--wide">
          {totalItems === 0 ? (
            <div className="nc-empty">
              <div className="nc-empty-icon" aria-hidden>
                <Bell size={28} strokeWidth={1.75} />
              </div>
              <p className="nc-empty-title">You're all caught up</p>
              <p className="nc-empty-sub">No notifications to show right now.</p>
            </div>
          ) : (
            SECTION_META.map(({ key, label }) => {
              const items = sections[key] || []
              if (!items.length) return null
              return (
                <section key={key} className="nc-section" aria-label={label}>
                  <h2 className="nc-section-heading">{label}</h2>
                  <div className="nc-section-list">
                    {items.map((item) => {
                      const tone = TONE_CLASS[item.tone] || TONE_CLASS.slate
                      const className = [
                        'nc-card',
                        tone,
                        item.isUnread ? 'is-unread' : '',
                      ]
                        .filter(Boolean)
                        .join(' ')

                      const body = (
                        <>
                          <span className={`nc-card-icon ${tone}`} aria-hidden>
                            <IconFor icon={item.icon} tone={item.tone} />
                          </span>
                          <div className="nc-card-body">
                            <div className="nc-card-title-row">
                              <h3 className="nc-card-title">{item.title}</h3>
                              <div className="nc-card-meta-inline">
                                {item.timeLabel ? (
                                  <time className="nc-card-time">{item.timeLabel}</time>
                                ) : null}
                                {item.isUnread ? (
                                  <span className="nc-card-unread-dot" aria-label="Unread" />
                                ) : null}
                              </div>
                            </div>
                            {item.message ? (
                              <p className="nc-card-message">{item.message}</p>
                            ) : null}
                          </div>
                        </>
                      )

                      if (item.href) {
                        return (
                          <a
                            key={item.id}
                            href={item.href}
                            className={className}
                            onClick={() => onItemActivate(item)}
                          >
                            {body}
                          </a>
                        )
                      }

                      return (
                        <article
                          key={item.id}
                          className={className}
                          onClick={() => onItemActivate(item)}
                        >
                          {body}
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
