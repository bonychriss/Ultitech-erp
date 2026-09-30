import { useEffect, useState } from 'react'

function cfg() {
  return window.__AI_AGENT__ || {}
}

function moneyText(value) {
  return value == null || value === '' ? '' : String(value)
}

function MetricIcon({ name }) {
  const common = {
    width: 16,
    height: 16,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.75,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
    'aria-hidden': true,
  }
  if (name === 'overdue') {
    return (
      <svg {...common}>
        <path d="M12 9v4" />
        <path d="M12 17h.01" />
        <path d="M10.3 4.3 2.8 17.2A2 2 0 0 0 4.5 20h15a2 2 0 0 0 1.7-2.8L13.7 4.3a2 2 0 0 0-3.4 0z" />
      </svg>
    )
  }
  if (name === 'due_soon') {
    return (
      <svg {...common}>
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7v5l3 2" />
      </svg>
    )
  }
  if (name === 'count') {
    return (
      <svg {...common}>
        <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
        <path d="M14 3v5h5" />
        <path d="M8 13h8" />
        <path d="M8 17h5" />
      </svg>
    )
  }
  return (
    <svg {...common}>
      <rect x="3" y="6" width="18" height="13" rx="2" />
      <path d="M3 10h18" />
      <path d="M7 15h4" />
    </svg>
  )
}

function parseSummary(text, analysis) {
  const sentences = String(text || '')
    .split(/(?<=\.)\s+/)
    .map((part) => part.trim())
    .filter(Boolean)
  const hello = []
  const items = []
  const notes = []
  const actions = []
  sentences.forEach((sentence) => {
    const line = sentence.replace(/\.$/, '').trim()
    const money = line.match(/\b[A-Z]{3}\s[\d,]+(?:\.\d+)?[KMB]?\b/)
    const leading = line.match(/^(\d[\d,]*)/)
    const days = line.match(/(\d[\d,]*)\s+days/i)
    if (/^good (morning|afternoon|evening)\b/i.test(line)) {
      hello.push(line)
      return
    }
    if (/^review\b|^follow up\b|need a collection follow-up|review that customer first/i.test(line)) {
      actions.push(line)
      return
    }
    if (/need attention/i.test(line)) {
      const count = line.match(/(\d[\d,]*)\s+items?/i)
      items.push({ key: 'attention', icon: 'attention', tone: 'info', label: 'Need attention', value: count ? count[1] : '', detail: 'Items today' })
      return
    }
    if (/invoice/i.test(line) && /overdue/i.test(line) && !/oldest/i.test(line)) {
      items.push({ key: 'overdue', icon: 'overdue', tone: 'urgent', label: 'Overdue invoices', value: leading ? leading[1] : '', detail: money ? money[0] : '' })
      return
    }
    if (/outstanding/i.test(line)) {
      items.push({ key: 'outstanding', icon: 'outstanding', tone: '', label: 'Outstanding', value: money ? money[0] : line, detail: '' })
      return
    }
    if (/voucher|approval/i.test(line)) {
      items.push({ key: 'approvals', icon: 'approvals', tone: 'attention', label: 'Waiting for approval', value: leading ? leading[1] : '', detail: 'Payment vouchers' })
      return
    }
    if (/minimum level|below the minimum|stock/i.test(line)) {
      items.push({ key: 'stock', icon: 'stock', tone: 'warn', label: 'Below minimum', value: leading ? leading[1] : '', detail: 'Products' })
      return
    }
    if (/purchase request/i.test(line)) {
      items.push({ key: 'procurement', icon: 'procurement', tone: 'info', label: 'Purchase requests', value: leading ? leading[1] : '', detail: days ? `Oldest ${days[1]} days` : 'Waiting' })
      return
    }
    if (/oldest overdue/i.test(line)) {
      items.push({ key: 'oldest', icon: 'overdue', tone: 'urgent', label: 'Oldest overdue', value: days ? `${days[1]} days` : line, detail: '' })
      return
    }
    notes.push(sentence)
  })
  if (analysis) actions.push(String(analysis).replace(/\.$/, ''))
  return { hello, items, notes, actions }
}

