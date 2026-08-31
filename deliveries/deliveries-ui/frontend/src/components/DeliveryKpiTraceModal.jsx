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

function renderItemsTable(trace, items, itemsHeading, emptyLabel, grading = {}) {
  const modalType = trace.modalType || 'deliveries'
  const gradesById = grading.gradesById || {}
  const gradesLoading = Boolean(grading.gradesLoading)

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
    if (!question || chatLoading || !assistantEnabled) return

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
        className="dlv-trace-modal"
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

        <div className="dlv-trace-body">
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
              {assistantEnabled ? (
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
