/** Semi-transparent PAID rubber-stamp overlay for voucher tables. */
export default function PaidStamp() {
  return (
    <div className="stamp-watermark paid-stamp" aria-label="Paid stamp">
      <div className="vv-paid-stamp">
        <span className="vv-paid-stamp__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.8" strokeLinecap="round" strokeLinejoin="round">
            <polyline points="20 6 9 17 4 12" />
          </svg>
        </span>
        <span className="vv-paid-stamp__text">PAID</span>
      </div>
    </div>
  )
}