function summarySentence(summary) {
  const find = (key) => summary.items.find((item) => item.key === key)
  const attention = find('attention')
  const overdue = find('overdue')
  const approvals = find('approvals')
  const stock = find('stock')
  const procurement = find('procurement')
  const parts = []
  if (overdue && overdue.value) parts.push(overdue.value + ' overdue invoices' + (overdue.detail ? ' (' + overdue.detail + ')' : ''))
  if (approvals && approvals.value) parts.push(approvals.value + ' pending approvals')
  if (stock && stock.value) parts.push(stock.value + ' low-stock products')
  if (procurement && procurement.value) parts.push(procurement.value + ' pending purchase requests')
  let detail = ''
  if (parts.length === 1) detail = parts[0]
  else if (parts.length > 1) detail = parts.slice(0, -1).join(', ') + ', and ' + parts[parts.length - 1]
  const head = attention && attention.value ? attention.value + ' items need attention' : ''
  if (head && detail) return head + ': ' + detail + '.'
  if (head) return head + '.'
  if (detail) return detail + '.'
  const fallback = summary.notes[0] || summary.actions[0] || ''
  return fallback ? (fallback.endsWith('.') ? fallback : fallback + '.') : ''
}

function SummaryLayout({ text, analysis }) {
  const sentence = summarySentence(parseSummary(text, analysis))
  if (!sentence) return null
  return (
    <p className="ai-summary">
      <span className="ai-summary-icon" aria-hidden="true">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
          <circle cx="12" cy="12" r="9" />
          <path d="M12 8v4" />
          <path d="M12 16h.01" />
        </svg>
      </span>
      <span>{sentence}</span>
    </p>
  )
}

function speakReply(text) {
  const value = String(text || '').replace(/\s+/g, ' ').trim()
  if (!value || !window.speechSynthesis) return
  window.speechSynthesis.cancel()
  const utterance = new SpeechSynthesisUtterance(value)
  utterance.lang = 'en-US'
  utterance.rate = 1
  window.speechSynthesis.speak(utterance)
}

async function post(action, extra) {
  const data = cfg()
  const body = new URLSearchParams()
  body.set('action', action)
  body.set('csrf', data.csrf || '')
  Object.keys(extra || {}).forEach((key) => body.set(key, extra[key]))
  const res = await fetch(data.apiUrl, { method: 'POST', credentials: 'same-origin', body })
  return res.json()
}

