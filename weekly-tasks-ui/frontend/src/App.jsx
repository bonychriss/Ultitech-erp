import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'

const FALLBACK_TONE = '#1e293b'

function sampleTone(img) {
  const canvas = document.createElement('canvas')
  const size = 32
  canvas.width = size
  canvas.height = size
  const ctx = canvas.getContext('2d', { willReadFrequently: true })
  if (!ctx) return null
  ctx.drawImage(img, 0, 0, size, size)
  let pixels
  try {
    pixels = ctx.getImageData(0, 0, size, size).data
  } catch {
    return null
  }
  let r = 0
  let g = 0
  let b = 0
  let n = 0
  for (let i = 0; i < pixels.length; i += 4) {
    const red = pixels[i]
    const green = pixels[i + 1]
    const blue = pixels[i + 2]
    const max = Math.max(red, green, blue)
    const min = Math.min(red, green, blue)
    if (max > 242 && min > 220) continue
    if (max < 16) continue
    r += red
    g += green
    b += blue
    n += 1
  }
  if (!n) return null
  r /= n
  g /= n
  b /= n
  const lum = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255
  const scale = lum > 0.34 ? 0.34 / lum : 1
  return `rgb(${Math.round(r * scale)}, ${Math.round(g * scale)}, ${Math.round(b * scale)})`
}

function usePhotoTone(src) {
  const [tone, setTone] = useState(FALLBACK_TONE)
  useEffect(() => {
    if (!src) {
      setTone(FALLBACK_TONE)
      return undefined
    }
    let cancelled = false
    const img = new Image()
    img.onload = () => {
      if (!cancelled) setTone(sampleTone(img) || FALLBACK_TONE)
    }
    img.onerror = () => {
      if (!cancelled) setTone(FALLBACK_TONE)
    }
    img.src = src
    return () => {
      cancelled = true
    }
  }, [src])
  return tone
}

function cfg() {
  const raw = window.__WEEKLY_TASKS_CFG__
  return raw && typeof raw === 'object' ? raw : {}
}

function BandIcon({ name }) {
  const common = {
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.8,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
  }
  let drawing = null
  if (name === 'outstanding') {
    drawing = <path d="m12 3 2.2 4.6 5 .7-3.6 3.5.9 5.1L12 14.8 7.5 17l.9-5.1L4.8 8.3l5-.7L12 3z" />
  } else if (name === 'exceeds') {
    drawing = <path d="M4 16.5 9.2 11l3.3 3.2L20 6.5 M14.5 6.5H20V12" />
  } else if (name === 'meets') {
    drawing = (
      <>
        <circle cx="12" cy="12" r="8" />
        <path d="m8.5 12.2 2.3 2.3 4.7-5" />
      </>
    )
  } else {
    drawing = (
      <>
        <circle cx="12" cy="12" r="8" />
        <path d="M12 8.5v4.2" />
        <path d="M12 16.2h.01" />
      </>
    )
  }
  return (
    <span className={`wt-band-icon wt-band-icon--${name}`} aria-hidden="true">
      <svg {...common}>{drawing}</svg>
    </span>
  )
}

function initials(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean)
  return parts.slice(0, 2).map((part) => part[0] || '').join('').toUpperCase() || '?'
}

function displayName(name) {
  return String(name || '')
    .toLowerCase()
    .replace(/(^|\s)\S/g, (part) => part.toUpperCase())
}

function fileBase(name) {
  const base = displayName(name).replace(/[^\w]+/g, '-').replace(/^-|-$/g, '')
  return base || 'profile'
}

function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 1000)
}

async function cardBlob(node) {
  const hidden = ['.wt-pcard-share', '.wt-pcard-price'].map((selector) => node.querySelector(selector))
  hidden.forEach((element) => {
    if (element) element.style.visibility = 'hidden'
  })
  try {
    const { toBlob } = await import('html-to-image')
    const blob = await toBlob(node, { cacheBust: true, pixelRatio: 2 })
    if (!blob) throw new Error('Could not capture the card')
    return blob
  } finally {
    hidden.forEach((element) => {
      if (element) element.style.visibility = ''
    })
  }
}

function blobToDataUrl(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(reader.result)
    reader.onerror = () => reject(reader.error)
    reader.readAsDataURL(blob)
  })
}

async function downloadCardImage(node, name) {
  const blob = await cardBlob(node)
  downloadBlob(blob, `${fileBase(name)}.png`)
  return blob
}

async function downloadCardPdf(node, name) {
  const blob = await cardBlob(node)
  const dataUrl = await blobToDataUrl(blob)
  const size = await new Promise((resolve, reject) => {
    const img = new Image()
    img.onload = () => resolve({ width: img.width, height: img.height })
    img.onerror = () => reject(new Error('Could not read the card image'))
    img.src = dataUrl
  })
  const { jsPDF } = await import('jspdf')
  const pdf = new jsPDF({
    orientation: size.width > size.height ? 'landscape' : 'portrait',
    unit: 'px',
    format: [size.width, size.height],
    hotfixes: ['px_scaling'],
  })
  pdf.addImage(dataUrl, 'PNG', 0, 0, size.width, size.height)
  pdf.save(`${fileBase(name)}.pdf`)
}

async function shareCardWhatsApp(node, name) {
  const popup = window.open('about:blank', '_blank')
  try {
    await downloadCardImage(node, name)
  } catch (error) {
    if (popup) popup.close()
    throw error
  }
  const text = encodeURIComponent(`${displayName(name)}\n${window.location.href}`)
  const url = `https://wa.me/?text=${text}`
  if (popup) popup.location = url
  else window.open(url, '_blank', 'noopener,noreferrer')
}

function StarRow({ score }) {
  const value = Math.max(0, Math.min(5, (Number(score) || 0) / 20))
  return (
    <span className="wt-pcard-stars" aria-label={`${value.toFixed(1)} out of 5`}>
      {[0, 1, 2, 3, 4].map((index) => {
        const fill = Math.max(0, Math.min(1, value - index))
        return (
          <span key={index} className="wt-star" aria-hidden="true">
            <svg viewBox="0 0 24 24">
              <path d="m12 3 2.2 4.6 5 .7-3.6 3.5.9 5.1L12 14.8 7.5 17l.9-5.1L4.8 8.3l5-.7L12 3z" fill="rgba(255,255,255,0.35)" />
            </svg>
            <svg viewBox="0 0 24 24" style={{ clipPath: `inset(0 ${100 - fill * 100}% 0 0)` }}>
              <path d="m12 3 2.2 4.6 5 .7-3.6 3.5.9 5.1L12 14.8 7.5 17l.9-5.1L4.8 8.3l5-.7L12 3z" fill="#fbbf24" />
            </svg>
          </span>
        )
      })}
      <span>{value.toFixed(1)}</span>
    </span>
  )
}

