import { fmtDate } from '../utils/format.js'
import PaidStamp from './PaidStamp.jsx'
import PostedStamp from './PostedStamp.jsx'

function cellStyle(extra = {}) {
  return { border: '1px solid #000', padding: '6px 8px', ...extra }
}

export default function VoucherPreview({ data }) {
  const { voucher, items, approvalSlots, media, status } = data
  const preparedBy = voucher.prepared_by || voucher.creator_name || 'N/A'

  return (
    <div
      className="voucher-container voucher-paper"
      id="voucherFull"
      style={{
        width: '100%',
        maxWidth: 'none',
        margin: 0,
        fontFamily: '"Courier New", Courier, monospace',
        position: 'relative',
      }}
    >
      <div
        className="pv-header"
        style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-start', marginBottom: 20 }}
      >
        <img
          src={media.logoUrl}
          alt="Logo"
          style={{ maxHeight: 60, maxWidth: 140, objectFit: 'contain', marginRight: 40 }}
          onError={(e) => { e.currentTarget.onerror = null; e.currentTarget.src = media.fallbackLogoUrl }}
        />
        <h1 style={{ fontSize: 24, fontWeight: 'bold', margin: 0, color: '#000', letterSpacing: 2, flexGrow: 1, textAlign: 'center', textTransform: 'uppercase' }}>
          PAYMENT VOUCHER
        </h1>
      </div>

      <div className="tables-wrap">
        {status.isPosted && <PostedStamp />}
        {status.isPaid && !status.isPosted && <PaidStamp />}

        {status.showAnomaly && (
          <div className="no-print" style={{ margin: '10px 0', padding: '10px 12px', background: '#fef3c7', border: '1px solid #f59e0b', fontSize: 12, color: '#92400e', maxWidth: 860 }}>
            Anomaly: This voucher is marked <strong>PAID</strong> before a valid admin approval.
          </div>
        )}

        <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: 10, border: '1px solid #000' }}>
          <colgroup>
            <col style={{ width: '18%' }} />
            <col style={{ width: '32%' }} />
            <col style={{ width: '18%' }} />
            <col style={{ width: '32%' }} />
          </colgroup>
          <tbody>
            <tr>
              <td style={cellStyle()}>Voucher NO. :</td>
              <td style={cellStyle()}>{voucher.voucher_no}</td>
              <td style={cellStyle()}>Date:</td>
              <td style={cellStyle()}>{fmtDate(voucher.date_created)}</td>
            </tr>
            <tr>
              <td style={cellStyle()}>Payee Name:</td>
              <td style={cellStyle()}>{voucher.payee_name}</td>
              <td style={cellStyle()}>Prepared By:</td>
              <td style={cellStyle()}>{String(preparedBy).toUpperCase()}</td>
            </tr>
            <tr>
              <td style={cellStyle()}>Description:</td>
              <td style={cellStyle()}>{voucher.description}</td>
              <td style={cellStyle()}>Supporting Documents (Qty.)</td>
              <td style={cellStyle()}>{voucher.supporting_documents || '0'}</td>
            </tr>
            <tr>
              <td style={cellStyle()}>Currency:</td>
              <td style={cellStyle()}>{voucher.currency}</td>
              <td style={cellStyle()}>Amount:</td>
              <td style={{ ...cellStyle(), textAlign: 'right' }}>
                {Number(voucher.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
              </td>
            </tr>
          </tbody>
        </table>

        <table style={{ width: '100%', borderCollapse: 'collapse', marginBottom: 10, border: '1px solid #000' }}>
          <colgroup>
            <col style={{ width: '18%' }} />
            <col style={{ width: '18%' }} />
            <col style={{ width: '20%' }} />
            <col style={{ width: '14%' }} />
            <col style={{ width: '30%' }} />
          </colgroup>
          <thead>
            <tr>
              {['Payment Type', 'Budget Type', 'Name', 'Amount', 'Description'].map((h) => (
                <th key={h} style={{ ...cellStyle(), textAlign: h === 'Amount' ? 'right' : 'left' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {items.map((item, i) => (
              <tr key={i}>
                <td style={cellStyle()}>{item.payment_type}</td>
                <td style={cellStyle()}>{item.budget_type}</td>
                <td style={cellStyle()}>{item.name}</td>
                <td style={{ ...cellStyle(), textAlign: 'right' }}>{Number(item.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                <td style={cellStyle()}>{item.description}</td>
              </tr>
            ))}
          </tbody>
        </table>

        <table className="vv-approvals-table" style={{ width: '100%', borderCollapse: 'collapse', tableLayout: 'fixed', marginTop: 10, marginBottom: 20, border: '1px solid #000' }}>
          <colgroup>
            <col className="vv-approval-col-label" />
            <col className="vv-approval-col-value" />
            <col className="vv-approval-col-label" />
            <col className="vv-approval-col-value" />
          </colgroup>
          <tbody>
            {[[0, 1], [2, 3]].map((pair) => (
              <tr key={pair.join('-')} className="vv-approval-grid-row">
                {pair.map((idx) => {
                  const slot = approvalSlots[idx]
                  if (!slot) return null
                  return [
                    <td key={`${idx}-l`} className="vv-approval-label">{slot.label}</td>,
                    <td key={`${idx}-v`} className="vv-approval-value">
                      <div className="vv-approval-signatory">
                        <div className="vv-approval-name-wrap">
                          {slot.name ? <span className="vv-approval-name">{slot.name}</span> : null}
                          {slot.approved && slot.name && slot.sig ? (
                            <img src={slot.sig} alt="Signature" className="vv-approval-signature vv-approval-signature--inline" style={{ maxHeight: 40, width: 'auto' }} onError={(e) => { e.currentTarget.style.display = 'none' }} />
                          ) : null}
                        </div>
                      </div>
                    </td>,
                  ]
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
