export type JournalLine = {
  id?: number
  line_no?: number
  account_id: number
  description?: string | null
  debit: string | number
  credit: string | number
  account?: { id: number; code: string; name: string } | null
}

export type JournalSource = { id: number; code: string; name: string }

export type Journal = {
  id: number
  journal_number: string
  journal_date: string
  posting_date?: string | null
  description: string
  reference?: string | null
  status: string
  currency_code?: string
  total_debit: string | number
  total_credit: string | number
  source_type?: string | null
  source_id_ref?: string | null
  posted_at?: string | null
  read_only?: boolean
  erp_journal_id?: number
  lines?: JournalLine[]
  source?: JournalSource | null
}

export function isReadOnlyJournal(journal: Journal | null | undefined): boolean {
  if (!journal) return true
  if (journal.read_only) return true
  return Number(journal.id) <= 0
}

export function money(value: string | number | undefined) {
  const n = Number(value || 0)
  if (!Number.isFinite(n)) return '0.00'
  return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

export function currencyCode(value: string | null | undefined): string {
  const code = String(value || '').trim().toUpperCase()
  return code || 'TZS'
}

export function moneyWithCurrency(
  value: string | number | undefined,
  currency: string | null | undefined = 'TZS',
) {
  return currencyCode(currency) + ' ' + money(value)
}

export function statusLabel(status: string): string {
  const s = String(status || '').toLowerCase()
  if (s === 'draft') return 'Draft'
  if (s === 'posted') return 'Posted'
  if (s === 'reversed') return 'Reversed'
  return s ? s.charAt(0).toUpperCase() + s.slice(1) : '-'
}

export function statusClass(status: string): string {
  const s = String(status || '').toLowerCase()
  if (s === 'posted') return 'ua-revenue-status ua-revenue-status--paid'
  if (s === 'draft') return 'ua-revenue-status ua-revenue-status--partial'
  if (s === 'reversed') return 'ua-revenue-status ua-revenue-status--unpaid'
  return 'ua-revenue-status'
}

export function isManualJournal(journal: Journal): boolean {
  const sourceCode = String(journal.source?.code || '').toLowerCase()
  const sourceType = String(journal.source_type || '').toLowerCase()
  if (sourceCode === 'manual' || sourceType === 'manual') return true
  if (!sourceCode && !sourceType) return true
  if (sourceCode === 'reversal' || sourceType === 'reversal') return false
  return false
}

export function sourceLabel(journal: Journal): string {
  if (journal.source?.name) return journal.source.name
  const code = String(journal.source?.code || journal.source_type || '').trim()
  if (!code) return 'Manual'
  return code
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase())
}

export function detailSubtitle(journal: Journal): string {
  const src = String(journal.source_type || journal.source?.code || '').toLowerCase()
  let kind = 'Journal'
  if (src === 'revenue_collection') kind = 'Customer Receipt'
  else if (src === 'opening_balance') kind = 'Opening Balance'
  else if (src.includes('payment') || src.includes('expense') || src.includes('voucher')) kind = 'Payment'
  else if (src.includes('invoice') || src.includes('sales')) kind = 'Sales Invoice'
  else if (src === 'manual' || (!src && !isReadOnlyJournal(journal))) kind = 'Manual Journal'
  else if (src) kind = sourceLabel(journal)

  if (isReadOnlyJournal(journal)) return 'Classic GL Posting - ' + kind
  return kind
}

export type JournalDescriptionView = {
  kind: string | null
  customer: string | null
  invoice: string | null
  rev: string | null
  bankRef: string | null
  headline: string
  meta: string | null
  note: string | null
  attachmentPath: string | null
  attachmentHref: string | null
  raw: string
  structured: boolean
}

/** Web root for ERP static files (e.g. /public_html when XAMPP docroot is htdocs). */
function erpUploadsWebRoot(): string {
  if (typeof window === 'undefined') return ''
  const path = String(window.location.pathname || '')
  const m = path.match(/^(\/public_html)(?=\/|$)/i)
  if (m) return m[1]
  const idx = path.toLowerCase().indexOf('/public_html/')
  if (idx >= 0) return path.slice(idx, idx + '/public_html'.length)
  return ''
}

