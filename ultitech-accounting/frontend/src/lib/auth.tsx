import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react'
import { apiRequest, type CompanySummary } from './api'
import { bootToEmbed, readBoot } from './boot'
import { clearErpEmbed, loadErpEmbed, saveErpEmbed, type ErpEmbedState } from './erpEmbed'

type User = { id: number; uuid: string; name: string; email: string }

type BridgeSession = {
  token: string
  user: User
  companies: CompanySummary[]
  companyId: number
  embed: ErpEmbedState
}

type AuthState = {
  token: string | null
  user: User | null
  companies: CompanySummary[]
  companyId: number | null
  bookId: number | null
  embed: ErpEmbedState
  hostedInErp: boolean
  classicAccountingUrl: string
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  setCompanyId: (id: number) => void
  setBookId: (id: number | null) => void
  applyBridgeSession: (session: BridgeSession) => void
}

const AuthContext = createContext<AuthState | null>(null)
const STORAGE_KEY = 'ultitech_accounting_auth'

function loadStored() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

function resolveCompanyId(boot: ReturnType<typeof readBoot>, companies: CompanySummary[]): number | null {
  const raw = boot.companyId ?? boot.company_id ?? null
  const n = raw == null ? NaN : Number(raw)
  if (Number.isFinite(n) && n > 0) return n
  const preferred = companies.find((c) => c.is_default) ?? companies[0]
  return preferred?.id ?? null
}

function initialFromBoot() {
  const boot = readBoot()
  if (boot.hostedInErp && boot.token) {
    const embed = bootToEmbed(boot) ?? loadErpEmbed()
    if (embed) saveErpEmbed(embed)
    const companies = Array.isArray(boot.companies) ? boot.companies : []
    return {
      token: boot.token,
      user: boot.user ?? null,
      companies,
      companyId: resolveCompanyId(boot, companies),
      bookId: boot.bookId != null && Number(boot.bookId) > 0 ? Number(boot.bookId) : null,
      embed: embed ?? loadErpEmbed(),
      hostedInErp: true,
      classicAccountingUrl: boot.classicAccountingUrl || embed?.backUrl || '',
    }
  }
  const stored = loadStored()
  return {
    token: (stored?.token as string | null) ?? null,
    user: (stored?.user as User | null) ?? null,
    companies: (stored?.companies as CompanySummary[]) ?? [],
    companyId: (stored?.companyId as number | null) ?? null,
    bookId: (stored?.bookId as number | null) ?? null,
    embed: loadErpEmbed(),
    hostedInErp: false,
    classicAccountingUrl: '',
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const initial = initialFromBoot()
  const [token, setToken] = useState<string | null>(initial.token)
  const [user, setUser] = useState<User | null>(initial.user)
  const [companies, setCompanies] = useState<CompanySummary[]>(initial.companies)
  const [companyId, setCompanyIdState] = useState<number | null>(initial.companyId)
  const [bookId, setBookId] = useState<number | null>(initial.bookId)
  const [embed, setEmbed] = useState<ErpEmbedState>(initial.embed)
  const hostedInErp = initial.hostedInErp
  const classicAccountingUrl = initial.classicAccountingUrl

  const persist = (next: Record<string, unknown>) => {
    if (hostedInErp) return
    localStorage.setItem(STORAGE_KEY, JSON.stringify(next))
  }

  const login = async (email: string, password: string) => {
    const result = await apiRequest<{
      token: string
      user: User
      companies: CompanySummary[]
    }>('/auth/login', { method: 'POST', body: { email, password } })

    const defaultCompany = result.companies.find((c) => c.is_default) ?? result.companies[0] ?? null
    clearErpEmbed()
    setEmbed(loadErpEmbed())
    setToken(result.token)
    setUser(result.user)
    setCompanies(result.companies)
    setCompanyIdState(defaultCompany?.id ?? null)
    setBookId(null)
    persist({
      token: result.token,
      user: result.user,
      companies: result.companies,
      companyId: defaultCompany?.id ?? null,
      bookId: null,
    })
  }

  const applyBridgeSession = useCallback((session: BridgeSession) => {
    saveErpEmbed(session.embed)
    setEmbed(session.embed)
    setToken(session.token)
    setUser(session.user)
    setCompanies(session.companies)
    setCompanyIdState(session.companyId)
    setBookId(null)
    persist({
      token: session.token,
      user: session.user,
      companies: session.companies,
      companyId: session.companyId,
      bookId: null,
    })
  }, [])

  const logout = async () => {
    if (hostedInErp) {
      const dest = classicAccountingUrl || embed.backUrl
      if (dest) {
        window.location.href = dest
        return
      }
    }
    if (token) {
      try {
        await apiRequest('/auth/logout', { method: 'POST', token })
      } catch {
        // ignore
      }
    }
    const back = embed.backUrl
    clearErpEmbed()
    setEmbed(loadErpEmbed())
    setToken(null)
    setUser(null)
    setCompanies([])
    setCompanyIdState(null)
    setBookId(null)
    localStorage.removeItem(STORAGE_KEY)
    if (back) window.location.href = back
  }

  const setCompanyId = (id: number) => {
    if (embed.lockedCompany || hostedInErp) return
    setCompanyIdState(id)
    setBookId(null)
    persist({ token, user, companies, companyId: id, bookId: null })
  }

  const value = useMemo(
    () => ({
      token,
      user,
      companies,
      companyId,
      bookId,
      embed,
      hostedInErp,
      classicAccountingUrl,
      login,
      logout,
      setCompanyId,
      setBookId: (id: number | null) => {
        setBookId(id)
        persist({ token, user, companies, companyId, bookId: id })
      },
      applyBridgeSession,
    }),
    [token, user, companies, companyId, bookId, embed, hostedInErp, classicAccountingUrl, applyBridgeSession],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