function MonthSelect({ month, boxed = false }) {
  const options = Array.isArray(month.options) ? month.options : []
  const selected = Array.isArray(month.selected) && month.selected.length
    ? month.selected.map(String)
    : [String(month.offset ?? 0)]
  const [open, setOpen] = useState(false)
  const [picked, setPicked] = useState(selected)
  const boxRef = useRef(null)

  useEffect(() => {
    if (!open) return undefined
    function onPointer(event) {
      if (boxRef.current && !boxRef.current.contains(event.target)) setOpen(false)
    }
    document.addEventListener('pointerdown', onPointer)
    return () => document.removeEventListener('pointerdown', onPointer)
  }, [open])

  if (options.length === 0) return null

  const labels = options
    .filter((option) => picked.includes(String(option.offset)))
    .map((option) => option.label)
  const summary = labels.length > 1 ? `${labels.length} months` : (labels[0] || 'Month')

  function toggle(offset) {
    const key = String(offset)
    setPicked((current) => {
      if (current.includes(key)) {
        return current.length === 1 ? current : current.filter((item) => item !== key)
      }
      return current.concat(key)
    })
  }

  function apply() {
    const offsets = picked.map(Number).filter((offset) => offset <= 0).sort((a, b) => b - a)
    const url = new URL(window.location.href)
    url.searchParams.delete('month')
    url.searchParams.delete('months')
    if (offsets.length > 1) url.searchParams.set('months', offsets.join(','))
    else if (offsets.length === 1 && offsets[0] !== 0) url.searchParams.set('month', String(offsets[0]))
    window.location.assign(url.toString())
  }

  return (
    <div className={boxed ? 'wt-month wt-month--boxed' : 'wt-month'} ref={boxRef}>
      {boxed ? null : <span>Month</span>}
      <button type="button" className="wt-month-btn" aria-expanded={open} onClick={() => setOpen((value) => !value)}>
        {boxed ? (
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true">
            <rect x="4" y="5" width="16" height="15" rx="2" />
            <path d="M8 3v4M16 3v4M4 10h16" />
          </svg>
        ) : null}
        {boxed ? (
          <span className="wt-month-copy">
            <small>Month</small>
            <strong>{summary}</strong>
          </span>
        ) : summary}
        {boxed ? (
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
            <path d="M6 9l6 6 6-6" />
          </svg>
        ) : null}
      </button>
      {open ? (
        <div className="wt-month-panel">
          {options.map((option) => (
            <label key={option.offset}>
              <input
                type="checkbox"
                checked={picked.includes(String(option.offset))}
                onChange={() => toggle(option.offset)}
              />
              {option.label}
            </label>
          ))}
          <button type="button" className="wt-month-apply" onClick={apply}>Apply</button>
        </div>
      ) : null}
    </div>
  )
}

function itemStatus(item) {
  if (!item.configured) return { key: 'none', label: 'Not configured' }
  if (item.met) return { key: 'met', label: 'Met' }
  return { key: 'short', label: 'Missed' }
}

function SalesMeasureCards({ items }) {
  return (
    <div className="wt-nc-section-list">
      {items.map((item) => {
        const status = itemStatus(item)
        const expected = item.configured ? item.expected : 'Not configured'
        const actual = item.configured ? item.actual : 'Not configured'
        const Row = item.href ? 'a' : 'article'
        return (
          <Row key={item.name} className="wt-nc-card wt-kpi-card" href={item.href || undefined}>
            <span className="wt-nc-icon" aria-hidden="true">
              <RecordIcon kind={measureIcon(item)} />
            </span>
            <div className="wt-nc-body">
              <p className="wt-nc-kicker">{item.group || 'Sales'}</p>
              <div className="wt-nc-title-row">
                <h3 className="wt-nc-title">{item.name}</h3>
                <em className={`wt-status wt-status--${status.key}`}>{status.label}</em>
              </div>
              <p className="wt-kpi-details">
                <span>{expected}</span>
                <span>{actual}</span>
              </p>
            </div>
          </Row>
        )
      })}
    </div>
  )
}

function CompareTable({ items }) {
  return (
    <div className="wt-compare">
      <div className="wt-compare-row wt-compare-row--head">
        <span>Measure</span>
        <span>Expected</span>
        <span>Done</span>
      </div>
      {items.map((item) => {
        const status = itemStatus(item)
        const Row = item.href ? 'a' : 'div'
        return (
          <Row key={item.name} className={`wt-compare-row wt-compare-row--${status.key}`} href={item.href || undefined}>
            <span className="wt-compare-name">{item.name}</span>
            <span>{item.configured ? item.expected : 'Not configured'}</span>
            <span className="wt-compare-done">
              {item.configured ? item.actual : 'Not configured'}
              <em className={`wt-status wt-status--${status.key}`}>{status.label}</em>
            </span>
          </Row>
        )
      })}
    </div>
  )
}