function attachmentHrefFromPath(path: string): string {
  const clean = path.replace(/^\/+/, '').trim()
  if (!clean) return ''
  if (/^https?:\/\//i.test(clean)) return clean
  // Already absolute app path
  if (clean.toLowerCase().startsWith('public_html/')) return '/' + clean
  const root = erpUploadsWebRoot().replace(/\/+$/, '')
  return `${root}/${clean}`
}

/** Turn classic ERP revenue memos into short readable copy (display only). */
export function presentJournalDescription(rawInput: string | null | undefined): JournalDescriptionView {
  const raw = String(rawInput || '').replace(/\s+/g, ' ').trim()
  if (!raw) {
    return {
      kind: null,
      customer: null,
      invoice: null,
      rev: null,
      bankRef: null,
      headline: '-',
      meta: null,
      note: null,
      attachmentPath: null,
      attachmentHref: null,
      raw: '',
      structured: false,
    }
  }

  let text = raw
  let isCustomerReceipt = false
  if (/^customer receipt:\s*/i.test(text)) {
    isCustomerReceipt = true
    text = text.replace(/^customer receipt:\s*/i, '')
  }

  let attachmentPath: string | null = null
  const attMatch = text.match(/\[Attachment:\s*([^\]]+)\]/i)
  if (attMatch) {
    attachmentPath = attMatch[1].trim()
    text = text.replace(attMatch[0], '').trim()
  }

  let invoice: string | null = null
  const invBlock = text.match(/\(\s*Sales invoice\s+(INV-[A-Z0-9-]+)(?:\s*\([^)]*\))?\s*\)/i)
  if (invBlock) {
    invoice = invBlock[1].toUpperCase()
    text = text.replace(invBlock[0], '').trim()
  } else {
    const loose = text.match(/\b(INV-[A-Z0-9-]+)\b/i)
    if (loose) invoice = loose[1].toUpperCase()
    text = text.replace(/\(\s*(?:Sales invoice\s+)?INV-[A-Z0-9-]+[^)]*\)/i, '').trim()
  }
  text = text.replace(/\(\s*\)/g, '').replace(/\s+/g, ' ').trim()

  let rev: string | null = null
  let customer: string | null = null
  let bankRef: string | null = null
  let note: string | null = null

  const debt = text.match(/^Debt Payment:\s*([A-Z0-9/-]+)\s*-\s*(.+)$/i)
  if (debt) {
    rev = debt[1].trim()
    const rest = debt[2].trim()
    const refSplit = rest.split(/\s+-\s+Ref\s+/i)
    customer = (refSplit[0] || '').trim() || null
    if (refSplit.length > 1) {
      const afterRef = refSplit[1].trim()
      const noteSplit = afterRef.split(/\s+-\s+/)
      bankRef = (noteSplit[0] || '').trim() || null
      if (noteSplit.length > 1) {
        note = noteSplit.slice(1).join(' - ').trim() || null
      }
    } else {
      const parts = rest.split(/\s+-\s+/)
      if (parts.length >= 1) customer = parts[0].trim() || null
      if (parts.length >= 2) note = parts.slice(1).join(' - ').trim() || null
    }
  }

  const kind =
    isCustomerReceipt || /^Debt Payment:/i.test(raw) ? 'Customer receipt' : null

  if (note) {
    note = note.replace(/^[\s\-)]+|[\s\-(]+$/g, '').trim() || null
  }
  if (note) {
    note = note
      .replace(/\brecentrly\b/gi, 'recently')
      .replace(/\brecien?tly\b/gi, 'recently')
  }

  const structured = Boolean(kind || customer || invoice || rev || bankRef)

  const bits: string[] = []
  if (kind) bits.push(kind)
  if (customer) bits.push(customer)
  if (invoice) bits.push(invoice)
  const headline = bits.length ? bits.join(' - ') : raw

  const metaBits: string[] = []
  if (rev) metaBits.push(rev)
  if (bankRef) metaBits.push('Ref ' + bankRef)
  const meta = metaBits.length ? metaBits.join(' - ') : null

  return {
    kind,
    customer,
    invoice,
    rev,
    bankRef,
    headline,
    meta,
    note,
    attachmentPath,
    attachmentHref: attachmentPath ? attachmentHrefFromPath(attachmentPath) : null,
    raw,
    structured,
  }
}

function shortenText(value: string, max = 36): string {
  const s = String(value || '').replace(/\s+/g, ' ').trim()
  if (!s) return '-'
  if (s.length <= max) return s
  return s.slice(0, Math.max(1, max - 3)).trimEnd() + '...'
}

function shortCompanyName(name: string | null | undefined, max = 28): string | null {
  const s = String(name || '').replace(/\s+/g, ' ').trim()
  if (!s) return null
  return shortenText(s, max)
}

