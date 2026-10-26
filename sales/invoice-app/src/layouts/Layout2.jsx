import { brandPurple as d } from '../data/sampleInvoice.js'
import './Layout2.css'

const usd = (n) =>
  `$${Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

function IconPhone() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        fill="currentColor"
        d="M6.6 10.8a15.5 15.5 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.24c1.1.37 2.3.56 3.5.56a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.2.19 2.4.56 3.5a1 1 0 0 1-.25 1L6.6 10.8z"
      />
    </svg>
  )
}

function IconWeb() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        fill="currentColor"
        d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm7.9 9h-3.1a15 15 0 0 0-1.3-5 8.05 8.05 0 0 1 4.4 5zM12 4c.9 1.3 1.8 3.3 2.2 5.5H9.8C10.2 7.3 11.1 5.3 12 4zM4.1 11h3.1a15 15 0 0 1 1.3-5 8.05 8.05 0 0 0-4.4 5zM7.2 13H4.1a8.05 8.05 0 0 0 4.4 5 15 15 0 0 1-1.3-5zm2.6 0h4.4c-.4 2.2-1.3 4.2-2.2 5.5-.9-1.3-1.8-3.3-2.2-5.5zm6.2 0h3.1a8.05 8.05 0 0 1-4.4 5 15 15 0 0 0 1.3-5z"
      />
    </svg>
  )
}

/** Layout 2 — purple Brand Name invoice */
export default function Layout2() {
  const subtotal = d.items.reduce((s, r) => s + r.qty * r.price, 0)
  const vat = subtotal * d.vatRate
  const total = subtotal + vat

  return (
    <article className="l2">
      <div className="l2-foot-art" aria-hidden="true" />

      <header className="l2-head">
        <div className="l2-brand">
          <span className="l2-mark">{d.initial}</span>
          <div>
            <p className="l2-brand-name">{d.brand}</p>
            <p className="l2-brand-tag">{d.tagline}</p>
          </div>
        </div>
        <div className="l2-title-block">
          <h1 className="l2-title">INVOICE</h1>
          <p className="l2-date">Date: {d.date}</p>
          <p className="l2-no">{d.invoiceNo}</p>
        </div>
      </header>

      <section className="l2-to">
        <p className="l2-to-label">TO:</p>
        <p className="l2-to-name">{d.to.name}</p>
        {d.to.address.map((line) => (
          <p key={line} className="l2-muted">
            {line}
          </p>
        ))}
      </section>

      <table className="l2-table">
        <thead>
          <tr>
            <th className="col-sl">SL</th>
            <th className="col-desc">DESCRIPTION</th>
            <th className="col-num">PRICE</th>
            <th className="col-qty">QUANTITY</th>
            <th className="col-num">AMOUNT</th>
          </tr>
        </thead>
        <tbody>
          {d.items.map((row, i) => (
            <tr key={`${row.name}-${i}`}>
              <td className="col-sl">{String(i + 1).padStart(2, '0')}</td>
              <td className="col-desc">{row.name}</td>
              <td className="col-num">{usd(row.price)}</td>
              <td className="col-qty">{row.qty}</td>
              <td className="col-num">{usd(row.qty * row.price)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <div className="l2-after">
        <div className="l2-thanks-block">
          <p className="l2-thanks">“{d.thanks}”</p>
          <p className="l2-contact">
            <span className="l2-ico">
              <IconPhone />
            </span>
            {d.phone}
            <span className="l2-ico l2-ico-web">
              <IconWeb />
            </span>
            {d.web}
          </p>
        </div>
        <div className="l2-sums">
          <div className="l2-sum-row">
            <span>Subtotal</span>
            <strong>{usd(subtotal)}</strong>
          </div>
          <div className="l2-sum-row">
            <span>VAT ({Math.round(d.vatRate * 100)}%)</span>
            <strong>{usd(vat)}</strong>
          </div>
          <div className="l2-total">
            <span>TOTAL</span>
            <strong>{usd(total)}</strong>
          </div>
        </div>
      </div>

      <div className="l2-bottom">
        <div className="l2-lower-left">
          <section className="l2-pay">
            <p className="l2-label">Payment Method</p>
            <p>
              <span>Account No</span> {d.payment.account}
            </p>
            <p>
              <span>Account Name</span> {d.payment.name}
            </p>
            <p>
              <span>Account Code</span> {d.payment.code}
            </p>
          </section>
          <div className="l2-sign">
            <p className="l2-sign-script">{d.signer.name}</p>
            <p className="l2-sign-name">{d.signer.name}</p>
            <p className="l2-sign-role">{d.signer.role}</p>
          </div>
        </div>
        <section className="l2-terms">
          <p className="l2-label">TERMS &amp; CONDITIONS</p>
          <p>{d.terms}</p>
        </section>
      </div>
    </article>
  )
}