function Reply({ reply }) {
  return (
    <div className="ai-bubble">
      <p>{reply.text || ''}</p>
      {reply.facts ? <p>{reply.facts}</p> : null}
      {reply.analysis ? <p className="ai-analysis">{reply.analysis}</p> : null}
      {(reply.customers || []).map((customer) => (
        <p key={customer.customer_id || customer.customer_name}>
          <strong>{customer.customer_name}</strong>
          {' | '}
          {customer.invoice_count} overdue
          {' | '}
          {customer.currency || 'TZS'} {Number(customer.overdue_amount || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}
          {customer.oldest_days ? ` | ${customer.oldest_days} days` : ''}
        </p>
      ))}
      {(reply.invoices || []).map((invoice) => (
        <p key={invoice.id || invoice.invoice_number}>
          <strong>{invoice.customer_name}</strong>
          <br />
          {invoice.invoice_number}
          <br />
          {invoice.amount || `${invoice.currency || 'TZS'} ${Number(invoice.balance_due || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`}
          {Number(invoice.days_overdue || 0) > 0 ? <><br />{invoice.days_overdue} days overdue</> : null}
          <br />
          <a className="ai-btn" href={invoice.view_url || '#'}>View invoice</a>
        </p>
      ))}
      {(reply.actions || []).map((action) => (
        action.url ? <p key={action.url}><a className="ai-btn" href={action.url}>{action.label || 'Open'}</a></p> : null
      ))}
      {reply.follow_up && reply.follow_up.draft ? (
        <>
          <p><strong>Follow-up suggestion</strong></p>
          <pre>{reply.follow_up.draft}</pre>
          {reply.follow_up.email_on_file ? <p>Email on the customer record: {reply.follow_up.email_on_file}</p> : null}
          <p className="ai-future">{reply.follow_up.future || ''}</p>
          <p>
            <button
              type="button"
              className="ai-btn ai-btn-ghost"
              onClick={(event) => {
                if (navigator.clipboard) {
                  navigator.clipboard.writeText(reply.follow_up.draft || '')
                  event.currentTarget.textContent = 'Copied'
                }
              }}
            >
              Copy draft
            </button>
          </p>
        </>
      ) : null}
    </div>
  )
}

export default function App() {
  const data = cfg()
  const [thread, setThread] = useState([])
  const [message, setMessage] = useState('')
  const [listening, setListening] = useState(false)
  const [facts, setFacts] = useState(data.facts || '')
  const [analysis, setAnalysis] = useState(data.analysis || '')
  const [briefingLead, setBriefingLead] = useState(`${data.greeting || ''}. ${data.attentionLabel || ''}`.trim())

  useEffect(() => {
    if (window.location.hash !== '#ai-receivables') {
      return
    }
    const node = document.getElementById('ai-receivables')
    if (node) {
      node.scrollIntoView({ block: 'start' })
    }
  }, [])

  useEffect(() => {
    let cancelled = false
    post('ace_briefing')
      .then((result) => {
        if (cancelled || !result || !result.ok || !result.text) {
          return
        }
        setFacts(result.text)
        setAnalysis('')
      })
      .catch(() => {})
    return () => {
      cancelled = true
    }
  }, [])

  const ask = (text) => {
    const value = String(text || '').trim()
    if (!value) return
    const pendingId = `pending-${Date.now()}`
    setThread((prev) => [
      ...prev,
      { role: 'user', text: value },
      { id: pendingId, role: 'agent', reply: { text: 'Working on that...' } },
    ])
    setMessage('')
    post('ask', { message: value })
      .then((result) => {
        const reply = !result || !result.ok
          ? { text: (result && result.error) || 'I could not read ERP data for this company.' }
          : (result.reply && (result.reply.text || result.reply.facts) ? result.reply : { text: 'I could not read ERP data for this company.' })
        setThread((prev) => prev.map((item) => (item.id === pendingId ? { ...item, reply } : item)))
        speakReply(reply.text)
        const nextUrl = String(reply.navigate || '')
        if (nextUrl.startsWith('/') && !nextUrl.startsWith('//')) {
          window.location.assign(nextUrl)
        }
      })
      .catch(() => {
        const text = 'I could not read ERP data for this company.'
        setThread((prev) => prev.map((item) => (item.id === pendingId ? { ...item, reply: { text } } : item)))
        speakReply(text)
      })
    document.getElementById('ai-chat')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
  }

  const listen = () => {
    const Speech = window.SpeechRecognition || window.webkitSpeechRecognition
    if (!Speech) {
      setThread((prev) => [...prev, { role: 'agent', reply: { text: 'This browser cannot listen. Type the command in the message box.' } }])
      return
    }
    const recognition = new Speech()
    recognition.lang = 'en-US'
    recognition.interimResults = false
    recognition.onstart = () => {
      window.speechSynthesis?.cancel()
      setListening(true)
    }
    recognition.onend = () => setListening(false)
    recognition.onerror = (event) => {
      setListening(false)
      if (event.error === 'not-allowed' || event.error === 'audio-capture' || event.error === 'no-speech') {
        setThread((prev) => [...prev, { role: 'agent', reply: { text: 'I could not hear a command. Try again, or type it.' } }])
      }
    }
    recognition.onresult = (event) => {
      const said = String(event.results?.[0]?.[0]?.transcript || '').trim()
      if (said) {
        setMessage(said)
        ask(said)
      }
    }
    recognition.start()
  }

  const followUp = (invoiceId) => {
    setThread((prev) => [...prev, { role: 'user', text: 'Prepare a follow-up for this invoice.' }])
    post('follow_up', { invoice_id: String(invoiceId) })
      .then((result) => {
        const reply = (result && result.reply) || { text: 'I could not prepare that follow-up.' }
        setThread((prev) => [...prev, { role: 'agent', reply }])
        speakReply(reply.text)
      })
      .catch(() => {
        const text = 'I could not prepare that follow-up.'
        setThread((prev) => [...prev, { role: 'agent', reply: { text } }])
        speakReply(text)
      })
    document.getElementById('ai-chat')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  return (
    <div className="ai-agent-page">
      <section className="ai-hero">
        <div className="ai-hero-mark" aria-hidden="true">AI</div>
        <div>
          <h2>{data.greeting || 'AI Agent'}</h2>
          <p>{data.loadError || data.attentionLabel}</p>
        </div>
      </section>

      {data.ok ? (
        <>
          <section className="ai-metrics" id="ai-receivables">
            {(data.metrics || []).map((metric) => (
              <article key={metric.key} className={`ai-metric${metric.tone ? ` ai-metric-${metric.tone}` : ''}`}>
                <span className="ai-metric-label">
                  <span className="ai-metric-icon"><MetricIcon name={metric.key} /></span>
                  {metric.label}
                </span>
                <strong>{moneyText(metric.value)}</strong>
                {metric.hint ? <small>{metric.hint}</small> : null}
              </article>
            ))}
          </section>

          {!data.summaryAvailable ? (
            <p className="ai-note">{data.summaryReason || 'Receivables are unavailable.'}</p>
          ) : (
            <section className="ai-card" id="ai-summary-card">
              <p className="ai-kicker">Agent receivable insights</p>
              <SummaryLayout text={facts} analysis={analysis} />
            </section>
          )}

          <section className="ai-card">
            <div className="ai-card-head">
              <h3>Overdue invoices</h3>
              <a href={data.urls?.invoices || '#'}>Open invoices</a>
            </div>
            {(data.overdue || []).length === 0 ? (
              <p className="ai-empty">No overdue customer invoices.</p>
            ) : (
              <ul className="ai-invoice-list">
                {data.overdue.map((invoice) => (
                  <li key={invoice.id}>
                    <div>
                      <strong>{invoice.customer_name}</strong>
                      <span>{invoice.invoice_number}</span>
                      <span>{invoice.amount}</span>
                      <span className="ai-overdue-days">{invoice.days_overdue} days overdue</span>
                    </div>
                    <div className="ai-row-actions">
                      <a className="ai-btn" href={invoice.view_url}>View invoice</a>
                      <button type="button" className="ai-btn ai-btn-ghost" onClick={() => followUp(invoice.id)}>Prepare follow-up</button>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </section>

          {data.summaryAvailable ? (
            <section className="ai-card">
              <div className="ai-card-head">
                <h3>Due soon</h3>
              </div>
              {(data.dueSoon || []).length === 0 ? (
                <p className="ai-empty">No invoices are due within the next {data.withinDays || 7} days.</p>
              ) : (
                <ul className="ai-invoice-list">
                  {data.dueSoon.map((invoice) => (
                    <li key={invoice.id}>
                      <div>
                        <strong>{invoice.customer_name}</strong>
                        <span>{invoice.invoice_number}</span>
                        <span>{invoice.amount}</span>
                        <span>Due {invoice.due_date}</span>
                      </div>
                      <div className="ai-row-actions">
                        <a className="ai-btn" href={invoice.view_url}>View invoice</a>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          ) : null}

          <section className="ai-card" id="ai-briefing">
            <p className="ai-kicker">Daily briefing</p>
            <p className="ai-date">{data.dateLabel}</p>
            <p className="ai-lead">{briefingLead}</p>
            <div className="ai-briefing">
              {(data.blocks || []).map((block) => (
                <article key={block.title} className={`ai-brief-item ai-tone-${block.tone}`}>
                  <h3>{block.title}</h3>
                  {(block.lines || []).map((line) => <p key={line}>{line}</p>)}
                  {block.show && block.url ? <a className="ai-btn" href={block.url}>{block.label}</a> : <p className="ai-future">Future integration until this data exists for the company.</p>}
                </article>
              ))}
              {data.anomaly ? (
                <article className="ai-brief-item ai-tone-info">
                  <h3>Invoice balances</h3>
                  <p>{data.anomaly.count} invoices have a balance that does not match the amount paid, or are marked paid while a balance remains.</p>
                  <a className="ai-btn" href={data.anomaly.url || '#'}>Review invoices</a>
                </article>
              ) : null}
            </div>
          </section>
        </>
      ) : null}

      <section className="ai-card ai-chat" id="ai-chat">
        <p className="ai-kicker">Ask the agent</p>
        <div className="ai-thread" aria-live="polite">
          {thread.map((item, index) => (
            item.role === 'user'
              ? <div key={index} className="ai-bubble ai-bubble-user"><p>{item.text}</p></div>
              : <Reply key={index} reply={item.reply || {}} />
          ))}
        </div>
        <form
          className="ai-ask"
          onSubmit={(event) => {
            event.preventDefault()
            ask(message)
          }}
        >
          <label className="visually-hidden" htmlFor="ai-message">Message</label>
          <input
            id="ai-message"
            type="text"
            maxLength={500}
            placeholder="Type or speak a command about invoices, balances, or today's briefing"
            autoComplete="off"
            value={message}
            onChange={(event) => setMessage(event.target.value)}
          />
          <button type="button" className={`ai-btn ai-btn-ghost${listening ? ' is-listening' : ''}`} onClick={listen}>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
              <rect x="9" y="3" width="6" height="11" rx="3" />
              <path d="M5 11a7 7 0 0 0 14 0" />
              <path d="M12 18v3" />
            </svg>
            {listening ? 'Listening' : 'Listen'}
          </button>
          <button type="submit" className="ai-btn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
              <path d="M22 2 11 13" />
              <path d="M22 2 15 22 11 13 2 9 22 2z" />
            </svg>
            Ask
          </button>
        </form>
      </section>
    </div>
  )
}
