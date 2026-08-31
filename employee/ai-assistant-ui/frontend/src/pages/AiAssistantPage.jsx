import { useCallback, useEffect, useRef, useState } from 'react'
import {
  Sparkles, Send, Loader2, Plus, SlidersHorizontal, ArrowRight, Trash2, Check,
  FileText, Clock, PieChart, Copy, Users, CreditCard, TrendingUp, MessageSquare,
  ThumbsUp, ThumbsDown,
} from 'lucide-react'
import { CFG } from '../config.js'
import KpiSummaryBlock from '../components/KpiSummaryBlock.jsx'

const AI_GREETING = 'Hello! I can help you with your vouchers, performance metrics, guidelines, and more. What would you like to know?'

const WELCOME_MESSAGES = [
  { role: 'user', content: 'Hi! What can I do for you today?' },
  { role: 'assistant', content: AI_GREETING },
]

function storageKey(module) {
  return `ea_active_chat_${module || 'general'}`
}

function readStoredChatId(module) {
  try {
    return sessionStorage.getItem(storageKey(module))
  } catch {
    return null
  }
}

function writeStoredChatId(module, id) {
  try {
    const key = storageKey(module)
    if (id == null) sessionStorage.removeItem(key)
    else sessionStorage.setItem(key, String(id))
  } catch {
    // ignore
  }
}

function markNewChatSession(module) {
  try {
    sessionStorage.setItem(`${storageKey(module)}_new`, '1')
  } catch {
    // ignore
  }
}

function consumeNewChatSession(module) {
  try {
    const key = `${storageKey(module)}_new`
    if (sessionStorage.getItem(key) === '1') {
      sessionStorage.removeItem(key)
      return true
    }
  } catch {
    // ignore
  }
  return false
}

function findChatById(chats, id) {
  if (id == null || !chats?.length) return null
  return chats.find((c) => String(c.id) === String(id)) || null
}

function isWelcomeSeed(messages) {
  if (!messages || messages.length < 2) return false
  return messages[0].content === WELCOME_MESSAGES[0].content
    && messages[1].content === WELCOME_MESSAGES[1].content
}

function stripWelcomeMessages(messages) {
  if (isWelcomeSeed(messages)) return messages.slice(2)
  return messages
}

function mapThreadMessage(m) {
  const msg = { role: m.role, content: m.content }
  if (m.feedback === 'up' || m.feedback === 'down') msg.feedback = m.feedback
  if (m.preference_prompt) msg.preferencePrompt = true
  if (m.rich && typeof m.rich === 'object') msg.rich = m.rich
  return msg
}

function messagesFromSavedChat(chat) {
  if (Array.isArray(chat.thread) && chat.thread.length > 0) {
    return chat.thread.map(mapThreadMessage)
  }
  return [
    { role: 'user', content: chat.question },
    { role: 'assistant', content: chat.response || 'No response saved for this chat.' },
  ]
}

function bootstrapChatState(data) {
  const chats = data?.recentChats || []
  const module = data?.activeModule || 'general'
  if (consumeNewChatSession(module)) {
    return { messages: WELCOME_MESSAGES, activeChatId: null }
  }
  const urlChat = new URLSearchParams(window.location.search).get('chat')
  const storedId = urlChat || readStoredChatId(module)
  const chat = findChatById(chats, storedId) || chats[0] || null
  if (chat) {
    return {
      messages: messagesFromSavedChat(chat),
      activeChatId: chat.id,
    }
  }
  return { messages: WELCOME_MESSAGES, activeChatId: null }
}

function syncChatUrl(chatId) {
  const url = new URL(window.location.href)
  if (chatId == null) url.searchParams.delete('chat')
  else url.searchParams.set('chat', String(chatId))
  window.history.replaceState({}, document.title, url.toString())
}

