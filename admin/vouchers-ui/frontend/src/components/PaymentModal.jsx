import { useEffect, useRef } from 'react'
import { X } from 'lucide-react'

function formatAmount(currency, amount) {
  const value = Number(amount) || 0
  return `${currency || ''} ${value.toLocaleString('en-US')}`.trim()
}

/**
 * Submits to the existing server-side payment handler with the SWIFT proof
 * file, so the payment/ledger logic is reused as-is.
 *
 * - mode "swift" (admin): posts to all-vouchers.php with mark_paid=1 + GET params.
 * - mode "account_swift" (employee): posts to mark-paid.php with a payment
 *   account selection + SWIFT proof.
 */
export default function PaymentModal({
  voucher,
  getParams,
  onClose,
  actionUrl = 'all-vouchers.php',
  mode = 'swift',
  accounts = [],
}) {
  const formRef = useRef(null)
  const needsAccount = mode === 'account_swift'

  useEffect(() => {
    function onKey(e) {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div className="pv-modal-overlay" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="pv-modal">
        <div className="pv-modal-head">
          <h3>Confirm Payment</h3>
          <button type="button" className="pv-modal-close" onClick={onClose} aria-label="Close">
            <X size={18} aria-hidden="true" />
          </button>
        </div>

        <div className="pv-modal-details">
          <div><strong>Voucher:</strong> {voucher.voucher_no}</div>
          <div><strong>Payee:</strong> {voucher.payee_name}</div>
          <div><strong>Amount:</strong> <span className="pv-modal-amount">{formatAmount(voucher.currency, voucher.total_amount)}</span></div>
        </div>

        <form
          ref={formRef}
          method="POST"
          action={actionUrl}
          encType="multipart/form-data"
        >
          <input type="hidden" name="voucher_id" value={voucher.id} />
          {!needsAccount && <input type="hidden" name="mark_paid" value="1" />}
          {!needsAccount && Object.entries(getParams || {}).map(([name, value]) => (
            <input key={name} type="hidden" name={name} value={value} />
          ))}

          {needsAccount && (
            <div className="pv-modal-field">
              <label>Select Payment Account (Required)</label>
              <select name="account_id" required defaultValue="">
                <option value="" disabled>-- Choose Account --</option>
                {accounts.map((acc) => (
                  <option key={acc.id} value={acc.id}>
                    {acc.name} ({acc.currency} {Number(acc.current_balance || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })})
                  </option>
                ))}
              </select>
            </div>
          )}

          <div className="pv-modal-field">
            <label>Attach SWIFT Proof (Required)</label>
            <input
              type="file"
              name="swift_file"
              required
              accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.svg,.bmp,.doc,.docx,.xls,.xlsx"
            />
            <div className="pv-modal-hint">Required proof of payment for this voucher.</div>
          </div>

          <div className="pv-modal-actions">
            <button type="button" className="pv-btn pv-btn-outline pv-btn-sm" onClick={onClose}>Cancel</button>
            <button type="submit" className="pv-btn pv-btn-success pv-btn-sm">Confirm &amp; Post</button>
          </div>
        </form>
      </div>
    </div>
  )
}
