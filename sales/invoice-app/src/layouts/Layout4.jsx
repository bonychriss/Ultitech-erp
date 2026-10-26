import { pixelMart as d } from '../data/sampleInvoice.js'
import './Layout4.css'

const BG = `${import.meta.env.BASE_URL}bg-layout4.webp`

const usd = (n) => `$${Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 })}`

function LogoMark() {
  return (
    <svg className="l4-logo-mark" viewBox="0 0 48 48" aria-hidden="true">
      <rect x="2" y="2" width="44" height="44" rx="11" fill="#e8232a" />
      <path
        d="M17 36V13h9.5c5.2 0 8.5 3 8.5 7.6s-3.3 7.6-8.5 7.6H22.4V36H17zm5.4-12.3h3.7c2.3 0 3.6-1.2 3.6-3.1s-1.3-3.1-3.6-3.1h-3.7v6.2z"
        fill="#fff"
      />
    </svg>
  )
}

/** Item thumbnail: shows the image if given, otherwise a neat placeholder. */
function ItemThumb({ src, name }) {
  if (src) {
    return <img className="l4-thumb" src={src} alt={name} loading="lazy" />
  }
  return (
    <span className="l4-thumb l4-thumb-empty" aria-label={`${name} image`}>
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <rect x="3" y="4" width="18" height="16" rx="2" fill="none" stroke="currentColor" strokeWidth="1.6" />
        <circle cx="9" cy="9.5" r="1.8" fill="currentColor" />
        <path d="M5 18l4.5-5 3 3.2 2.5-2.7L19 18z" fill="currentColor" />
      </svg>
    </span>
  )
}

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

function IconPin() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        fill="currentColor"
        d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5z"
      />
    </svg>
  )
}

/** Layout 4 — Pixel Mart red & black (vector frame + overlaid details) */
export default function Layout4() {
  const subtotal = d.items.reduce((s, r) => s + r.qty * r.price, 0)
  const vat = subtotal * d.vatRate
  const discount = subtotal * d.discountRate
  const grand = subtotal + vat - discount

  return (
    <article className="l4">
      <img className="l4-bg" src={BG} alt="" draggable="false" />

      <div className="l4-content">
        {/* Header */}
        <header className="l4-head">
          <div className="l4-logo">
            <LogoMark />
            <div>
              <p className="l4-logo-name">{d.brand}</p>
              <p className="l4-logo-tag">{d.tagline}</p>
            </div>
          </div>
          <h1 className="l4-title">INVOICE</h1>
        </header>

        {/* Bill to + meta */}
        <div className="l4-meta">
          <section className="l4-to">
            <p className="l4-to-label">Invoice To:</p>
            <p className="l4-to-name">{d.to.name}</p>
            <p className="l4-to-role">{d.to.role}</p>
            <p className="l4-to-line">
              <span>P :</span> {d.to.phone}
            </p>
            <p className="l4-to-line">
              <span>E :</span> {d.to.email}
            </p>
          </section>
          <section className="l4-ids">
            <p className="l4-no">INVOICE NO:{d.invoiceNo}</p>
            <div className="l4-id-row">
              <span>Account No</span>
              <strong>{d.accountNo}</strong>
            </div>
            <div className="l4-id-row">
              <span>Invoice Date</span>
              <strong>{d.date}</strong>
            </div>
          </section>
        </div>

        {/* Items */}
        <table className="l4-table">
          <thead>
            <tr>
              <th className="th-dark">Image</th>
              <th className="th-red">Item description</th>
              <th className="th-dark">Quantity</th>
              <th className="th-red">Unite Price</th>
              <th className="th-dark">Total Price</th>
            </tr>
          </thead>
          <tbody>
            {d.items.map((row, i) => (
              <tr key={`${row.name}-${i}`}>
                <td>
                  <ItemThumb src={row.image} name={row.name} />
                </td>
                <td>
                  <p className="l4-item-name">{row.name}</p>
                  <p className="l4-item-detail">{row.detail}</p>
                </td>
                <td>{String(row.qty).padStart(2, '0')}</td>
                <td>{usd(row.price)}</td>
                <td>{usd(row.qty * row.price)}</td>
              </tr>
            ))}
          </tbody>
        </table>

        {/* Payment + totals */}
        <div className="l4-bottom">
          <section className="l4-pay">
            <p className="l4-pay-title">Payment method</p>
            <div className="l4-pay-row">
              <span>Account</span>
              <strong>{d.payment.account}</strong>
            </div>
            <div className="l4-pay-row">
              <span>Swift</span>
              <strong>{d.payment.swift}</strong>
            </div>
            <div className="l4-pay-row">
              <span>Payoneer</span>
              <strong>{d.payment.payoneer}</strong>
            </div>
          </section>

          <section className="l4-sums">
            <div className="l4-sum-row">
              <span>Sub Total</span>
              <strong>{usd(subtotal)}</strong>
            </div>
            <div className="l4-sum-row">
              <span>Vat &amp; Tax {Math.round(d.vatRate * 100)}%</span>
              <strong>{usd(vat)}</strong>
            </div>
            <div className="l4-sum-row">
              <span>Discount {Math.round(d.discountRate * 100)}%</span>
              <strong>{usd(discount)}</strong>
            </div>
            <div className="l4-grand">
              <span>Grand Total</span>
              <strong>{usd(grand)}</strong>
            </div>
          </section>
        </div>

        {/* Terms + thanks + signature */}
        <div className="l4-terms-row">
          <div>
            <p className="l4-terms">
              <strong>Terms &amp; Conditions:</strong> {d.terms}
            </p>
            <p className="l4-thanks">{d.thanks}</p>
          </div>
          <div className="l4-sign">
            <p className="l4-sign-script">{d.signer.name.split(' ').map((w) => w[0] + w.slice(1).toLowerCase()).join(' ')}</p>
            <p className="l4-sign-name">{d.signer.name}</p>
            <p className="l4-sign-role">{d.signer.role}</p>
          </div>
        </div>

        {/* Footer contacts */}
        <footer className="l4-foot">
          <p>
            <span className="l4-ico">
              <IconPhone />
            </span>
            {d.footer.phone}
          </p>
          <p>
            <span className="l4-ico">
              <IconWeb />
            </span>
            {d.footer.web}
          </p>
          <p>
            <span className="l4-ico">
              <IconPin />
            </span>
            {d.footer.address}
          </p>
        </footer>
      </div>
    </article>
  )
}