const ICON_RULES = [
  { test: /voucher|summary/i, icon: FileText },
  { test: /pending|approval/i, icon: Clock },
  { test: /budget|actual|expense/i, icon: PieChart },
  { test: /duplicate/i, icon: Copy },
  { test: /attendance|late/i, icon: Users },
  { test: /payment|paid/i, icon: CreditCard },
  { test: /cash\s*flow|liquidity/i, icon: TrendingUp },
]

async function postAction(actionUrl, action, params) {
  const res = await fetch(actionUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ action, params }),
  })
  const text = await res.text()
  if (!text) {
    return { success: false, error: `Empty response (HTTP ${res.status}).` }
  }
  try {
    return JSON.parse(text)
  } catch {
    return { success: false, error: `Invalid server response (HTTP ${res.status}).` }
  }
}

function chatIcon(question) {
  const q = question || ''
  const rule = ICON_RULES.find((r) => r.test.test(q))
  return rule ? rule.icon : MessageSquare
}

function chatTitle(question) {
  const q = (question || '').trim()
  if (!q) return 'Untitled chat'
  const lower = q.toLowerCase()
  if (/voucher.*summary|summary.*voucher/i.test(lower)) return 'Voucher summary'
  if (/pending|approval/i.test(lower)) return 'Pending approvals'
  if (/budget|actual/i.test(lower)) return 'Budget vs actual'
  if (/duplicate/i.test(lower)) return 'Duplicate detection'
  if (/attendance|late/i.test(lower)) return 'Late attendance impact'
  if (/payment|report/i.test(lower)) return 'Payment report'
  if (/cash\s*flow/i.test(lower)) return 'Cash flow overview'
  const words = q.split(/\s+/).slice(0, 4).join(' ')
  return words.length < q.length ? `${words}...` : words
}

function truncate(text, max = 36) {
  const t = (text || '').trim()
  if (t.length <= max) return t
  return `${t.slice(0, max)}...`
}

function chatBucket(iso) {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return 'earlier'
  const now = new Date()
  const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const startYesterday = new Date(startToday)
  startYesterday.setDate(startYesterday.getDate() - 1)
  if (d >= startToday) return 'today'
  if (d >= startYesterday) return 'yesterday'
  return 'earlier'
}

function formatChatTime(iso, bucket) {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  if (bucket === 'earlier') {
    return d.toLocaleDateString([], { month: 'short', day: '2-digit' })
  }
  return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}

function groupChats(chats) {
  const groups = { today: [], yesterday: [], earlier: [] }
  for (const chat of chats) {
    const bucket = chatBucket(chat.created_at)
    groups[bucket].push(chat)
  }
  return groups
}

function ChatMessage({
  role,
  content,
  rich,
  userInitial,
  showActions,
  feedback,
  onFeedback,
  feedbackBusy,
}) {
  const isUser = role === 'user'
  const [copied, setCopied] = useState(false)

  const copyAnswer = useCallback(async () => {
    const text = (content || '').trim()
    if (!text) return
    try {
      await navigator.clipboard.writeText(text)
      setCopied(true)
      window.setTimeout(() => setCopied(false), 2000)
    } catch {
      const ta = document.createElement('textarea')
      ta.value = text
      ta.setAttribute('readonly', '')
      ta.style.position = 'fixed'
      ta.style.left = '-9999px'
      document.body.appendChild(ta)
      ta.select()
      try {
        document.execCommand('copy')
        setCopied(true)
        window.setTimeout(() => setCopied(false), 2000)
      } catch {
        /* ignore */
      }
      document.body.removeChild(ta)
    }
  }, [content])

  return (
    <div className={`ea-msg${isUser ? ' ea-msg--user' : ' ea-msg--assistant'}`}>
      {!isUser && (
        <span className="ea-avatar ea-avatar--ai" aria-hidden="true">
          <Sparkles size={16} />
        </span>
      )}
      <div className="ea-msg-content">
        <div className="ea-bubble">{content}</div>
        {rich?.type === 'kpi_summary' && <KpiSummaryBlock rich={rich} />}
        {showActions && (
          <div className="ea-msg-actions" role="group" aria-label="Message actions">
            <button
              type="button"
              className={`ea-msg-action${copied ? ' is-copied' : ''}`}
              onClick={copyAnswer}
              aria-label={copied ? 'Copied' : 'Copy answer'}
              title={copied ? 'Copied' : 'Copy'}
            >
              {copied ? <Check size={15} aria-hidden="true" /> : <Copy size={15} aria-hidden="true" />}
            </button>
            <button
              type="button"
              className={`ea-msg-action${feedback === 'up' ? ' is-active is-up' : ''}`}
              onClick={() => onFeedback('up')}
              disabled={feedbackBusy}
              aria-label="Good answer"
              title="Good answer"
            >
              <ThumbsUp size={15} aria-hidden="true" />
            </button>
            <button
              type="button"
              className={`ea-msg-action${feedback === 'down' ? ' is-active is-down' : ''}`}
              onClick={() => onFeedback('down')}
              disabled={feedbackBusy}
              aria-label="Poor answer"
              title="Poor answer"
            >
              <ThumbsDown size={15} aria-hidden="true" />
            </button>
          </div>
        )}
      </div>
      {isUser && (
        <span className="ea-avatar ea-avatar--user" aria-hidden="true">{userInitial}</span>
      )}
    </div>
  )
}