function Detail({ month, detail, missing }) {
  const tone = usePhotoTone(detail && detail.photo)
  const cardRef = useRef(null)
  const shareRef = useRef(null)
  const menuRef = useRef(null)
  const [shareOpen, setShareOpen] = useState(false)
  const [shareBusy, setShareBusy] = useState(false)
  const [menuBox, setMenuBox] = useState(null)

  useEffect(() => {
    if (!shareOpen) return undefined
    function onPointer(event) {
      if (menuRef.current && menuRef.current.contains(event.target)) return
      if (shareRef.current && shareRef.current.contains(event.target)) return
      setShareOpen(false)
    }
    function onKey(event) {
      if (event.key === 'Escape') setShareOpen(false)
    }
    document.addEventListener('pointerdown', onPointer)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('pointerdown', onPointer)
      document.removeEventListener('keydown', onKey)
    }
  }, [shareOpen])

  if (missing || !detail) {
    return (
      <div className="wt-dash">
        <a className="wt-back" href={(month && (month.selfUrl || month.thisUrl)) || '#'}>Back</a>
        <p className="wt-empty">This person is not on the performance list.</p>
      </div>
    )
  }

  const items = Array.isArray(detail.items) ? detail.items : []
  const insight = String(detail.insight || '').trim()

  async function runExport(kind) {
    const node = cardRef.current
    if (!node || shareBusy) return
    setShareOpen(false)
    setShareBusy(true)
    try {
      if (kind === 'pdf') await downloadCardPdf(node, detail.name)
      else if (kind === 'image') await downloadCardImage(node, detail.name)
      else await shareCardWhatsApp(node, detail.name)
    } catch {
      setShareBusy(false)
      return
    }
    setShareBusy(false)
  }

  return (
    <div className="wt-dash wt-dash--detail">
      <a className="wt-back" href={detail.backUrl || '#'}>Back to performance</a>

      <div className="wt-detail-layout">
      <article ref={cardRef} className="wt-pcard" style={{ '--wt-tone': tone }}>
        <div className="wt-pcard-photo">
          {detail.photo ? (
            <>
              <img className="wt-pcard-sharp" src={detail.photo} alt="" />
              <img className="wt-pcard-blur" src={detail.photo} alt="" aria-hidden="true" />
            </>
          ) : (
            <span>{initials(detail.name)}</span>
          )}
          <div className="wt-pcard-fade" />
        </div>
        <div className="wt-pcard-body">
          <div className="wt-pcard-title">
            <h1 className="wt-pcard-name">
              {displayName(detail.name)}
              {Number(detail.score) >= 70 ? (
                <svg viewBox="0 0 24 24" aria-label="Meets the bar">
                  <circle cx="12" cy="12" r="10" fill="#16a34a" />
                  <path d="m7.8 12.2 2.7 2.7 5.7-6" fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
              ) : null}
            </h1>
            <span className="wt-pcard-price">{Number(detail.score) || 0}%</span>
          </div>
          <p className="wt-pcard-desc">
            {detail.role
              ? `${detail.department} \u2022 ${displayName(String(detail.role).replace(/[_-]+/g, ' '))}`
              : detail.department}
          </p>
          <div className="wt-pcard-chips">
            <span className="wt-pcard-chip">{Number(detail.tasksDone) || 0}/{Number(detail.tasksAssigned) || 0} tasks</span>
          </div>
          <div className="wt-pcard-actions">
            <StarRow score={detail.score} />
            <button
              ref={shareRef}
              type="button"
              className={shareOpen ? 'wt-pcard-share is-open' : 'wt-pcard-share'}
              aria-label="Share profile"
              aria-expanded={shareOpen}
              aria-haspopup="menu"
              onClick={() => {
                if (shareOpen) {
                  setShareOpen(false)
                  return
                }
                setMenuBox(shareRef.current.getBoundingClientRect())
                setShareOpen(true)
              }}
            >
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="6" cy="12" r="2.2" fill="none" stroke="currentColor" strokeWidth="1.7" />
                <circle cx="17" cy="7" r="2.2" fill="none" stroke="currentColor" strokeWidth="1.7" />
                <circle cx="17" cy="17" r="2.2" fill="none" stroke="currentColor" strokeWidth="1.7" />
                <path d="m8 11 7-3.2M8 13l7 3.2" fill="none" stroke="currentColor" strokeWidth="1.7" />
              </svg>
            </button>
          </div>
        </div>
      </article>

      <div className="wt-detail-main" id="wt-detail-main">
      <div className="wt-week wt-week--detail">
        <MonthSelect month={month} />
      </div>

      <section className="wt-total" aria-label="Total performance">
        <span className="wt-total-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.7">
            <path d="M5 19V10M12 19V5M19 19v-7" />
          </svg>
        </span>
        <span>Total performance</span>
        <strong>{Number(detail.score) || 0}%</strong>
      </section>

      <section className="wt-dept">
        <h2 className="wt-dept-title">This month</h2>
        <CompareTable items={items} />
      </section>

      <section className="wt-dept">
        <h2 className="wt-dept-title wt-insight-title">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M9 18h6M10 21h4" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
            <path d="M12 3a6 6 0 0 0-3.2 11.1c.5.4.8 1 .8 1.6V17h4.8v-1.3c0-.6.3-1.2.8-1.6A6 6 0 0 0 12 3z" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinejoin="round" />
          </svg>
          Smart insight
        </h2>
        <p className="wt-insight">{insight || 'Nothing to flag this month.'}</p>
      </section>
      </div>
      </div>
      {shareOpen && menuBox ? createPortal(
        <div ref={menuRef} className="wt-share-fan">
          {[
            { id: 'image', label: 'Download as image', angle: -62 },
            { id: 'pdf', label: 'Download as PDF', angle: -18 },
            { id: 'whatsapp', label: 'Share to WhatsApp', angle: 26 },
          ].map((action) => {
            const rad = (action.angle * Math.PI) / 180
            const cx = menuBox.left + menuBox.width / 2
            const cy = menuBox.top + menuBox.height / 2
            const reach = 74
            return (
              <button
                key={action.id}
                type="button"
                className={`wt-share-orb wt-share-orb--${action.id}`}
                aria-label={action.label}
                title={action.label}
                disabled={shareBusy}
                style={{
                  left: cx + Math.cos(rad) * reach - 24,
                  top: cy + Math.sin(rad) * reach - 24,
                }}
                onClick={() => runExport(action.id)}
              >
                {action.id === 'pdf' ? (
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M7 3h7l5 5v13H7V3z" fill="none" stroke="currentColor" strokeWidth="1.7" />
                    <path d="M14 3v6h6M8 17h8M8 13h8" fill="none" stroke="currentColor" strokeWidth="1.7" />
                  </svg>
                ) : null}
                {action.id === 'image' ? (
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <rect x="3" y="5" width="18" height="14" rx="2" fill="none" stroke="currentColor" strokeWidth="1.7" />
                    <circle cx="8.5" cy="10" r="1.3" fill="currentColor" />
                    <path d="m21 16-4.5-4.5L7 19" fill="none" stroke="currentColor" strokeWidth="1.7" />
                  </svg>
                ) : null}
                {action.id === 'whatsapp' ? (
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path fill="currentColor" d="M12.04 2C6.58 2 2.15 6.4 2.15 11.83c0 1.74.46 3.44 1.34 4.94L2 22l5.39-1.41a10 10 0 0 0 4.65 1.18h.01c5.46 0 9.89-4.4 9.89-9.83C21.94 6.4 17.5 2 12.04 2zm5.76 13.85c-.24.68-1.4 1.3-1.94 1.38-.5.08-1.12.11-1.81-.11-.41-.14-.95-.31-1.63-.61-2.87-1.24-4.74-4.13-4.88-4.32-.14-.19-1.15-1.53-1.15-2.92s.73-2.07 1-2.35c.24-.28.64-.41 1.02-.41.12 0 .23 0 .33.01.3.01.44.03.64.49.24.57.82 1.98.89 2.12.07.14.12.31.02.49-.09.19-.14.31-.28.47-.14.17-.29.37-.42.5-.14.14-.28.29-.12.55.16.26.7 1.16 1.51 1.88 1.04.92 1.91 1.21 2.2 1.35.28.14.45.12.62-.07.16-.19.7-.81.89-1.09.19-.28.38-.23.64-.14.26.09 1.65.78 1.93.92.28.14.47.21.54.33.07.12.07.68-.17 1.36z" />
                  </svg>
                ) : null}
              </button>
            )
          })}
        </div>,
        document.body,
      ) : null}
    </div>
  )
}

