import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import {
  Star, Search, Loader2, MessageSquare, ThumbsUp, BarChart3,
  Smile, Laugh, Meh, Frown, Angry,
} from 'lucide-react'
import { CFG } from '../config.js'
import DeliveryKpiTraceModal from '../components/DeliveryKpiTraceModal.jsx'
import { resolveKpiTrace } from '../utils/kpiTrace.js'

function cleanText(value) {
  if (value == null) return ''
  return String(value)
    .replace(/\uFFFD/g, '')
    .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '')
    .trim()
}

function formatDateTime(value) {
  if (!value) return '—'
  const d = new Date(String(value).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
}

function ratingMood(rating) {
  const value = Number(rating) || 0
  if (value >= 5) {
    return { icon: Laugh, tone: 'great', label: 'Very satisfied' }
  }
  if (value >= 4) {
    return { icon: Smile, tone: 'good', label: 'Satisfied' }
  }
  if (value >= 3) {
    return { icon: Meh, tone: 'ok', label: 'Neutral' }
  }
  if (value >= 2) {
    return { icon: Frown, tone: 'poor', label: 'Dissatisfied' }
  }
  return { icon: Angry, tone: 'bad', label: 'Very dissatisfied' }
}

function FaceRating({ rating, size = 22 }) {
  const mood = ratingMood(rating)
  const MoodIcon = mood.icon
  return (
    <div
      className={`dlv-review-face dlv-review-face--${mood.tone}`}
      title={mood.label}
      aria-label={`${mood.label} (${rating} of 5)`}
    >
      <MoodIcon size={size} aria-hidden="true" />
      <span className="dlv-review-face-label">{mood.label}</span>
    </div>
  )
}

function displayFeedback(text) {
  return cleanText(text)
    .replace(/\n*\s*Follow up:\s*(Yes|No)\s*$/i, '')
    .trim()
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

  const reviews = data.reviews || []
  const stats = data.stats || {}

  const kpis = [
    {
      key: 'avgRating',
      label: 'Customer average',
      value: Number(stats.avgRating || 0).toFixed(1),
      unit: '/ 5',
      color: 'amber',
      icon: <Star size={15} aria-hidden="true" />,
      title: 'Average customer star rating out of 5.',
    },
    {
      key: 'totalReviews',
      label: 'Customer reviews',
      value: Number(stats.totalReviews || 0).toLocaleString(),
      unit: '',
      color: 'blue',
      icon: <BarChart3 size={15} aria-hidden="true" />,
      title: 'Number of customer ratings submitted after delivery.',
    },
    {
      key: 'positive',
      label: 'Customer positive',
      value: `${Number(stats.positivePercent || 0)}%`,
      unit: '',
      color: 'green',
      icon: <ThumbsUp size={15} aria-hidden="true" />,
      title: 'Share of customer ratings at 4 or 5 stars.',
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
          placeholder="Search reviews..."
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
      <div className="dlv-page dlv-page--reviews">
        <div className="dlv-loading" role="status">
          <Loader2 className="dlv-spin" aria-hidden="true" />
          <span>Loading customer reviews...</span>
        </div>
      </div>
    )
  }

  return (
    <div className="dlv-page dlv-page--reviews">
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

      <div className="dlv-reviews-summary">
        {kpis.map((k) => (
          <button
            key={k.key}
            type="button"
            className="dlv-reviews-stat"
            onClick={() => openKpiTrace(k.key)}
            aria-label={`View details for ${k.label}`}
            title={k.title}
          >
            <span className="dlv-reviews-stat-top">
              <span className="dlv-reviews-stat-label">{k.label}</span>
              <span className={`dlv-reviews-stat-icon dlv-reviews-stat-icon--${k.color}`}>{k.icon}</span>
            </span>
            <span className="dlv-reviews-stat-value">
              {k.value}
              {k.unit ? <span className="dlv-reviews-stat-unit">{k.unit}</span> : null}
            </span>
          </button>
        ))}
      </div>

      <section className="dlv-reviews-section">
        <div className="dlv-reviews-section-head">
          <h2 className="dlv-reviews-section-title">Customer feedback</h2>
          <span className="dlv-reviews-section-meta">
            {filtered.length} review{filtered.length === 1 ? '' : 's'}
          </span>
        </div>

        {filtered.length === 0 ? (
          <div className="dlv-empty dlv-empty--reviews">
            <MessageSquare size={28} aria-hidden="true" />
            <p>{reviews.length === 0 ? 'No reviews yet.' : 'No reviews match your search.'}</p>
          </div>
        ) : (
          <ul className="dlv-reviews-list">
            <li className="dlv-review-head" aria-hidden="true">
              <div className="dlv-review-col-label">Rating</div>
              <div className="dlv-review-col-label">Name</div>
              <div className="dlv-review-col-label">Rated</div>
              <div className="dlv-review-col-label">Comment</div>
            </li>
            {filtered.map((review) => {
              const feedbackText = displayFeedback(review.feedback)
              const clientName = cleanText(review.client_name) || '—'
              const clientPhone = cleanText(review.client_phone)
              const driverName = cleanText(review.driver_name)
              const orderRef = cleanText(review.order_ref)

              return (
                <li key={review.id} className="dlv-review-card">
                  <div className="dlv-review-rating-lead" title="Customer face rating">
                    <FaceRating rating={review.rating || 0} size={22} />
                  </div>

                  <div className="dlv-review-who">
                    <div className="dlv-review-client">
                      <div className="dlv-review-name">{clientName}</div>
                      <div className="dlv-review-meta-line">
                        {clientPhone ? <span>{clientPhone}</span> : null}
                        {clientPhone && orderRef ? <span aria-hidden="true">·</span> : null}
                        {orderRef ? <span>{orderRef}</span> : null}
                      </div>
                      <time className="dlv-review-date" dateTime={review.completion_time}>
                        {formatDateTime(review.completion_time)}
                      </time>
                    </div>
                  </div>

                  <div className="dlv-review-rated" title="Team member who handled the delivery">
                    {driverName ? (
                      <span className="dlv-review-rated-name">{driverName}</span>
                    ) : (
                      <span className="dlv-review-rated-empty">Unassigned</span>
                    )}
                  </div>

                  <div className="dlv-review-comment">
                    <p className={`dlv-review-text${feedbackText ? '' : ' is-empty'}`}>
                      {feedbackText || 'No written feedback'}
                    </p>
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </section>
    </div>
  )
}
