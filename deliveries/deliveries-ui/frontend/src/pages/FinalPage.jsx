import { useState } from 'react'
import {
  CheckCircle2, FileText, Download, ExternalLink, Star, Loader2,
} from 'lucide-react'
import { CFG } from '../config.js'
import '../final.css'

function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.style.display = 'none'
  document.body.appendChild(a)
  a.click()
  a.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 1000)
}

const HTML2PDF_SRC = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js'

function stripDownloadParam(url) {
  try {
    const u = new URL(url, window.location.href)
    u.searchParams.delete('download')
    return u.pathname + u.search
  } catch {
    return url
      .replace(/([?&])download=1&/g, '$1')
      .replace(/([?&])download=1$/g, '')
      .replace(/\?$/, '')
  }
}

function loadHtml2Pdf() {
  if (window.html2pdf) return Promise.resolve(window.html2pdf)
  const existing = document.querySelector('script[data-html2pdf]')
  if (existing) {
    return new Promise((resolve, reject) => {
      existing.addEventListener('load', () => {
        if (window.html2pdf) resolve(window.html2pdf)
        else reject(new Error('PDF library failed to load.'))
      }, { once: true })
      existing.addEventListener('error', () => reject(new Error('PDF library failed to load.')), { once: true })
    })
  }
  return new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.src = HTML2PDF_SRC
    script.dataset.html2pdf = '1'
    script.onload = () => {
      if (window.html2pdf) resolve(window.html2pdf)
      else reject(new Error('PDF library failed to load.'))
    }
    script.onerror = () => reject(new Error('PDF library failed to load.'))
    document.head.appendChild(script)
  })
}

function waitForImages(root) {
  const imgs = [...root.querySelectorAll('img')]
  return Promise.all(imgs.map((img) => new Promise((resolve) => {
    if (img.complete) {
      resolve()
      return
    }
    img.onload = resolve
    img.onerror = resolve
    window.setTimeout(resolve, 5000)
  })))
}

async function backgroundPdfDownload(url, filename, selector) {
  let absoluteUrl
  try {
    absoluteUrl = new URL(stripDownloadParam(url), window.location.href).href
  } catch {
    throw new Error('Invalid document URL.')
  }

  let res
  try {
    res = await fetch(absoluteUrl, { credentials: 'same-origin' })
  } catch {
    throw new Error('Network error. Check your connection and try again.')
  }

  const html = await res.text()
  if (!res.ok) {
    const errText = html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()
    throw new Error(errText.slice(0, 140) || `Could not load document (${res.status}).`)
  }

  const parsed = new DOMParser().parseFromString(html, 'text/html')
  const source = parsed.querySelector(selector)
  if (!source) {
    const errText = parsed.body?.textContent?.replace(/\s+/g, ' ').trim() || ''
    throw new Error(errText.slice(0, 140) || 'Document content not found.')
  }

  const wrapper = document.createElement('div')
  wrapper.style.cssText = 'position:fixed;left:-10000px;top:0;width:210mm;background:#fff;'

  parsed.querySelectorAll('style, link[rel="stylesheet"]').forEach((node) => {
    if (node.tagName === 'LINK') {
      const link = node.cloneNode(true)
      const href = link.getAttribute('href')
      if (href) {
        try {
          link.href = new URL(href, absoluteUrl).href
        } catch {
          /* keep original */
        }
      }
      wrapper.appendChild(link)
    } else {
      wrapper.appendChild(node.cloneNode(true))
    }
  })

  wrapper.classList.add('pdf-export-root')
  wrapper.querySelectorAll('style').forEach((styleEl) => {
    styleEl.textContent = styleEl.textContent.replace(/body\.pdf-mode/g, '.pdf-export-root')
  })

  const clone = source.cloneNode(true)
  clone.querySelectorAll('[src]').forEach((el) => {
    const src = el.getAttribute('src')
    if (!src) return
    try {
      el.src = new URL(src, absoluteUrl).href
    } catch {
      /* keep original */
    }
  })

  wrapper.appendChild(clone)
  document.body.appendChild(wrapper)

  try {
    await waitForImages(clone)
    await new Promise((r) => { window.setTimeout(r, 400) })

    const html2pdf = await loadHtml2Pdf()
    await html2pdf().set({
      margin: 0,
      filename,
      image: { type: 'jpeg', quality: 0.98 },
      html2canvas: { scale: 2, useCORS: true, scrollY: 0 },
      jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
      pagebreak: { mode: ['css', 'legacy'] },
    }).from(clone).save()
  } finally {
    wrapper.remove()
  }
}

async function downloadDocument(doc) {
  const filename = doc.filename || 'download.pdf'
  const mode = doc.downloadMode || 'file'

  if (mode === 'pdf_page') {
    const selector = doc.pdfSelector || '#pdf-content'
    await backgroundPdfDownload(doc.url, filename, selector)
    return
  }

  let res
  try {
    res = await fetch(doc.url, { credentials: 'same-origin' })
  } catch {
    throw new Error('Network error. Check your connection and try again.')
  }
  if (!res.ok) {
    throw new Error(`Download failed (${res.status}). The file may be missing or access was denied.`)
  }
  const blob = await res.blob()
  if (!blob || blob.size === 0) {
    throw new Error('Download failed. The file is empty or unavailable.')
  }
  saveBlob(blob, filename)
}