function TeamTrend({ trend }) {
  const points = trend && Array.isArray(trend.points) ? trend.points : []
  if (points.length < 2) return null
  const width = 560
  const height = 168
  const padX = 18
  const padTop = 12
  const padBottom = 22
  const coords = points.map((point, index) => {
    const average = Math.max(0, Math.min(100, Number(point.average) || 0))
    return {
      x: padX + (index * (width - padX * 2)) / (points.length - 1),
      y: padTop + (1 - average / 100) * (height - padTop - padBottom),
      short: point.short,
      average,
    }
  })
  const line = coords.map((point, index) => `${index ? 'L' : 'M'}${point.x},${point.y}`).join(' ')
  const change = Number(trend.change) || 0
  const direction = change < 0 ? 'Decrease' : change > 0 ? 'Development' : 'No change'
  return (
    <section className="wt-trend" aria-label="Team performance over six months">
      <h2 className="wt-dept-title">Team performance</h2>
      <div className="wt-trend-stats">
        <article>
          <strong>{coords[coords.length - 1].average}%</strong>
          <span>Average now</span>
        </article>
        <article className={change < 0 ? 'is-down' : 'is-up'}>
          <strong>{change > 0 ? `+${change}` : change}</strong>
          <span>{direction}</span>
        </article>
        <article className="is-up">
          <strong>{Number(trend.improved) || 0}</strong>
          <span>Improved</span>
        </article>
        <article className="is-down">
          <strong>{Number(trend.declined) || 0}</strong>
          <span>Declined</span>
        </article>
      </div>
      <svg className="wt-trend-chart" viewBox={`0 0 ${width} ${height}`} role="img" aria-label="Average score for the last six months">
        <path d={line} fill="none" stroke="#111827" strokeWidth="2" />
        {coords.map((point) => (
          <g key={point.short}>
            <circle cx={point.x} cy={point.y} r="3.5" fill="#111827" />
            <text x={point.x} y={height - 4} textAnchor="middle" fontSize="11" fill="#6b7280">{point.short}</text>
          </g>
        ))}
      </svg>
    </section>
  )
}

function todoSections(rows) {
  const today = new Date()
  const start = (date) => new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime()
  const todayStart = start(today)
  const groups = new Map()
  rows.forEach((row, index) => {
    const parsed = row.date ? new Date(`${String(row.date).slice(0, 10)}T00:00:00`) : null
    let key = 'earlier'
    let label = 'Earlier'
    if (parsed && !Number.isNaN(parsed.getTime())) {
      const diff = todayStart - start(parsed)
      if (diff === 0) {
        key = 'today'
        label = 'Today'
      } else if (diff === 86400000) {
        key = 'yesterday'
        label = 'Yesterday'
      }
    }
    if (!groups.has(key)) groups.set(key, { key, label, items: [] })
    groups.get(key).items.push({ row, index })
  })
  return ['today', 'yesterday', 'earlier'].filter((key) => groups.has(key)).map((key) => groups.get(key))
}

function QuoteIcon() {
  return (
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.5">
      <path d="M7 3.5h7.5L19 8v12.5H7z" />
      <path d="M14.5 3.5V8H19" />
      <path d="M9.5 12h5M9.5 15.5h5" />
    </svg>
  )
}

function InvoiceIcon() {
  return (
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.5">
      <path d="M7 3.5h10v17l-2-1.2-2 1.2-2-1.2-2 1.2-2-1.2z" />
      <path d="M9.5 8h5M9.5 11.5h5M9.5 15h3" />
    </svg>
  )
}

function measureIcon(item) {
  const key = String(item?.key || '')
  if (key === 'system-uptime') return 'uptime'
  if (key === 'it-support-response-resolution') return 'support'
  if (key === 'data-backup') return 'backup'
  if (key === 'system-accuracy') return 'accuracy'
  return 'sales'
}

