import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import {
  AlertTriangle,
  BarChart3,
  Car,
  CircleHelp,
  FileText,
  Gauge,
  Loader2,
  Send,
  Sparkles,
  Timer,
  X,
} from 'lucide-react'
import { fetchFeedbackGrades } from '../api/gradeFeedback.js'
import { resolveKpiAiAssistUrl, sendKpiChatMessage } from '../api/kpiAssist.js'
import FeedbackGradeBadge from './FeedbackGradeBadge.jsx'

function formatDate(value) {
  if (!value) return '-'
  const d = new Date(String(value).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return '-'
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatStatus(status) {
  const s = String(status || '').toLowerCase()
  if (s === 'request_pending') return 'Pending'
  return s ? s.replace(/_/g, ' ') : '-'
}

function cellText(value) {
  const text = String(value ?? '').trim()
  return text || '-'
}

function metricTone(key) {
  if (key === 'on_time') return 'green'
  if (key === 'vehicle_care') return 'teal'
  if (key === 'documentation') return 'purple'
  return 'blue'
}

function MetricIcon({ toneKey }) {
  const size = 18
  if (toneKey === 'on_time') return <Timer size={size} aria-hidden="true" />
  if (toneKey === 'vehicle_care') return <Car size={size} aria-hidden="true" />
  if (toneKey === 'documentation') return <FileText size={size} aria-hidden="true" />
  return <Gauge size={size} aria-hidden="true" />
}

function ScoreRing({ value, size = 52, stroke = 5 }) {
  const pct = Math.min(100, Math.max(0, Number(value) || 0))
  const radius = (size - stroke) / 2
  const circumference = 2 * Math.PI * radius
  const offset = circumference - (pct / 100) * circumference
  return (
    <svg
      className="dlv-perf-score-ring"
      width={size}
      height={size}
      viewBox={`0 0 ${size} ${size}`}
      aria-hidden="true"
    >
      <circle
        className="dlv-perf-score-ring__track"
        cx={size / 2}
        cy={size / 2}
        r={radius}
        fill="none"
        strokeWidth={stroke}
      />
      <circle
        className="dlv-perf-score-ring__value"
        cx={size / 2}
        cy={size / 2}
        r={radius}
        fill="none"
        strokeWidth={stroke}
        strokeDasharray={circumference}
        strokeDashoffset={offset}
        strokeLinecap="round"
        transform={`rotate(-90 ${size / 2} ${size / 2})`}
      />
      <text
        x="50%"
        y="50%"
        dominantBaseline="central"
        textAnchor="middle"
        className="dlv-perf-score-ring__label"
      >
        {pct.toFixed(1)}%
      </text>
    </svg>
  )
}

function DriverPerformanceBoard({ drivers, footnote, onSelectDriver, title, headline, score }) {
  return (
    <section className="dlv-perf-board">
      <div className="dlv-perf-summary dlv-perf-summary--board">
        <div className="dlv-perf-summary__text">
          <div className="dlv-perf-summary__title-row">
            <span className="dlv-perf-summary__icon" aria-hidden="true">
              <Gauge size={18} />
            </span>
            <div>
              <h3 className="dlv-perf-summary__title">{cellText(title || 'Driver performance')}</h3>
              <p className="dlv-perf-summary__sub">
                {cellText(
                  headline
                    || 'Overall performance based on completed deliveries, vehicle care, documentation and on-time delivery.',
                )}
              </p>
            </div>
          </div>
        </div>
        <div className="dlv-perf-summary__score" aria-label={`Overall score ${Number(score || 0).toFixed(1)} percent`}>
          <ScoreRing value={score} />
          <div className="dlv-perf-summary__score-copy">
            <strong>Overall Score</strong>
            <span>Driver performance</span>
          </div>
        </div>
      </div>

      <header className="dlv-perf-board__head">
        <h3 className="dlv-perf-board__title">Drivers this week</h3>
        <p className="dlv-perf-board__hint">All drivers listed. Click one to open their score breakdown.</p>
      </header>

      {drivers.length === 0 ? (
        <p className="dlv-trace-empty">No drivers with scored activity this week.</p>
      ) : (
        <ul className="dlv-perf-board__list" role="list">
          {drivers.map((driver, index) => {
            const driverScore = Number(driver.score || 0)
            const tone = driverScore >= 85 ? 'green' : (driverScore >= 70 ? 'amber' : 'red')
            return (
              <li key={driver.id || `${driver.name}-${index}`}>
                <button
                  type="button"
                  className={`dlv-perf-driver dlv-perf-driver--${tone}`}
                  onClick={() => onSelectDriver(driver.id)}
                >
                  <span className="dlv-perf-driver__rank" aria-hidden="true">{index + 1}</span>
                  <span className="dlv-perf-driver__body">
                    <strong className="dlv-perf-driver__name">{cellText(driver.name)}</strong>
                  </span>
                  <span className="dlv-perf-driver__score">{driverScore.toFixed(1)}%</span>
                </button>
              </li>
            )
          })}
        </ul>
      )}

      {footnote ? <p className="dlv-perf-board__footnote">{footnote}</p> : null}
    </section>
  )
}

function PerformanceBreakdown({ trace }) {
  const metrics = Array.isArray(trace.metrics) ? trace.metrics : []
  const calculation = Array.isArray(trace.calculation) ? trace.calculation : []
  const suggestions = Array.isArray(trace.suggestions) ? trace.suggestions : []
  const drivers = Array.isArray(trace.drivers) ? trace.drivers : []
  const [selectedKey, setSelectedKey] = useState(null)
  const [selectedDriverId, setSelectedDriverId] = useState(null)
  const [howOpen, setHowOpen] = useState(false)

  useEffect(() => {
    setSelectedKey(null)
    setSelectedDriverId(null)
    setHowOpen(false)
  }, [trace])

  const selectedDriver = drivers.find((row) => Number(row.id) === Number(selectedDriverId)) || null
  const showDriverBoard = drivers.length > 0 && !selectedDriver

  const activeMetrics = selectedDriver
    ? (Array.isArray(selectedDriver.metrics) ? selectedDriver.metrics : [])
    : metrics
  const activeCalculation = selectedDriver
    ? (Array.isArray(selectedDriver.calculation) ? selectedDriver.calculation : [])
    : calculation
  const activeSuggestions = selectedDriver
    ? (Array.isArray(selectedDriver.suggestions) ? selectedDriver.suggestions : [])
    : suggestions

  if (showDriverBoard) {
    return (
      <DriverPerformanceBoard
        drivers={drivers}
        footnote={trace.footnote}
        title={trace.title}
        headline={trace.headline}
        score={trace.score}
        onSelectDriver={(id) => {
          setSelectedDriverId(id)
          setSelectedKey(null)
        }}
      />
    )
  }

  const overallScore = selectedDriver
    ? Number(selectedDriver.score || 0)
    : Number(trace.score || 0)

  return (
    <>
      <div className="dlv-perf-summary">
        <div className="dlv-perf-summary__text">
          {selectedDriver ? (
            <button
              type="button"
              className="dlv-perf-back"
              onClick={() => {
                setSelectedDriverId(null)
                setSelectedKey(null)
              }}
            >
              &larr; All drivers
            </button>
          ) : null}
          <div className="dlv-perf-summary__title-row">
            <span className="dlv-perf-summary__icon" aria-hidden="true">
              <Gauge size={18} />
            </span>
            <div>
              <h3 className="dlv-perf-summary__title">
                {selectedDriver ? cellText(selectedDriver.name) : 'Driver performance'}
              </h3>
              <p className="dlv-perf-summary__sub">
                Overall performance based on completed deliveries, vehicle care, documentation and on-time delivery.
              </p>
            </div>
          </div>
        </div>
        <div className="dlv-perf-summary__score" aria-label={`Overall score ${overallScore.toFixed(1)} percent`}>
          <ScoreRing value={overallScore} />
          <div className="dlv-perf-summary__score-copy">
            <strong>Overall Score</strong>
            <span>Driver performance</span>
          </div>
        </div>
      </div>

      {activeMetrics.length > 0 ? (
        <section className="dlv-trace-section dlv-trace-section--flat dlv-perf-breakdown">
          <div className="dlv-perf-breakdown-head">
            <h3 className="dlv-trace-section-title">
              <BarChart3 size={16} aria-hidden="true" />
              Score Breakdown
            </h3>
            {activeCalculation.length > 0 ? (
              <div className="dlv-perf-about">
                <button
                  type="button"
                  className={`dlv-perf-about__btn${howOpen ? ' is-open' : ''}`}
                  aria-expanded={howOpen}
                  aria-controls="dlv-perf-how-panel"
                  title="How it was obtained"
                  onClick={() => setHowOpen((prev) => !prev)}
                >
                  <CircleHelp size={16} aria-hidden="true" />
                  <span className="dlv-perf-about__label">About</span>
                </button>
                {howOpen ? (
                  <div id="dlv-perf-how-panel" className="dlv-perf-about__panel" role="region" aria-label="How it was obtained">
                    <strong className="dlv-perf-about__title">How it was obtained</strong>
                    <ol className="dlv-perf-calc">
                      {activeCalculation.map((line, index) => (
                        <li key={`calc-${index}`}>{line}</li>
                      ))}
                    </ol>
                  </div>
                ) : null}
              </div>
            ) : null}
          </div>
          <p className="dlv-perf-hint">Click a metric to drop down the related deliveries or tasks.</p>
          <div className="dlv-perf-metrics">
            {activeMetrics.map((metric) => {
              const key = metric.key || metric.label
              const selected = selectedKey === key
              const tasks = Array.isArray(metric.tasks) ? metric.tasks : []
              const isOnTime = key === 'on_time'
              const tone = metricTone(key)
              const actual = Math.min(100, Math.max(0, Number(metric.actual || 0)))
              return (
                <div key={key} className={`dlv-perf-metric-block${selected ? ' is-open' : ''}`}>
                  <button
                    type="button"
                    className={`dlv-perf-metric dlv-perf-metric--${tone}${selected ? ' is-selected' : ''}`}
                    aria-expanded={selected}
                    aria-pressed={selected}
                    onClick={() => setSelectedKey((prev) => (prev === key ? null : key))}
                  >
                    <div className="dlv-perf-metric__head">
                      <span className={`dlv-perf-metric__icon dlv-perf-metric__icon--${tone}`}>
                        <MetricIcon toneKey={key} />
                      </span>
                      <strong>{cellText(metric.label)}</strong>
                      <span className="dlv-perf-metric__pct">{actual.toFixed(1)}%</span>
                    </div>
                    <p className="dlv-perf-metric__desc">{cellText(metric.description)}</p>
                    <div className="dlv-perf-metric__bar" aria-hidden="true">
                      <span style={{ width: `${actual}%` }} />
                    </div>
                  </button>

                  {selected ? (
                    <div className="dlv-perf-dropdown" aria-live="polite">
                      <div className="dlv-perf-dropdown__head">
                        <span>
                          {isOnTime
                            ? 'Deliveries'
                            : cellText(metric.tasksTitle || `${metric.label} tasks`)}
                        </span>
                        <span className="dlv-perf-dropdown__count">{tasks.length}</span>
                      </div>
                      {tasks.length === 0 ? (
                        <p className="dlv-trace-empty">
                          {cellText(
                            metric.tasksEmpty
                              || (isOnTime
                                ? 'No completed deliveries for this driver this week.'
                                : 'No tasks contributed to this score yet.'),
                          )}
                        </p>
                      ) : (
                        <ul className="dlv-perf-dropdown__list">
                          {tasks.map((task) => (
                            <li
                              key={task.id || `${task.title}-${task.at}`}
                              className={`dlv-perf-dropdown__item${task.ok === false ? ' is-miss' : ' is-ok'}`}
                            >
                              <div className="dlv-perf-dropdown__row">
                                <strong>{cellText(task.title)}</strong>
                                {task.at ? (
                                  <span className="dlv-trace-muted">{formatDate(task.at)}</span>
                                ) : null}
                              </div>
                              <p className="dlv-perf-dropdown__detail">{cellText(task.detail)}</p>
                              {task.result ? (
                                <span className="dlv-perf-dropdown__result">{cellText(task.result)}</span>
                              ) : null}
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                  ) : null}
                </div>
              )
            })}
          </div>
        </section>
      ) : null}

      {activeSuggestions.length > 0 ? (
        <section className="dlv-trace-section dlv-trace-section--flat dlv-perf-tips">
          <div className="dlv-perf-breakdown-head">
            <h3 className="dlv-trace-section-title">
              <Sparkles size={16} aria-hidden="true" />
              AI suggestions
            </h3>
          </div>
          <div className="dlv-perf-tip">
            <div className="dlv-perf-tip__head">
              <span className="dlv-perf-tip__icon" aria-hidden="true">
                <Sparkles size={14} />
              </span>
              <strong>Improve score</strong>
            </div>
            <ul className="dlv-perf-tip__list">
              {activeSuggestions.map((tip, index) => (
                <li key={`tip-${index}`}>{tip}</li>
              ))}
            </ul>
          </div>
        </section>
      ) : null}
    </>
  )
}

function renderItemsTable(trace, items, itemsHeading, emptyLabel, grading = {}) {
  const modalType = trace.modalType || 'deliveries'
  const gradesById = grading.gradesById || {}
  const gradesLoading = Boolean(grading.gradesLoading)

  if (modalType === 'performance') {
    return (
      <PerformanceBreakdown
        trace={trace}
      />
    )
  }

  if (modalType === 'notes') {
    return (
      <section className="dlv-trace-section">
        <h3 className="dlv-trace-section-title">{itemsHeading}</h3>
        {items.length === 0 ? (
          <p className="dlv-trace-empty">{emptyLabel}</p>
        ) : (
          <div className="dlv-trace-table-wrap">
            <table className="dlv-trace-table">
              <thead>
                <tr>
                  <th>Note #</th>
                  <th>Customer</th>
                  <th>Destination</th>
                  <th>Created By</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={`${item.id}-${item.deliveryNumber}`}>
                    <td>
                      <div className="dlv-trace-delivery-cell">
                        <span className="dlv-trace-delivery-no">{cellText(item.deliveryNumber)}</span>
                      </div>
                    </td>
                    <td>
                      <div>{cellText(item.clientName)}</div>
                      {item.clientPhone ? (
                        <small className="dlv-trace-muted">{item.clientPhone}</small>
                      ) : null}
                    </td>
                    <td>{cellText(item.destination)}</td>
                    <td>{cellText(item.creatorName)}</td>
                    <td className="dlv-trace-muted">{formatDate(item.createdAt)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {trace.footnote ? <p className="dlv-trace-footnote">{trace.footnote}</p> : null}
      </section>
    )
  }

  if (modalType === 'reviews') {
    return (
      <section className="dlv-trace-section">
        <div className="dlv-trace-grade-head">
          <h3 className="dlv-trace-section-title">{itemsHeading}</h3>
          <span className="dlv-trace-ai-badge">
            <Sparkles size={11} aria-hidden="true" />
            AI grading
          </span>
        </div>
        {grading.gradesNote ? (
          <p className="dlv-trace-grade-note" role="status">{grading.gradesNote}</p>
        ) : null}
        {items.length === 0 ? (
          <p className="dlv-trace-empty">{emptyLabel}</p>
        ) : (
          <div className="dlv-trace-table-wrap">
            <table className="dlv-trace-table">
              <thead>
                <tr>
                  <th>Client</th>
                  <th>Feedback</th>
                  <th>AI grade</th>
                  <th>Rating</th>
                  <th>Order</th>
                  <th>Driver</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={`${item.id}-${item.orderRef}`}>
                    <td>
                      <div>{cellText(item.clientName)}</div>
                      {item.clientPhone ? (
                        <small className="dlv-trace-muted">{item.clientPhone}</small>
                      ) : null}
                    </td>
                    <td className="dlv-trace-feedback">{cellText(item.feedback)}</td>
                    <td>
                      <FeedbackGradeBadge
                        grade={gradesById[item.id]}
                        loading={gradesLoading && !gradesById[item.id]}
                        compact
                      />
                    </td>
                    <td>{cellText(item.rating)} / 5</td>
                    <td>{cellText(item.orderRef)}</td>
                    <td>{cellText(item.driverName)}</td>
                    <td className="dlv-trace-muted">{formatDate(item.completionTime)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {trace.footnote ? <p className="dlv-trace-footnote">{trace.footnote}</p> : null}
      </section>
    )
  }

  if (modalType === 'customers') {
    return (
      <section className="dlv-trace-section">
        <h3 className="dlv-trace-section-title">{itemsHeading}</h3>
        {items.length === 0 ? (
          <p className="dlv-trace-empty">{emptyLabel}</p>
        ) : (
          <div className="dlv-trace-table-wrap">
            <table className="dlv-trace-table">
              <thead>
                <tr>
                  <th>Customer</th>
                  <th>Notes</th>
                  <th>Destination</th>
                  <th>Phone</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={`${item.id}-${item.clientName}`}>
                    <td>{cellText(item.clientName)}</td>
                    <td>{cellText(item.status)}</td>
                    <td>{cellText(item.destination)}</td>
                    <td className="dlv-trace-muted">{cellText(item.clientPhone)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {trace.footnote ? <p className="dlv-trace-footnote">{trace.footnote}</p> : null}
      </section>
    )
  }

  return (
    <section className="dlv-trace-section">
      <h3 className="dlv-trace-section-title">{itemsHeading}</h3>
      {items.length === 0 ? (
        <p className="dlv-trace-empty">{emptyLabel}</p>
      ) : (
        <div className="dlv-trace-table-wrap">
          <table className="dlv-trace-table">
            <thead>
              <tr>
                <th>Delivery</th>
                <th>Client</th>
                <th>Destination</th>
                <th>Status</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={`${item.id}-${item.deliveryNumber}`}>
                  <td>
                    <div className="dlv-trace-delivery-cell">
                      <span className="dlv-trace-delivery-no">{cellText(item.deliveryNumber)}</span>
                      {item.createdAt ? (
                        <span className="dlv-trace-delivery-date">{formatDate(item.createdAt)}</span>
                      ) : null}
                    </div>
                  </td>
                  <td>
                    <div>{cellText(item.clientName)}</div>
                    {item.clientPhone ? (
                      <small className="dlv-trace-muted">{item.clientPhone}</small>
                    ) : null}
                  </td>
                  <td>{cellText(item.destination)}</td>
                  <td>{formatStatus(item.status)}</td>
                  <td className="dlv-trace-muted">{formatDate(item.createdAt)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {trace.footnote ? <p className="dlv-trace-footnote">{trace.footnote}</p> : null}
    </section>
  )
}

export default function DeliveryKpiTraceModal({ trace, traceKey = '', onClose, enableAssistant = true }) {
  const comingSoon = Boolean(trace.comingSoon)
  const items = Array.isArray(trace.items) ? trace.items : []
  const assistantEnabled = enableAssistant && Boolean(traceKey) && Boolean(resolveKpiAiAssistUrl())
  const itemsHeading = trace.itemsTitle
    ? `${trace.itemsTitle} (${items.length})`
    : `Contributing deliveries (${items.length})`
  const emptyLabel = trace.modalType === 'reviews'
    ? 'No reviews contributed to this KPI.'
    : trace.modalType === 'customers'
    ? 'No customers contributed to this KPI.'
    : trace.modalType === 'performance'
    ? 'No completed deliveries contributed to this score yet.'
    : trace.itemsTitle?.toLowerCase().includes('trip')
      ? 'No trips contributed to this KPI.'
      : trace.itemsTitle?.toLowerCase().includes('note')
        ? 'No notes contributed to this KPI.'
        : 'No deliveries contributed to this KPI.'

  const [chatInput, setChatInput] = useState('')
  const [chatLoading, setChatLoading] = useState(false)
  const [chatError, setChatError] = useState('')
  const [chatMessages, setChatMessages] = useState([])
  const [gradesById, setGradesById] = useState({})
  const [gradesLoading, setGradesLoading] = useState(false)
  const [gradesNote, setGradesNote] = useState('')
  const chatEndRef = useRef(null)
  const isReviewsTrace = trace.modalType === 'reviews'
  const isPerformanceTrace = trace.modalType === 'performance'
  const performanceDrivers = Array.isArray(trace.drivers) ? trace.drivers : []
  const showPerformanceBoardOnly = isPerformanceTrace && performanceDrivers.length > 0
  const showAssistant = assistantEnabled && !showPerformanceBoardOnly

  useEffect(() => {
    setChatInput('')
    setChatLoading(false)
    setChatError('')
    setChatMessages([])
    setGradesById({})
    setGradesNote('')
    setGradesLoading(false)
  }, [trace])

  useEffect(() => {
    if (!isReviewsTrace || items.length === 0) {
      return undefined
    }

    let alive = true
    setGradesLoading(true)
    ;(async () => {
      try {
        const result = await fetchFeedbackGrades(items.map((row) => ({
          id: row.id,
          feedback: row.feedback || '',
          rating: row.rating || 0,
        })))
        if (!alive) return
        setGradesById(result.gradesById)
        setGradesNote(result.note || (result.viaAi ? 'AI marks based on feedback quality and sentiment.' : ''))
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
  }, [isReviewsTrace, items])

  useEffect(() => {
    chatEndRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [chatMessages, chatLoading])

  useEffect(() => {
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    function handleKeyDown(event) {
      if (event.key === 'Escape') onClose()
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => {
      document.body.style.overflow = previousOverflow
      window.removeEventListener('keydown', handleKeyDown)
    }
  }, [onClose])

  async function handleChatSubmit(event) {
    event.preventDefault()
    const question = chatInput.trim()
    if (!question || chatLoading || !showAssistant) return

    const nextMessages = [...chatMessages, { role: 'user', content: question }]
    setChatMessages(nextMessages)
    setChatInput('')
    setChatError('')
    setChatLoading(true)

    try {
      const result = await sendKpiChatMessage(traceKey, trace, question, chatMessages)
      setChatMessages([...nextMessages, { role: 'assistant', content: result.reply || 'No response.' }])
    } catch (err) {
      setChatError(err instanceof Error ? err.message : 'Could not reach AI assistant.')
    } finally {
      setChatLoading(false)
    }
  }

  return createPortal(
    <div className="dlv-trace-backdrop" onClick={onClose} role="presentation">
      <div
        className={`dlv-trace-modal${isPerformanceTrace ? ' dlv-trace-modal--performance' : ''}`}
        role="dialog"
        aria-modal="true"
        aria-labelledby="dlv-kpi-trace-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className={`dlv-trace-head${isPerformanceTrace ? ' dlv-trace-head--performance' : ''}`}>
          {isPerformanceTrace ? (
            <div className="dlv-trace-head-text dlv-trace-head-text--sr">
              <h2 id="dlv-kpi-trace-title" className="dlv-trace-title">{trace.title || 'Driver performance'}</h2>
            </div>
          ) : (
            <div className="dlv-trace-head-text">
              <h2 id="dlv-kpi-trace-title" className="dlv-trace-title">{trace.title}</h2>
              <p className="dlv-trace-headline">{trace.headline}</p>
            </div>
          )}
          <button type="button" className="dlv-trace-close" onClick={onClose} aria-label="Close">
            <X size={20} aria-hidden="true" />
          </button>
        </div>

        <div className={`dlv-trace-body${isPerformanceTrace ? ' dlv-trace-body--performance' : ''}`}>
          {comingSoon ? (
            <div className="dlv-trace-coming-soon">
              <div className="dlv-trace-soon-hero" aria-hidden="true">
                <span className="dlv-trace-soon-orbit dlv-trace-soon-orbit--1" />
                <span className="dlv-trace-soon-orbit dlv-trace-soon-orbit--2" />
                <span className="dlv-trace-soon-orbit dlv-trace-soon-orbit--3" />
                <span className="dlv-trace-soon-ring dlv-trace-soon-ring--1" />
                <span className="dlv-trace-soon-ring dlv-trace-soon-ring--2" />
                <span className="dlv-trace-soon-icon-wrap">
                  <AlertTriangle className="dlv-trace-soon-icon" size={38} aria-hidden="true" />
                </span>
              </div>

              <h3 className="dlv-trace-soon-title">
                Coming soon
                <span className="dlv-trace-soon-dots" aria-hidden="true">
                  <span>.</span>
                  <span>.</span>
                  <span>.</span>
                </span>
              </h3>

              <p className="dlv-trace-soon-sub">
                Exception tracking details are on the way. You will be able to review failed and returned deliveries here.
              </p>

              <div className="dlv-trace-soon-progress" aria-hidden="true">
                <span className="dlv-trace-soon-progress-bar" />
              </div>
            </div>
          ) : (
            <>
              {showAssistant ? (
                <section className="dlv-trace-section dlv-trace-section--chat">
                  <div className="dlv-trace-chat-head">
                    <h3 className="dlv-trace-section-title">Ask assistant</h3>
                    <span className="dlv-trace-ai-badge">
                      <Sparkles size={11} aria-hidden="true" />
                      AI help
                    </span>
                  </div>
                  <div className="dlv-trace-chat-thread" aria-live="polite">
                    {chatMessages.length === 0 ? (
                      <p className="dlv-trace-chat-empty">
                        Ask about this KPI, e.g. &quot;Why is this count lower than last month?&quot;
                      </p>
                    ) : (
                      chatMessages.map((message, index) => (
                        <div
                          key={`${message.role}-${index}`}
                          className={`dlv-trace-chat-bubble dlv-trace-chat-bubble--${message.role}`}
                        >
                          {message.content}
                        </div>
                      ))
                    )}
                    {chatLoading ? (
                      <div className="dlv-trace-chat-bubble dlv-trace-chat-bubble--assistant dlv-trace-chat-bubble--loading">
                        <Loader2 size={14} className="dlv-spin" aria-hidden="true" />
                        Thinking...
                      </div>
                    ) : null}
                    <div ref={chatEndRef} />
                  </div>
                  {chatError ? <p className="dlv-trace-ai-error" role="alert">{chatError}</p> : null}
                  <form className="dlv-trace-chat-form" onSubmit={handleChatSubmit}>
                    <input
                      type="text"
                      className="dlv-trace-chat-input"
                      value={chatInput}
                      onChange={(e) => setChatInput(e.target.value)}
                      placeholder="Ask about this KPI..."
                      disabled={chatLoading}
                      aria-label="Ask AI about this KPI"
                    />
                    <button
                      type="submit"
                      className="dlv-trace-chat-send"
                      disabled={chatLoading || chatInput.trim() === ''}
                      aria-label="Send question"
                    >
                      <Send size={15} aria-hidden="true" />
                    </button>
                  </form>
                </section>
              ) : null}

              {renderItemsTable(trace, items, itemsHeading, emptyLabel, {
                gradesById,
                gradesLoading,
                gradesNote,
              })}
            </>
          )}
        </div>
      </div>
    </div>,
    document.body,
  )
}
