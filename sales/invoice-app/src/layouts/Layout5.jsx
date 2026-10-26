import { brandYellow as d } from '../data/sampleInvoice.js'
import './Layout5.css'

const BG = `${import.meta.env.BASE_URL}bg-layout5.webp`

const usd = (n) =>
  `$${Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

function LogoMark() {
  return (
    <svg className="l5-logo-mark" viewBox="0 0 48 48" aria-hidden="true">
      <rect x="3" y="3" width="42" height="42" rx="6" fill="none" stroke="currentColor" strokeWidth="2.4" />
      <path d="M14 30 L24 16 L34 30" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  )
}

/** Layout 5 — yellow-bar Brand Name (original artwork + overlaid details) */
export default function Layout5() {
  const subtotal = d.items.reduce((s, r) => s + r.qty * r.price, 0)
  const tax = subtotal * d.taxRate
  const total = subtotal + tax

  return (
    <article className="l5">
      <img className="l5-bg" src={BG} alt="" draggable="false" />

      <div className="l5-content">
        <header className="l5-brand">
          <LogoMark />
          <div>
            <p className="l5-brand-name">{d.brand}</p>
            <p className="l5-brand-tag">{d.tagline}</p>
          </div>
        </header>

        <div className="l5-title-band">
          <h1 className="l5-title">INVOICE</h1>
        </div>

        <div className="l5-meta">
          <section>
            <p className="l5-to-label">Invoice to:</p>
            <p className="l5-to-name">{d.to.name}</p>
            {d.to.address.map((line) => (
              <p key={line} className="l5-muted">
                {line}
              </p>
            ))}
          </section>
          <section className="l5-ids">
            <div className="l5-id-row">
              <span>Invoice#</span>
              <strong>{d.invoiceNo}</strong>
            </div>
            <div className="l5-id-row">
              <span>Date</span>
              <strong>{d.date}</strong>
            </div>
          </section>
        </div>

        <table className="l5-table">
          <thead>
            <tr>
              <th className="col-sl">SL.</th>
              <th className="col-desc">Item Description</th>
              <th className="col-num">Price</th>
              <th className="col-qty">Qty.</th>
              <th className="col-num">Total</th>
            </tr>
          </thead>
          <tbody>
            {d.items.map((row, i) => (
              <tr key={`${row.name}-${i}`}>
                <td className="col-sl">{i + 1}</td>
                <td className="col-desc">{row.name}</td>
                <td className="col-num">{usd(row.price)}</td>
                <td className="col-qty">{row.qty}</td>
                <td className="col-num">{usd(row.qty * row.price)}</td>
              </tr>
            ))}
          </tbody>
        </table>

        <div className="l5-mid">
          <div>
            <p className="l5-thanks">{d.thanks}</p>
            <p className="l5-terms-label">Terms &amp; Conditions</p>
            <p className="l5-terms">{d.terms}</p>
          </div>
          <div className="l5-sums">
            <div className="l5-sum-row">
              <span>Sub Total:</span>
              <strong>{usd(subtotal)}</strong>
            </div>
            <div className="l5-sum-row">
              <span>Tax:</span>
              <strong>{usd(tax)}</strong>
            </div>
            <div className="l5-total">
              <span>Total:</span>
              <strong>{usd(total)}</strong>
            </div>
          </div>
        </div>

        <section className="l5-pay">
          <p className="l5-pay-title">Payment Info:</p>
          <p>
            <span>Account #:</span> {d.payment.account}
          </p>
          <p>
            <span>A/C Name:</span> {d.payment.name}
          </p>
          <p>
            <span>Bank Details:</span> {d.payment.bank}
          </p>
        </section>

        <p className="l5-sign">{d.sign}</p>

        <footer className="l5-foot">
          <p>
            {d.footer.phone} <span>|</span> {d.footer.address} <span>|</span> {d.footer.web}
          </p>
        </footer>
      </div>
    </article>
  )
}