function RecordIcon({ kind }) {
  if (kind === 'quote') return <QuoteIcon />
  if (kind === 'converted') {
    return (
      <span className="wt-nc-icon-pair">
        <QuoteIcon />
        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
          <path d="M5 12h12M13 7l5 5-5 5" />
        </svg>
        <InvoiceIcon />
      </span>
    )
  }
  if (kind === 'customer') {
    return (
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.5">
        <circle cx="12" cy="8" r="3" />
        <path d="M6 19.5c.6-3 2.8-4.5 6-4.5s5.4 1.5 6 4.5" />
      </svg>
    )
  }
  if (kind === 'sales') {
    return (
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.5">
        <path d="M4 19V5M4 19h16" />
        <path d="m7 14 4-4 3 3 5-6" />
      </svg>
    )
  }
  if (kind === 'attendance') {
    return (
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.5">
        <rect x="4" y="5" width="16" height="15" rx="2" />
        <path d="M8 3v4M16 3v4M4 10h16" />
      </svg>
    )
  }
  if (kind === 'uptime') {
    return (
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.5">
        <path d="M4 13a8 8 0 1 0 2.2-5.5" />
        <path d="M4 4v5h5" />
        <path d="M12 8v5l3 2" />
      </svg>
    )
  }
  if (kind === 'support') {
    return (
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.5">
        <path d="M4 13a8 8 0 0 1 16 0" />
        <rect x="3" y="13" width="4" height="6" rx="1" />
        <rect x="17" y="13" width="4" height="6" rx="1" />
        <path d="M12 19v1a2 2 0 0 0 2 2h1" />
      </svg>
    )
  }
  if (kind === 'backup') {
    return (
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.5">
        <ellipse cx="12" cy="6" rx="7" ry="3" />
        <path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6" />
        <path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6" />
      </svg>
    )
  }
  if (kind === 'accuracy') {
    return (
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.5">
        <path d="M12 3 5 6v6c0 4.2 2.8 7.2 7 8.5 4.2-1.3 7-4.3 7-8.5V6l-7-3z" />
        <path d="m9 12 2 2 4-4" />
      </svg>
    )
  }
  return (
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.5">
      <path d="M9 6h6M9 10h6M9 14h4" />
      <rect x="5" y="3" width="14" height="18" rx="2" />
    </svg>
  )
}

function RecordCards({ rows, label, kind, onOpen }) {
  const sections = todoSections(rows)
  return (
    <div className="wt-nc-list">
      {sections.map((section) => (
        <section key={section.key} className="wt-nc-section" aria-label={section.label}>
          <h2 className="wt-nc-heading">{section.label}</h2>
          <div className="wt-nc-section-list">
            {section.items.map(({ row, index }) => {
              const canOpen = typeof onOpen === 'function' && Boolean(row.documentUrl || row.documentText)
              return (
                <article
                  key={`${row.date}-${row.title}-${index}`}
                  className={`wt-nc-card${canOpen ? ' wt-nc-card--link' : ''}`}
                  role={canOpen ? 'button' : undefined}
                  tabIndex={canOpen ? 0 : undefined}
                  onClick={canOpen ? () => onOpen(row) : undefined}
                  onKeyDown={canOpen ? (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                      event.preventDefault()
                      onOpen(row)
                    }
                  } : undefined}
                >
                  <span className={`wt-nc-icon${row.mark === 'converted' ? ' wt-nc-icon--pair' : ''}`} aria-hidden="true">
                    <RecordIcon kind={row.mark || kind} />
                  </span>
                  <div className="wt-nc-body">
                    <p className="wt-nc-kicker">{row.kicker || label}</p>
                    <div className="wt-nc-title-row">
                      <h3 className="wt-nc-title">{row.title}</h3>
                      {row.when ? <time className="wt-nc-time">{row.when}</time> : null}
                    </div>
                    {row.phone || row.quotes ? (
                      <p className="wt-nc-meta">
                        <span>{row.phone || ''}</span>
                        <span>{row.quotes || ''}</span>
                      </p>
                    ) : (
                      <p className="wt-nc-message">{row.status}</p>
                    )}
                  </div>
                </article>
              )
            })}
          </div>
        </section>
      ))}
    </div>
  )
}

