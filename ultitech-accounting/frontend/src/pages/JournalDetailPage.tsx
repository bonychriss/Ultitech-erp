import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiRequest, ApiError } from '../lib/api'
import { useAuth } from '../lib/auth'
import {
  detailSubtitle,
  isReadOnlyJournal,
  money,
  presentJournalDescription,
  presentJournalDescriptionProse,
  printJournal,
  type Journal,
} from '../lib/journals'
import { Button, EmptyState, ErrorBanner, Input, Label } from '../components/ui'
function PrintIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true">
      <path d="M6 9V2h12v7" />
      <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
      <path d="M6 14h12v8H6z" />
    </svg>
  )
}
function parseJournalId(raw: string | undefined): number | null {
  if (raw == null || raw === '') return null
  const n = Number(raw)
  if (!Number.isFinite(n) || n === 0) return null
  return Math.trunc(n)
}
export function JournalDetailPage() {
  const { id: idParam } = useParams()
  const journalId = parseJournalId(idParam)
  const navigate = useNavigate()
  const { token, companyId, bookId } = useAuth()
  const client = useQueryClient()
  const ready = Boolean(token && companyId && journalId != null)
  const [error, setError] = useState('')
  const [reverseReason, setReverseReason] = useState('')
  const [reverseOpen, setReverseOpen] = useState(false)
  const journalQuery = useQuery({
    queryKey: ['journal', companyId, bookId, journalId],
    enabled: ready,
    queryFn: () =>
      apiRequest<{ data: Journal }>(`/journals/${journalId}`, { token, companyId, bookId }),
  })
  const selected = journalQuery.data?.data ?? null
  useEffect(() => {
    if (!selected?.journal_number) return
    document.title = selected.journal_number + ' - Journal Entries'
    const title = document.querySelector(
      '.employee-header.employee-header--accounting-desk .employee-header-page-title',
    ) as HTMLElement | null
    if (title) title.textContent = selected.journal_number
    const current = document.querySelector(
      '.employee-header.employee-header--accounting-desk .vv-breadcrumb-current, #erp-auto-breadcrumb .erp-auto-breadcrumb-current',
    ) as HTMLElement | null
    if (current) current.textContent = selected.journal_number
  }, [selected?.journal_number])
  const postMutation = useMutation({
    mutationFn: async (id: number) =>
      apiRequest<{ data: Journal }>(`/journals/${id}/post`, {
        method: 'POST',
        token,
        companyId,
        bookId,
        idempotencyKey: crypto.randomUUID(),
      }),
    onSuccess: async () => {
      setError('')
      await Promise.all([
        client.invalidateQueries({ queryKey: ['journals', companyId, bookId] }),
        client.invalidateQueries({ queryKey: ['journal', companyId, bookId, journalId] }),
      ])
    },
    onError: (err) => setError((err as Error).message || 'Could not post journal.'),
  })
  const reverseMutation = useMutation({
    mutationFn: async () => {
      if (journalId == null) throw new Error('No journal selected.')
      const reason = reverseReason.trim()
      if (!reason) throw new Error('Enter a reversal reason.')
      return apiRequest<{ data: Journal }>(`/journals/${journalId}/reverse`, {
        method: 'POST',
        token,
        companyId,
        bookId,
        body: { reason },
      })
    },
    onSuccess: async (res) => {
      setReverseOpen(false)
      setReverseReason('')
      setError('')
      await client.invalidateQueries({ queryKey: ['journals', companyId, bookId] })
      navigate(`/journals/${res.data.id}`, { replace: true })
    },
    onError: (err) => setError((err as ApiError | Error).message || 'Could not reverse journal.'),
  })
  if (journalId == null) {
    return (
      <div className="ua-revenue ua-journals ua-journals-detail-page">
        <EmptyState title="Invalid journal" body="That journal link is not valid." />
        <div className="ua-journals-detail-page__nav">
          <Button type="button" variant="secondary" onClick={() => navigate('/journals')}>
            Back to journals
          </Button>
        </div>
      </div>
    )
  }
  return (
    <div className="ua-revenue ua-journals ua-journals-detail-page">
      <nav className="ua-journals-detail-crumbs" aria-label="Breadcrumb">
        <Link to="/journals">Journal Entries</Link>
        <span aria-hidden="true">/</span>
        <strong>{selected?.journal_number || (journalQuery.isLoading ? '...' : `Journal ${journalId}`)}</strong>
      </nav>
      {!ready ? <ErrorBanner message="Company session not ready. Re-open Accounting from ERP." /> : null}
      {error ? <ErrorBanner message={error} /> : null}
      {journalQuery.isError ? (
        <ErrorBanner message={(journalQuery.error as Error)?.message || 'Unable to load journal.'} />
      ) : null}
      {journalQuery.isLoading ? <div className="ua-revenue-loading">Loading journal...</div> : null}
      {!journalQuery.isLoading && !selected && !journalQuery.isError ? (
        <EmptyState title="Journal not found" body="It may have been removed or is not in this book." />
      ) : null}
      {selected ? (
        <div className="ua-journals-detail">
          <div className="ua-journals-detail-head">
            <div>
              <h1 id="ua-journal-detail-title" className="ua-journals-detail-title">
                {selected.journal_number}
              </h1>
              <p className="ua-journals-detail-sub">{detailSubtitle(selected)}</p>
            </div>
            <button
              type="button"
              className="ua-journals-detail-print"
              onClick={() => printJournal(selected)}
            >
              <PrintIcon />
              Print
            </button>
          </div>
          <div className="ua-journals-detail-lines-card">
            <div className="ua-journals-detail-table-wrap">
              <table className="ua-journals-detail-table">
                <thead>
                  <tr>
                    <th>Account</th>
                    <th className="is-num">Debit</th>
                    <th className="is-num">Credit</th>
                  </tr>
                </thead>
                <tbody>
                  {(selected.lines || []).map((line, index) => {
                    const account = line.account || null
                    const accountLabel = account
                      ? account.name
                      : `Account #${line.account_id}`
                    return (
                      <tr key={line.id || `${line.account_id}-${index}`}>
                        <td>{accountLabel}</td>
                        <td className="is-num">
                          {Number(line.debit) > 0
                            ? money(line.debit)
                            : ''}
                        </td>
                        <td className="is-num">
                          {Number(line.credit) > 0
                            ? money(line.credit)
                            : ''}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </div>
          {(() => {
            const desc = presentJournalDescription(selected.description)
            const fileName = desc.attachmentPath
              ? desc.attachmentPath.split(/[/\\]/).pop() || 'Attachment'
              : null
            const lower = String(fileName || '').toLowerCase()
            const isImage = /\.(png|jpe?g|gif|webp|bmp)$/i.test(lower)
            const isPdf = /\.pdf$/i.test(lower)
            const kindLabel = isImage ? 'IMG' : isPdf ? 'PDF' : 'FILE'
            const kindTone = isImage ? 'image' : isPdf ? 'pdf' : 'file'
            const href = desc.attachmentHref || ''
            const pdfThumbSrc = href
              ? `${href.split('#')[0]}#page=1&view=FitH&toolbar=0&navpanes=0&scrollbar=0`
              : ''
            const shortName = (() => {
              const s = String(fileName || '')
              if (s.length <= 28) return s
              const dot = s.lastIndexOf('.')
              if (dot > 0 && s.length - dot <= 5) {
                const ext = s.slice(dot)
                return `${s.slice(0, Math.max(1, 28 - ext.length - 1))}...${ext}`
              }
              return `${s.slice(0, 27)}...`
            })()
            return (
              <div className="ua-journals-detail-desc-card">
                <div className="ua-journals-detail-desc-card__title">Description</div>
                <p className="ua-journals-detail-desc-card__body">
                  {presentJournalDescriptionProse(selected.description)}
                </p>
                {href && fileName ? (
                  <div className="ua-journals-detail-attachments">
                    <div className="ua-journals-detail-attachments__label">Attachment</div>
                    <div className="ua-journals-gmail-list" role="list">
                      <article
                        className="ua-journals-gmail-card"
                        title={fileName}
                        role="listitem"
                      >
                        <button
                          type="button"
                          className="ua-journals-gmail-card-hit"
                          aria-label={`Open ${fileName}`}
                          onClick={() => window.open(href, '_blank', 'noopener,noreferrer')}
                        >
                          {isImage ? (
                            <div className="ua-journals-gmail-thumb ua-journals-gmail-thumb--image">
                              <img src={href} alt="" loading="lazy" />
                            </div>
                          ) : isPdf ? (
                            <div className="ua-journals-gmail-thumb ua-journals-gmail-thumb--pdf">
                              <iframe
                                src={pdfThumbSrc}
                                title="PDF preview"
                                loading="lazy"
                                tabIndex={-1}
                              />
                            </div>
                          ) : (
                            <div className="ua-journals-gmail-thumb ua-journals-gmail-thumb--placeholder">
                              <span className={`ua-journals-gmail-thumb-badge ua-journals-gmail-thumb-badge--${kindTone}`}>
                                {kindLabel}
                              </span>
                            </div>
                          )}
                        </button>
                        <div className="ua-journals-gmail-card-foot">
                          <span className={`ua-journals-gmail-type ua-journals-gmail-type--${kindTone}`} aria-hidden="true">
                            {kindLabel}
                          </span>
                          <span className="ua-journals-gmail-fname">{shortName}</span>
                          <a
                            href={href}
                            download
                            rel="noopener noreferrer"
                            className="ua-journals-gmail-foot-action"
                            title="Download"
                            aria-label={`Download ${fileName}`}
                            onClick={(e) => e.stopPropagation()}
                          >
                            ?
                          </a>
                        </div>
                        <div className="ua-journals-gmail-card-caption">Payment proof</div>
                      </article>
                    </div>
                  </div>
                ) : null}
              </div>
            )
          })()}
          {reverseOpen ? (
            <div className="ua-journals-reverse-box">
              <Label>Reversal reason</Label>
              <Input
                value={reverseReason}
                onChange={(e) => setReverseReason(e.target.value)}
                placeholder="Why is this being reversed?"
                autoFocus
              />
            </div>
          ) : null}
          {!isReadOnlyJournal(selected) ? (
            <div className="ua-journals-detail-page__footer">
              <div className="ua-journals-detail-actions__right">
                {String(selected.status).toLowerCase() === 'draft' ? (
                  <Button
                    type="button"
                    disabled={postMutation.isPending}
                    onClick={() => postMutation.mutate(selected.id)}
                  >
                    {postMutation.isPending ? 'Posting...' : 'Post journal'}
                  </Button>
                ) : null}
                {String(selected.status).toLowerCase() === 'posted' ? (
                  reverseOpen ? (
                    <>
                      <Button
                        type="button"
                        variant="danger"
                        disabled={reverseMutation.isPending || !reverseReason.trim()}
                        onClick={() => reverseMutation.mutate()}
                      >
                        {reverseMutation.isPending ? 'Reversing...' : 'Confirm reverse'}
                      </Button>
                      <Button
                        type="button"
                        variant="secondary"
                        disabled={reverseMutation.isPending}
                        onClick={() => {
                          setReverseOpen(false)
                          setReverseReason('')
                        }}
                      >
                        Cancel
                      </Button>
                    </>
                  ) : (
                    <Button type="button" variant="secondary" onClick={() => setReverseOpen(true)}>
                      Reverse
                    </Button>
                  )
                ) : null}
              </div>
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}
