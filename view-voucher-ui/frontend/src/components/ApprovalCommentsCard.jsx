import { useMemo, useState } from 'react'
import { MessageCircle, SmilePlus } from 'lucide-react'
import { showToast } from '../toast.js'

function initialOf(name) {
  const n = String(name || 'U').trim()
  return (n.charAt(0) || 'U').toUpperCase()
}

function formatCommentWhen(value) {
  const raw = String(value || '').trim()
  if (!raw) return ''
  const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T')
  const d = new Date(normalized)
  if (Number.isNaN(d.getTime())) return raw.slice(0, 16)
  return d.toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
}

function CommentAvatar({ name }) {
  return (
    <div className="vv-comment-avatar" aria-hidden="true">
      {initialOf(name)}
    </div>
  )
}

function CommentItem({
  comment,
  voucherId,
  catalog,
  csrfToken,
  reactUrl,
  replyUrl,
  viewerName,
  onReactionsChange,
  onReplyAdded,
}) {
  const [replyOpen, setReplyOpen] = useState(false)
  const [replyText, setReplyText] = useState('')
  const [pickerOpen, setPickerOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [replyBusy, setReplyBusy] = useState(false)

  const actionLabel = String(comment.action || '').replace(/_/g, ' ').trim()
  const actionKey = actionLabel.toLowerCase().replace(/\s+/g, '-')
  const replies = Array.isArray(comment.replies) ? comment.replies : []
  const reactions = Array.isArray(comment.reactions) ? comment.reactions : []
  const whenLabel = formatCommentWhen(comment.created_at)

  async function toggleReaction(key) {
    if (busy || !reactUrl) return
    setBusy(true)
    setPickerOpen(false)
    try {
      const fd = new FormData()
      fd.append('voucher_id', String(voucherId))
      fd.append('approval_log_id', String(comment.id))
      fd.append('reaction', key)
      fd.append('csrf_token', csrfToken || '')
      const res = await fetch(reactUrl, { method: 'POST', body: fd })
      const json = await res.json()
      if (!json?.ok) {
        showToast(json?.error || 'Could not save reaction', 'error')
        return
      }
      onReactionsChange(comment.id, Array.isArray(json.reactions) ? json.reactions : [])
      showToast(json.toast || (json.active ? 'Reaction saved' : 'Reaction removed'), 'success')
    } catch {
      showToast('Network error saving reaction', 'error')
    } finally {
      setBusy(false)
    }
  }

  async function submitReply(event) {
    event.preventDefault()
    const body = replyText.trim()
    if (!body || replyBusy || !replyUrl) return
    setReplyBusy(true)
    try {
      const fd = new FormData()
      fd.append('voucher_id', String(voucherId))
      fd.append('approval_log_id', String(comment.id))
      fd.append('body', body)
      fd.append('csrf_token', csrfToken || '')
      const res = await fetch(replyUrl, { method: 'POST', body: fd })
      const json = await res.json()
      if (!json?.ok || !json.reply) {
        showToast(json?.error || 'Could not save reply', 'error')
        return
      }
      onReplyAdded(comment.id, json.reply)
      setReplyText('')
      setReplyOpen(false)
      showToast(json.toast || 'Reply sent', 'success')
    } catch {
      showToast('Network error saving reply', 'error')
    } finally {
      setReplyBusy(false)
    }
  }

  return (
    <li className="vv-comment-row">
      <CommentAvatar name={comment.full_name} />
      <div className="vv-comment-main">
        <div className="vv-comment-top">
          <div className="vv-comment-who">
            <span className="vv-comment-name">{comment.full_name || 'User'}</span>
            {actionLabel ? (
              <span className={`vv-comment-action is-${actionKey}`}>{actionLabel}</span>
            ) : null}
          </div>
          {whenLabel ? (
            <time className="vv-comment-time" dateTime={String(comment.created_at || '')}>
              {whenLabel}
            </time>
          ) : null}
        </div>

        <p className="vv-comment-body">{comment.comments}</p>

        <div className="vv-comment-toolbar">
          <div className="vv-comment-reactions" role="group" aria-label="Reactions">
            {reactions.map((rx) => (
              <button
                key={rx.key}
                type="button"
                className={`vv-comment-reaction${rx.mine ? ' is-mine' : ''}`}
                title={rx.label || rx.key}
                disabled={busy}
                aria-pressed={Boolean(rx.mine)}
                onClick={() => toggleReaction(rx.key)}
              >
                <span aria-hidden="true">{rx.emoji}</span>
                <span>{rx.count}</span>
              </button>
            ))}
            <div className={`vv-comment-react-wrap${pickerOpen ? ' is-open' : ''}`}>
              <button
                type="button"
                className="vv-comment-tool-btn"
                disabled={busy}
                aria-expanded={pickerOpen}
                aria-label="Add reaction"
                title="Add reaction"
                onClick={() => setPickerOpen((v) => !v)}
              >
                <SmilePlus size={15} strokeWidth={2} />
              </button>
              {pickerOpen && (
                <div className="vv-comment-react-picker" role="menu">
                  {catalog.map((item) => (
                    <button
                      key={item.key}
                      type="button"
                      role="menuitem"
                      className="vv-comment-react-option"
                      title={item.label}
                      disabled={busy}
                      onClick={() => toggleReaction(item.key)}
                    >
                      <span aria-hidden="true">{item.emoji}</span>
                      <span>{item.label}</span>
                    </button>
                  ))}
                </div>
              )}
            </div>
          </div>

          <button
            type="button"
            className={`vv-comment-tool-btn vv-comment-reply-btn${replyOpen ? ' is-active' : ''}`}
            onClick={() => setReplyOpen((v) => !v)}
          >
            <MessageCircle size={15} strokeWidth={2} />
            <span>Reply{replies.length > 0 ? ` · ${replies.length}` : ''}</span>
          </button>
        </div>

        {replies.length > 0 && (
          <ul className="vv-comment-replies">
            {replies.map((reply) => (
              <li key={reply.id} className="vv-comment-reply">
                <CommentAvatar name={reply.full_name} />
                <div className="vv-comment-reply-main">
                  <div className="vv-comment-top">
                    <span className="vv-comment-name">{reply.full_name || 'User'}</span>
                    <time className="vv-comment-time" dateTime={String(reply.created_at || '')}>
                      {formatCommentWhen(reply.created_at)}
                    </time>
                  </div>
                  <p className="vv-comment-body">{reply.body}</p>
                </div>
              </li>
            ))}
          </ul>
        )}

        {replyOpen && (
          <form className="vv-comment-composer" onSubmit={submitReply}>
            <CommentAvatar name={viewerName || 'You'} />
            <div className="vv-comment-composer-fields">
              <label className="sr-only" htmlFor={`vv-reply-${comment.id}`}>Reply</label>
              <textarea
                id={`vv-reply-${comment.id}`}
                rows={2}
                value={replyText}
                disabled={replyBusy}
                placeholder="Write a reply…"
                onChange={(e) => setReplyText(e.target.value)}
              />
              <div className="vv-comment-composer-actions">
                <button
                  type="button"
                  className="vv-comment-composer-cancel"
                  disabled={replyBusy}
                  onClick={() => {
                    setReplyOpen(false)
                    setReplyText('')
                  }}
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="vv-comment-composer-submit"
                  disabled={replyBusy || !replyText.trim()}
                >
                  {replyBusy ? 'Sending…' : 'Reply'}
                </button>
              </div>
            </div>
          </form>
        )}
      </div>
    </li>
  )
}

export default function ApprovalCommentsCard({ data, onCommentsChange }) {
  const comments = Array.isArray(data?.comments) ? data.comments : []
  const catalog = useMemo(() => {
    const raw = Array.isArray(data?.reactionCatalog) ? data.reactionCatalog : []
    if (raw.length > 0) return raw
    return [
      { key: 'thumbs_up', emoji: '👍', label: 'Helpful' },
      { key: 'check', emoji: '✅', label: 'Acknowledged' },
      { key: 'eyes', emoji: '👀', label: 'Seen' },
    ]
  }, [data?.reactionCatalog])

  if (comments.length === 0) return null

  const voucherId = data?.voucher?.id
  const actions = data?.actions || {}
  const viewerName = data?.viewer?.full_name || 'You'

  function handleReactionsChange(logId, nextReactions) {
    onCommentsChange?.((prev) => (prev || []).map((c) => (
      c.id === logId ? { ...c, reactions: nextReactions } : c
    )))
  }

  function handleReplyAdded(logId, reply) {
    onCommentsChange?.((prev) => (prev || []).map((c) => (
      c.id === logId
        ? { ...c, replies: [...(Array.isArray(c.replies) ? c.replies : []), reply] }
        : c
    )))
  }

  return (
    <section className="vv-card vv-card-comments no-print">
      <div className="vv-card-head">
        <h2 className="vv-card-title">
          Approval Comments
          <span className="vv-gmail-count"> · {comments.length}</span>
        </h2>
      </div>
      <div className="vv-card-body">
        <ul className="vv-comments-list">
          {comments.map((comment) => (
            <CommentItem
              key={comment.id || `${comment.full_name}-${comment.created_at}`}
              comment={comment}
              voucherId={voucherId}
              catalog={catalog}
              csrfToken={actions.csrfToken}
              reactUrl={actions.commentReactUrl}
              replyUrl={actions.commentReplyUrl}
              viewerName={viewerName}
              onReactionsChange={handleReactionsChange}
              onReplyAdded={handleReplyAdded}
            />
          ))}
        </ul>
      </div>
    </section>
  )
}
