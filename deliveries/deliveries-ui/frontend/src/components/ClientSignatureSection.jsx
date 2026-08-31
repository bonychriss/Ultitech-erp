import { useCallback, useEffect, useRef, useState } from 'react'
import {
  QrCode, Smartphone, Copy, CheckCircle2, Loader2, PenLine, MessageCircle, AlertCircle,
} from 'lucide-react'
import { CFG } from '../config.js'

function qrImageUrl(verifyUrl) {
  if (!verifyUrl) return ''
  return `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(verifyUrl)}`
}

export default function ClientSignatureSection({ order, csrfToken, onSigned }) {
  const [mode, setMode] = useState('qr')
  const [recipientName, setRecipientName] = useState(order.client_name || '')
  const [submitting, setSubmitting] = useState(false)
  const [copied, setCopied] = useState(false)
  const [error, setError] = useState('')
  const canvasRef = useRef(null)
  const padRef = useRef(null)

  const verifyUrl = order.verifyUrl || ''
  const orderId = order.id || CFG.orderId

  const initPad = useCallback(() => {
    const canvas = canvasRef.current
    const SignaturePad = window.SignaturePad
    if (!canvas || !SignaturePad) return

    const ratio = Math.max(window.devicePixelRatio || 1, 1)
    canvas.width = canvas.offsetWidth * ratio
    canvas.height = canvas.offsetHeight * ratio
    canvas.getContext('2d').scale(ratio, ratio)

    if (padRef.current) {
      padRef.current.off()
    }
    padRef.current = new SignaturePad(canvas, {
      backgroundColor: 'rgba(255, 255, 255, 0)',
      penColor: '#0f172a',
    })
  }, [])

  useEffect(() => {
    if (mode !== 'device' || order.isClientSigned) return undefined
    initPad()
    const onResize = () => initPad()
    window.addEventListener('resize', onResize)
    return () => window.removeEventListener('resize', onResize)
  }, [mode, order.isClientSigned, initPad])

  useEffect(() => {
    if (order.isClientSigned || !CFG.checkSignatureUrl || !orderId) return undefined

    let alive = true
    const poll = async () => {
      try {
        const res = await fetch(`${CFG.checkSignatureUrl}?order_id=${encodeURIComponent(orderId)}`, {
          headers: { Accept: 'application/json' },
        })
        const result = await res.json()
        if (!alive || !result?.ok || !result.data?.signed) return
        onSigned?.(
          result.data.order || {
            isClientSigned: true,
            signatureUrl: result.data.signatureUrl || '',
            recipient_name: result.data.recipientName || '',
            status: 'delivered',
          },
          result.data.documents,
        )
      } catch {
        /* ignore polling errors */
      }
    }

    const id = window.setInterval(poll, 3000)
    poll()
    return () => {
      alive = false
      window.clearInterval(id)
    }
  }, [order.isClientSigned, orderId, onSigned])

  async function copyLink() {
    if (!verifyUrl) return
    try {
      await navigator.clipboard.writeText(verifyUrl)
      setCopied(true)
      window.setTimeout(() => setCopied(false), 2000)
    } catch {
      setError('Could not copy link.')
    }
  }

  function shareWhatsApp() {
    if (!verifyUrl) return
    const text = `Please sign for your delivery here: ${verifyUrl}`
    window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank', 'noopener')
  }

  function clearSignature() {
    padRef.current?.clear()
  }

  async function submitDeviceSignature(e) {
    e.preventDefault()
    setError('')
    const name = recipientName.trim()
    if (!name) {
      setError('Recipient name is required.')
      return
    }
    if (!padRef.current || padRef.current.isEmpty()) {
      setError('Please ask the client to sign in the box.')
      return
    }
    if (!CFG.submitClientSignatureUrl) {
      setError('Signature submission is not configured.')
      return
    }

    setSubmitting(true)
    try {
      const fd = new FormData()
      fd.append('csrf_token', csrfToken || '')
      fd.append('order_id', String(orderId))
      fd.append('recipient_name', name)
      fd.append('signature_data', padRef.current.toDataURL())

      const res = await fetch(CFG.submitClientSignatureUrl, { method: 'POST', body: fd })
      const result = await res.json()
      if (!result?.ok) {
        setError(result?.error || 'Could not save signature.')
        return
      }
      const updated = result.data?.order
      if (updated) {
        onSigned?.(updated, result.data?.documents)
      } else {
        onSigned?.({
          isClientSigned: true,
          signatureUrl: result.data?.signatureUrl || '',
          recipient_name: name,
          status: 'delivered',
        }, result.data?.documents)
      }
    } catch {
      setError('Network error while saving signature.')
    } finally {
      setSubmitting(false)
    }
  }

  if (order.isClientSigned) {
    return (
      <>
        <div className="cv-sign-success">
          <CheckCircle2 size={22} aria-hidden="true" />
          <div>
            <strong>Signed by {order.recipient_name || 'Client'}</strong>
            <p>Delivery confirmed with client signature.</p>
          </div>
        </div>
        {order.signatureUrl && (
          <div className="cv-sign-preview">
            <img src={order.signatureUrl} alt="Client signature" />
          </div>
        )}
      </>
    )
  }

  return (
    <>
      <div className="cv-sign-tabs" role="tablist">
        <button
          type="button"
          role="tab"
          aria-selected={mode === 'qr'}
          className={mode === 'qr' ? 'cv-sign-tab cv-sign-tab--active' : 'cv-sign-tab'}
          onClick={() => setMode('qr')}
        >
          <QrCode size={16} aria-hidden="true" /> Scan QR code
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={mode === 'device'}
          className={mode === 'device' ? 'cv-sign-tab cv-sign-tab--active' : 'cv-sign-tab'}
          onClick={() => setMode('device')}
        >
          <Smartphone size={16} aria-hidden="true" /> Sign on this phone
        </button>
      </div>

      {mode === 'qr' && (
        <div className="cv-sign-qr-card">
          <div className="cv-sign-qr-grid">
            <div className="cv-sign-qr-visual">
              <span className="cv-sign-qr-badge">Digital handover</span>
              <h3 className="cv-sign-qr-title">Scan to sign</h3>
              {verifyUrl ? (
                <div className="cv-sign-qr-frame">
                  <img
                    className="cv-sign-qr-img"
                    src={qrImageUrl(verifyUrl)}
                    width={168}
                    height={168}
                    alt="QR code for client signature"
                  />
                </div>
              ) : (
                <p className="cv-muted-inline">Verification link is not available.</p>
              )}
            </div>

            {verifyUrl && (
              <div className="cv-sign-qr-side">
                <p className="cv-sign-qr-hint">
                  Ask the client to scan this code with their phone camera to view the delivery and sign.
                </p>

                <label className="cv-sign-share-label" htmlFor="od-verify-url">Signing link</label>
                <div className="cv-sign-share-row">
                  <input id="od-verify-url" type="text" readOnly value={verifyUrl} className="cv-input cv-sign-share-input" />
                  <button type="button" className="cv-sign-icon-btn" onClick={copyLink} title="Copy link">
                    {copied ? <CheckCircle2 size={16} /> : <Copy size={16} />}
                  </button>
                </div>

                <button type="button" className="cv-wa-btn" onClick={shareWhatsApp}>
                  <MessageCircle size={16} aria-hidden="true" /> Share via WhatsApp
                </button>

                <div className="cv-sign-wait">
                  <Loader2 size={16} className="cv-spin" aria-hidden="true" />
                  <span>Waiting for client to sign...</span>
                </div>
              </div>
            )}
          </div>
        </div>
      )}

      {mode === 'device' && (
        <form className="cv-sign-device" onSubmit={submitDeviceSignature}>
          <p className="cv-hint">Pass your phone to the client so they can sign below.</p>

          <div className="cv-row">
            <label className="cv-label" htmlFor="od-recipient">Received by</label>
            <div className="cv-field cv-field--narrow">
              <input
                id="od-recipient"
                type="text"
                className="cv-input"
                value={recipientName}
                onChange={(e) => setRecipientName(e.target.value)}
                placeholder="Client full name"
                required
              />
            </div>
          </div>

          <div className="cv-row cv-row--top">
            <span className="cv-label">Signature</span>
            <div className="cv-field">
              <div className="cv-sign-pad-wrap">
                <canvas ref={canvasRef} className="cv-sign-canvas" aria-label="Signature pad" />
                <button type="button" className="cv-sign-clear" onClick={clearSignature}>
                  Clear
                </button>
              </div>
            </div>
          </div>

          {error && <p className="cv-err" role="alert"><AlertCircle size={12} /> {error}</p>}

          <button type="submit" className="cv-btn-save" disabled={submitting}>
            {submitting ? <Loader2 size={16} className="cv-spin" aria-hidden="true" /> : <PenLine size={16} aria-hidden="true" />}
            {submitting ? 'Saving...' : 'Confirm client signature'}
          </button>
        </form>
      )}
    </>
  )
}
