import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import {
  Star, Search, Loader2, MessageSquare, ThumbsUp, BarChart3,
} from 'lucide-react'
import { CFG } from '../config.js'
import DeliveryKpiTraceModal from '../components/DeliveryKpiTraceModal.jsx'
import FeedbackGradeBadge from '../components/FeedbackGradeBadge.jsx'
import { fetchFeedbackGrades } from '../api/gradeFeedback.js'
import { resolveKpiTrace } from '../utils/kpiTrace.js'

function cleanText(value) {
  if (value == null) return ''
  return String(value)
    .replace(/\uFFFD/g, '')
    .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '')
    .trim()
}

function formatDateTime(value) {
  if (!value) return '-'
  const d = new Date(String(value).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return '-'
  return d.toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
}

function StarRating({ rating, size = 16 }) {
  const stars = []
  for (let i = 1; i <= 5; i += 1) {
    stars.push(
      <Star
        key={i}
        size={size}
        fill={i <= rating ? 'currentColor' : 'none'}
        className={i <= rating ? 'dlv-review-star dlv-review-star--filled' : 'dlv-review-star'}
        aria-hidden="true"
      />,
    )
  }
  return <div className="dlv-review-stars" aria-label={`${rating} out of 5 stars`}>{stars}</div>
}

function clientInitials(name) {
  const parts = cleanText(name).split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return `${parts[0][0] || ''}${parts[parts.length - 1][0] || ''}`.toUpperCase()
}

export default function CustomerReviewsPage() {
  const initial = CFG.data || {}
  const [data, setData] = useState(initial)
  const [loading, setLoading] = useState(!initial.reviews)
  const [searchInput, setSearchInput] = useState('')
  const [searchOpen, setSearchOpen] = useState(false)
  const [activeKpiTrace, setActiveKpiTrace] = useState(null)
  const [activeKpiTraceKey, setActiveKpiTraceKey] = useState('')
  const searchInputRef = useRef(null)
  const mobileSearchInputRef = useRef(null)
  const searchExpandRef = useRef(null)
  const [headerSearchMount, setHeaderSearchMount] = useState(null)
  const [gradesById, setGradesById] = useState({})
  const [gradesLoading, setGradesLoading] = useState(false)
  const [gradesNote, setGradesNote] = useState('')

  const urls = data.urls || {}
  const reviews = data.reviews || []
  const stats = data.stats || {}

  const kpis = [
    {
      key: 'avgRating',
      label: 'Average satisfaction',
      value: `${Number(stats.avgRating || 0).toFixed(1)}`,
      sub: 'Out of 5.0 stars',
      color: 'amber',
      icon: <Star size={17} aria-hidden="true" />,
    },
    {
      key: 'totalReviews',
      label: 'Total reviews',
      value: Number(stats.totalReviews || 0).toLocaleString(),
      sub: 'All submitted ratings',
      color: 'blue',
      icon: <BarChart3 size={17} aria-hidden="true" />,
    },
    {
      key: 'positive',
      label: 'Positive sentiment',
      value: `${Number(stats.positivePercent || 0)}%`,
      sub: 'Rated 4 stars or higher',
      color: 'green',
      icon: <ThumbsUp size={17} aria-hidden="true" />,
    },
  ]

  function openKpiTrace(key) {
    const trace = resolveKpiTrace(key, data)
    if (!trace) return
    setActiveKpiTraceKey(key)
    setActiveKpiTrace(trace)
  }

  useEffect(() => {
    if (initial.reviews) return undefined
    if (!CFG.customerReviewsInitUrl) {
      setLoading(false)
      return undefined
    }
    let alive = true
    ;(async () => {
      try {
        const qs = window.location.search || ''
        const res = await fetch(`${CFG.customerReviewsInitUrl}${qs}`, { headers: { Accept: 'application/json' } })
        const payload = await res.json()
        if (alive && payload?.ok && payload.data) {
          setData(payload.data)
        }
      } catch {
        /* keep empty state */
      } finally {
        if (alive) setLoading(false)
      }
    })()
    return () => { alive = false }
  }, [initial.reviews])

  useEffect(() => {
    if (!reviews.length) {
      setGradesById({})
      setGradesNote('')
      return undefined
    }

    let alive = true
    setGradesLoading(true)
    ;(async () => {
      try {
        const result = await fetchFeedbackGrades(reviews.map((row) => ({
          id: row.id,
          feedback: cleanText(row.feedback),
          rating: row.rating || 0,
        })))
        if (!alive) return
        setGradesById(result.gradesById)
        setGradesNote(result.note || '')
      } catch {
        if (alive) {
          setGradesById({})
          setGradesNote('')
        }
      } finally {
        if (alive) setGradesLoading(false)
      }
    })()

    return () => { alive = false }
  }, [reviews])

  useEffect(() => {
    setHeaderSearchMount(document.getElementById('dlv-header-search-mount'))
  }, [])

  useEffect(() => {
    if (searchInput.trim() !== '') setSearchOpen(true)
  }, [searchInput])

  useEffect(() => {
    if (!searchOpen) return undefined

    function handlePointerDown(event) {
      if (!searchExpandRef.current?.contains(event.target) && searchInput.trim() === '') {
        setSearchOpen(false)
      }
    }

    function handleKeyDown(event) {
      if (event.key === 'Escape') setSearchOpen(false)
    }

    const focusTimer = window.setTimeout(() => {
      mobileSearchInputRef.current?.focus()
    }, 180)

    document.addEventListener('mousedown', handlePointerDown)
    document.addEventListener('keydown', handleKeyDown)
    return () => {
      window.clearTimeout(focusTimer)
      document.removeEventListener('mousedown', handlePointerDown)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [searchOpen, searchInput])

  function toggleMobileSearch() {
    setSearchOpen((open) => !open)
  }

  function renderSearchField(inputRef, id = 'dlv-reviews-search') {
    return (
      <div className="dlv-search-field">
        <Search className="dlv-search-icon" size={15} aria-hidden="true" />
        <input
          ref={inputRef}
          id={id}
          type="search"
          className="dlv-search-input"
          placeholder="Search client, feedback, driver..."
          value={searchInput}
          autoComplete="off"
          onChange={(e) => setSearchInput(e.target.value)}
          aria-label="Search customer reviews"
        />
      </div>
    )
  }

  const hasSearchValue = searchInput.trim() !== ''
  const desktopSearchField = renderSearchField(searchInputRef, 'dlv-reviews-search-desktop')

  const filtered = useMemo(() => {
    const q = searchInput.trim().toLowerCase()
    if (!q) return reviews
    return reviews.filter((review) => {
      const text = [
        review.client_name,
        review.client_phone,
        review.feedback,
        review.order_ref,
        review.trip_ref,
        review.driver_name,
        review.rating,
      ].join(' ').toLowerCase()
      return q.split(/\s+/).every((term) => text.includes(term))
    })
  }, [reviews, searchInput])

  if (loading && !data.reviews) {
    return (
      <div className="dlv-page">
        <div className="dlv-loading" role="status">
          <Loader2 className="dlv-spin" aria-hidden="true" />
          <span>Loading customer reviews...</span>
        </div>
      </div>
    )
  }

  return (
    <div className="dlv-page">
      {activeKpiTrace && (
        <DeliveryKpiTraceModal
          trace={activeKpiTrace}
          traceKey={activeKpiTraceKey}
          onClose={() => {
            setActiveKpiTrace(null)
            setActiveKpiTraceKey('')
          }}
        />
      )}

      {headerSearchMount ? createPortal(desktopSearchField, headerSearchMount) : null}

      <div className="dlv-page-header">
        <div className="dlv-page-header-search dlv-page-header-search--desktop">
          {!headerSearchMount ? desktopSearchField : null}
        </div>

        <div className="dlv-page-header-actions">
          <div className="dlv-toolbar-secondary">
            <div
              className={`dlv-search-expand${searchOpen ? ' is-open' : ''}`}
              ref={searchExpandRef}
            >
              <button
                type="button"
                className={`dlv-search-toggle${searchOpen ? ' is-active' : ''}${hasSearchValue ? ' has-value' : ''}`}
                onClick={toggleMobileSearch}
                aria-expanded={searchOpen}
                aria-controls="dlv-reviews-search-mobile-panel"
                title="Search customer reviews"
              >
                <Search size={18} aria-hidden="true" />
              </button>
              <div
                id="dlv-reviews-search-mobile-panel"
                className={`dlv-search-panel${searchOpen ? ' is-open' : ''}`}
              >
                {renderSearchField(mobileSearchInputRef, 'dlv-reviews-search-mobile')}
              </div>
            </div>
          </div>
        </div>
      </div>

      <div className="dlv-sticky-top">
        <div className="dlv-kpis dlv-kpis--3">
          {kpis.map((k) => (
            <button
              key={k.key}
              type="button"
              className="dlv-kpi-card dlv-kpi--clickable"
              onClick={() => openKpiTrace(k.key)}
              aria-label={`View details for ${k.label}`}
              title="Click to see contributing records"
            >
              <div className="dlv-kpi-text">
                <span className="dlv-kpi-label">{k.label}</span>
                <div className="dlv-kpi-value">{k.value}</div>
                <div className="dlv-kpi-sub">{k.sub}</div>
              </div>
              <span className={`dlv-kpi-badge dlv-badge--${k.color}`}>{k.icon}</span>
            </button>
          ))}
        </div>
      </div>

      <section className="dlv-reviews-section">
        <div className="dlv-reviews-section-head">
          <h3 className="dlv-reviews-section-title">
            <MessageSquare size={18} aria-hidden="true" />
            Feedback history
          </h3>
          <span className="dlv-reviews-section-meta">
            {filtered.length} review{filtered.length === 1 ? '' : 's'}
            {gradesNote ? ` � ${gradesNote}` : ''}
          </span>
        </div>

        {filtered.length === 0 ? (
          <div className="dlv-empty dlv-empty--reviews">
            <MessageSquare size={32} aria-hidden="true" />
            <p>{reviews.length === 0 ? 'No reviews received yet.' : 'No reviews match your search.'}</p>
          </div>
        ) : (
          <div className="dlv-reviews-list">
            {filtered.map((review) => {
              const feedbackText = cleanText(review.feedback)
              const clientName = cleanText(review.client_name) || '-'
              const clientPhone = cleanText(review.client_phone)

              return (
                <article key={review.id} className="dlv-review-card">
                  <header className="dlv-review-head">
                    <div className="dlv-review-avatar" aria-hidden="true">
                      {clientInitials(clientName)}
                    </div>
                    <div className="dlv-review-client">
                      <div className="dlv-review-name">{clientName}</div>
                      {clientPhone ? (
                        <div className="dlv-review-phone">{clientPhone}</div>
                      ) : null}
                      <time className="dlv-review-date" dateTime={review.completion_time}>
                        {formatDateTime(review.completion_time)}
                      </time>
                    </div>
                  </header>

                  <div className={`dlv-review-message${feedbackText ? '' : ' dlv-review-message--empty'}`}>
                    <p>{feedbackText || 'No written feedback'}</p>
                  </div>

                  <div className="dlv-review-grade-wrap">
                    <FeedbackGradeBadge
                      grade={gradesById[review.id]}
                      loading={gradesLoading && !gradesById[review.id]}
                    />
                  </div>

                  <footer className="dlv-review-rating-row">
                    <StarRating rating={review.rating || 0} size={16} />
                    <span className="dlv-review-score">{Number(review.rating || 0).toFixed(1)}</span>
                  </footer>
                </article>
              )
            })}
          </div>
        )}
      </section>
    </div>
  )
}
