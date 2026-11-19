import { readBoot } from './boot'

function resolveApiBase(): string {
  const boot = readBoot()
  // Prefer same-origin proxy when hosted in ERP (avoids CORS / stripped Authorization).
  if (boot.hostedInErp) {
    const proxy = typeof boot.proxyApiBase === 'string' ? boot.proxyApiBase.trim() : ''
    if (proxy) return proxy
  }
  const fromBoot = boot.apiBase
  if (typeof fromBoot === 'string' && fromBoot.trim() !== '') {
    return fromBoot.trim()
  }
  return (import.meta.env.VITE_API_URL as string | undefined) ?? '/api/v1'
}

function buildUrl(path: string): string {
  const base = resolveApiBase()
  const normalizedPath = path.startsWith('/') ? path : `/${path}`

  // Same-origin proxy: .../api-proxy.php?ua_api=1&path=/api/v1  +  /bank-accounts
  if (base.includes('api-proxy.php') && base.includes('path=')) {
    const url = new URL(base, window.location.origin)
    const root = url.searchParams.get('path') || '/api/v1'
    url.searchParams.set('path', `${root.replace(/\/$/, '')}${normalizedPath}`)
    return url.toString()
  }

  return `${base.replace(/\/$/, '')}${normalizedPath}`
}

export type CompanySummary = {
  id: number
  uuid: string
  code: string
  legal_name: string
  role?: string
  permissions?: string[]
  is_default?: boolean
}

type RequestOptions = {
  method?: string
  body?: unknown
  companyId?: number | string | null
  bookId?: number | string | null
  token?: string | null
  idempotencyKey?: string
}

export class ApiError extends Error {
  status: number
  details: unknown

  constructor(message: string, status: number, details?: unknown) {
    super(message)
    this.status = status
    this.details = details
  }
}

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  }

  if (options.token) {
    headers.Authorization = `Bearer ${options.token}`
    // Apache/XAMPP often strips Authorization; proxy also reads this.
    headers['X-Authorization'] = `Bearer ${options.token}`
  }
  if (options.companyId) headers['X-Company-Id'] = String(options.companyId)
  if (options.bookId) headers['X-Book-Id'] = String(options.bookId)
  if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey

  const response = await fetch(buildUrl(path), {
    method: options.method ?? 'GET',
    headers,
    body: options.body ? JSON.stringify(options.body) : undefined,
  })

  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    throw new ApiError(payload.message ?? 'Request failed', response.status, payload)
  }

  return payload as T
}