/** Short line memo for the journal lines table. */
export function presentJournalLineDescription(
  rawInput: string | null | undefined,
  opts?: { isDebit?: boolean; isCredit?: boolean },
): string {
  const view = presentJournalDescription(rawInput)
  if (!view.raw) return '-'
  const lower = view.raw.toLowerCase()

  if (lower.includes('customer receipt') || lower.includes('debt payment')) {
    if (view.invoice) return view.invoice
    if (opts?.isCredit) return 'Clear AR'
    if (opts?.isDebit) return 'Bank receipt'
    return 'Customer receipt'
  }

  const pvMatch = view.raw.match(/\b(PV\/[A-Z0-9\/\-]+)\b/i)
  if (pvMatch) return pvMatch[1].toUpperCase()

  if (lower.startsWith('reversal of ') || lower.includes('reversal')) {
    const gl = view.raw.match(/\b(GL-\d{4}-\d+)\b/i)
    return gl ? gl[1].toUpperCase() : 'Reversal'
  }

  if (view.invoice) return view.invoice
  if (view.headline !== view.raw) return shortenText(view.headline, 36)
  return shortenText(view.raw, 36)
}

export function presentJournalListDescription(rawInput: string | null | undefined): string {
  const view = presentJournalDescription(rawInput)
  if (view.structured) {
    const bits = [view.kind || 'Customer receipt', view.invoice || shortCompanyName(view.customer, 24)]
      .filter(Boolean) as string[]
    return shortenText(bits.join(' - '), 42)
  }
  return shortenText(view.headline, 42)
}

/** Single prose paragraph for the detail Description card. */
export function presentJournalDescriptionProse(rawInput: string | null | undefined): string {
  const view = presentJournalDescription(rawInput)
  if (!view.raw) return '-'
  if (!view.structured) return shortenText(view.headline || view.raw, 90)

  const bits: string[] = [view.kind || 'Customer receipt']
  const company = shortCompanyName(view.customer, 32)
  if (company) bits.push(company)
  if (view.invoice) bits.push(view.invoice)
  else if (view.rev) bits.push(view.rev)

  let prose = bits.join(' - ')
  if (view.bankRef) prose += ' (' + view.bankRef + ')'
  if (!prose.endsWith('.')) prose += '.'
  return prose
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

/** Build a print sheet in the current page (avoids blank about:blank popups). */
export function printJournal(journal: Journal): void {
  const currency = currencyCode(journal.currency_code)
  const lines = journal.lines || []
  const lineRows = lines
    .map((line) => {
      const account = line.account
      const label = account
        ? `${account.code ? account.code + ' - ' : ''}${account.name}`
        : `Account #${line.account_id}`
      const debit = Number(line.debit) > 0 ? money(line.debit) : ''
      const credit = Number(line.credit) > 0 ? money(line.credit) : ''
      return `<tr>
        <td>${escapeHtml(label)}</td>
        <td class="num">${escapeHtml(debit)}</td>
        <td class="num">${escapeHtml(credit)}</td>
      </tr>`
    })
    .join('')

  const description = presentJournalDescriptionProse(journal.description)
  const prevTitle = document.title
  document.title = `${journal.journal_number} - Journal`

  document.getElementById('ua-journal-print-root')?.remove()
  const root = document.createElement('div')
  root.id = 'ua-journal-print-root'
  root.className = 'ua-journal-print-root'
  root.innerHTML = `
    <h1>${escapeHtml(journal.journal_number)}</h1>
    <p class="ua-journal-print-sub">${escapeHtml(detailSubtitle(journal))}</p>
    <table class="ua-journal-print-table">
      <thead>
        <tr>
          <th>Account</th>
          <th class="num">Debit</th>
          <th class="num">Credit</th>
        </tr>
      </thead>
      <tbody>${lineRows}</tbody>
      <tfoot>
        <tr>
          <td>Total</td>
          <td class="num">${escapeHtml(currency + ' ' + money(journal.total_debit))}</td>
          <td class="num">${escapeHtml(currency + ' ' + money(journal.total_credit))}</td>
        </tr>
      </tfoot>
    </table>
    <div class="ua-journal-print-desc">
      <h2>Description</h2>
      <p>${escapeHtml(description)}</p>
    </div>
  `
  document.body.appendChild(root)
  document.documentElement.classList.add('ua-printing-journal')
  document.body.classList.add('ua-printing-journal')

  const cleanup = () => {
    document.documentElement.classList.remove('ua-printing-journal')
    document.body.classList.remove('ua-printing-journal')
    root.remove()
    document.title = prevTitle
    window.removeEventListener('afterprint', cleanup)
  }
  window.addEventListener('afterprint', cleanup)
  window.setTimeout(() => {
    window.print()
    window.setTimeout(cleanup, 1500)
  }, 30)
}
