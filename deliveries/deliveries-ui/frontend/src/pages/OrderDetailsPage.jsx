import { useEffect, useState } from 'react'
import {
  MapPin, CheckCircle2, AlertCircle,
  Loader2, X,
} from 'lucide-react'
import { CFG } from '../config.js'
import ClientSignatureSection from '../components/ClientSignatureSection.jsx'
import OrderDocumentsSection from '../components/OrderDocumentsSection.jsx'

const BASE_SECTIONS = [
  { id: 'od-delivery', label: 'Delivery' },
  { id: 'od-documents', label: 'Documents' },
  { id: 'od-signature', label: 'Client signature' },
]

function statusLabel(status) {
  const s = String(status || '').toLowerCase()
  if (!s) return 'Unknown'
  return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

function goListBack(e, fallback) {
  if (e) e.preventDefault()
  const dest = fallback || 'my_deliveries.php'
  if (window.erpNavBack && typeof window.erpNavBack.go === 'function') {
    if (window.erpNavBack.go(dest)) return
  }
  window.location.href = dest
}

export default function OrderDetailsPage() {
  const initial = CFG.data || {}
  const [data, setData] = useState(initial)
  const [loading, setLoading] = useState(!initial.order)
  const [flash, setFlash] = useState(initial.flash || null)
  const [activeSection, setActiveSection] = useState(BASE_SECTIONS[0].id)

  const order = data.order || {}
  const documents = data.documents || {}
  const urls = data.urls || {}
  const orderId = CFG.orderId || order.id

  const showDocumentsSection = documents.hasDocuments
    || !!(order.invoice_ref || order.sales_invoice_id)
  const sections = BASE_SECTIONS.filter((s) => {
    if (s.id === 'od-documents' && !showDocumentsSection) return false
    return true
  })

  useEffect(() => {
    if (initial.order) return undefined
    if (!CFG.orderDetailsInitUrl || !orderId) {
      setLoading(false)
      return undefined
    }
    let alive = true
    ;(async () => {
      try {
        const qs = `?order_id=${encodeURIComponent(orderId)}`
        const res = await fetch(`${CFG.orderDetailsInitUrl}${qs}`, { headers: { Accept: 'application/json' } })
        const payload = await res.json()
        if (alive && payload?.ok && payload.data) setData(payload.data)
        else if (alive) setFlash({ type: 'error', message: payload?.error || 'Could not load order.' })
      } catch {
        if (alive) setFlash({ type: 'error', message: 'Could not load order.' })
      } finally {
        if (alive) setLoading(false)
      }
    })()
    return () => { alive = false }
  }, [initial.order, orderId])

  useEffect(() => {
    if (loading || !order.id) return undefined
    const observer = new IntersectionObserver(
      (entries) => {
        const visible = entries
          .filter((e) => e.isIntersecting)
          .sort((a, b) => b.intersectionRatio - a.intersectionRatio)
        if (visible[0]) setActiveSection(visible[0].target.id)
      },
      { rootMargin: '-40% 0px -50% 0px', threshold: [0, 0.25, 0.5, 1] },
    )
    sections.forEach((s) => {
      const el = document.getElementById(s.id)
      if (el) observer.observe(el)
    })
    return () => observer.disconnect()
  }, [loading, order.id, sections])

  function scrollToSection(id) {
    const el = document.getElementById(id)
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  function handleClientSigned(updatedOrder, updatedDocuments) {
    setData((prev) => ({
      ...prev,
      order: { ...prev.order, ...updatedOrder, isClientSigned: true, isDriverAccomplished: true },
      documents: updatedDocuments || prev.documents,
    }))
    setFlash({ type: 'success', message: 'Client signature received.' })
  }

  if (loading && !data.order) {
    return (
      <div className="cv-shell">
        <div className="cv-loading" role="status">
          <Loader2 className="cv-spin" aria-hidden="true" />
          <span>Loading order...</span>
        </div>
      </div>
    )
  }

  if (!order.id) {
    return (
      <div className="cv-shell">
        <div className="cv-topbar">
          <h1>Order details</h1>
        </div>
        <div className="cv-success-panel">
          <AlertCircle size={40} color="#ef4444" aria-hidden="true" />
          <h2 style={{ color: '#991b1b' }}>Order not found</h2>
          <div className="cv-success-links">
            <a
              href={urls.dashboard || urls.myDeliveries || 'index'}
              onClick={(e) => goListBack(e, urls.dashboard || urls.myDeliveries || 'index')}
            >&larr; Back</a>
          </div>
        </div>
      </div>
    )
  }

  const title = order.invoice_ref ? `Order ${order.invoice_ref}` : `Order #${order.id}`

  return (
    <div className="cv-shell cv-shell--order-details">
      <div className="cv-topbar cv-topbar--split">
        <div>
          <h1>{title}</h1>
        </div>
        <a
          href={urls.dashboard || urls.myDeliveries || 'index'}
          className="cv-link-back"
          onClick={(e) => goListBack(e, urls.dashboard || urls.myDeliveries || 'index')}
        >
          &larr; Back
        </a>
      </div>

      {flash && (
        <div className={`cv-alert cv-alert--${flash.type === 'success' ? 'success' : 'error'}`} role="alert">
          {flash.type === 'success' ? <CheckCircle2 size={18} /> : <AlertCircle size={18} />}
          <div><span>{flash.message}</span></div>
          <button type="button" className="cv-alert-x" onClick={() => setFlash(null)} aria-label="Dismiss">
            <X size={16} />
          </button>
        </div>
      )}

      <div className="cv-layout">
        <nav className="cv-nav" aria-label="Order sections">
          {sections.map((s) => (
            <button
              key={s.id}
              type="button"
              className={`cv-nav-item${activeSection === s.id ? ' is-active' : ''}`}
              onClick={() => scrollToSection(s.id)}
            >
              {s.label}
            </button>
          ))}
        </nav>

        <div className="cv-main">
          <section id="od-delivery" className="cv-section">
            <header className="cv-section-head">
              <h2>Delivery information</h2>
              <p>
                Trip: {order.trip_ref || 'Unassigned'} &bull; {order.driver_name || 'No driver'} &bull; {statusLabel(order.status)}
              </p>
            </header>

            <div className="cv-info-grid">
              <div className="cv-info-item">
                <span className="cv-label">Client name</span>
                <div className="cv-value">{order.client_name || '-'}</div>
              </div>
              <div className="cv-info-item">
                <span className="cv-label">Client phone</span>
                <div className="cv-value">{order.client_phone || '-'}</div>
              </div>
              <div className="cv-info-item">
                <span className="cv-label">From</span>
                <div className="cv-value">{order.pickup_location || '-'}</div>
              </div>
              <div className="cv-info-item">
                <span className="cv-label">To</span>
                <div className="cv-value">{order.delivery_address || '-'}</div>
              </div>
              <div className="cv-info-item">
                <span className="cv-label">Route cost</span>
                <div className="cv-value">
                  {order.route_cost != null && order.route_cost !== ''
                    ? Number(order.route_cost).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                    : '-'}
                </div>
              </div>
              <div className="cv-info-item cv-info-item--full">
                <span className="cv-label">Package</span>
                <div className="cv-value">
                  {order.package_description || 'General goods'}
                  {order.package_weight ? ` (${order.package_weight})` : ''}
                </div>
              </div>
            </div>
          </section>

          {showDocumentsSection && (
            <section id="od-documents" className="cv-section">
              <header className="cv-section-head">
                <h2>Documents</h2>
                <p>Delivery note, invoice, and receipt for this delivery.</p>
              </header>
              <OrderDocumentsSection
                documents={documents}
                isClientSigned={order.isClientSigned}
                shareUrl={order.verifyUrl || documents.shareUrl || ''}
              />
            </section>
          )}

          <section id="od-signature" className="cv-section">
            <header className="cv-section-head">
              <h2>Client signature</h2>
              <p>Hand the phone to the client or let them scan the QR code on their own device.</p>
            </header>
            <ClientSignatureSection
              order={order}
              csrfToken={data.csrfToken}
              onSigned={handleClientSigned}
            />
          </section>

          <div className="cv-actions">
            <a
              href={urls.dashboard || urls.myDeliveries || 'index'}
              className="cv-btn-cancel"
              onClick={(e) => goListBack(e, urls.dashboard || urls.myDeliveries || 'index')}
            >
              Back
            </a>
            {order.trip_id > 0 && (
              <a href={`${urls.viewTrip || 'view_trip.php'}&trip_id=${order.trip_id}`} className="cv-btn-ghost">
                <MapPin size={15} aria-hidden="true" /> View trip
              </a>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
