import { useEffect, useRef, useState } from 'react'

function cfg() {
  return window.__AI_ASSISTANT__ || {}
}

function storageKey() {
  const data = cfg()
  return 'ultitech-ai-assistant-' + (data.module || 'general')
}

function startersFor(module) {
  if (module === 'petty_cash') {
    return ['Open the cash book', 'What needs attention today?', 'Which invoices are overdue?']
  }
  return ['What needs attention today?', 'Which invoices are overdue?', 'Create an invoice']
}

function speakReply(text) {
  const value = String(text || '').trim()
  if (!value || !window.speechSynthesis) return
  window.speechSynthesis.cancel()
  const utterance = new SpeechSynthesisUtterance(value)
  utterance.lang = 'en-US'
  utterance.rate = 1
  window.speechSynthesis.speak(utterance)
}

async function postAsk(message) {
  const data = cfg()
  const body = new URLSearchParams()
  body.set('action', 'ask')
  body.set('csrf', data.csrf || '')
  body.set('message', message)
  const res = await fetch(data.apiUrl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString(),
  })
  return res.json()
}

function AssistantBody({ reply }) {
  if (!reply) return null
  if (reply.pending) {
    return (
      <div className="gpt-dots" aria-label="Working">
        <span /><span /><span />
      </div>
    )
  }
  const links = []
  ;(reply.actions || []).forEach((action) => {
    if (action && action.url) links.push({ href: action.url, label: action.label || 'Open' })
  })
  ;(reply.invoices || []).forEach((invoice) => {
    if (invoice && invoice.view_url) {
      links.push({ href: invoice.view_url, label: 'View ' + (invoice.invoice_number || 'invoice') })
    }
  })
  return (
    <>
      {reply.text ? <p>{reply.text}</p> : null}
      {reply.facts ? <p>{reply.facts}</p> : null}
      {links.length ? (
        <div className="gpt-links">
          {links.map((link) => <a key={link.href + link.label} href={link.href}>{link.label}</a>)}
        </div>
      ) : null}
    </>
  )
}

export default function App() {
  const data = cfg()
  const rawName = String(data.firstName || '').trim()
  const name = /^(system|admin|user|there)$/i.test(rawName) ? '' : rawName
  const [thread, setThread] = useState(() => {
    try {
      const saved = JSON.parse(sessionStorage.getItem(storageKey()) || '[]')
      return Array.isArray(saved) ? saved.filter((item) => item && !item.pending) : []
    } catch {
      return []
    }
  })
  const [message, setMessage] = useState('')
  const [listening, setListening] = useState(false)
  const [busy, setBusy] = useState(false)
  const scroller = useRef(null)
  const box = useRef(null)

  useEffect(() => {
    const node = scroller.current
    if (node) node.scrollTop = node.scrollHeight
    const keep = thread.filter((item) => !item.pending)
    sessionStorage.setItem(storageKey(), JSON.stringify(keep))
  }, [thread])

  const resize = () => {
    const node = box.current
    if (!node) return
    node.style.height = 'auto'
    node.style.height = Math.min(node.scrollHeight, 180) + 'px'
  }

  const ask = (text, voiced) => {
    const value = String(text || '').trim()
    if (!value || busy) return
    setBusy(true)
    setMessage('')
    if (box.current) box.current.style.height = 'auto'
    setThread((prev) => [
      ...prev.filter((item) => !item.pending),
      { role: 'user', text: value },
      { role: 'agent', pending: true },
    ])
    postAsk(value)
      .then((result) => {
        const reply = !result || !result.ok
          ? { text: (result && result.error) || 'I could not read ERP data for this company.' }
          : (result.reply || { text: 'I could not read ERP data for this company.' })
        setThread((prev) => {
          const next = prev.filter((item) => !item.pending)
          next.push({ role: 'agent', reply })
          return next
        })
        if (voiced) speakReply(reply.text)
        const nextUrl = String(reply.navigate || '')
        if (nextUrl.startsWith('/') && !nextUrl.startsWith('//')) {
          window.location.assign(nextUrl)
        }
      })
      .catch(() => {
        setThread((prev) => {
          const next = prev.filter((item) => !item.pending)
          next.push({ role: 'agent', reply: { text: 'I could not read ERP data for this company.' } })
          return next
        })
      })
      .finally(() => setBusy(false))
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
        ask(said, true)
      }
    }
    recognition.start()
  }

  const empty = thread.length === 0

  return (
    <div className="gpt-app">
      <div className="gpt-scroll" ref={scroller}>
        <div className="gpt-column">
          {!empty ? (
            <div className="gpt-toolbar">
              <button
                type="button"
                className="gpt-new"
                onClick={() => {
                  sessionStorage.removeItem(storageKey())
                  setThread([])
                }}
              >
                New chat
              </button>
            </div>
          ) : null}
          {empty ? (
            <div className="gpt-empty">
              <h1>How can I help you today{name ? `, ${name}` : ''}?</h1>
              <div className="gpt-starters">
                {startersFor(data.module).map((prompt) => (
                  <button key={prompt} type="button" onClick={() => ask(prompt, false)}>{prompt}</button>
                ))}
              </div>
            </div>
          ) : (
            <div className="gpt-thread">
              {thread.map((item, index) => (
                item.role === 'user' ? (
                  <div className="gpt-row user" key={index}>
                    <div className="gpt-user">{item.text}</div>
                  </div>
                ) : (
                  <div className="gpt-row" key={index}>
                    <div className="gpt-assistant">
                      <div className="gpt-mark" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                          <path d="M12 2l1.6 6.2L20 10l-6.4 1.8L12 18l-1.6-6.2L4 10l6.4-1.8L12 2z" />
                        </svg>
                      </div>
                      <div className="gpt-body">
                        <AssistantBody reply={item.pending ? { pending: true } : item.reply} />
                      </div>
                    </div>
                  </div>
                )
              ))}
            </div>
          )}
        </div>
      </div>
      <div className="gpt-compose-wrap">
        <form
          className="gpt-compose"
          onSubmit={(event) => {
            event.preventDefault()
            ask(message, false)
          }}
        >
          <label className="visually-hidden" htmlFor="ai-message" style={{ position: 'absolute', width: 1, height: 1, overflow: 'hidden' }}>
            Message
          </label>
          <textarea
            id="ai-message"
            ref={box}
            rows={1}
            maxLength={500}
            placeholder="Message"
            value={message}
            onChange={(event) => {
              setMessage(event.target.value)
              resize()
            }}
            onKeyDown={(event) => {
              if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault()
                ask(message, false)
              }
            }}
          />
          <button type="button" className={`gpt-icon${listening ? ' is-listening' : ''}`} onClick={listen} aria-label={listening ? 'Listening' : 'Listen'}>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
              <rect x="9" y="3" width="6" height="11" rx="3" />
              <path d="M5 11a7 7 0 0 0 14 0" />
              <path d="M12 18v3" />
            </svg>
          </button>
          <button type="submit" className="gpt-send" disabled={!message.trim() || busy} aria-label="Ask">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
              <path d="M12 19V5" />
              <path d="m5 12 7-7 7 7" />
            </svg>
          </button>
        </form>
      </div>
    </div>
  )
}
