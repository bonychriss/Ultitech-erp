/** Semi-transparent POSTED rubber-stamp overlay for voucher tables. */
export default function PostedStamp() {
  return (
    <div className="stamp-watermark posted-stamp" aria-label="Posted stamp">
      <div className="vv-posted-stamp">
        <span className="vv-posted-stamp__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.8" strokeLinecap="round" strokeLinejoin="round">
            <polyline points="20 6 9 17 4 12" />
          </svg>
        </span>
        <span className="vv-posted-stamp__text">POSTED</span>
      </div>
    </div>
  )
}
