export type Bootstrap = {
  ok: boolean
  authenticated: boolean
  user: { id: number; username: string; email: string } | null
  settingsConfigured: boolean
  stats: { customers: number; staff: number; sentToday: number }
}

export type Contact = {
  id: number
  name: string
  phone: string
  type: 'customer' | 'staff'
  tags: string
  notes: string | null
  is_active: boolean
}

export type MessageItem = {
  id: number
  contact_id: number | null
  campaign_id: number | null
  direction: 'in' | 'out'
  phone: string
  body: string
  status: string
  matter: string
  error_message: string | null
  created_at: number
}

export type Campaign = {
  id: number
  title: string
  body: string
  audience: string
  status: string
  total: number
  sent_count: number
  failed_count: number
  sent_at: number | null
  created_at: number
}

export type Settings = {
  phone_number_id: string
  business_account_id: string
  display_phone: string
  webhook_verify_token: string
  access_token_set: boolean
  access_token_masked: string
  auto_reply_enabled: boolean
  auto_reply_text: string
  configured: boolean
}

declare global {
  interface Window {
    __WA_API_BASE__?: string
    __WA_WEB_BASE__?: string
  }
}

function apiRoot(): string {
  if (window.__WA_API_BASE__) return window.__WA_API_BASE__.replace(/\/$/, '')
  const path = window.location.pathname
  const idx = path.indexOf('/frontend/web')
  if (idx >= 0) return path.slice(0, idx) + '/frontend/web/index.php'
  return '/whatsapp/frontend/web/index.php'
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const url = `${apiRoot()}${path.startsWith('/') ? path : '/' + path}`
  const headers = new Headers(options.headers || {})
  if (options.body && !(options.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json')
  }
  headers.set('Accept', 'application/json')
  const res = await fetch(url, {
    ...options,
    headers,
    credentials: 'include',
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error((data as { message?: string }).message || `Request failed (${res.status})`)
  }
  return data as T
}

export const api = {
  bootstrap: () => request<Bootstrap>('/api/bootstrap'),
  login: (username: string, password: string) =>
    request<Bootstrap>('/api/login', {
      method: 'POST',
      body: JSON.stringify({ username, password, rememberMe: true }),
    }),
  logout: () => request<{ ok: boolean }>('/api/logout', { method: 'POST' }),
  contacts: (type?: string, q?: string) => {
    const params = new URLSearchParams()
    if (type) params.set('type', type)
    if (q) params.set('q', q)
    const qs = params.toString()
    return request<{ ok: boolean; contacts: Contact[] }>(`/api/contacts${qs ? `?${qs}` : ''}`)
  },
  createContact: (payload: Partial<Contact>) =>
    request<{ ok: boolean; contact: Contact }>('/api/contacts', {
      method: 'POST',
      body: JSON.stringify(payload),
    }),
  updateContact: (id: number, payload: Partial<Contact>) =>
    request<{ ok: boolean; contact: Contact }>(`/api/contacts/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    }),
  deleteContact: (id: number) =>
    request<{ ok: boolean }>(`/api/contacts/${id}`, { method: 'DELETE' }),
  send: (payload: { phone?: string; contact_id?: number; body: string; matter?: string }) =>
    request<{ ok: boolean; message: string; item: MessageItem }>('/api/send', {
      method: 'POST',
      body: JSON.stringify(payload),
    }),
  messages: () => request<{ ok: boolean; messages: MessageItem[] }>('/api/messages'),
  campaigns: () => request<{ ok: boolean; campaigns: Campaign[] }>('/api/campaigns'),
  createCampaign: (payload: { title: string; body: string; audience: string }) =>
    request<{ ok: boolean; campaign: Campaign }>('/api/campaigns', {
      method: 'POST',
      body: JSON.stringify(payload),
    }),
  sendCampaign: (id: number) =>
    request<{ ok: boolean; message: string; campaign: Campaign }>(`/api/campaigns/${id}/send`, {
      method: 'POST',
    }),
  settings: () =>
    request<{ ok: boolean; settings: Settings; webhookUrl: string }>('/api/settings'),
  saveSettings: (payload: Record<string, unknown>) =>
    request<{ ok: boolean; settings: Settings; webhookUrl: string }>('/api/settings', {
      method: 'PUT',
      body: JSON.stringify(payload),
    }),
}
