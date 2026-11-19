import {
  useEffect,
  useMemo,
  useRef,
  useState,
  type FormEvent,
  type PointerEvent as ReactPointerEvent,
  type ReactNode,
  type RefObject,
} from 'react'
import { createPortal } from 'react-dom'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiRequest, ApiError } from '../lib/api'
import { useAuth } from '../lib/auth'
import { readBoot, type BootBankAccount, type BootCashAccount, type BootInstitution } from '../lib/boot'
import {
  BankingMovementsList,
  sortMovementsNewestFirst,
  type BankMovement,
} from '../components/BankingMovementsList'
import {
  Button,
  EmptyState,
  ErrorBanner,
  Input,
  Label,
  PageHeader,
  SectionTitle,
  Select,
  Table,
} from '../components/ui'

type BankAccount = BootBankAccount
type CashAccount = BootCashAccount
type Kind = 'bank' | 'cash'
type BankTransaction = BankMovement

/** Drag the grey handle downward to dismiss a bottom sheet (iOS-style). */
function SheetDragHandle({
  sheetRef,
  onDismiss,
  disabled = false,
}: {
  sheetRef: RefObject<HTMLElement | null>
  onDismiss: () => void
  disabled?: boolean
}) {
  const drag = useRef({
    active: false,
    startY: 0,
    lastY: 0,
    lastT: 0,
    dy: 0,
    pointerId: -1,
  })

  const resetSheet = () => {
    const el = sheetRef.current
    if (!el) return
    el.style.transition = 'transform 0.22s ease, opacity 0.22s ease'
    el.style.transform = ''
    el.style.opacity = ''
    window.setTimeout(() => {
      if (el) el.style.transition = ''
    }, 240)
  }

  const onPointerDown = (event: ReactPointerEvent<HTMLDivElement>) => {
    if (disabled) return
    const el = sheetRef.current
    if (!el) return
    drag.current = {
      active: true,
      startY: event.clientY,
      lastY: event.clientY,
      lastT: performance.now(),
      dy: 0,
      pointerId: event.pointerId,
    }
    el.classList.add('is-dragging')
    el.style.transition = 'none'
    event.currentTarget.setPointerCapture(event.pointerId)
  }

  const onPointerMove = (event: ReactPointerEvent<HTMLDivElement>) => {
    if (!drag.current.active || event.pointerId !== drag.current.pointerId) return
    const el = sheetRef.current
    if (!el) return
    const dy = Math.max(0, event.clientY - drag.current.startY)
    drag.current.dy = dy
    drag.current.lastY = event.clientY
    drag.current.lastT = performance.now()
    el.style.transform = `translate3d(0, ${dy}px, 0)`
    el.style.opacity = String(Math.max(0.35, 1 - dy / 420))
  }

  const onPointerUp = (event: ReactPointerEvent<HTMLDivElement>) => {
    if (!drag.current.active || event.pointerId !== drag.current.pointerId) return
    const el = sheetRef.current
    const dy = drag.current.dy
    const dt = Math.max(16, performance.now() - drag.current.lastT)
    const velocity = (event.clientY - drag.current.lastY) / dt
    drag.current.active = false
    if (el) el.classList.remove('is-dragging')
    try {
      event.currentTarget.releasePointerCapture(event.pointerId)
    } catch {
      /* ignore */
    }
    if (dy > 90 || (dy > 40 && velocity > 0.35)) {
      if (el) {
        el.style.transition = 'transform 0.2s ease, opacity 0.2s ease'
        el.style.transform = `translate3d(0, ${Math.max(dy, el.offsetHeight || 360)}px, 0)`
        el.style.opacity = '0'
      }
      window.setTimeout(() => onDismiss(), 180)
      return
    }
    resetSheet()
  }

  return (
    <div
      className="ua-sheet__grab"
      role="button"
      tabIndex={0}
      aria-label="Drag down to close"
      onPointerDown={onPointerDown}
      onPointerMove={onPointerMove}
      onPointerUp={onPointerUp}
      onPointerCancel={onPointerUp}
      onKeyDown={(event) => {
        if (disabled) return
        if (event.key === 'Escape' || event.key === 'Enter' || event.key === ' ') {
          event.preventDefault()
          onDismiss()
        }
      }}
    >
      <div className="ua-sheet__handle" aria-hidden="true" />
    </div>
  )
}

const emptyForm = {
  code: '',
  name: '',
  bank_name: '',
  account_number: '',
  account_type: 'bank',
  currency_code: 'TZS',
  status: 'active',
  opening_balance: '',
  opening_date: '',
}

const CURRENCIES = [
  { code: 'TZS', name: 'Tanzanian Shilling', flag: 'tz' },
  { code: 'USD', name: 'US Dollar', flag: 'us' },
  { code: 'EUR', name: 'Euro', flag: 'eu' },
  { code: 'GBP', name: 'British Pound', flag: 'gb' },
  { code: 'KES', name: 'Kenyan Shilling', flag: 'ke' },
  { code: 'UGX', name: 'Ugandan Shilling', flag: 'ug' },
  { code: 'RWF', name: 'Rwandan Franc', flag: 'rw' },
  { code: 'ZAR', name: 'South African Rand', flag: 'za' },
] as const

function currencyFlagUrl(flag: string): string {
  return `https://flagcdn.com/w40/${flag}.png`
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

function formatAccountNumber(value: string | null | undefined): string {
  const raw = String(value ?? '').trim()
  return raw || '—'
}

function BalanceCell({
  balance,
  currency,
  direction,
  delta,
  changedAt,
  basis,
}: {
  balance: number | null | undefined
  currency: string
  direction?: 'up' | 'down' | null
  delta?: number | null
  changedAt?: string | null
  basis?: 'opening' | 'last_seen' | null
}) {
  if (balance == null || !Number.isFinite(Number(balance))) {
    return <span>—</span>
  }
  const dir = direction === 'up' || direction === 'down' ? direction : null
  const tipParts: string[] = []
  if (dir && delta != null && Number.isFinite(Number(delta))) {
    const signed =
      Number(delta) > 0
        ? `+${money(Math.abs(Number(delta)), currency)}`
        : `-${money(Math.abs(Number(delta)), currency)}`
    tipParts.push(
      `${dir === 'up' ? 'Increased' : 'Decreased'} ${signed}${
        basis === 'opening' ? ' vs opening' : basis === 'last_seen' ? ' since last check' : ''
      }`,
    )
  }
  if (changedAt) {
    try {
      tipParts.push(`as of ${new Date(changedAt).toLocaleString()}`)
    } catch {
      /* ignore */
    }
  }
  return (
    <span
      className={`ua-banking-balance${dir ? ` ua-banking-balance--${dir}` : ''}`}
      title={tipParts.join(' · ') || undefined}
    >
      <span>{money(Number(balance), currency)}</span>
      {dir ? (
        <span className="ua-banking-balance__arrow" aria-label={dir === 'up' ? 'Up' : 'Down'}>
          {dir === 'up' ? '▲' : '▼'}
        </span>
      ) : null}
    </span>
  )
}

function statusLabel(value: string | null | undefined): string {
  const status = String(value ?? 'active').trim().toLowerCase()
  if (!status) return 'Active'
  return status.charAt(0).toUpperCase() + status.slice(1)
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (!parts.length) return '?'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return (parts[0][0] + parts[1][0]).toUpperCase()
}

function resolveInstitutionLogo(
  institutions: BootInstitution[],
  ...names: Array<string | null | undefined>
): string {
  const needles = names
    .map((n) => String(n || '').trim().toLowerCase())
    .filter(Boolean)
  if (!needles.length || !institutions.length) return ''

  for (const item of institutions) {
    const candidates = [item.name, ...(item.aliases || [])]
      .map((v) => String(v || '').trim().toLowerCase())
      .filter(Boolean)
    for (const needle of needles) {
      for (const candidate of candidates) {
        if (
          needle === candidate ||
          needle.includes(candidate) ||
          candidate.includes(needle)
        ) {
          return String(item.logo_url || '').trim()
        }
      }
    }
  }
  return ''
}

function PettyCashIcon() {
  return (
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <rect
        x="2.75"
        y="6.25"
        width="18.5"
        height="11.5"
        rx="2"
        stroke="currentColor"
        strokeWidth="1.6"
      />
      <circle cx="12" cy="12" r="2.35" stroke="currentColor" strokeWidth="1.6" />
      <path
        d="M6.2 9.1h1.4M16.4 14.9h1.4"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinecap="round"
      />
    </svg>
  )
}

function SummaryBankIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path
        d="M4 10h16M6 10v8m4-8v8m4-8v8m4-8v8M3 18h18M12 4l9 6H3l9-6Z"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinejoin="round"
      />
    </svg>
  )
}

function SummaryMovesIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path
        d="M7 7h11m0 0-3-3m3 3-3 3M17 17H6m0 0 3 3m-3-3 3-3"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  )
}

function BankLogo({
  src,
  label,
  variant = 'bank',
}: {
  src: string
  label: string
  variant?: 'bank' | 'cash'
}) {
  if (src) {
    return (
      <span className="ua-banking-logo">
        <img src={src} alt="" loading="lazy" />
      </span>
    )
  }
  if (variant === 'cash') {
    return (
      <span className="ua-banking-logo ua-banking-logo--cash" aria-hidden="true" title="Petty cash">
        <PettyCashIcon />
      </span>
    )
  }
  return (
    <span className="ua-banking-logo ua-banking-logo--initial" aria-hidden="true">
      {initials(label)}
    </span>
  )
}

function CameraImageIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path
        d="M4.5 8.5h2.1l1.1-1.8A1.5 1.5 0 0 1 8.95 6h6.1a1.5 1.5 0 0 1 1.25.7l1.1 1.8h2.1A1.5 1.5 0 0 1 21 10v7.5A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5V10a1.5 1.5 0 0 1 1.5-1.5Z"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinejoin="round"
      />
      <circle cx="12" cy="13.25" r="3.1" stroke="currentColor" strokeWidth="1.6" />
    </svg>
  )
}

function AccountMobileCard({
  title,
  logo,
  logoVariant = 'bank',
  accountNumber,
  currency,
  balance,
  status,
  inactive,
  menuOpen,
  onToggleMenu,
  onEdit,
  onStatus,
}: {
  title: string
  logo: string
  logoVariant?: 'bank' | 'cash'
  accountNumber?: string | null
  currency?: string | null
  balance: ReactNode
  status: string
  inactive: boolean
  menuOpen: boolean
  onToggleMenu: () => void
  onEdit: () => void
  onStatus: () => void
}) {
  return (
    <article className={`ua-acct-card${inactive ? ' ua-acct-card--inactive' : ''}`}>
      <div className="ua-acct-card__top">
        <div className="ua-banking-bankcell">
          <BankLogo src={logo} label={title} variant={logoVariant} />
          <span className="ua-acct-card__title">{title}</span>
        </div>
        <span className={`ua-banking-status${inactive ? ' ua-banking-status--inactive' : ''}`}>
          {status}
        </span>
      </div>
      <div className="ua-acct-card__acct">{formatAccountNumber(accountNumber)}</div>
      <div className="ua-acct-card__balance">{balance}</div>
      <div className="ua-acct-card__foot">
        <span className="ua-acct-card__currency">{currency || '—'}</span>
        <div className="ua-banking-actions" data-ua-actions>
          <button
            type="button"
            className="ua-banking-actions__btn"
            aria-label={`Actions for ${title}`}
            aria-haspopup="menu"
            aria-expanded={menuOpen}
            onClick={onToggleMenu}
          >
            <span aria-hidden="true">⋯</span>
          </button>
          {menuOpen ? (
            <div className="ua-banking-actions__menu" role="menu">
              <button type="button" role="menuitem" onClick={onEdit}>
                Edit account
              </button>
              <button type="button" role="menuitem" onClick={onStatus}>
                {inactive ? 'Activate' : 'Deactivate'}
              </button>
            </div>
          ) : null}
        </div>
      </div>
    </article>
  )
}

function nextAccountCode(
  kind: Kind,
  accountType: string,
  bankRows: Array<{ code: string }>,
  cashRows: Array<{ code: string }>,
): string {
  const prefix = kind === 'cash' ? 'CASH' : accountType === 'mobile_money' ? 'MOB' : 'BANK'
  const pool = kind === 'cash' ? cashRows : bankRows
  let max = 0
  for (const row of pool) {
    const match = String(row.code || '').match(new RegExp(`^${prefix}-(\\d+)$`, 'i'))
    if (match) max = Math.max(max, Number(match[1]))
  }
  return `${prefix}-${max + 1}`
}

