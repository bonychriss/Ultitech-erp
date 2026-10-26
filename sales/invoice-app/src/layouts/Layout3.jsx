import { invoice, money, totals } from '../data/sampleInvoice.js'
import './Layout3.css'

const BG = `${import.meta.env.BASE_URL}bg-layout3.webp`

function lines(text) {
  return String(text)
    .split('\n')
    .map((line) => (
      <span key={line} className="l3-line">
        {line}
      </span>
    ))
}

/** Layout 3 — navy & orange swoosh frame (pre-made artwork + overlaid details) */
export default function Layout3() {
  const { subtotal, tax, discount, total } = totals()
  const taxPct = Math.round(invoice.taxRate * 100)
  const discPct = Math.round(invoice.discountRate * 100)

  return (
    <article className="l3">
      <img className="l3-bg" src={BG} alt="" draggable="false" />

      <div className="l3-content">
        <header className="l3-head">
          <div className="l3-brand">
            <p className="l3-brand-name">{invoice.brand}</p>
            <p className="l3-brand-sub">{lines(invoice.officeAddress)}</p>
            <p className="l3-brand-sub">{invoice.phone}</p>
          </div>
          <div className="l3-title-block">
            <h1 className="l3-title">INVOICE</h1>
            <p className="l3-meta">
              <span>No.</span> {invoice.number}
            </p>
            <p className="l3-meta">
              <span>Date</span> {invoice.date}
            </p>
          </div>
        </header>

        <section className="l3-billto">
          <p className="l3-label">Invoice to</p>
          <p className="l3-name">{invoice.to.name}</p>
          <p className="l3-muted">{lines(invoice.to.address)}</p>
        </section>

        <table className="l3-table">
          <thead>
            <tr>
              <th>Description</th>
              <th>Unit Price</th>
              <th>Qty</th>
              <th>Total</th>
            </tr>
          </thead>
          <tbody>
            {invoice.items.map((row, i) => (
              <tr key={`${row.name}-${i}`}>
                <td>
                  <p className="l3-item-name">{row.name}</p>
                  <p className="l3-item-detail">{row.detail}</p>
                </td>
                <td>{money(row.unitPrice)}</td>
                <td>{row.qty}</td>
                <td>{money(row.qty * row.unitPrice)}</td>
              </tr>
            ))}
          </tbody>
        </table>

        <div className="l3-bottom">
          <section className="l3-pay">
            <p className="l3-label">Payment info</p>
            <p className="l3-muted">
              <strong>Account :</strong> {invoice.payment.account}
            </p>
            <p className="l3-muted">
              <strong>A/C Name :</strong> {invoice.payment.name}
            </p>
            <p className="l3-muted">
              <strong>Bank :</strong> {invoice.payment.bank}
            </p>
            <p className="l3-label l3-label-gap">Terms</p>
            <p className="l3-muted l3-terms">{invoice.terms}</p>
          </section>

          <section className="l3-sums">
            <div className="l3-sum-row">
              <span>Subtotal</span>
              <strong>{money(subtotal)}</strong>
            </div>
            <div className="l3-sum-row">
              <span>Tax VAT {taxPct}%</span>
              <strong>{money(tax)}</strong>
            </div>
            <div className="l3-sum-row">
              <span>Discount {discPct}%</span>
              <strong>{money(discount)}</strong>
            </div>
            <div className="l3-sum-due">
              <span>Total due</span>
              <strong>{money(total)}</strong>
            </div>
          </section>
        </div>

        <footer className="l3-foot">
          <p className="l3-thanks">{invoice.thankYou}</p>
          <p className="l3-muted">
            {invoice.questions.email} · {invoice.questions.phone}
          </p>
        </footer>
      </div>
    </article>
  )
}