function AboutNote({ about }) {
  const [open, setOpen] = useState(false)
  const boxRef = useRef(null)
  useEffect(() => {
    if (!open) return undefined
    const close = (event) => {
      if (boxRef.current && !boxRef.current.contains(event.target)) setOpen(false)
    }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [open])
  if (!about || !about.text) return null
  return (
    <span className="wt-about" ref={boxRef}>
      <button
        type="button"
        className="wt-about-btn"
        aria-expanded={open}
        aria-label="About this measure"
        onClick={() => setOpen((value) => !value)}
      >
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true">
          <circle cx="12" cy="12" r="8" />
          <path d="M12 11v5" />
          <path d="M12 8h.01" />
        </svg>
      </button>
      {open ? (
        <span className="wt-about-pop" role="note">
          {about.score ? <strong>Score {about.score}</strong> : null}
          {about.note ? <span>{about.note}</span> : null}
          <span>{about.text}</span>
        </span>
      ) : null}
    </span>
  )
}

function AttIcon({ name }) {
  const common = { viewBox: '0 0 24 24', width: 18, height: 18, fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, 'aria-hidden': true }
  if (name === 'early') {
    return (
      <svg {...common}>
        <rect x="4" y="5" width="16" height="15" rx="2" />
        <path d="M8 3v4M16 3v4M4 10h16M9 15l2 2 4-4" />
      </svg>
    )
  }
  if (name === 'late') {
    return (
      <svg {...common}>
        <circle cx="12" cy="12" r="8" />
        <path d="M12 8v5l3 2" />
      </svg>
    )
  }
  if (name === 'pending') {
    return (
      <svg {...common}>
        <circle cx="12" cy="12" r="8" />
        <path d="M9 9l6 6M15 9l-6 6" />
      </svg>
    )
  }
  if (name === 'clock') {
    return (
      <svg {...common}>
        <circle cx="12" cy="12" r="8" />
        <path d="M12 8v5l3 2" />
      </svg>
    )
  }
  return (
    <svg {...common}>
      <path d="M10 7H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h8" />
      <path d="M10 17h8a2 2 0 0 0 2-2V7" />
      <path d="M15 12H8m0 0 2.5-2.5M8 12l2.5 2.5" />
    </svg>
  )
}

function AttendanceBoard({ board }) {
  const chart = Array.isArray(board?.chart) ? board.chart : []
  const width = 760
  const height = 210
  const left = 12
  const right = 28
  const top = 14
  const bottom = 46
  const plotW = width - left - right
  const plotH = height - top - bottom
  if (!chart.length) return null
  const slot = plotW / chart.length
  const barW = Math.max(6, Math.min(16, slot * 0.46))
  const base = top + plotH
  const yAt = (value) => top + plotH - (Math.min(1, Number(value) || 0) * plotH)
  const bars = chart.map((point, index) => {
    const present = Number(point.present) === 1 || Number(point.value) > 0
    const today = Number(point.today) === 1
    const reach = present ? (today ? 0.96 : 0.78) : 0
    const cap = present ? Math.min(16, plotH * 0.16) : 0
    const full = reach * plotH
    const body = Math.max(0, full - cap)
    const x = left + slot * index + (slot - barW) / 2
    return {
      key: `${point.label || index}-${index}`,
      x,
      label: point.label || String(point.day || index + 1),
      present,
      today,
      body,
      cap,
      full,
    }
  })
  return (
    <div className="wt-att">
      <section className="wt-att-chart" aria-label="Attendance chart">
        <div className="wt-att-chart-head">
          <span className="wt-att-chart-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.8">
              <path d="M5 19V10M10 19V5M15 19v-7M20 19V8" />
            </svg>
          </span>
          <div>
            <h2>Attendance Overview</h2>
            <p>Daily attendance for the selected month</p>
          </div>
        </div>
        <svg className="wt-att-svg" viewBox={`0 0 ${width} ${height}`} role="img">
          {[0, 0.5, 1].map((tick) => {
            const y = yAt(tick)
            return (
              <g key={tick}>
                <line className="wt-att-grid" x1={left} x2={width - right} y1={y} y2={y} />
                <text className="wt-att-tick" x={width - 4} y={y + 3} textAnchor="end">{tick === 0.5 ? '' : tick}</text>
              </g>
            )
          })}
          {bars.map((bar) => (
            <g key={bar.key}>
              {bar.present ? (
                <>
                  <rect
                    className={bar.today ? 'wt-att-bar-cap is-today' : 'wt-att-bar-cap'}
                    x={bar.x}
                    y={base - bar.full}
                    width={barW}
                    height={bar.full}
                    rx={barW / 2}
                  />
                  <rect
                    className={bar.today ? 'wt-att-bar is-today' : 'wt-att-bar'}
                    x={bar.x}
                    y={base - bar.body}
                    width={barW}
                    height={bar.body}
                  />
                </>
              ) : null}
              <text
                className={bar.today ? 'wt-att-label is-today' : 'wt-att-label'}
                x={bar.x + barW / 2}
                y={base + 14}
                textAnchor="end"
                transform={`rotate(-45 ${bar.x + barW / 2} ${base + 14})`}
              >
                {bar.label}
              </text>
            </g>
          ))}
        </svg>
      </section>
    </div>
  )
}

function AttendanceCards({ board }) {
  const stats = Array.isArray(board?.stats) ? board.stats : []
  const byKey = Object.fromEntries(stats.map((stat) => [stat.key, stat]))
  const [open, setOpen] = useState('')
  const cards = [
    { key: 'early', label: 'Came early', hint: 'Arrived before start time', icon: 'early', empty: 'No early days this month.' },
    { key: 'late', label: 'Came late', hint: 'Arrived after start time', icon: 'late', empty: 'No late days this month.' },
    { key: 'pending', label: 'Pending task', hint: 'Tasks not yet completed', icon: 'pending', empty: 'No pending tasks this month.' },
  ]
  const openCard = cards.find((card) => card.key === open) || null
  const openItems = openCard && Array.isArray(byKey[openCard.key]?.items) ? byKey[openCard.key].items : []
  return (
    <section className="wt-att-kpi-card" aria-label="Attendance details">
      <div className="wt-att-kpi-row">
        {cards.map((card) => {
          const active = open === card.key
          return (
            <button
              key={card.key}
              type="button"
              className={`wt-att-kpi-btn wt-att-kpi--${card.key}${active ? ' is-open' : ''}`}
              aria-expanded={active}
              onClick={() => setOpen(active ? '' : card.key)}
            >
              <span className="wt-att-kpi-head">
                <span className="wt-att-ico"><AttIcon name={card.icon} /></span>
                <strong>{card.label}</strong>
                <b>{Number(byKey[card.key]?.count) || 0}</b>
              </span>
              <span className="wt-att-kpi-hint">{card.hint}</span>
            </button>
          )
        })}
      </div>
      {openCard ? (
        <div className="wt-att-kpi-details">
          {openItems.length === 0 ? (
            <p>{openCard.empty}</p>
          ) : openItems.map((item, index) => (
            <article key={`${openCard.key}-${index}`}>
              <span>{openCard.key === 'pending' ? (item.title || 'Pending task') : openCard.label}</span>
              {item.when ? <time>{item.when}</time> : null}
            </article>
          ))}
        </div>
      ) : null}
    </section>
  )
}

function ToneChart({ title, subtitle, points, topLabel }) {
  const chart = Array.isArray(points) ? points : []
  const width = 760
  const height = 210
  const left = 12
  const right = 46
  const top = 14
  const bottom = 46
  const plotW = width - left - right
  const plotH = height - top - bottom
  if (!chart.length) return null
  const slot = plotW / chart.length
  const barW = Math.max(12, Math.min(chart.length <= 8 ? 42 : 16, slot * 0.42))
  const base = top + plotH
  const yAt = (value) => top + plotH - (Math.min(1, Number(value) || 0) * plotH)
  const bars = chart.map((point, index) => {
    const amount = Math.min(1, Math.max(0, Number(point.value) || 0))
    const today = Number(point.today) === 1
    const full = amount * plotH
    const cap = amount > 0 ? Math.min(16, full * 0.18) : 0
    const body = Math.max(0, full - cap)
    const x = left + slot * index + (slot - barW) / 2
    return {
      key: `${point.label || index}-${index}`,
      x,
      label: point.label || '',
      show: amount > 0,
      today,
      body,
      full,
    }
  })
  return (
    <section className="wt-att-chart" aria-label={title}>
      <div className="wt-att-chart-head">
        <span className="wt-att-chart-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path d="M5 19V10M10 19V5M15 19v-7M20 19V8" />
          </svg>
        </span>
        <div>
          <h2>{title}</h2>
          <p>{subtitle}</p>
        </div>
      </div>
      <svg className="wt-att-svg" viewBox={`0 0 ${width} ${height}`} role="img">
        {[0, 0.5, 1].map((tick) => {
          const y = yAt(tick)
          const text = tick === 1 ? topLabel : tick === 0 ? '0' : ''
          return (
            <g key={tick}>
              <line className="wt-att-grid" x1={left} x2={width - right} y1={y} y2={y} />
              <text className="wt-att-tick" x={width - 4} y={y + 3} textAnchor="end">{text}</text>
            </g>
          )
        })}
        {bars.map((bar) => (
          <g key={bar.key}>
            {bar.show ? (
              <>
                <rect
                  className={bar.today ? 'wt-att-bar-cap is-today' : 'wt-att-bar-cap'}
                  x={bar.x}
                  y={base - bar.full}
                  width={barW}
                  height={bar.full}
                  rx={4}
                />
                <rect
                  className={bar.today ? 'wt-att-bar is-today' : 'wt-att-bar'}
                  x={bar.x}
                  y={base - bar.body}
                  width={barW}
                  height={bar.body}
                />
              </>
            ) : null}
            <text
              className={bar.today ? 'wt-att-label is-today' : 'wt-att-label'}
              x={bar.x + barW / 2}
              y={base + 14}
              textAnchor="end"
              transform={`rotate(-45 ${bar.x + barW / 2} ${base + 14})`}
            >
              {bar.label}
            </text>
          </g>
        ))}
      </svg>
    </section>
  )
}

function UptimeBoard({ board, rows }) {
  const top = Array.isArray(board?.top) ? board.top : []
  const outages = Array.isArray(rows) ? rows : []
  return (
    <>
      <div className="wt-up-charts">
        <ToneChart
          title="System uptime"
          subtitle={`${Number(board?.uptimePct) || 0}% available · ${Number(board?.downDays) || 0} down days`}
          points={board?.chart}
          topLabel="100%"
        />
        <ToneChart
          title="Usage"
          subtitle={`${board?.usageLabel || '0 hrs'} signed in`}
          points={board?.usage}
          topLabel={board?.scaleLabel || '0'}
        />
      </div>
      <section className="wt-up-top" aria-label="Highest uptime">
        <h2>Highest uptime</h2>
        {top.length === 0 ? (
          <p>No one signed in this period.</p>
        ) : (
          <ol>
            {top.map((person, index) => (
              <li key={`${person.name}-${index}`}>
                <div className="wt-up-who">
                  <span className="wt-up-rank">{index + 1}</span>
                  {person.photo ? (
                    <img className="wt-up-face" src={person.photo} alt="" />
                  ) : (
                    <span className="wt-up-face" aria-hidden="true">
                      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.7">
                        <circle cx="12" cy="8" r="3" />
                        <path d="M6 19.5c.6-3 2.8-4.5 6-4.5s5.4 1.5 6 4.5" />
                      </svg>
                    </span>
                  )}
                  <strong>{person.name}</strong>
                </div>
                <em>
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true">
                    <circle cx="12" cy="12" r="8" />
                    <path d="M12 8v5l3 2" />
                  </svg>
                  {person.hoursLabel}
                </em>
                <small>
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true">
                    <rect x="4" y="5" width="16" height="15" rx="2" />
                    <path d="M8 3v4M16 3v4M4 10h16" />
                  </svg>
                  {Number(person.days) || 0} {Number(person.days) === 1 ? 'day' : 'days'}
                </small>
              </li>
            ))}
          </ol>
        )}
      </section>
      {outages.length ? (
        <RecordCards rows={outages} label="Outage" kind="attendance" />
      ) : null}
    </>
  )
}

function MeasurePage({ month, measure }) {
  const rows = Array.isArray(measure.rows) ? measure.rows : []
  const breakdown = Array.isArray(measure.items) ? measure.items : []
  const [doc, setDoc] = useState(null)
  const [docReady, setDocReady] = useState(false)
  const frameRef = useRef(null)
  const openDocument = (row) => {
    if (measure.key !== 'delivery-documents' && !row.documentUrl && !row.documentText) return
    setDocReady(false)
    setDoc({
      title: row.title || 'Document',
      url: row.documentUrl || '',
      text: row.documentText || '',
    })
  }
  const downloadDocument = () => {
    const win = frameRef.current && frameRef.current.contentWindow
    if (win && typeof win.downloadDeliveryNote === 'function') {
      win.downloadDeliveryNote()
    }
  }
  return (
    <div className="wt-dash">
      <a className="wt-back" href={measure.backUrl || '#'}>Back</a>
      {measure.key === 'attendance' && measure.configured && measure.board ? (
        <>
          <section className="wt-att-page">
            <header className="wt-att-top">
              <span className="wt-att-top-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.7">
                  <rect x="4" y="5" width="16" height="15" rx="2" />
                  <path d="M8 3v4M16 3v4M4 10h16" />
                </svg>
              </span>
              <div>
                <h1>Attendance</h1>
                <p>{measure.summary}</p>
              </div>
              <MonthSelect month={month} boxed />
            </header>
            <AttendanceBoard board={measure.board} />
          </section>
          <AttendanceCards board={measure.board} />
        </>
      ) : null}
      {measure.key === 'system-uptime' && measure.configured && measure.board ? (
        <section className="wt-att-page">
          <header className="wt-att-top">
            <span className="wt-att-top-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.7">
                <path d="M4 13a8 8 0 1 0 2.2-5.5" />
                <path d="M4 4v5h5" />
                <path d="M12 8v5l3 2" />
              </svg>
            </span>
            <div>
              <h1>System Uptime</h1>
              <p>{Number(measure.board.uptimePct) || 0}% available · {measure.board.usageLabel || '0 hrs'} used</p>
            </div>
            <MonthSelect month={month} boxed />
          </header>
          <UptimeBoard board={measure.board} rows={rows} />
        </section>
      ) : null}
      {(measure.key === 'attendance' || measure.key === 'system-uptime') && measure.configured && measure.board ? null : (
      <>
      <header className="wt-head">
        <div>
          <div className="wt-title-row">
            <h1 className="wt-title">{measure.title}</h1>
            <AboutNote about={measure.about} />
          </div>
          {measure.empty === 'Coming soon' ? null : (
            <p className="wt-sub">{measure.summary}</p>
          )}
        </div>
        <MonthSelect month={month} />
      </header>
      {!measure.configured ? (
        <p className="wt-empty wt-empty--card">Target not set.</p>
      ) : breakdown.length ? (
        <SalesMeasureCards items={breakdown} />
      ) : rows.length === 0 ? (
        measure.empty === 'Coming soon' ? (
          <div className="wt-soon" role="status">
            <span className="wt-soon-dots" aria-hidden="true">
              <span />
              <span />
              <span />
            </span>
            <p>Coming soon</p>
          </div>
        ) : (
          <p className="wt-empty wt-empty--card">{measure.empty || 'Nothing recorded in this period.'}</p>
        )
      ) : ['todo', 'attendance', 'monthly-sales-revenue', 'new-customers', 'quotation-conversion', 'collections', 'customer-visits', 'goods-delivery', 'system-uptime', 'it-support-response-resolution', 'data-backup', 'system-accuracy'].includes(measure.key) ? (
        <RecordCards
          rows={rows}
          onOpen={openDocument}
          label={{
            attendance: 'Attendance',
            'monthly-sales-revenue': 'Sales',
            'new-customers': 'Customer',
            'quotation-conversion': 'Quotation',
            collections: 'Collection',
            'customer-visits': 'Visit',
            'goods-delivery': 'Delivery',
            'system-uptime': 'Uptime',
            'it-support-response-resolution': 'Support',
            'data-backup': 'Backup',
            'system-accuracy': 'Accuracy',
          }[measure.key] || 'To-do'}
          kind={
            measure.key === 'attendance' ? 'attendance'
              : measure.key === 'todo' ? 'todo'
                : measure.key === 'new-customers' ? 'customer'
                  : 'sales'
          }
        />
      ) : (
        <div className="wt-compare">
          <div className="wt-compare-row wt-compare-row--head">
            <span>Detail</span>
            <span>When</span>
            <span>Status</span>
          </div>
          {rows.map((row, index) => {
            const met = /complete|present|^met$|^recorded$/i.test(String(row.status || ''))
            const canOpen = measure.key === 'delivery-documents' || Boolean(row.documentUrl || row.documentText)
            return (
              <div
                key={`${row.when}-${row.title}-${index}`}
                className={`wt-compare-row${canOpen ? ' wt-compare-row--link' : ''}`}
                role={canOpen ? 'button' : undefined}
                tabIndex={canOpen ? 0 : undefined}
                onClick={canOpen ? () => openDocument(row) : undefined}
                onKeyDown={canOpen ? (event) => {
                  if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault()
                    openDocument(row)
                  }
                } : undefined}
              >
                <span className="wt-compare-name">{row.title}</span>
                <span>{row.when}</span>
                <span className="wt-compare-done">
                  <em className={`wt-status wt-status--${met ? 'met' : 'short'}`}>{row.status}</em>
                </span>
              </div>
            )
          })}
        </div>
      )}
      {doc ? createPortal(
        <div className="wt-doc" role="dialog" aria-modal="true" aria-label={doc.title}>
          <button type="button" className="wt-doc-backdrop" aria-label="Close document" onClick={() => setDoc(null)} />
          <div className="wt-doc-panel">
            <header className="wt-doc-head">
              <strong>{doc.title}</strong>
              <span className="wt-doc-actions">
                {measure.key === 'delivery-documents' && doc.url ? (
                  <button type="button" className="wt-doc-download" disabled={!docReady} onClick={downloadDocument}>Download</button>
                ) : null}
                <button type="button" className="wt-doc-close" onClick={() => setDoc(null)}>Close</button>
              </span>
            </header>
            {doc.url ? (
              <iframe ref={frameRef} className="wt-doc-frame" title={doc.title} src={doc.url} onLoad={() => setDocReady(true)} />
            ) : doc.text ? (
              <div className="wt-doc-note">{doc.text}</div>
            ) : (
              <p className="wt-doc-missing">No document recorded for {doc.title}.</p>
            )}
          </div>
        </div>,
        document.body
      ) : null}
      </>
      )}
    </div>
  )
}