function DocDownloadButton({ doc }) {
  const [status, setStatus] = useState('idle') // idle | downloading | success | error
  const [errorText, setErrorText] = useState('')

  const tones = {
    blue: 'dlv-final-doc--blue',
    green: 'dlv-final-doc--green',
    purple: 'dlv-final-doc--purple',
  }

  async function handleClick() {
    if (status === 'downloading') return
    setStatus('downloading')
    setErrorText('')
    try {
      await downloadDocument(doc)
      setStatus('success')
    } catch (err) {
      setStatus('error')
      setErrorText(err?.message || 'Download failed. Please try again.')
    }
  }

  const subtitle = status === 'downloading'
    ? 'Downloading'
    : status === 'success'
      ? 'Download complete'
      : status === 'error'
        ? (errorText || 'Download failed. Tap to try again.')
        : doc.subtitle

  const toneClass = tones[doc.tone] || tones.blue

  return (
    <button
      type="button"
      className={`dlv-final-doc dlv-final-doc-btn ${toneClass} dlv-final-doc--${status}`}
      onClick={handleClick}
      disabled={status === 'downloading'}
      aria-busy={status === 'downloading'}
    >
      <div className="dlv-final-doc-main">
        <FileText size={16} aria-hidden="true" />
        <div>
          <strong>{doc.label}</strong>
          <span>{subtitle}</span>
        </div>
      </div>
      {status === 'downloading' && (
        <Loader2 size={16} className="dlv-final-spin" aria-hidden="true" />
      )}
      {status === 'success' && (
        <CheckCircle2 size={16} className="dlv-final-doc-check" aria-hidden="true" />
      )}
      {(status === 'idle' || status === 'error') && (
        <Download size={14} aria-hidden="true" />
      )}
      {status === 'downloading' && (
        <span className="dlv-final-doc-progress" aria-hidden="true">
          <span className="dlv-final-doc-progress-fill" />
        </span>
      )}
    </button>
  )
}

function StarRating({ value, onChange, disabled }) {
  return (
    <div className="dlv-final-stars" role="radiogroup" aria-label="Service rating">
      {[5, 4, 3, 2, 1].map((star) => (
        <button
          key={star}
          type="button"
          role="radio"
          aria-checked={value === star}
          className={`dlv-final-star ${value >= star ? 'is-active' : ''}`}
          onClick={() => !disabled && onChange(star)}
          disabled={disabled}
        >
          <Star size={28} fill={value >= star ? 'currentColor' : 'none'} aria-hidden="true" />
        </button>
      ))}
    </div>
  )
}

export default function FinalPage() {
  const initial = CFG.data || {}
  const [brand] = useState(initial.brand || {})
  const [message] = useState(initial.message || '')
  const [documents] = useState(initial.documents || [])
  const [feedback, setFeedback] = useState(initial.feedback || { saved: false, canSubmit: true, rating: null })
  const [rating, setRating] = useState(0)
  const [comment, setComment] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')

  const websiteLabel = (brand.website || '').replace(/^https?:\/\//i, '')

  async function submitFeedback(e) {
    e.preventDefault()
    if (!CFG.submitFeedbackUrl || !initial.hash) return
    if (rating < 1) {
      setError('Please select a star rating.')
      return
    }
    setSubmitting(true)
    setError('')
    try {
      const body = new URLSearchParams({
        hash: initial.hash,
        rating: String(rating),
        feedback: comment,
      })
      const res = await fetch(CFG.submitFeedbackUrl, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
      })
      const result = await res.json()
      if (!result?.ok) {
        setError(result?.error || 'Could not submit feedback.')
        return
      }
      setFeedback(result.data?.feedback || { saved: true, canSubmit: false, rating })
    } catch {
      setError('Could not submit feedback. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="dlv-final-page">
      <article className="dlv-final-box">
        <header className="dlv-final-brand">
          {brand.logoUrl && (
            <img
              src={brand.logoUrl}
              alt=""
              onError={(e) => { e.currentTarget.style.display = 'none' }}
            />
          )}
          <h1>{brand.name || 'Company'}</h1>
        </header>

        <div className="dlv-final-success-icon" aria-hidden="true">
          <CheckCircle2 size={22} />
        </div>
        <h2>Delivery Verified!</h2>
        <p className="dlv-final-lead">{message}</p>

        {documents.length > 0 && (
          <section className="dlv-final-section">
            <h3>Attached documents</h3>
            {documents.map((doc) => (
              <DocDownloadButton key={`${doc.type}-${doc.url}`} doc={doc} />
            ))}
          </section>
        )}

        {feedback.saved ? (
          <div className="dlv-final-feedback-saved">
            Your feedback has been received. Thank you!
          </div>
        ) : feedback.canSubmit ? (
          <section className="dlv-final-section">
            <form className="dlv-final-feedback" onSubmit={submitFeedback}>
              <h3>Rate our service</h3>
              <StarRating value={rating} onChange={setRating} disabled={submitting} />
              <textarea
                value={comment}
                onChange={(e) => setComment(e.target.value)}
                placeholder="Any additional comments? (Optional)"
                rows={3}
                disabled={submitting}
              />
              {error && <p className="dlv-final-error">{error}</p>}
              <button type="submit" className="dlv-final-submit-btn" disabled={submitting || rating < 1}>
                {submitting ? <Loader2 size={14} className="dlv-final-spin" aria-hidden="true" /> : null}
                Submit feedback
              </button>
            </form>
          </section>
        ) : null}

        <footer className="dlv-final-footer">
          <p>You may now close this window.</p>
          {brand.website && (
            <p>
              Visit our site at{' '}
              <a href={brand.website} target="_blank" rel="noopener noreferrer">
                {websiteLabel}
                <ExternalLink size={10} aria-hidden="true" />
              </a>
            </p>
          )}
        </footer>
      </article>
    </div>
  )
}
