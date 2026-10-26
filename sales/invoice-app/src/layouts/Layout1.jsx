import { invoice, money, totals } from '../data/sampleInvoice.js'
import './Layout1.css'

function Logo() {
  return (
    <svg className="l1-logo" viewBox="0 0 72 64" aria-hidden="true">
      <path
        d="M38 8c-14 0-26 11-26 24s12 24 26 24c8.2 0 15.5-3.6 20.2-9.3l-7.4-6.2C47.5 44.8 43 47.2 38 47.2c-8.4 0-15.2-6.8-15.2-15.2S29.6 16.8 38 16.8c5 0 9.5 2.4 12.4 6.3l7.4-6.2C53.5 11.6 46.2 8 38 8z"
        fill="currentColor"
      />
      <path
        d="M52 14c-10.5 0-19 8.5-19 19s8.5 19 19 19c6 0 11.3-2.6 15-6.8l-5.8-4.8c-2.2 2.5-5.5 4.1-9.2 4.1-6.6 0-12-5.4-12-12s5.4-12 12-12c3.7 0 7 1.6 9.2 4.1l5.8-4.8C63.3 16.6 58 14 52 14z"
        fill="currentColor"
        opacity="0.55"
      />
    </svg>
  )
}

function lines(text) {
  return String(text)
    .split('\n')
    .map((line) => (
      <span key={line} className="l1-line">
        {line}
      </span>
    ))
}

/** Layout 1 — purple professional invoice */
export default function Layout1() {
  const { subtotal, tax, discount, total } = totals()
  const taxPct = Math.round(invoice.taxRate * 100)
  const discPct = Math.round(invoice.discountRate * 100)

  return (
    <article className="l1">
      <header className="l1-head">
        <div className="l1-brand">
          <Logo />
          <p className="l1-brand-name">{invoice.brand}</p>
        </div>
        <div className="l1-title-block">
          <h1 className="l1-title">INVOICE</h1>
          <p className="l1-date">{invoice.date}</p>
        </div>
      </header>

      <div className="l1-parties">
        <section className="l1-party">
          <h2>Office Address</h2>
          <p>{lines(invoice.officeAddress)}</p>
          <p className="l1-party-phone">{invoice.phone}</p>
        </section>
        <section className="l1-party l1-party-to">
          <h2>To :</h2>
          <p className="l1-party-name">{invoice.to.name}</p>
          <p>{lines(invoice.to.address)}</p>
        </section>
      </div>

      <table className="l1-table">
        <thead>
          <tr>
            <th className="col-desc">Items Description</th>
            <th className="col-price">Unit Price</th>
            <th className="col-qty">Qnt</th>
            <th className="col-total">Total</th>
          </tr>
        </thead>
        <tbody>
          {invoice.items.map((row, i) => (
            <tr key={`${row.name}-${i}`}>
              <td className="col-desc">
                <p className="l1-item-name">{row.name}</p>
                <p className="l1-item-detail">{row.detail}</p>
              </td>
              <td className="col-price">{money(row.unitPrice)}</td>
              <td className="col-qty">{row.qty}</td>
              <td className="col-total">{money(row.qty * row.unitPrice)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <div className="l1-mid">
        <section className="l1-note">
          <h2>Note :</h2>
          <p>{invoice.note}</p>
        </section>
        <section className="l1-sums">
          <div className="l1-sum-row">
            <span>SUBTOTAL</span>
            <strong>{money(subtotal)}</strong>
          </div>
          <div className="l1-sum-row">
            <span>Tax VAT {taxPct}%</span>
            <strong>{money(tax)}</strong>
          </div>
          <div className="l1-sum-row">
            <span>DISCOUNT {discPct}%</span>
            <strong>{money(discount)}</strong>
          </div>
          <div className="l1-sum-due">
            <span>TOTAL DUE :</span>
            <strong>{money(total)}</strong>
          </div>
        </section>
      </div>

      <p className="l1-thanks">{invoice.thankYou}</p>
      <hr className="l1-rule" />

      <footer className="l1-foot">
        <section>
          <h3>Questions?</h3>
          <p>
            <span className="l1-foot-label">Email us :</span> {invoice.questions.email}
          </p>
          <p>
            <span className="l1-foot-label">Call us :</span> {invoice.questions.phone}
          </p>
        </section>
        <section>
          <h3>Payment Info</h3>
          <p>
            <span className="l1-foot-label">Account :</span> {invoice.payment.account}
          </p>
          <p>
            <span className="l1-foot-label">A/C Name :</span> {invoice.payment.name}
          </p>
          <p>
            <span className="l1-foot-label">Bank Detail :</span> {invoice.payment.bank}
          </p>
        </section>
        <section>
          <h3>Terms & Conditions / Note</h3>
          <p>{invoice.terms}</p>
        </section>
      </footer>
    </article>
  )
}