export default function App() {
  const data = cfg()
  const month = data.month || {}
  const bands = Array.isArray(data.bands) ? data.bands : []
  const departments = Array.isArray(data.departments) ? data.departments : []

  if (data.measure) {
    return <MeasurePage month={month} measure={data.measure} />
  }

  if (data.detail || data.detailMissing) {
    return <Detail month={month} detail={data.detail} missing={Boolean(data.detailMissing)} />
  }

  return (
    <div className="wt-dash">
      <header className="wt-head">
        <div>
          <h1 className="wt-title">Performance</h1>
          <p className="wt-sub">{month.label || 'This month'}</p>
        </div>
        <div className="wt-week">
          <MonthSelect month={month} />
        </div>
      </header>

      <TeamTrend trend={data.trend} />

      <section className="wt-bands" aria-label="Score bands">
        {bands.map((band) => (
          <article key={band.key} className={`wt-band wt-band--${band.key}`}>
            <BandIcon name={band.key} />
            <span className="wt-band-copy">
              <span className="wt-band-count">{Number(band.count) || 0}</span>
              <span className="wt-band-label">{band.label}</span>
            </span>
          </article>
        ))}
      </section>

      {departments.length === 0 ? (
        <p className="wt-empty">No active people for this month.</p>
      ) : departments.map((dept) => (
        <section key={dept.name} className="wt-dept">
          <h2 className="wt-dept-title">{dept.name}</h2>
          <div className="wt-table">
            <div className="wt-row wt-row--head">
              <span>Name</span>
              <span>Score</span>
              <span>Tasks</span>
              <span>Activity</span>
            </div>
            {(dept.people || []).map((person) => (
              <a key={person.id} className="wt-row" href={person.href || '#'}>
                <span className="wt-person">
                  <span className="wt-avatar" aria-hidden="true">{initials(person.name)}</span>
                  <span>
                    {person.name}
                    {person.isViewer ? <span className="wt-you">You</span> : null}
                  </span>
                </span>
                <span className="wt-score-cell">
                  <strong>{Number(person.score) || 0}%</strong>
                  <span className={`wt-pill wt-pill--${person.bandKey}`}>{person.band}</span>
                </span>
                <span>{Number(person.tasksDone) || 0}/{Number(person.tasksAssigned) || 0}</span>
                <span>{Number(person.activity) || 0}%</span>
              </a>
            ))}
          </div>
        </section>
      ))}
    </div>
  )
}
