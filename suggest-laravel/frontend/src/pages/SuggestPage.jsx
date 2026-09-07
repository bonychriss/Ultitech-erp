import { useEffect, useState } from 'react'
import {
  Plus,
  Send,
  LoaderCircle,
  Inbox,
  CheckCircle2,
  Clock3,
  Ban,
  X,
} from 'lucide-react'

function getCfg() {
  return window.__SUGGEST_CFG__ || {}
}

function statusMeta(status) {
  const s = String(status || 'pending').toLowerCase()
  if (s === 'accomplished') {
    return { label: 'Done', className: 'sg-pill sg-pill--done', Icon: CheckCircle2 }
  }
  if (s === 'impossible') {
    return { label: 'Not feasible', className: 'sg-pill sg-pill--bad', Icon: Ban }
  }
  return { label: 'Pending', className: 'sg-pill sg-pill--pending', Icon: Clock3 }
}

export default function SuggestPage() {
  const cfg = getCfg()
  const [items, setItems] = useState([])
  const [text, setText] = useState('1. ')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [flash, setFlash] = useState('')
  const [error, setError] = useState('')
  const [filter, setFilter] = useState('all')
  const [open, setOpen] = useState(false)

  async function loadList() {
    setLoading(true)
    setError('')
    try {
      const res = await fetch(cfg.apiUrl || '?api=suggestions', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      })
      const data = await res.json()
      if (!res.ok || !data.ok) {
        throw new Error(data.error || 'Could not load suggestions.')
      }
      setItems(Array.isArray(data.suggestions) ? data.suggestions : [])
    } catch (e) {
      setError(e.message || 'Load failed.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadList()
  }, [])

  useEffect(() => {
    if (!flash) return undefined
    const t = setTimeout(() => setFlash(''), 4500)
    return () => clearTimeout(t)
  }, [flash])

  useEffect(() => {
    if (!open) return undefined
    const onKey = (e) => {
      if (e.key === 'Escape') closeModal()
    }
    document.addEventListener('keydown', onKey)
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = prev
    }
  }, [open])

  function openModal() {
    setError('')
    setOpen(true)
  }

  function closeModal() {
    setOpen(false)
    setError('')
  }

  function addLine() {
    const matches = text.match(/^(\d+)\.\s/gm)
    let nextNum = 1
    if (matches && matches.length > 0) {
      nextNum = parseInt(matches[matches.length - 1].match(/\d+/)[0], 10) + 1
    }
    setText((prev) => `${prev.trim() === '' ? '' : `${prev}\n`}${nextNum}. `)
  }

  async function onSubmit(e) {
    e.preventDefault()
    const suggestion = text.trim()
    if (suggestion.length < 3) {
      setError('Please write at least a short suggestion.')
      return
    }
    setSaving(true)
    setError('')
    try {
      const res = await fetch(cfg.apiUrl || '?api=suggestions', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ suggestion }),
      })
      const data = await res.json()
      if (!res.ok || !data.ok) {
        throw new Error(data.error || data.message || 'Submit failed.')
      }
      if (data.suggestion) {
        setItems((prev) => [data.suggestion, ...prev])
      } else {
        await loadList()
      }
      setText('1. ')
      setFlash(data.message || 'Submitted.')
      setOpen(false)
    } catch (err) {
      setError(err.message || 'Submit failed.')
    } finally {
      setSaving(false)
    }
  }

  const filtered = items.filter((row) => {
    if (filter === 'all') return true
    return String(row.status || '').toLowerCase() === filter
  })

  return (
    <div className="sg-page">
      <section className="sg-feed" aria-labelledby="sg-feed-heading">
        <header className="sg-panel-head sg-panel-head--row">
          <div>
            <h3 id="sg-feed-heading">Recent ideas</h3>
            <p>Latest submissions from your company workspace.</p>
          </div>
          <div className="sg-toolbar">
            <div className="sg-filters" role="tablist" aria-label="Filter by status">
              {[
                ['all', 'All'],
                ['pending', 'Pending'],
                ['accomplished', 'Done'],
              ].map(([id, label]) => (
                <button
                  key={id}
                  type="button"
                  role="tab"
                  aria-selected={filter === id}
                  className={`sg-filter${filter === id ? ' is-active' : ''}`}
                  onClick={() => setFilter(id)}
                >
                  {label}
                </button>
              ))}
            </div>
            <button type="button" className="sg-btn sg-btn--purple" onClick={openModal}>
              <Plus size={16} strokeWidth={2} aria-hidden="true" />
              New request
            </button>
          </div>
        </header>

        {flash ? <div className="sg-flash sg-flash--ok">{flash}</div> : null}
        {error && !open ? <div className="sg-flash sg-flash--err">{error}</div> : null}

        {loading ? (
          <div className="sg-empty">
            <LoaderCircle size={22} className="sg-spin" aria-hidden="true" />
            Loading suggestions...
          </div>
        ) : filtered.length === 0 ? (
          <div className="sg-empty">
            <Inbox size={28} strokeWidth={1.5} aria-hidden="true" />
            <strong>No suggestions yet</strong>
            <span>Use New request to send the first idea.</span>
            <button type="button" className="sg-btn sg-btn--purple" onClick={openModal}>
              <Plus size={16} strokeWidth={2} aria-hidden="true" />
              New request
            </button>
          </div>
        ) : (
          <ul className="sg-list">
            {filtered.map((row) => {
              const meta = statusMeta(row.status)
              const Icon = meta.Icon
              return (
                <li key={row.id} className="sg-card">
                  <div className="sg-card-top">
                    <span className={meta.className}>
                      <Icon size={12} strokeWidth={2.25} aria-hidden="true" />
                      {meta.label}
                    </span>
                    <time className="sg-time">{row.created_at || ''}</time>
                  </div>
                  <p className="sg-card-body">{row.suggestion}</p>
                  <div className="sg-card-foot">By {row.author || 'Unknown'}</div>
                </li>
              )
            })}
          </ul>
        )}
      </section>

      {open ? (
        <div
          className="sg-modal-backdrop"
          role="presentation"
          onClick={(e) => {
            if (e.target === e.currentTarget) closeModal()
          }}
        >
          <div
            className="sg-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="sg-compose-heading"
          >
            <header className="sg-modal-head">
              <div>
                <h3 id="sg-compose-heading">New request</h3>
                <p>Numbered lines help when you have more than one idea.</p>
              </div>
              <button
                type="button"
                className="sg-modal-close"
                onClick={closeModal}
                aria-label="Close"
              >
                <X size={18} strokeWidth={2} />
              </button>
            </header>

            {error ? <div className="sg-flash sg-flash--err">{error}</div> : null}

            <form className="sg-form" onSubmit={onSubmit}>
              <label className="sg-label" htmlFor="suggestionBox">
                Your suggestion
              </label>
              <textarea
                id="suggestionBox"
                className="sg-textarea"
                value={text}
                onChange={(e) => setText(e.target.value)}
                rows={8}
                placeholder="1. Suggestion one..."
                required
                autoFocus
              />
              <div className="sg-form-actions">
                <button type="button" className="sg-btn sg-btn--ghost" onClick={addLine}>
                  <Plus size={16} strokeWidth={2} aria-hidden="true" />
                  Add line
                </button>
                <button type="submit" className="sg-btn sg-btn--primary" disabled={saving}>
                  {saving ? (
                    <LoaderCircle size={16} className="sg-spin" strokeWidth={2} aria-hidden="true" />
                  ) : (
                    <Send size={16} strokeWidth={2} aria-hidden="true" />
                  )}
                  {saving ? 'Sending...' : 'Submit to developer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </div>
  )
}
