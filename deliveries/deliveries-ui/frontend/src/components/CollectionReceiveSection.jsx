import { useState } from 'react'
import { AlertCircle, CheckCircle2, Loader2, PackageCheck } from 'lucide-react'
import { CFG } from '../config.js'

export default function CollectionReceiveSection({ order, collection, csrfToken, onReceived }) {
  const [qty, setQty] = useState(() => {
    if (collection?.expectedQuantity != null) return String(collection.expectedQuantity)
    return ''
  })
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')

  if (!collection) return null

  const progress = collection.progress || []
  const received = !!collection.received

  async function handleConfirm(e) {
    e.preventDefault()
    if (submitting || received) return
    const n = Number(qty)
    if (!Number.isFinite(n) || n < 0) {
      setError('Enter a valid received quantity.')
      return
    }
    const receiveUrl = CFG.confirmCollectionReceiveUrl
      || (CFG.submitClientSignatureUrl
        ? String(CFG.submitClientSignatureUrl).replace(/submit-client-signature\.php.*/i, 'confirm-collection-receive.php')
        : '')
      || (CFG.orderDetailsInitUrl
        ? String(CFG.orderDetailsInitUrl).replace(/order-details-init\.php.*/i, 'confirm-collection-receive.php')
        : '')
    if (!receiveUrl) {
      setError('Receive API is not configured.')
      return
    }
    setError('')
    setSubmitting(true)
    try {
      const fd = new FormData()
      fd.append('csrf_token', csrfToken || '')
      fd.append('order_id', String(order.id))
      fd.append('received_quantity', String(n))
      const res = await fetch(receiveUrl, { method: 'POST', body: fd })
      const result = await res.json()
      if (!result?.ok) {
        setError(result?.error || 'Could not confirm receipt.')
        return
      }
      if (typeof onReceived === 'function') {
        onReceived(result.data?.order, result.data?.collection, result.data?.message)
      }
    } catch {
      setError('Network error. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="dlv-collection-receive">
      {progress.length > 0 ? (
        <ol className="dlv-collection-progress" aria-label="Collection progress">
          {progress.map((step) => (
            <li
              key={step.key}
              className={`dlv-collection-progress__step${step.done ? ' is-done' : ''}${step.current ? ' is-current' : ''}`}
            >
              <span className="dlv-collection-progress__dot" aria-hidden="true" />
              <span className="dlv-collection-progress__label">{step.label}</span>
            </li>
          ))}
        </ol>
      ) : null}

      <div className="cv-info-grid" style={{ marginBottom: '1rem' }}>
        <div className="cv-info-item">
          <span className="cv-label">Expected quantity</span>
          <div className="cv-value">
            {collection.expectedQuantity != null ? collection.expectedQuantity : '—'}
          </div>
        </div>
        <div className="cv-info-item">
          <span className="cv-label">Received quantity</span>
          <div className="cv-value">
            {received && collection.receivedQuantity != null ? collection.receivedQuantity : '—'}
          </div>
        </div>
      </div>

      {received ? (
        <div className="cv-alert cv-alert--success" role="status">
          <CheckCircle2 size={18} aria-hidden="true" />
          <div>
            <strong>Receipt confirmed</strong>
            <span>
              Actual quantity recorded. Complete stock posting in procurement/stock receiving so inventory is not duplicated here.
            </span>
            {collection.stockReceiveUrl ? (
              <a href={collection.stockReceiveUrl} style={{ marginTop: 6, display: 'inline-block' }}>
                Open stock / purchases ?
              </a>
            ) : null}
          </div>
        </div>
      ) : collection.canConfirmReceive ? (
        <form className="dlv-collection-receive__form" onSubmit={handleConfirm}>
          {error ? (
            <div className="cv-alert cv-alert--error" role="alert">
              <AlertCircle size={16} aria-hidden="true" />
              <div><span>{error}</span></div>
            </div>
          ) : null}
          <label className="dlv-create-field">
            <span>Actual received quantity <em>*</em></span>
            <input
              className="cv-input"
              type="number"
              min="0"
              step="any"
              value={qty}
              onChange={(e) => setQty(e.target.value)}
              disabled={submitting}
              required
            />
          </label>
          <p className="dlv-task-chooser__hint" style={{ marginTop: '0.5rem' }}>
            Confirming receipt closes this transport task. It does not increase stock — use procurement/stock receiving for inventory.
          </p>
          <button type="submit" className="cv-btn-save" disabled={submitting} style={{ marginTop: '0.75rem' }}>
            {submitting
              ? <Loader2 size={16} className="dlv-spin" aria-hidden="true" />
              : <PackageCheck size={16} aria-hidden="true" />}
            Confirm received quantity
          </button>
        </form>
      ) : (
        <div className="cv-alert cv-alert--error" role="status" style={{ background: '#f8fafc', borderColor: '#e2e8f0', color: '#475569' }}>
          <AlertCircle size={16} aria-hidden="true" />
          <div>
            <span>
              Waiting for a storekeeper / warehouse user to confirm the actual quantity when goods arrive at the office/store.
            </span>
          </div>
        </div>
      )}
    </div>
  )
}
