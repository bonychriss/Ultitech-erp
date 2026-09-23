import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { AlertTriangle, Loader2, Send, Sparkles, X } from 'lucide-react'
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

function DriverPerformanceBoard({ drivers, footnote, onSelectDriver }) {
  return (
    <section className="dlv-perf-board">
      <header className="dlv-perf-board__head">
        <h3 className="dlv-perf-board__title">Drivers this week</h3>
        <p className="dlv-perf-board__hint">All drivers listed. Click one to open their score breakdown.</p>
      </header>

      {drivers.length === 0 ? (
        <p className="dlv-trace-empty">No drivers with scored activity this week.</p>
      ) : (
        <ul className="dlv-perf-board__list" role="list">
          {drivers.map((driver, index) => {
            const score = Number(driver.score || 0)
            const tone = score >= 85 ? 'green' : (score >= 70 ? 'amber' : 'red')
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
                    <span className="dlv-perf-driver__meta">
                      On-time {Number(driver.on_time_pct || 0).toFixed(0)}%
                      {' | '}
                      Vehicle {Number(driver.vehicle_care_pct || 0).toFixed(0)}%
                      {' | '}
                      Docs {Number(driver.documentation_pct || 0).toFixed(0)}%
                      {' | '}
                      {Number(driver.completed || 0)} completed
                    </span>
                  </span>
                  <span className="dlv-perf-driver__score">{score.toFixed(1)}%</span>
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

function PerformanceBreakdown({ trace, items, itemsHeading, emptyLabel }) {
  const metrics = Array.isArray(trace.metrics) ? trace.metrics : []
  const calculation = Array.isArray(trace.calculation) ? trace.calculation : []
  const suggestions = Array.isArray(trace.suggestions) ? trace.suggestions : []
  const drivers = Array.isArray(trace.drivers) ? trace.drivers : []
  const [selectedKey, setSelectedKey] = useState(null)
  const [selectedDriverId, setSelectedDriverId] = useState(null)

  useEffect(() => {
    setSelectedKey(null)
    setSelectedDriverId(null)
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
  const activeItems = selectedDriver
    ? (Array.isArray(selectedDriver.items) ? selectedDriver.items : [])
    : items

  const selectedMetric = activeMetrics.find((metric) => (metric.key || metric.label) === selectedKey) || null
  const metricTasks = Array.isArray(selectedMetric?.tasks) ? selectedMetric.tasks : []

  if (showDriverBoard) {
    return (
      <DriverPerformanceBoard
        drivers={drivers}
        footnote={trace.footnote}
        onSelectDriver={(id) => {
          setSelectedDriverId(id)
          setSelectedKey(null)
        }}
      />
    )
  }

  return (
    <>
      {selectedDriver ? (
        <div className="dlv-perf-driver-nav">
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
          <div className="dlv-perf-driver-nav__title">
            <strong>{cellText(selectedDriver.name)}</strong>
            <span>{Number(selectedDriver.score || 0).toFixed(1)}%</span>
          </div>
        </div>
      ) : null}

      {activeMetrics.length > 0 ? (
        <section className="dlv-trace-section">
          <h3 className="dlv-trace-section-title">Score breakdown</h3>
          <p className="dlv-perf-hint">Click a card to see the tasks that built that score.</p>
          <div className="dlv-perf-metrics">
            {activeMetrics.map((metric) => {
              const key = metric.key || metric.label
              const selected = selectedKey === key
              return (
                <button
                  key={key}
                  type="button"
                  className={`dlv-perf-metric${selected ? ' is-selected' : ''}`}
                  aria-pressed={selected}
                  onClick={() => setSelectedKey((prev) => (prev === key ? null : key))}
                >
                  <div className="dlv-perf-metric__head">
                    <strong>{cellText(metric.label)}</strong>
                    <span>{Number(metric.actual || 0).toFixed(1)}%</span>
                  </div>
                  <p className="dlv-perf-metric__desc">{cellText(metric.description)}</p>
                  <div className="dlv-perf-metric__meta">
                    <span>Target {Number(metric.target || 0).toFixed(0)}%</span>
                    <span>Weight {Number(metric.weight || 0).toFixed(0)}%</span>
                    <span>Achievement {Number(metric.achievement || 0).toFixed(1)}%</span>
                  </div>
                  <div className="dlv-perf-metric__bar" aria-hidden="true">
                    <span style={{ width: `${Math.min(100, Math.max(0, Number(metric.actual || 0)))}%` }} />
                  </div>
                </button>
              )
            })}
          </div>

          {selectedMetric ? (
            <div className="dlv-perf-tasks" aria-live="polite">
              <div className="dlv-perf-tasks__head">
                <h4 className="dlv-perf-tasks__title">
                  {cellText(selectedMetric.tasksTitle || `${selectedMetric.label} tasks`)}
                </h4>
                <span className="dlv-perf-tasks__count">{metricTasks.length}</span>
              </div>
              {metricTasks.length === 0 ? (
                <p className="dlv-trace-empty">
                  {cellText(selectedMetric.tasksEmpty || 'No tasks contributed to this score yet.')}
                </p>
              ) : (
                <ol className="dlv-perf-task-list">
                  {metricTasks.map((task) => (
                    <li
                      key={task.id || `${task.title}-${task.at}`}
                      className={`dlv-perf-task${task.ok === false ? ' is-miss' : ' is-ok'}`}
                    >
                      <div className="dlv-perf-task__top">
                        <strong>{cellText(task.title)}</strong>
                        {task.at ? (
                          <span className="dlv-trace-muted">{formatDate(task.at)}</span>
                        ) : null}
                      </div>
                      <p className="dlv-perf-task__detail">{cellText(task.detail)}</p>
                      <span className="dlv-perf-task__result">{cellText(task.result)}</span>
                    </li>
                  ))}
                </ol>
              )}
            </div>
          ) : null}
        </section>
      ) : null}

      {activeCalculation.length > 0 ? (
        <section className="dlv-trace-section">
          <h3 className="dlv-trace-section-title">How it was obtained</h3>
          <ol className="dlv-perf-calc">
            {activeCalculation.map((line, index) => (
              <li key={`calc-${index}`}>{line}</li>
            ))}
          </ol>
        </section>
      ) : null}

      {activeSuggestions.length > 0 ? (
        <section className="dlv-trace-section">
          <div className="dlv-trace-grade-head">
            <h3 className="dlv-trace-section-title">AI suggestions</h3>
            <span className="dlv-trace-ai-badge">
              <Sparkles size={11} aria-hidden="true" />
              Improve score
            </span>
          </div>
          <ul className="dlv-perf-suggestions">
            {activeSuggestions.map((tip, index) => (
              <li key={`tip-${index}`}>{tip}</li>
            ))}
          </ul>
        </section>
      ) : null}

      {!selectedMetric ? (
        <section className="dlv-trace-section">
          <h3 className="dlv-trace-section-title">
            {selectedDriver
              ? `Completed deliveries (${activeItems.length})`
              : (itemsHeading || 'Completed deliveries this week')}
          </h3>
          {activeItems.length === 0 ? (
            <p className="dlv-trace-empty">{emptyLabel || 'No completed deliveries in this week yet.'}</p>
          ) : (
            <div className="dlv-trace-table-wrap">
              <table className="dlv-trace-table">
                <thead>
                  <tr>
                    <th>Delivery</th>
                    <th>Client</th>
                    <th>Timing</th>
                    <th>Signed</th>
                    <th>Rating</th>
                    <th>Date</th>
                  </tr>
                </thead>
                <tbody>
                  {activeItems.map((item) => (
                    <tr key={`${item.id}-${item.deliveryNumber}`}>
                      <td>
                        <span className="dlv-trace-delivery-no">{cellText(item.deliveryNumber)}</span>
                      </td>
                      <td>
                        <div>{cellText(item.clientName)}</div>
                        {item.clientPhone ? (
                          <small className="dlv-trace-muted">{item.clientPhone}</small>
                        ) : null}
                      </td>
                      <td>{cellText(item.status)}</td>
                      <td>{item.signed ? 'Yes' : 'No'}</td>
                      <td>{item.rating ? `${item.rating}/5` : '-'}</td>
                      <td className="dlv-trace-muted">{formatDate(item.createdAt)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {trace.footnote ? <p className="dlv-trace-footnote">{trace.footnote}</p> : null}
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
        items={items}
        itemsHeading={itemsHeading}
        emptyLabel={emptyLabel}
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
        <div className="dlv-trace-head">
          <div className="dlv-trace-head-text">
            <h2 id="dlv-kpi-trace-title" className="dlv-trace-title">{trace.title}</h2>
            <p className="dlv-trace-headline">{trace.headline}</p>
          </div>
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
