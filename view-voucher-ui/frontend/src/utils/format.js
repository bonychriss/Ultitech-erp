export function fmtMoney(amount, currency = 'TZS') {
  const n = Number(amount) || 0
  return `${currency} ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

export function fmtDate(iso) {
  if (!iso) return '-'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return String(iso).slice(0, 10)
  return d.toISOString().slice(0, 10)
}

export function fmtDateTime(iso) {
  if (!iso) return 'Pending'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return String(iso)
  return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

export function waLink(phone) {
  if (!phone) return null
  let clean = String(phone).replace(/[^0-9]/g, '')
  if (clean.length === 9 && clean[0] !== '0') clean = `255${clean}`
  else if (clean.length === 10 && clean[0] === '0') clean = `255${clean.slice(1)}`
  return clean ? `https://wa.me/${clean}` : null
}
