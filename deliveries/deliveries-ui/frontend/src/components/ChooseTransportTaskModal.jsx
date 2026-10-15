import { useEffect } from 'react'
import { createPortal } from 'react-dom'
import { PackageOpen, Truck, X } from 'lucide-react'

/**
 * First step after "+ New Transport Task": pick Customer Delivery or Goods Collection.
 */
export default function ChooseTransportTaskModal({ open, onClose, onSelect }) {
  useEffect(() => {
    if (!open) return undefined
    const onKey = (e) => {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    document.body.classList.add('dlv-create-open')
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = prev
      document.body.classList.remove('dlv-create-open')
    }
  }, [open, onClose])

  if (!open) return null

  return createPortal(
    <div className="dlv-create-modal dlv-task-chooser" role="dialog" aria-modal="true" aria-labelledby="dlv-task-chooser-title">
      <button type="button" className="dlv-create-modal__backdrop" aria-label="Close" onClick={onClose} />
      <div className="dlv-create-modal__panel dlv-task-chooser__panel">
        <div className="dlv-create-modal__head">
          <h2 id="dlv-task-chooser-title">Choose Transport Task</h2>
          <button type="button" className="dlv-create-modal__close" onClick={onClose} aria-label="Close">
            <X size={18} aria-hidden="true" />
          </button>
        </div>
        <p className="dlv-task-chooser__sub">
          Select the type of transport task to create.
        </p>
        <div className="dlv-task-chooser__grid">
          <button
            type="button"
            className="dlv-task-chooser__card"
            onClick={() => onSelect('customer_delivery')}
          >
            <span className="dlv-task-chooser__icon dlv-task-chooser__icon--delivery" aria-hidden="true">
              <Truck size={26} strokeWidth={2} />
            </span>
            <span className="dlv-task-chooser__title">Customer Delivery</span>
            <span className="dlv-task-chooser__desc">
              Deliver goods from our office/store to a customer.
            </span>
            <span className="dlv-task-chooser__cta">Continue</span>
          </button>

          <button
            type="button"
            className="dlv-task-chooser__card"
            onClick={() => onSelect('goods_collection')}
          >
            <span className="dlv-task-chooser__icon dlv-task-chooser__icon--collection" aria-hidden="true">
              <PackageOpen size={26} strokeWidth={2} />
            </span>
            <span className="dlv-task-chooser__title">Goods Collection</span>
            <span className="dlv-task-chooser__desc">
              Collect goods from a supplier or another location and bring them to our office/store.
            </span>
            <span className="dlv-task-chooser__cta">Continue</span>
          </button>
        </div>
      </div>
    </div>,
    document.body,
  )
}