function TypingIndicator() {
  return (
    <div className="ea-msg ea-msg--assistant">
      <span className="ea-avatar ea-avatar--ai" aria-hidden="true"><Sparkles size={16} /></span>
      <div className="ea-bubble ea-bubble--typing">
        <span className="ea-dot" /><span className="ea-dot" /><span className="ea-dot" />
      </div>
    </div>
  )
}

function HistoryItem({ chat, active, onSelect, onDelete, deleting }) {
  const bucket = chatBucket(chat.created_at)
  const Icon = chatIcon(chat.question)
  return (
    <div className={`ea-hist-item-wrap${active ? ' is-active' : ''}`}>
      <button
        type="button"
        className="ea-hist-item"
        onClick={() => onSelect(chat)}
      >
        <span className="ea-hist-icon" aria-hidden="true"><Icon size={16} /></span>
        <span className="ea-hist-body">
          <span className="ea-hist-row">
            <span className="ea-hist-title">{chatTitle(chat.question)}</span>
            <span className="ea-hist-time">{formatChatTime(chat.created_at, bucket)}</span>
          </span>
          <span className="ea-hist-preview">{truncate(chat.question)}</span>
        </span>
      </button>
      <button
        type="button"
        className="ea-hist-delete"
        onClick={(e) => {
          e.stopPropagation()
          onDelete(chat)
        }}
        disabled={deleting}
        aria-label={`Delete ${chatTitle(chat.question)}`}
        title="Delete chat"
      >
        {deleting ? <Loader2 size={14} className="ea-spin" /> : <Trash2 size={14} />}
      </button>
    </div>
  )
}

function ChatHistorySidebar({
  chats,
  activeChatId,
  showAll,
  deletingChatId,
  onNewChat,
  onSelectChat,
  onDeleteChat,
  onViewAll,
  onCloseMobile,
}) {
  const visible = showAll ? chats : chats.slice(0, 8)
  const groups = groupChats(visible)
  const sections = [
    { key: 'today', label: 'Today' },
    { key: 'yesterday', label: 'Yesterday' },
    { key: 'earlier', label: 'Earlier' },
  ]

  return (
    <aside className="ea-history">
      <div className="ea-history-top">
        <button type="button" className="ea-new-chat-btn" onClick={onNewChat}>
          <Plus size={15} aria-hidden="true" />
          New Chat
        </button>
        <button type="button" className="ea-history-filter" aria-label="Filter chats">
          <SlidersHorizontal size={16} />
        </button>
        {onCloseMobile && (
          <button type="button" className="ea-history-close" onClick={onCloseMobile} aria-label="Close history">
            &times;
          </button>
        )}
      </div>

      <div className="ea-history-list">
        {sections.map(({ key, label }) => {
          const items = groups[key]
          if (!items.length) return null
          return (
            <div key={key} className="ea-hist-group">
              <h3 className="ea-hist-label">{label}</h3>
              {items.map((chat) => (
                <HistoryItem
                  key={`${chat.id}-${chat.created_at}-${chat.question}`}
                  chat={chat}
                  active={activeChatId === chat.id}
                  onSelect={onSelectChat}
                  onDelete={onDeleteChat}
                  deleting={String(deletingChatId) === String(chat.id)}
                />
              ))}
            </div>
          )
        })}
        {visible.length === 0 && (
          <p className="ea-hist-empty">Your conversations will appear here.</p>
        )}
      </div>

      {chats.length > 8 && !showAll && (
        <button type="button" className="ea-view-all" onClick={onViewAll}>
          View all chats
          <ArrowRight size={14} aria-hidden="true" />
        </button>
      )}
    </aside>
  )
}