export function BankingPage() {
  const { token, companyId, bookId } = useAuth()
  const client = useQueryClient()
  const boot = readBoot()
  const ready = Boolean(token && companyId)

  const prefetchedBanks = Array.isArray(boot.prefetch?.bank_accounts) ? boot.prefetch!.bank_accounts! : []
  const prefetchedCash = Array.isArray(boot.prefetch?.cash_accounts) ? boot.prefetch!.cash_accounts! : []

  const [formMode, setFormMode] = useState<'create' | 'edit' | null>(null)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [kind, setKind] = useState<Kind>('bank')
  const [form, setForm] = useState(emptyForm)
  const [formError, setFormError] = useState('')
  const [nameOpen, setNameOpen] = useState(false)
  const [bankPickerOpen, setBankPickerOpen] = useState(false)
  const [bankPickerQuery, setBankPickerQuery] = useState('')
  const [customInstitutions, setCustomInstitutions] = useState<BootInstitution[]>([])
  const [isNarrow, setIsNarrow] = useState(false)
  const [currencyOpen, setCurrencyOpen] = useState(false)
  const accountSheetRef = useRef<HTMLDivElement>(null)
  const bankPickerRef = useRef<HTMLDivElement>(null)
  const [selectedLogo, setSelectedLogo] = useState('')
  const [institutionSlug, setInstitutionSlug] = useState('')
  const [logoBase64, setLogoBase64] = useState('')
  const [logoFileName, setLogoFileName] = useState('')
  const [clearLogo, setClearLogo] = useState(false)
  const [txnQuery, setTxnQuery] = useState('')
  const [menuKey, setMenuKey] = useState('')
  const [statusEdit, setStatusEdit] = useState<null | {
    kind: Kind
    account: BankAccount | CashAccount
    status: 'active' | 'inactive'
  }>(null)
  const [statusError, setStatusError] = useState('')

  const institutions = useMemo(() => {
    const base = (Array.isArray(boot.institutions) ? boot.institutions : []) as BootInstitution[]
    if (customInstitutions.length === 0) return base
    const seen = new Set(base.map((i) => i.name.trim().toUpperCase()))
    const extras = customInstitutions.filter((i) => {
      const key = i.name.trim().toUpperCase()
      if (seen.has(key)) return false
      seen.add(key)
      return true
    })
    return extras.length ? [...base, ...extras] : base
  }, [boot.institutions, customInstitutions])

  const banks = useQuery({
    queryKey: ['bank-accounts', companyId, bookId],
    enabled: ready,
    initialData: prefetchedBanks.length ? { data: prefetchedBanks } : undefined,
    refetchInterval: 60_000,
    queryFn: () =>
      apiRequest<{ data: BankAccount[] }>('/bank-accounts', { token, companyId, bookId }),
  })

  const cash = useQuery({
    queryKey: ['cash-accounts', companyId, bookId],
    enabled: ready,
    initialData: prefetchedCash.length ? { data: prefetchedCash } : undefined,
    refetchInterval: 60_000,
    queryFn: () =>
      apiRequest<{ data: CashAccount[] }>('/cash-accounts', { token, companyId, bookId }),
  })

  const txns = useQuery({
    queryKey: ['bank-transactions', companyId, bookId],
    enabled: ready,
    queryFn: () =>
      apiRequest<{ data: BankTransaction[] }>('/bank-transactions', { token, companyId, bookId }),
  })

  const bankRows = (banks.data?.data?.length ? banks.data.data : prefetchedBanks) ?? []
  const cashRows = (cash.data?.data?.length ? cash.data.data : prefetchedCash) ?? []
  const txnRows = txns.data?.data ?? []

  const accountNameById = useMemo(() => {
    const map = new Map<number, string>()
    for (const row of bankRows) map.set(row.id, row.name)
    return map
  }, [bankRows])

  const cashNameById = useMemo(() => {
    const map = new Map<number, string>()
    for (const row of cashRows) map.set(row.id, row.name)
    return map
  }, [cashRows])

  const movementAccountName = (txn: BankTransaction): string => {
    if (txn.account_name) return txn.account_name
    if (txn.wallet_type === 'cash' && txn.cash_account_id) {
      return cashNameById.get(txn.cash_account_id) || 'Cash'
    }
    if (txn.bank_account_id) {
      return accountNameById.get(txn.bank_account_id) || 'Bank'
    }
    return '—'
  }

  const filteredTxns = useMemo(() => {
    const q = txnQuery.trim().toLowerCase()
    return sortMovementsNewestFirst(txnRows).filter((txn) => {
      if (!q) return true
      const hay = [
        txn.description,
        txn.reference,
        txn.account_name,
        movementAccountName(txn),
      ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
      return hay.includes(q)
    })
  }, [txnRows, txnQuery, accountNameById, cashNameById])

  const previewTxns = useMemo(() => filteredTxns.slice(0, 20), [filteredTxns])
  const hasMoreTxns = filteredTxns.length > 20

  const resetForm = () => {
    setForm({
      ...emptyForm,
      opening_date: new Date().toISOString().slice(0, 10),
    })
    setFormError('')
    setSelectedLogo('')
    setInstitutionSlug('')
    setLogoBase64('')
    setLogoFileName('')
    setClearLogo(false)
    setNameOpen(false)
    setCurrencyOpen(false)
    setEditingId(null)
  }

  const onLogoFile = (file: File | null) => {
    if (!file) return
    if (file.size > 2 * 1024 * 1024) {
      setFormError('Account image must be 2MB or smaller.')
      return
    }
    const reader = new FileReader()
    reader.onload = () => {
      const result = typeof reader.result === 'string' ? reader.result : ''
      if (!result) return
      setLogoBase64(result)
      setLogoFileName(file.name)
      setSelectedLogo(result)
      setInstitutionSlug('')
      setClearLogo(false)
      setFormError('')
    }
    reader.readAsDataURL(file)
  }

  const selectedCurrency =
    CURRENCIES.find((c) => c.code === form.currency_code.toUpperCase()) ?? CURRENCIES[0]

  useEffect(() => {
    const mq = window.matchMedia('(max-width: 991.98px)')
    const sync = () => setIsNarrow(mq.matches)
    sync()
    mq.addEventListener('change', sync)
    return () => mq.removeEventListener('change', sync)
  }, [])

  const institutionSuggestions = useMemo(() => {
    if (kind !== 'bank') return []
    const q = form.name.trim().toLowerCase()
    if (q.length < 1) return []
    const scored: Array<{ item: BootInstitution; score: number }> = []
    for (const item of institutions) {
      const itemType = String(item.type || '').toLowerCase()
      if (itemType !== 'bank' && itemType !== 'mobile') continue
      const name = String(item.name || '').toLowerCase()
      const aliases = (item.aliases || []).map((a) => String(a).toLowerCase())
      let score = 0
      if (name.startsWith(q)) score = 3
      else if (name.includes(q)) score = 2
      else if (aliases.some((a) => a.startsWith(q))) score = 2
      else if (aliases.some((a) => a.includes(q))) score = 1
      if (score > 0) scored.push({ item, score })
    }
    return scored
      .sort((a, b) => b.score - a.score || a.item.name.localeCompare(b.item.name))
      .slice(0, 8)
      .map((row) => row.item)
  }, [kind, form.name, institutions])

  const bankPickerList = useMemo(() => {
    const wantMobile = form.account_type === 'mobile_money'
    const q = bankPickerQuery.trim().toLowerCase()
    const scored: Array<{ item: BootInstitution; score: number }> = []
    for (const item of institutions) {
      const itemType = String(item.type || '').toLowerCase()
      if (wantMobile ? itemType !== 'mobile' : itemType !== 'bank') continue
      const name = String(item.name || '').toLowerCase()
      const aliases = (item.aliases || []).map((a) => String(a).toLowerCase())
      if (!q) {
        scored.push({ item, score: 1 })
        continue
      }
      let score = 0
      if (name.startsWith(q)) score = 3
      else if (name.includes(q)) score = 2
      else if (aliases.some((a) => a.startsWith(q))) score = 2
      else if (aliases.some((a) => a.includes(q))) score = 1
      if (score > 0) scored.push({ item, score })
    }
    return scored
      .sort((a, b) => b.score - a.score || a.item.name.localeCompare(b.item.name))
      .map((row) => row.item)
  }, [institutions, form.account_type, bankPickerQuery])

  const bankNameCaps = (value: string) => value.trim().toUpperCase()

  const bankPickerExactMatch = useMemo(() => {
    const q = bankPickerQuery.trim().toUpperCase()
    if (!q) return true
    return bankPickerList.some((item) => item.name.trim().toUpperCase() === q)
  }, [bankPickerQuery, bankPickerList])

  const rememberCustomInstitutionLocally = (name: string, type: 'bank' | 'mobile', logoUrl = '') => {
    const capped = bankNameCaps(name)
    if (!capped) return
    const slug =
      'custom-' +
      capped
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
    setCustomInstitutions((prev) => {
      if (prev.some((i) => i.name.trim().toUpperCase() === capped)) return prev
      return [
        ...prev,
        {
          slug,
          name: capped,
          type,
          aliases: [],
          logo_url: logoUrl || undefined,
        },
      ]
    })
  }

  const pickInstitution = (item: BootInstitution) => {
    const isMobile = String(item.type).toLowerCase() === 'mobile'
    const capped = bankNameCaps(item.name)
    setForm((prev) => ({
      ...prev,
      name: capped,
      bank_name: capped,
      account_type: isMobile ? 'mobile_money' : 'bank',
    }))
    setSelectedLogo(item.logo_url || '')
    setInstitutionSlug(item.slug || '')
    setLogoBase64('')
    setLogoFileName('')
    setClearLogo(false)
    setNameOpen(false)
    setBankPickerOpen(false)
    setBankPickerQuery('')
  }

  const useTypedBankName = () => {
    const capped = bankNameCaps(bankPickerQuery)
    if (!capped) return
    const isMobile = form.account_type === 'mobile_money'
    rememberCustomInstitutionLocally(capped, isMobile ? 'mobile' : 'bank')
    setForm((prev) => ({
      ...prev,
      name: capped,
      bank_name: capped,
    }))
    setSelectedLogo('')
    setInstitutionSlug('')
    setLogoBase64('')
    setLogoFileName('')
    setClearLogo(false)
    setNameOpen(false)
    setBankPickerOpen(false)
    setBankPickerQuery('')
  }

  const openBankPicker = () => {
    setBankPickerQuery('')
    setCurrencyOpen(false)
    setNameOpen(false)
    setBankPickerOpen(true)
  }

  const logoPayload = () => ({
    institution_slug: institutionSlug || null,
    logo_base64: logoBase64 || null,
    logo_file_name: logoFileName || null,
    clear_logo: clearLogo || undefined,
  })

  const bankCodesKey = bankRows.map((row) => row.code).join('|')
  const cashCodesKey = cashRows.map((row) => row.code).join('|')

  useEffect(() => {
    if (formMode !== 'create') return
    setForm((prev) => {
      const nextCode = nextAccountCode(kind, prev.account_type, bankRows, cashRows)
      return prev.code === nextCode ? prev : { ...prev, code: nextCode }
    })
  }, [formMode, kind, form.account_type, bankCodesKey, cashCodesKey])

  const create = useMutation({
    mutationFn: async () => {
      const openingBalanceRaw = String(form.opening_balance ?? '').replace(/,/g, '').trim()
      const openingBalance = openingBalanceRaw === '' ? 0 : Number(openingBalanceRaw)
      if (Number.isNaN(openingBalance)) {
        throw new Error('Opening balance must be a number.')
      }

      if (kind === 'cash') {
        return apiRequest<{ data: CashAccount }>('/cash-accounts', {
          method: 'POST',
          token,
          companyId,
          bookId,
          body: {
            code: form.code.trim(),
            name: form.name.trim(),
            currency_code: form.currency_code.trim().toUpperCase(),
            status: form.status,
            opening_balance: openingBalance,
            opening_date: form.opening_date || null,
            ...logoPayload(),
          },
        })
      }

      const cappedName = bankNameCaps(form.name)
      const cappedBank = bankNameCaps(form.bank_name || form.name)
      return apiRequest<{ data: BankAccount }>('/bank-accounts', {
        method: 'POST',
        token,
        companyId,
        bookId,
        body: {
          code: form.code.trim(),
          name: cappedName,
          bank_name: cappedBank || cappedName,
          account_number: form.account_number.trim() || null,
          currency_code: form.currency_code.trim().toUpperCase(),
          account_type: form.account_type,
          status: form.status,
          opening_balance: openingBalance,
          opening_date: form.opening_date || null,
          ...logoPayload(),
        },
      })
    },
    onSuccess: async () => {
      if (kind === 'bank') {
        const capped = bankNameCaps(form.name)
        if (capped && (!institutionSlug || institutionSlug.startsWith('custom-'))) {
          rememberCustomInstitutionLocally(
            capped,
            form.account_type === 'mobile_money' ? 'mobile' : 'bank',
            selectedLogo && !clearLogo ? selectedLogo : '',
          )
        }
      }
      resetForm()
      setFormMode(null)
      await Promise.all([
        client.invalidateQueries({ queryKey: ['bank-accounts', companyId, bookId] }),
        client.invalidateQueries({ queryKey: ['cash-accounts', companyId, bookId] }),
        client.invalidateQueries({ queryKey: ['accounts', companyId, bookId] }),
      ])
    },
    onError: (err) => {
      setFormError((err as ApiError | Error).message || 'Could not create account.')
    },
  })

  const updateAccount = useMutation({
    mutationFn: async () => {
      if (!editingId) throw new Error('Nothing to edit.')
      if (kind === 'cash') {
        return apiRequest<{ data: CashAccount }>(`/cash-accounts/${editingId}`, {
          method: 'PATCH',
          token,
          companyId,
          bookId,
          body: {
            name: form.name.trim(),
            currency_code: form.currency_code.trim().toUpperCase(),
            ...logoPayload(),
          },
        })
      }
      const cappedName = bankNameCaps(form.name)
      const cappedBank = bankNameCaps(form.bank_name || form.name)
      return apiRequest<{ data: BankAccount }>(`/bank-accounts/${editingId}`, {
        method: 'PATCH',
        token,
        companyId,
        bookId,
        body: {
          name: cappedName,
          bank_name: cappedBank || cappedName,
          account_number: form.account_number.trim() || null,
          currency_code: form.currency_code.trim().toUpperCase(),
          account_type: form.account_type,
          ...logoPayload(),
        },
      })
    },
    onSuccess: async () => {
      resetForm()
      setFormMode(null)
      setMenuKey('')
      await Promise.all([
        client.invalidateQueries({ queryKey: ['bank-accounts', companyId, bookId] }),
        client.invalidateQueries({ queryKey: ['cash-accounts', companyId, bookId] }),
      ])
    },
    onError: (err) => {
      setFormError((err as ApiError | Error).message || 'Could not update account.')
    },
  })

  const updateStatus = useMutation({
    mutationFn: async () => {
      if (!statusEdit) throw new Error('Nothing to update.')
      const account = statusEdit.account
      if (statusEdit.kind === 'cash') {
        return apiRequest<{ data: CashAccount }>(`/cash-accounts/${account.id}`, {
          method: 'PATCH',
          token,
          companyId,
          bookId,
          body: {
            name: account.name,
            currency_code: account.currency_code,
            status: statusEdit.status,
          },
        })
      }
      const bank = account as BankAccount
      return apiRequest<{ data: BankAccount }>(`/bank-accounts/${bank.id}`, {
        method: 'PATCH',
        token,
        companyId,
        bookId,
        body: {
          name: bank.name,
          bank_name: bank.bank_name || bank.name,
          account_number: bank.account_number || null,
          currency_code: bank.currency_code,
          account_type: bank.account_type === 'mobile_money' ? 'mobile_money' : 'bank',
          status: statusEdit.status,
        },
      })
    },
    onSuccess: async () => {
      setStatusEdit(null)
      setStatusError('')
      setMenuKey('')
      await Promise.all([
        client.invalidateQueries({ queryKey: ['bank-accounts', companyId, bookId] }),
        client.invalidateQueries({ queryKey: ['cash-accounts', companyId, bookId] }),
      ])
    },
    onError: (err) => {
      setStatusError((err as ApiError | Error).message || 'Could not update status.')
    },
  })

  const openCreate = () => {
    resetForm()
    setKind('bank')
    setFormMode('create')
  }

  const openStatus = (nextKind: Kind, account: BankAccount | CashAccount) => {
    setMenuKey('')
    setStatusError('')
    const currentlyInactive = String(account.status || '').toLowerCase() === 'inactive'
    setStatusEdit({
      kind: nextKind,
      account,
      // Menu says Activate / Deactivate — open ready to apply that action.
      status: currentlyInactive ? 'active' : 'inactive',
    })
  }

  const openEdit = (nextKind: Kind, account: BankAccount | CashAccount) => {
    setMenuKey('')
    setFormError('')
    setNameOpen(false)
    setCurrencyOpen(false)
    setInstitutionSlug('')
    setLogoBase64('')
    setLogoFileName('')
    setClearLogo(false)
    setKind(nextKind)
    setEditingId(account.id)
    setFormMode('edit')

    const openingBalance =
      account.opening_balance != null && Number.isFinite(Number(account.opening_balance))
        ? String(account.opening_balance)
        : ''
    const openingDate = account.opening_date
      ? String(account.opening_date).slice(0, 10)
      : ''

    if (nextKind === 'cash') {
      setForm({
        ...emptyForm,
        code: account.code || '',
        name: account.name || '',
        currency_code: account.currency_code || 'TZS',
        status: 'active',
        opening_balance: openingBalance,
        opening_date: openingDate,
      })
      setSelectedLogo(
        String(account.logo_url || '').trim() ||
          resolveInstitutionLogo(institutions, account.name),
      )
      return
    }

    const bank = account as BankAccount
    setForm({
      code: bank.code || '',
      name: bank.name || '',
      bank_name: bank.bank_name || bank.name || '',
      account_number: bank.account_number || '',
      currency_code: bank.currency_code || 'TZS',
      account_type: bank.account_type === 'mobile_money' ? 'mobile_money' : 'bank',
      status: 'active',
      opening_balance: openingBalance,
      opening_date: openingDate,
    })
    setSelectedLogo(
      String(bank.logo_url || '').trim() ||
        resolveInstitutionLogo(institutions, bank.bank_name, bank.name),
    )
  }

  useEffect(() => {
    if (!menuKey) return
    const onDoc = (event: MouseEvent) => {
      const target = event.target as HTMLElement | null
      if (target?.closest?.('[data-ua-actions]')) return
      setMenuKey('')
    }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [menuKey])

  const saving = create.isPending || updateAccount.isPending

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    if (formMode === 'edit') {
      updateAccount.mutate()
      return
    }
    create.mutate()
  }

  const closeForm = () => {
    if (saving) return
    setBankPickerOpen(false)
    setBankPickerQuery('')
    resetForm()
    setFormMode(null)
  }

  useEffect(() => {
    if (!formMode && !statusEdit && !bankPickerOpen) return
    const onKey = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return
      if (create.isPending || updateAccount.isPending || updateStatus.isPending) return
      if (bankPickerOpen) {
        setBankPickerOpen(false)
        setBankPickerQuery('')
        return
      }
      if (statusEdit) {
        setStatusEdit(null)
        setStatusError('')
        return
      }
      resetForm()
      setFormMode(null)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [formMode, statusEdit, bankPickerOpen, create.isPending, updateAccount.isPending, updateStatus.isPending])

  useEffect(() => {
    const open = Boolean(formMode) || bankPickerOpen
    if (!open) {
      document.documentElement.classList.remove('ua-sheet-open')
      document.body.classList.remove('ua-sheet-open')
      return
    }
    document.documentElement.classList.add('ua-sheet-open')
    document.body.classList.add('ua-sheet-open')
    window.scrollTo(0, window.scrollY)
    document.documentElement.scrollLeft = 0
    document.body.scrollLeft = 0
    return () => {
      document.documentElement.classList.remove('ua-sheet-open')
      document.body.classList.remove('ua-sheet-open')
    }
  }, [formMode, bankPickerOpen])

  useEffect(() => {
    if (!formMode) {
      setBankPickerOpen(false)
      setBankPickerQuery('')
    }
  }, [formMode])

  const apiFailed =
    (banks.isError || cash.isError) && bankRows.length === 0 && cashRows.length === 0
  const err = apiFailed
    ? (banks.error as ApiError | Error | null)?.message ||
      (cash.error as ApiError | Error | null)?.message ||
      'Unable to refresh banking data from API.'
    : null

  const activeBanks = useMemo(
    () =>
      bankRows.filter(
        (row) =>
          String(row.status || 'active').toLowerCase() !== 'inactive' &&
          row.account_type !== 'mobile_money',
      ),
    [bankRows],
  )
  const activeMobile = useMemo(
    () =>
      bankRows.filter(
        (row) =>
          String(row.status || 'active').toLowerCase() !== 'inactive' &&
          row.account_type === 'mobile_money',
      ),
    [bankRows],
  )
  const activeCash = useMemo(
    () =>
      cashRows.filter((row) => String(row.status || 'active').toLowerCase() !== 'inactive'),
    [cashRows],
  )

  const sumBalances = (rows: Array<{ balance?: number | null; currency_code?: string }>) => {
    let total = 0
    let hasAny = false
    for (const row of rows) {
      if (row.balance == null || !Number.isFinite(Number(row.balance))) continue
      total += Number(row.balance)
      hasAny = true
    }
    return { total, hasAny }
  }

  const bankBalance = sumBalances(activeBanks)
  const mobileBalance = sumBalances(activeMobile)
  const cashBalance = sumBalances(activeCash)
  const totalLiquidity = {
    total: bankBalance.total + mobileBalance.total + cashBalance.total,
    hasAny: bankBalance.hasAny || mobileBalance.hasAny || cashBalance.hasAny,
  }
  const moneyInCount = useMemo(
    () =>
      txnRows.filter((txn) => {
        const inAmt = txn.credit && Number(txn.credit) ? Number(txn.credit) : 0
        const outAmt = txn.debit && Number(txn.debit) ? Number(txn.debit) : 0
        return inAmt > 0 && outAmt <= 0
      }).length,
    [txnRows],
  )
  const moneyOutCount = useMemo(
    () =>
      txnRows.filter((txn) => {
        const inAmt = txn.credit && Number(txn.credit) ? Number(txn.credit) : 0
        const outAmt = txn.debit && Number(txn.debit) ? Number(txn.debit) : 0
        return outAmt > 0 && inAmt <= 0
      }).length,
    [txnRows],
  )
  const accountCount = activeBanks.length + activeMobile.length + activeCash.length

  return (
    <div className="ua-banking">
      <PageHeader
        title="Cash & Bank"
        actions={
          <Button type="button" pill onClick={openCreate} disabled={!ready}>
            Add account
          </Button>
        }
      />

      {!ready && bankRows.length === 0 ? (
        <ErrorBanner message="Company session not ready. Re-open Accounting from ERP." />
      ) : null}
      {err ? <ErrorBanner message={err} /> : null}

      <div className="ua-banking-kpis">
        <div className="ua-banking-kpi">
          <div className="ua-banking-kpi__text">
            <span className="ua-banking-kpi__label">Accounts</span>
            <strong className="ua-banking-kpi__value">{accountCount}</strong>
            <span className="ua-banking-kpi__sub">Active desks</span>
          </div>
          <span className="ua-banking-kpi__badge ua-banking-kpi__badge--indigo" aria-hidden="true">
            <SummaryBankIcon />
          </span>
        </div>
        <div className="ua-banking-kpi">
          <div className="ua-banking-kpi__text">
            <span className="ua-banking-kpi__label">Bank</span>
            <strong className="ua-banking-kpi__value">
              {bankBalance.hasAny ? money(bankBalance.total, 'TZS') : '—'}
            </strong>
            <span className="ua-banking-kpi__sub">{activeBanks.length} account{activeBanks.length === 1 ? '' : 's'}</span>
          </div>
          <span className="ua-banking-kpi__badge ua-banking-kpi__badge--blue" aria-hidden="true">
            <SummaryBankIcon />
          </span>
        </div>
        <div className="ua-banking-kpi">
          <div className="ua-banking-kpi__text">
            <span className="ua-banking-kpi__label">Mobile</span>
            <strong className="ua-banking-kpi__value">
              {mobileBalance.hasAny ? money(mobileBalance.total, 'TZS') : '—'}
            </strong>
            <span className="ua-banking-kpi__sub">{activeMobile.length} wallet{activeMobile.length === 1 ? '' : 's'}</span>
          </div>
          <span className="ua-banking-kpi__badge ua-banking-kpi__badge--amber" aria-hidden="true">
            <SummaryMovesIcon />
          </span>
        </div>
        <div className="ua-banking-kpi">
          <div className="ua-banking-kpi__text">
            <span className="ua-banking-kpi__label">Cash</span>
            <strong className="ua-banking-kpi__value">
              {cashBalance.hasAny ? money(cashBalance.total, 'TZS') : '—'}
            </strong>
            <span className="ua-banking-kpi__sub ua-banking-kpi__sub--green">
              {activeCash.length} account{activeCash.length === 1 ? '' : 's'}
            </span>
          </div>
          <span className="ua-banking-kpi__badge ua-banking-kpi__badge--green" aria-hidden="true">
            <PettyCashIcon />
          </span>
        </div>
        <div className="ua-banking-kpi">
          <div className="ua-banking-kpi__text">
            <span className="ua-banking-kpi__label">Money in</span>
            <strong className="ua-banking-kpi__value">{moneyInCount}</strong>
            <span className="ua-banking-kpi__sub ua-banking-kpi__sub--green">Credits</span>
          </div>
          <span className="ua-banking-kpi__badge ua-banking-kpi__badge--green" aria-hidden="true">
            <SummaryMovesIcon />
          </span>
        </div>
        <div className="ua-banking-kpi">
          <div className="ua-banking-kpi__text">
            <span className="ua-banking-kpi__label">Money out</span>
            <strong className="ua-banking-kpi__value">{moneyOutCount}</strong>
            <span className="ua-banking-kpi__sub ua-banking-kpi__sub--red">Debits</span>
          </div>
          <span className="ua-banking-kpi__badge ua-banking-kpi__badge--red" aria-hidden="true">
            <SummaryMovesIcon />
          </span>
        </div>
        <div className="ua-banking-kpi ua-banking-kpi--wide">
          <div className="ua-banking-kpi__text">
            <span className="ua-banking-kpi__label">Total liquidity</span>
            <strong className="ua-banking-kpi__value">
              {totalLiquidity.hasAny ? money(totalLiquidity.total, 'TZS') : '—'}
            </strong>
            <span className="ua-banking-kpi__sub">
              Bank {bankBalance.hasAny ? money(bankBalance.total, 'TZS') : '—'}
              {' / '}
              Mobile {mobileBalance.hasAny ? money(mobileBalance.total, 'TZS') : '—'}
              {' / '}
              Cash {cashBalance.hasAny ? money(cashBalance.total, 'TZS') : '—'}
            </span>
          </div>
          <span className="ua-banking-kpi__badge ua-banking-kpi__badge--blue" aria-hidden="true">
            <SummaryBankIcon />
          </span>
        </div>
      </div>

      {formMode
        ? createPortal(
        <div
          className="ua-sheet-overlay"
          role="presentation"
          onClick={closeForm}
        >
          <div
            ref={accountSheetRef}
            role="dialog"
            aria-modal="true"
            aria-labelledby="ua-account-form-title"
            className="ua-sheet"
            onClick={(event) => event.stopPropagation()}
          >
            <SheetDragHandle
              sheetRef={accountSheetRef}
              onDismiss={closeForm}
              disabled={saving}
            />
            <form className="ua-sheet__form" onSubmit={onSubmit}>
            <div className="ua-sheet__scroll">
            <div id="ua-account-form-title" className="ua-sheet__title">
              {formMode === 'edit'
                ? kind === 'cash'
                  ? 'Edit cash account'
                  : 'Edit bank / mobile account'
                : kind === 'cash'
                  ? 'Add cash account'
                  : 'Add bank details'}
            </div>

            {formMode === 'create' ? (
              <div className="ua-sheet__kind">
                <label className="ua-sheet__kind-option">
                  <input
                    type="radio"
                    name="ua-account-kind"
                    value="bank"
                    checked={kind === 'bank'}
                    onChange={() => setKind('bank')}
                    className="h-4 w-4 accent-[var(--brand)]"
                  />
                  Bank / mobile
                </label>
                <label className="ua-sheet__kind-option">
                  <input
                    type="radio"
                    name="ua-account-kind"
                    value="cash"
                    checked={kind === 'cash'}
                    onChange={() => setKind('cash')}
                    className="h-4 w-4 accent-[var(--brand)]"
                  />
                  Cash
                </label>
              </div>
            ) : null}

            {formError ? <ErrorBanner message={formError} /> : null}

            <div className="grid gap-2 sm:grid-cols-2">
              <input type="hidden" name="code" value={form.code} readOnly />

              <div className="relative sm:col-span-2">
                <Label>{kind === 'bank' ? 'Bank name' : 'Name'}</Label>
                <div className="ua-banking-identity">
                  <div
                    className={`ua-banking-logo-upload${
                      selectedLogo && !clearLogo ? ' ua-banking-logo-upload--has-image' : ''
                    }`}
                  >
                    <label
                      className="ua-banking-logo-upload__hit"
                      title="Upload account logo"
                      aria-label="Upload account logo"
                    >
                      {selectedLogo && !clearLogo ? (
                        <img src={selectedLogo} alt="" className="ua-banking-logo-upload__img" />
                      ) : (
                        <span
                          className={`ua-banking-logo-upload__placeholder${
                            kind === 'cash' ? ' ua-banking-logo-upload__placeholder--cash' : ''
                          }`}
                        >
                          {kind === 'cash' ? <PettyCashIcon /> : <CameraImageIcon />}
                        </span>
                      )}
                      <input
                        type="file"
                        accept=".jpg,.jpeg,.png,.webp,.gif,image/*"
                        className="sr-only"
                        onChange={(e) => onLogoFile(e.target.files?.[0] ?? null)}
                      />
                    </label>
                    {selectedLogo || logoBase64 || institutionSlug ? (
                      <button
                        type="button"
                        className="ua-banking-logo-upload__clear"
                        title="Remove logo"
                        aria-label="Remove logo"
                        onClick={() => {
                          setSelectedLogo('')
                          setInstitutionSlug('')
                          setLogoBase64('')
                          setLogoFileName('')
                          setClearLogo(true)
                        }}
                      >
                        ×
                      </button>
                    ) : null}
                  </div>
                  <div className="ua-banking-identity__field">
                    {kind === 'bank' && isNarrow ? (
                      <button
                        type="button"
                        className="ua-bank-trigger"
                        onClick={openBankPicker}
                        aria-haspopup="dialog"
                        aria-expanded={bankPickerOpen}
                      >
                        <span className={form.name.trim() ? 'ua-bank-trigger__value' : 'ua-bank-trigger__placeholder'}>
                          {form.name.trim()
                            ? form.name
                            : form.account_type === 'mobile_money'
                              ? 'Select mobile money…'
                              : 'Select bank…'}
                        </span>
                        <span className="ua-bank-trigger__chevron" aria-hidden="true">
                          ▾
                        </span>
                      </button>
                    ) : (
                      <Input
                        value={form.name}
                        onChange={(e) => {
                          const nextName =
                            kind === 'bank' ? e.target.value.toUpperCase() : e.target.value
                          setForm({
                            ...form,
                            name: nextName,
                            bank_name: kind === 'bank' ? nextName : form.bank_name,
                          })
                          setSelectedLogo('')
                          setInstitutionSlug('')
                          setLogoBase64('')
                          setLogoFileName('')
                          setClearLogo(false)
                          setNameOpen(kind === 'bank')
                        }}
                        onFocus={() => {
                          if (kind === 'bank' && form.name.trim()) setNameOpen(true)
                        }}
                        onBlur={() => {
                          window.setTimeout(() => setNameOpen(false), 150)
                        }}
                        style={kind === 'bank' ? { textTransform: 'uppercase' } : undefined}
                        placeholder={
                          kind === 'cash'
                            ? 'Office Petty Cash'
                            : form.account_type === 'mobile_money'
                              ? 'Search mobile money…'
                              : 'Search bank…'
                        }
                        required
                        autoFocus={kind === 'cash' || !isNarrow}
                        autoComplete="off"
                        role={kind === 'bank' ? 'combobox' : undefined}
                        aria-expanded={kind === 'bank' ? nameOpen : undefined}
                        aria-autocomplete={kind === 'bank' ? 'list' : undefined}
                      />
                    )}
                    {kind === 'bank' && isNarrow ? (
                      <input type="hidden" name="name" value={form.name} required />
                    ) : null}
                  </div>
                </div>
                <p className="ua-banking-logo-hint">
                  Optional logo · JPG, PNG, WEBP, GIF · max 2MB
                  {kind === 'bank' ? ' · or pick a bank/mobile name for its logo' : ''}
                </p>
                {kind === 'bank' && !isNarrow && nameOpen && institutionSuggestions.length > 0 ? (
                  <ul
                    role="listbox"
                    className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border border-[var(--line)] bg-white py-1 shadow-lg"
                  >
                    {institutionSuggestions.map((item) => (
                      <li key={item.slug} role="option">
                        <button
                          type="button"
                          className="flex w-full items-center gap-2 px-2.5 py-2 text-left text-sm hover:bg-[var(--surface)]"
                          onMouseDown={(e) => e.preventDefault()}
                          onClick={() => pickInstitution(item)}
                        >
                          {item.logo_url ? (
                            <img
                              src={item.logo_url}
                              alt=""
                              className="h-8 w-8 shrink-0 rounded border border-[var(--line)] bg-white object-contain p-0.5"
                            />
                          ) : (
                            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded border border-[var(--line)] bg-[var(--surface)] text-[10px] text-[var(--muted)]">
                              {item.name.slice(0, 2).toUpperCase()}
                            </span>
                          )}
                          <span className="min-w-0 flex-1">
                            <span className="block truncate uppercase text-[var(--ink)]">{item.name}</span>
                            <span className="block text-xs text-[var(--muted)]">
                              {String(item.type).toLowerCase() === 'mobile' ? 'Mobile money' : 'Bank'}
                            </span>
                          </span>
                        </button>
                      </li>
                    ))}
                  </ul>
                ) : null}
              </div>

              {kind === 'bank' ? (
                <>
                  <div>
                    <Label>Account no.</Label>
                    <Input
                      value={form.account_number}
                      onChange={(e) => setForm({ ...form, account_number: e.target.value })}
                    />
                  </div>
                  <div>
                    <Label>Type</Label>
                    <Select
                      value={form.account_type}
                      onChange={(e) => {
                        setForm({ ...form, account_type: e.target.value })
                        setSelectedLogo('')
                        setNameOpen(Boolean(form.name.trim()))
                      }}
                    >
                      <option value="bank">Bank</option>
                      <option value="mobile_money">Mobile money</option>
                    </Select>
                  </div>
                </>
              ) : null}

              <div className="relative">
                <Label>Currency</Label>
                <button
                  type="button"
                  className="flex w-full items-center gap-2 rounded-md border border-[var(--line)] bg-white px-3 py-2 text-left text-sm outline-none ring-[var(--brand)] focus:ring-2"
                  onClick={() => setCurrencyOpen((open) => !open)}
                  aria-haspopup="listbox"
                  aria-expanded={currencyOpen}
                >
                  <img
                    src={currencyFlagUrl(selectedCurrency.flag)}
                    alt=""
                    className="h-5 w-7 shrink-0 rounded-sm border border-[var(--line)] object-cover"
                  />
                  <span className="min-w-0 flex-1 truncate">
                    {selectedCurrency.code} — {selectedCurrency.name}
                  </span>
                </button>
                {currencyOpen ? (
                  <ul
                    role="listbox"
                    className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border border-[var(--line)] bg-white py-1 shadow-lg"
                  >
                    {CURRENCIES.map((currency) => (
                      <li key={currency.code} role="option">
                        <button
                          type="button"
                          className="flex w-full items-center gap-2 px-2.5 py-2 text-left text-sm hover:bg-[var(--surface)]"
                          onMouseDown={(e) => e.preventDefault()}
                          onClick={() => {
                            setForm({ ...form, currency_code: currency.code })
                            setCurrencyOpen(false)
                          }}
                        >
                          <img
                            src={currencyFlagUrl(currency.flag)}
                            alt=""
                            className="h-5 w-7 shrink-0 rounded-sm border border-[var(--line)] object-cover"
                          />
                          <span className="min-w-0 flex-1">
                            <span className="block text-[var(--ink)]">{currency.code}</span>
                            <span className="block text-xs text-[var(--muted)]">{currency.name}</span>
                          </span>
                        </button>
                      </li>
                    ))}
                  </ul>
                ) : null}
              </div>

              {formMode === 'create' ? (
                <>
                  <div>
                    <Label>Opening balance</Label>
                    <Input
                      value={form.opening_balance}
                      onChange={(e) => setForm({ ...form, opening_balance: e.target.value })}
                      inputMode="decimal"
                      placeholder="0"
                    />
                  </div>
                  <div>
                    <Label>Opening date</Label>
                    <Input
                      type="date"
                      value={form.opening_date}
                      onChange={(e) => setForm({ ...form, opening_date: e.target.value })}
                    />
                  </div>
                </>
              ) : form.opening_balance !== '' || form.opening_date ? (
                <div className="sm:col-span-2 text-xs text-[var(--muted)]">
                  Opening{' '}
                  {form.opening_balance !== ''
                    ? money(Number(form.opening_balance) || 0, form.currency_code || 'TZS')
                    : '—'}
                  {form.opening_date ? ` on ${form.opening_date}` : ''}. Live balance stays from
                  classic transactions.
                </div>
              ) : null}

              {formMode === 'create' ? (
                <div className="ua-sheet__hint sm:col-span-2 text-xs text-[var(--muted)]">
                  A matching GL asset account is created automatically in classic Balances.
                </div>
              ) : null}
            </div>
            </div>

            <div className="ua-sheet__actions">
              <Button type="submit" disabled={!ready || saving || !form.name.trim()}>
                {saving ? 'Saving…' : formMode === 'edit' ? 'Save changes' : 'Save account'}
              </Button>
              <Button type="button" variant="secondary" onClick={closeForm} disabled={saving}>
                Cancel
              </Button>
            </div>
            </form>
          </div>
        </div>,
        document.body,
      )
        : null}

      {bankPickerOpen
        ? createPortal(
            <div
              className="ua-bank-picker-overlay"
              role="presentation"
              onClick={() => {
                setBankPickerOpen(false)
                setBankPickerQuery('')
              }}
            >
              <div
                ref={bankPickerRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby="ua-bank-picker-title"
                className="ua-bank-picker"
                onClick={(event) => event.stopPropagation()}
              >
                <SheetDragHandle
                  sheetRef={bankPickerRef}
                  onDismiss={() => {
                    setBankPickerOpen(false)
                    setBankPickerQuery('')
                  }}
                />
                <div id="ua-bank-picker-title" className="ua-bank-picker__title">
                  {form.account_type === 'mobile_money' ? 'Select mobile money' : 'Select Bank'}
                </div>
                <div className="ua-bank-picker__search">
                  <input
                    type="search"
                    value={bankPickerQuery}
                    onChange={(e) => setBankPickerQuery(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' && bankPickerQuery.trim() && !bankPickerExactMatch) {
                        e.preventDefault()
                        useTypedBankName()
                      }
                    }}
                    placeholder={
                      form.account_type === 'mobile_money'
                        ? 'Search or type a new name …'
                        : 'Search or type a new bank …'
                    }
                    autoFocus
                    autoComplete="off"
                    enterKeyHint="done"
                  />
                </div>
                <ul className="ua-bank-picker__list" role="listbox">
                  {bankPickerQuery.trim() && !bankPickerExactMatch ? (
                    <li role="option">
                      <button
                        type="button"
                        className="ua-bank-picker__row ua-bank-picker__row--add"
                        onClick={useTypedBankName}
                      >
                        <span className="ua-bank-picker__logo ua-bank-picker__logo--add">+</span>
                        <span className="ua-bank-picker__name">
                          Add “{bankNameCaps(bankPickerQuery)}”
                        </span>
                      </button>
                    </li>
                  ) : null}
                  {bankPickerList.length === 0 &&
                  !(bankPickerQuery.trim() && !bankPickerExactMatch) ? (
                    <li className="ua-bank-picker__empty">
                      Type a name above to add a new{' '}
                      {form.account_type === 'mobile_money' ? 'mobile money' : 'bank'}.
                    </li>
                  ) : (
                    bankPickerList.map((item) => (
                      <li key={item.slug} role="option">
                        <button
                          type="button"
                          className="ua-bank-picker__row"
                          onClick={() => pickInstitution(item)}
                        >
                          {item.logo_url ? (
                            <img src={item.logo_url} alt="" className="ua-bank-picker__logo" />
                          ) : (
                            <span className="ua-bank-picker__logo ua-bank-picker__logo--fallback">
                              {item.name.slice(0, 2).toUpperCase()}
                            </span>
                          )}
                          <span className="ua-bank-picker__name">{item.name.toUpperCase()}</span>
                        </button>
                      </li>
                    ))
                  )}
                </ul>
              </div>
            </div>,
            document.body,
          )
        : null}

      {statusEdit ? (
        <div
          className="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto bg-slate-900/45 p-4 sm:items-center"
          role="presentation"
          onClick={() => {
            if (!updateStatus.isPending) {
              setStatusEdit(null)
              setStatusError('')
            }
          }}
        >
          <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="ua-account-status-title"
            className="w-full max-w-sm rounded-lg border border-[var(--line)] bg-white p-4 shadow-xl"
            onClick={(event) => event.stopPropagation()}
          >
            {(() => {
              const statusAccount = statusEdit.account
              const statusBank =
                statusEdit.kind === 'bank' ? (statusAccount as BankAccount) : null
              const statusLabelText =
                statusBank?.bank_name || statusAccount.name || 'Account'
              const statusLogo =
                String(statusAccount.logo_url || '').trim() ||
                (statusEdit.kind === 'bank'
                  ? resolveInstitutionLogo(
                      institutions,
                      statusBank?.bank_name,
                      statusAccount.name,
                    )
                  : '')
              return (
                <>
                  <div
                    id="ua-account-status-title"
                    className="mb-3 flex items-center gap-3 text-base font-normal text-[var(--ink)]"
                  >
                    <BankLogo
                      src={statusLogo}
                      label={statusLabelText}
                      variant={statusEdit.kind === 'cash' ? 'cash' : 'bank'}
                    />
                    <span className="min-w-0">
                      <span className="block truncate">{statusAccount.name}</span>
                      <span className="block text-xs text-[var(--muted)]">
                        {statusEdit.status === 'active' ? 'Activate' : 'Deactivate'} account
                      </span>
                    </span>
                  </div>
                  {statusError ? <ErrorBanner message={statusError} /> : null}
                  <div className="mb-4 flex flex-wrap gap-4">
                    <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-[var(--ink)]">
                      <input
                        type="radio"
                        name="ua-account-status"
                        value="active"
                        checked={statusEdit.status === 'active'}
                        onChange={() => setStatusEdit({ ...statusEdit, status: 'active' })}
                        className="h-4 w-4 accent-[var(--brand)]"
                      />
                      Activate
                    </label>
                    <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-[var(--ink)]">
                      <input
                        type="radio"
                        name="ua-account-status"
                        value="inactive"
                        checked={statusEdit.status === 'inactive'}
                        onChange={() => setStatusEdit({ ...statusEdit, status: 'inactive' })}
                        className="h-4 w-4 accent-[var(--brand)]"
                      />
                      Deactivate
                    </label>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <Button
                      type="button"
                      disabled={!ready || updateStatus.isPending}
                      onClick={() => updateStatus.mutate()}
                    >
                      {updateStatus.isPending
                        ? 'Saving…'
                        : statusEdit.status === 'active'
                          ? 'Activate'
                          : 'Deactivate'}
                    </Button>
                    <Button
                      type="button"
                      variant="secondary"
                      disabled={updateStatus.isPending}
                      onClick={() => {
                        setStatusEdit(null)
                        setStatusError('')
                      }}
                    >
                      Cancel
                    </Button>
                  </div>
                </>
              )
            })()}
          </div>
        </div>
      ) : null}

      <section className="ua-banking-panel">
        <div className="ua-banking-panel__head ua-banking-panel__head--list">
          <SectionTitle>Bank & mobile accounts</SectionTitle>
        </div>
        <div className="ua-banking-panel__body">
        {banks.isLoading && bankRows.length === 0 ? (
          <div className="ua-banking-panel__note">Loading…</div>
        ) : bankRows.length ? (
          <>
            <div className="ua-banking-desktop-only">
              <Table
                scrollable={false}
                headers={['Bank', 'Account Number', 'Currency', 'Balance', 'Status', '']}
              >
                {bankRows.map((account) => {
                  const bankLabel = account.name || account.bank_name || 'Bank'
                  const logo =
                    String(account.logo_url || '').trim() ||
                    resolveInstitutionLogo(institutions, account.bank_name, account.name)
                  const rowKey = `bank-${account.id}`
                  const inactive = String(account.status || '').toLowerCase() === 'inactive'
                  return (
                    <tr
                      key={account.id}
                      className={`border-t border-[var(--line)]${inactive ? ' ua-banking-row--inactive' : ''}`}
                    >
                      <td className="px-3 py-2">
                        <div className="ua-banking-bankcell">
                          <BankLogo src={logo} label={bankLabel} />
                          <span>{bankLabel}</span>
                        </div>
                      </td>
                      <td className="px-3 py-2">{formatAccountNumber(account.account_number)}</td>
                      <td className="px-3 py-2">{account.currency_code}</td>
                      <td className="px-3 py-2">
                        <BalanceCell
                          balance={account.balance}
                          currency={account.currency_code || 'TZS'}
                          direction={account.balance_direction}
                          delta={account.balance_delta}
                          changedAt={account.balance_changed_at}
                          basis={account.balance_change_basis}
                        />
                      </td>
                      <td className="px-3 py-2">
                        <span
                          className={`ua-banking-status${inactive ? ' ua-banking-status--inactive' : ''}`}
                        >
                          {statusLabel(account.status)}
                        </span>
                      </td>
                      <td className="px-3 py-2 ua-banking-actions-cell">
                        <div className="ua-banking-actions" data-ua-actions>
                          <button
                            type="button"
                            className="ua-banking-actions__btn"
                            aria-label={`Actions for ${account.name}`}
                            aria-haspopup="menu"
                            aria-expanded={menuKey === rowKey}
                            onClick={() => setMenuKey((cur) => (cur === rowKey ? '' : rowKey))}
                          >
                            <span aria-hidden="true">⋯</span>
                          </button>
                          {menuKey === rowKey ? (
                            <div className="ua-banking-actions__menu" role="menu">
                              <button
                                type="button"
                                role="menuitem"
                                onClick={() => openEdit('bank', account)}
                              >
                                Edit account
                              </button>
                              <button
                                type="button"
                                role="menuitem"
                                onClick={() => openStatus('bank', account)}
                              >
                                {inactive ? 'Activate' : 'Deactivate'}
                              </button>
                            </div>
                          ) : null}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </Table>
            </div>
            <div className="ua-acct-cards ua-banking-mobile-only" role="list">
              {bankRows.map((account) => {
                const bankLabel = account.name || account.bank_name || 'Bank'
                const logo =
                  String(account.logo_url || '').trim() ||
                  resolveInstitutionLogo(institutions, account.bank_name, account.name)
                const rowKey = `bank-m-${account.id}`
                const inactive = String(account.status || '').toLowerCase() === 'inactive'
                return (
                  <AccountMobileCard
                    key={account.id}
                    title={bankLabel}
                    logo={logo}
                    accountNumber={account.account_number}
                    currency={account.currency_code}
                    inactive={inactive}
                    status={statusLabel(account.status)}
                    menuOpen={menuKey === rowKey}
                    onToggleMenu={() => setMenuKey((cur) => (cur === rowKey ? '' : rowKey))}
                    onEdit={() => openEdit('bank', account)}
                    onStatus={() => openStatus('bank', account)}
                    balance={
                      <BalanceCell
                        balance={account.balance}
                        currency={account.currency_code || 'TZS'}
                        direction={account.balance_direction}
                        delta={account.balance_delta}
                        changedAt={account.balance_changed_at}
                        basis={account.balance_change_basis}
                      />
                    }
                  />
                )
              })}
            </div>
          </>
        ) : (
          <EmptyState title="No bank accounts yet" body="Click Add account, then choose Bank / mobile." />
        )}
        </div>
      </section>

      <section className="ua-banking-panel">
        <div className="ua-banking-panel__head ua-banking-panel__head--list">
          <SectionTitle>Cash accounts</SectionTitle>
        </div>
        <div className="ua-banking-panel__body">
        {cash.isLoading && cashRows.length === 0 ? (
          <div className="ua-banking-panel__note">Loading…</div>
        ) : cashRows.length ? (
          <>
            <div className="ua-banking-desktop-only">
              <Table
                scrollable={false}
                headers={['Account', 'Account Name', 'Currency', 'Balance', 'Status', '']}
              >
                {cashRows.map((account) => {
                  const logo = String(account.logo_url || '').trim()
                  const rowKey = `cash-${account.id}`
                  const inactive = String(account.status || '').toLowerCase() === 'inactive'
                  return (
                    <tr
                      key={account.id}
                      className={`border-t border-[var(--line)]${inactive ? ' ua-banking-row--inactive' : ''}`}
                    >
                      <td className="px-3 py-2">
                        <BankLogo src={logo} label={account.name || 'Cash'} variant="cash" />
                      </td>
                      <td className="px-3 py-2">{account.name}</td>
                      <td className="px-3 py-2">{account.currency_code}</td>
                      <td className="px-3 py-2">
                        <BalanceCell
                          balance={account.balance}
                          currency={account.currency_code || 'TZS'}
                          direction={account.balance_direction}
                          delta={account.balance_delta}
                          changedAt={account.balance_changed_at}
                          basis={account.balance_change_basis}
                        />
                      </td>
                      <td className="px-3 py-2">
                        <span
                          className={`ua-banking-status${inactive ? ' ua-banking-status--inactive' : ''}`}
                        >
                          {statusLabel(account.status)}
                        </span>
                      </td>
                      <td className="px-3 py-2 ua-banking-actions-cell">
                        <div className="ua-banking-actions" data-ua-actions>
                          <button
                            type="button"
                            className="ua-banking-actions__btn"
                            aria-label={`Actions for ${account.name}`}
                            aria-haspopup="menu"
                            aria-expanded={menuKey === rowKey}
                            onClick={() => setMenuKey((cur) => (cur === rowKey ? '' : rowKey))}
                          >
                            <span aria-hidden="true">⋯</span>
                          </button>
                          {menuKey === rowKey ? (
                            <div className="ua-banking-actions__menu" role="menu">
                              <button
                                type="button"
                                role="menuitem"
                                onClick={() => openEdit('cash', account)}
                              >
                                Edit account
                              </button>
                              <button
                                type="button"
                                role="menuitem"
                                onClick={() => openStatus('cash', account)}
                              >
                                {inactive ? 'Activate' : 'Deactivate'}
                              </button>
                            </div>
                          ) : null}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </Table>
            </div>
            <div className="ua-acct-cards ua-banking-mobile-only" role="list">
              {cashRows.map((account) => {
                const logo = String(account.logo_url || '').trim()
                const rowKey = `cash-m-${account.id}`
                const inactive = String(account.status || '').toLowerCase() === 'inactive'
                return (
                  <AccountMobileCard
                    key={account.id}
                    title={account.name || 'Cash'}
                    logo={logo}
                    logoVariant="cash"
                    accountNumber={null}
                    currency={account.currency_code}
                    inactive={inactive}
                    status={statusLabel(account.status)}
                    menuOpen={menuKey === rowKey}
                    onToggleMenu={() => setMenuKey((cur) => (cur === rowKey ? '' : rowKey))}
                    onEdit={() => openEdit('cash', account)}
                    onStatus={() => openStatus('cash', account)}
                    balance={
                      <BalanceCell
                        balance={account.balance}
                        currency={account.currency_code || 'TZS'}
                        direction={account.balance_direction}
                        delta={account.balance_delta}
                        changedAt={account.balance_changed_at}
                        basis={account.balance_change_basis}
                      />
                    }
                  />
                )
              })}
            </div>
          </>
        ) : (
          <EmptyState title="No cash accounts yet" body="Click Add account, then choose Cash." />
        )}
        </div>
      </section>

      <section className="ua-banking-panel">
        <div className="ua-banking-panel__head ua-banking-panel__head--split">
          <SectionTitle>Money in and out</SectionTitle>
          <div className="ua-banking-panel__tools">
            <input
              className="ua-banking-search"
              value={txnQuery}
              onChange={(e) => setTxnQuery(e.target.value)}
              placeholder="Search movements"
              aria-label="Search movements"
            />
            <Link to="/banking/movements" className="ua-banking-viewall">
              View all
            </Link>
          </div>
        </div>
        <div className="ua-banking-panel__body">
        {txns.isLoading && txnRows.length === 0 ? (
          <div className="ua-banking-panel__note">Loading…</div>
        ) : previewTxns.length ? (
          <>
            <BankingMovementsList items={previewTxns} accountName={movementAccountName} />
            {hasMoreTxns || txnRows.length > 20 ? (
              <div className="ua-banking-panel__footer">
                <span className="ua-banking-panel__count">
                  Showing {previewTxns.length} of {filteredTxns.length}
                  {txnQuery.trim() ? ' matching' : ''}
                </span>
                <Link to="/banking/movements" className="ua-banking-viewall">
                  View all
                </Link>
              </div>
            ) : null}
          </>
        ) : (
          <EmptyState
            title="No movements yet"
            body="When money comes in or goes out, it will show here."
          />
        )}
        </div>
      </section>
    </div>
  )
}
