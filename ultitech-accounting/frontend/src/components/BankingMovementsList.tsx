export type BankMovement = {
  id: number
  bank_account_id: number
  cash_account_id?: number
  wallet_type?: 'bank' | 'cash' | string
  account_name?: string
  transaction_date: string
  description?: string
  debit: string
  credit: string
  reference?: string
  is_reconciled?: boolean
  source?: string
}

function money(n: number, currency = 'TZS'): string {
  try {
    return new Intl.NumberFormat('en-TZ', {
      style: 'currency',
      currency,
      maximumFractionDigits: currency === 'TZS' ? 0 : 2,
    }).format(n)
  } catch {
    return `${currency} ${n.toLocaleString()}`
  }
}

function MovementInIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path
        d="M12 4v12m0 0 4.5-4.5M12 16l-4.5-4.5M5 20h14"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  )
}

function MovementOutIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path
        d="M12 20V8m0 0 4.5 4.5M12 8 7.5 12.5M5 4h14"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  )
}

export function formatMovementDate(rawDate: string): string {
  const raw = String(rawDate || '').slice(0, 10)
  const d = new Date(`${raw}T00:00:00`)
  if (Number.isNaN(d.getTime())) return raw || '—'
  return d.toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

export function sortMovementsNewestFirst(rows: BankMovement[]): BankMovement[] {
  return [...rows].sort((a, b) => {
    const byDate = String(b.transaction_date).localeCompare(String(a.transaction_date))
    if (byDate !== 0) return byDate
    return Number(b.id) - Number(a.id)
  })
}

export function BankingMovementsList({
  items,
  accountName,
}: {
  items: BankMovement[]
  accountName: (txn: BankMovement) => string
}) {
  return (
    <div className="ua-banking-moves" role="list">
      {items.map((txn) => {
        const inAmt = txn.credit && Number(txn.credit) ? Number(txn.credit) : 0
        const outAmt = txn.debit && Number(txn.debit) ? Number(txn.debit) : 0
        const isIn = inAmt > 0 && outAmt <= 0
        const isOut = outAmt > 0 && inAmt <= 0
        const amount = isIn ? inAmt : isOut ? outAmt : Math.max(inAmt, outAmt)
        const account = accountName(txn)
        const rawRef = String(txn.reference || '').trim()
        const isSystemRef = /^(payment_voucher|expense|receipt_voucher|journal|invoice|bill)\s*#\d+$/i.test(
          rawRef,
        ) || /^MOV-\d+$/i.test(rawRef)
        const refLabel = !rawRef || isSystemRef ? '' : rawRef
        const status = isIn ? 'Money in' : isOut ? 'Money out' : 'Movement'
        return (
          <div
            key={txn.id}
            className={`ua-banking-move${isIn ? ' ua-banking-move--in' : ''}${isOut ? ' ua-banking-move--out' : ''}${refLabel ? '' : ' ua-banking-move--no-ref'}`}
            role="listitem"
          >
            <span
              className={`ua-banking-move-icon ${
                isIn
                  ? 'ua-banking-move-icon--in'
                  : isOut
                    ? 'ua-banking-move-icon--out'
                    : 'ua-banking-move-icon--flat'
              }`}
              aria-hidden="true"
            >
              {isIn ? <MovementInIcon /> : isOut ? <MovementOutIcon /> : '·'}
            </span>
            {refLabel ? <div className="ua-banking-move__ref">{refLabel}</div> : <div className="ua-banking-move__ref" aria-hidden="true" />}
            <span
              className={`ua-banking-move__badge ${
                isIn
                  ? 'ua-banking-move__badge--in'
                  : isOut
                    ? 'ua-banking-move__badge--out'
                    : 'ua-banking-move__badge--flat'
              }`}
            >
              {status}
            </span>
            <div className="ua-banking-move__payee">{txn.description || account || 'Movement'}</div>
            <div className="ua-banking-move__meta">
              <span className="ua-banking-move__account">{account}</span>
              <span className="ua-banking-move__date">{formatMovementDate(txn.transaction_date)}</span>
            </div>
            <div
              className={`ua-banking-move__amount ${
                isIn
                  ? 'ua-banking-move__amount--in'
                  : isOut
                    ? 'ua-banking-move__amount--out'
                    : ''
              }`}
            >
              {amount ? `${isIn ? '+' : isOut ? '−' : ''}${money(amount, 'TZS')}` : '—'}
            </div>
          </div>
        )
      })}
    </div>
  )
}