function ConfirmToast({ open, title, message, confirmLabel, loading, onConfirm, onCancel }) {
  if (!open) return null

  return (
    <div className="ea-toast-backdrop" onClick={onCancel} role="presentation">
      <div
        className="ea-toast"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ea-toast-title"
        onClick={(e) => e.stopPropagation()}
      >
        <p id="ea-toast-title" className="ea-toast-title">{title}</p>
        {message && <p className="ea-toast-message">{message}</p>}
        <div className="ea-toast-actions">
          <button type="button" className="ea-toast-btn ea-toast-btn--ghost" onClick={onCancel} disabled={loading}>
            Cancel
          </button>
          <button type="button" className="ea-toast-btn ea-toast-btn--danger" onClick={onConfirm} disabled={loading}>
            {loading ? <Loader2 size={14} className="ea-spin" aria-hidden="true" /> : null}
            {confirmLabel}
          </button>
        </div>
      </div>
    </div>
  )
}

export default function AiAssistantPage() {
  const initial = CFG.data || null
  const boot = bootstrapChatState(initial)
  const [data, setData] = useState(initial)
  const [loading, setLoading] = useState(!initial)
  const [error, setError] = useState('')

  const [activeTab] = useState(initial?.activeTab === 'attendance' ? 'performance' : (initial?.activeTab || 'chat'))
  const [messages, setMessages] = useState(boot.messages)
  const [activeChatId, setActiveChatId] = useState(boot.activeChatId)
  const [showAllChats, setShowAllChats] = useState(false)
  const [historyOpen, setHistoryOpen] = useState(false)
  const [deletingChatId, setDeletingChatId] = useState(null)
  const [pendingDeleteChat, setPendingDeleteChat] = useState(null)
  const [feedbackBusyKey, setFeedbackBusyKey] = useState(null)
  const [chatInput, setChatInput] = useState('')
  const [chatLoading, setChatLoading] = useState(false)
  const [reportLoading, setReportLoading] = useState('')
  const [reports, setReports] = useState({ performance: '', guidelines: '' })

  const chatEndRef = useRef(null)
  const kpiHandled = useRef(false)

  const activeModule = data?.activeModule || 'general'
  const userInitial = (data?.userFirstName || 'U').charAt(0).toUpperCase()
  const recentChats = data?.recentChats || []

  const upsertSavedChat = useCallback((savedChat) => {
    if (!savedChat || savedChat.id == null) return
    setData((prev) => {
      if (!prev) return prev
      const list = (prev.recentChats || []).filter((c) => String(c.id) !== String(savedChat.id))
      return {
        ...prev,
        recentChats: [savedChat, ...list].slice(0, 20),
      }
    })
    setActiveChatId(savedChat.id)
    writeStoredChatId(activeModule, savedChat.id)
    syncChatUrl(savedChat.id)
  }, [activeModule])

  const loadInit = useCallback(async () => {
    if (!CFG.apiUrl) return
    setLoading(true)
    setError('')
    try {
      const url = new URL(CFG.apiUrl, window.location.href)
      const qs = new URLSearchParams(window.location.search)
      qs.forEach((v, k) => url.searchParams.set(k, v))
      const res = await fetch(url.toString(), { headers: { Accept: 'application/json' } })
      const json = await res.json()
      if (!json.ok || !json.data) {
        setError(json.error || 'Could not load AI assistant')
        return
      }
      setData(json.data)
      const restored = bootstrapChatState(json.data)
      setMessages(restored.messages)
      setActiveChatId(restored.activeChatId)
      if (restored.activeChatId != null) {
        writeStoredChatId(json.data.activeModule || 'general', restored.activeChatId)
        syncChatUrl(restored.activeChatId)
      }
    } catch {
      setError('Network error loading AI assistant')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    if (!initial) loadInit()
  }, [initial, loadInit])

  useEffect(() => {
    chatEndRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [messages, chatLoading])

  const resetChat = useCallback(() => {
    setMessages(WELCOME_MESSAGES)
    setActiveChatId(null)
    setChatInput('')
    setHistoryOpen(false)
    writeStoredChatId(activeModule, null)
    markNewChatSession(activeModule)
    syncChatUrl(null)
  }, [activeModule])

  const loadRecentChat = useCallback((chat) => {
    setActiveChatId(chat.id)
    setMessages(messagesFromSavedChat(chat))
    setHistoryOpen(false)
    writeStoredChatId(activeModule, chat.id)
    syncChatUrl(chat.id)
  }, [activeModule])

  const requestDeleteChat = useCallback((chat) => {
    if (!chat?.id || deletingChatId) return
    setPendingDeleteChat(chat)
  }, [deletingChatId])

  const cancelDeleteChat = useCallback(() => {
    if (deletingChatId) return
    setPendingDeleteChat(null)
  }, [deletingChatId])

  const confirmDeleteChat = useCallback(async () => {
    const chat = pendingDeleteChat
    if (!chat?.id || deletingChatId) return

    setDeletingChatId(chat.id)
    setError('')
    try {
      const result = await postAction(CFG.actionUrl, 'delete_chat', { chat_id: chat.id })
      if (!result.success) {
        setError(result.error || 'Could not delete chat.')
        return
      }

      const remaining = (data?.recentChats || []).filter((c) => String(c.id) !== String(chat.id))
      setData((prev) => (prev ? { ...prev, recentChats: remaining } : prev))

      if (String(activeChatId) === String(chat.id)) {
        setMessages(WELCOME_MESSAGES)
        setActiveChatId(null)
        setChatInput('')
        writeStoredChatId(activeModule, null)
        markNewChatSession(activeModule)
        syncChatUrl(null)
      }
      setPendingDeleteChat(null)
    } catch {
      setError('Could not delete chat.')
    } finally {
      setDeletingChatId(null)
    }
  }, [activeChatId, activeModule, data?.recentChats, deletingChatId, pendingDeleteChat])

  const deleteChat = requestDeleteChat

  const rateMessage = useCallback(async (messageIndex, rating) => {
    setMessages((prev) => prev.map((m, i) => (
      i === messageIndex ? { ...m, feedback: rating } : m
    )))

    if (!activeChatId) return

    const busyKey = `${activeChatId}-${messageIndex}`
    setFeedbackBusyKey(busyKey)
    setError('')
    try {
      const result = await postAction(CFG.actionUrl, 'message_feedback', {
        chat_id: activeChatId,
        message_index: messageIndex,
        rating,
      })
      if (!result.success) {
        setError(result.error || 'Could not save feedback.')
        return
      }
      if (Array.isArray(result.thread) && result.thread.length > 0) {
        setMessages(result.thread.map(mapThreadMessage))
      }
    } catch {
      setError('Could not save feedback.')
    } finally {
      setFeedbackBusyKey(null)
    }
  }, [activeChatId])

  const sendChat = useCallback(async (text) => {
    const trimmed = text.trim()
    if (!trimmed || chatLoading) return
    if (!data?.aiEnabled) {
      setMessages((m) => [...m, { role: 'assistant', content: 'AI Assistant is currently disabled.' }])
      return
    }

    const threadForApi = stripWelcomeMessages([
      ...messages,
      { role: 'user', content: trimmed },
    ])

    setChatInput('')
    setMessages((m) => [...m, { role: 'user', content: trimmed }])
    setChatLoading(true)

    try {
      const result = await postAction(CFG.actionUrl, 'explain_kpi', {
        kpi: 'User Question',
        value: trimmed,
        active_module: activeModule,
        chat_id: activeChatId || 0,
        messages: threadForApi,
      })
      const reply = result.success ? result.analysis : `Error: ${result.error || 'Unknown error'}`
      const savedThread = result.savedChat?.thread
      const nextMessages = savedThread?.length
        ? savedThread.map(mapThreadMessage)
        : [...threadForApi, {
          role: 'assistant',
          content: reply,
          ...(result.rich ? { rich: result.rich } : {}),
        }]
      setMessages(nextMessages)
      if (result.success && result.savedChat) {
        upsertSavedChat(result.savedChat)
      }
    } catch (err) {
      setMessages((m) => [...m, {
        role: 'assistant',
        content: `Error: ${err?.message || 'Connection error. Please try again.'}`,
      }])
    } finally {
      setChatLoading(false)
    }
  }, [activeChatId, activeModule, chatLoading, data?.aiEnabled, messages, upsertSavedChat])

  const generateReport = useCallback(async (moduleName, tabKey) => {
    if (!data?.aiEnabled) return
    setReportLoading(tabKey)
    setReports((r) => ({ ...r, [tabKey]: '' }))
    try {
      const result = await postAction(CFG.actionUrl, 'module_report', { module: moduleName })
      setReports((r) => ({
        ...r,
        [tabKey]: result.success ? result.analysis : `Error: ${result.error || 'Unknown error'}`,
      }))
    } catch {
      setReports((r) => ({ ...r, [tabKey]: 'Connection error.' }))
    } finally {
      setReportLoading('')
    }
  }, [data?.aiEnabled])

  useEffect(() => {
    const kpi = data?.kpiPrompt
    if (!kpi || kpiHandled.current || !data?.aiEnabled) return
    kpiHandled.current = true
    sendChat(`Explain the KPI: ${kpi.kpi} (value: ${kpi.val || '-'})`)
  }, [data, sendChat])

  const showHistory = activeTab === 'chat'

  if (loading) {
    return (
      <div className="ea-page">
        <div className="ea-loading" role="status">
          <Loader2 className="ea-spin" aria-hidden="true" />
          <span>Loading...</span>
        </div>
      </div>
    )
  }

  return (
    <div className="ea-page">
      {error && <div className="ea-flash" role="alert">{error}</div>}

      <div className={`ea-shell${showHistory ? ' ea-shell--with-history' : ''}`}>
        {showHistory && (
          <>
            <ChatHistorySidebar
              chats={recentChats}
              activeChatId={activeChatId}
              showAll={showAllChats}
              deletingChatId={deletingChatId}
              onNewChat={resetChat}
              onSelectChat={loadRecentChat}
              onDeleteChat={deleteChat}
              onViewAll={() => setShowAllChats(true)}
            />
            <div
              className={`ea-history-backdrop${historyOpen ? ' is-open' : ''}`}
              onClick={() => setHistoryOpen(false)}
              aria-hidden="true"
            />
            <div className={`ea-history-mobile${historyOpen ? ' is-open' : ''}`}>
              <ChatHistorySidebar
                chats={recentChats}
                activeChatId={activeChatId}
                showAll={showAllChats}
                deletingChatId={deletingChatId}
                onNewChat={resetChat}
                onSelectChat={loadRecentChat}
                onDeleteChat={deleteChat}
                onViewAll={() => setShowAllChats(true)}
                onCloseMobile={() => setHistoryOpen(false)}
              />
            </div>
          </>
        )}

        <div className="ea-main">
          <header className="ea-head">
            <div className="ea-head-row">
              {showHistory && (
                <button
                  type="button"
                  className="ea-history-toggle"
                  onClick={() => setHistoryOpen(true)}
                >
                  Chats
                </button>
              )}
              <div>
                <h1>AI Assistant</h1>
                <p>Your personal ERP advisor.</p>
              </div>
            </div>
          </header>

          {activeTab === 'chat' && (
            <div className="ea-chat">
              <div className="ea-chat-body">
                {messages.map((msg, i) => (
                  <ChatMessage
                    key={`${msg.role}-${i}-${msg.content?.slice(0, 24)}`}
                    role={msg.role}
                    content={msg.content}
                    rich={msg.rich}
                    userInitial={userInitial}
                    showActions={msg.role === 'assistant' && msg.content !== AI_GREETING && !msg.preferencePrompt}
                    feedback={msg.feedback}
                    onFeedback={(rating) => rateMessage(i, rating)}
                    feedbackBusy={feedbackBusyKey === `${activeChatId}-${i}`}
                  />
                ))}
                {chatLoading && <TypingIndicator />}
                <div ref={chatEndRef} />
              </div>

              <div className="ea-composer">
                <input
                  type="text"
                  className="ea-composer-input"
                  placeholder="Type your message..."
                  value={chatInput}
                  onChange={(e) => setChatInput(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter') sendChat(chatInput) }}
                  disabled={chatLoading}
                  aria-label="Type your message"
                />
                <button
                  type="button"
                  className="ea-composer-send"
                  onClick={() => sendChat(chatInput)}
                  disabled={chatLoading || !chatInput.trim()}
                  aria-label="Send"
                >
                  <Send size={16} aria-hidden="true" />
                </button>
              </div>
            </div>
          )}

          {activeTab === 'performance' && (
            <div className="ea-panel">
              <p className="ea-panel-lead">Review your weekly missions and get AI feedback on your performance.</p>
              <button type="button" className="ea-btn" onClick={() => generateReport('performance', 'performance')} disabled={!!reportLoading}>
                {reportLoading === 'performance' ? <Loader2 size={14} className="ea-spin" /> : null}
                Generate Analysis
              </button>
              <div className="ea-output">{reports.performance || 'Click the button to generate your performance summary.'}</div>
            </div>
          )}

          {activeTab === 'guidelines' && (
            <div className="ea-panel">
              <p className="ea-panel-lead">Load role-specific guidelines and duty reminders.</p>
              <button
                type="button"
                className="ea-btn"
                onClick={() => generateReport(activeModule === 'voucher' ? 'vouchers' : 'general', 'guidelines')}
                disabled={!!reportLoading}
              >
                {reportLoading === 'guidelines' ? <Loader2 size={14} className="ea-spin" /> : null}
                Load Guidelines
              </button>
              <div className="ea-output">{reports.guidelines || 'Click the button to load your guidelines.'}</div>
            </div>
          )}

          {activeTab === 'settings' && (
            <div className="ea-panel">
              <p className="ea-panel-lead">Assistant configuration for your session.</p>
              <dl className="ea-settings-list">
                <div><dt>Status</dt><dd>{data?.aiEnabled ? 'Enabled' : 'Disabled'}</dd></div>
                <div><dt>Module context</dt><dd>{activeModule === 'general' ? 'General' : activeModule.charAt(0).toUpperCase() + activeModule.slice(1)}</dd></div>
                <div><dt>Recent chats</dt><dd>{recentChats.length}</dd></div>
              </dl>
            </div>
          )}
        </div>
      </div>

      <ConfirmToast
        open={!!pendingDeleteChat}
        title="Delete this conversation?"
        message={pendingDeleteChat ? chatTitle(pendingDeleteChat.question) : ''}
        confirmLabel="Delete"
        loading={!!deletingChatId}
        onConfirm={confirmDeleteChat}
        onCancel={cancelDeleteChat}
      />
    </div>
  )
}
