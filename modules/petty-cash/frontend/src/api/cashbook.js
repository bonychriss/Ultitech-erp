function readBootConfig() {
  try {
    const el = typeof document !== 'undefined' ? document.getElementById('cashbook-boot-config') : null
    if (!el) return {}
    return JSON.parse(el.textContent || '{}') || {}
  } catch {
    return {}
  }
}

function apiBase() {
  const boot = readBootConfig()
  const configured = typeof window !== 'undefined' ? window.__CASHBOOK_API_BASE__ : ''
  let base = String(configured || boot.apiBase || '').trim().replace(/\/$/, '')

  // Never call company-folder API roots — they 404 as HTML on physical /ultimate/.
  if (/\/[^/]+\/modules\/petty-cash\/api$/i.test(base)) {
    base = base.replace(/\/[^/]+\/modules\/petty-cash\/api$/i, '/modules/petty-cash/api')
  }

  if (base) return base

  const page = String(
    (typeof window !== 'undefined' ? window.__CASHBOOK_PAGE_BASE__ : '') || boot.pageBase || '',
  )
    .trim()
    .replace(/\/$/, '')

  if (page) {
    const stripped = page.replace(/\/[^/]+\/modules\/petty-cash$/i, '/modules/petty-cash')
    if (stripped.includes('/modules/petty-cash')) {
      return `${stripped}/api`.replace(/\/$/, '')
    }
    return `${page}/api`.replace(/\/$/, '')
  }

  // Relative fallback (works under /ultimate via modules proxy rewrite).
  return './api'
}

function pageBase() {
  const boot = readBootConfig()
  const raw =
    (typeof window !== 'undefined' ? window.__CASHBOOK_PAGE_BASE__ : '') || boot.pageBase || ''
  const base = String(raw || '').replace(/\/$/, '')
  if (base) return base
  const api = apiBase()
  if (api.endsWith('/api')) return api.slice(0, -4)
  return '.'
}

export function deskUrl(desk, params = {}) {
  const q = new URLSearchParams({ module: 'petty_cash', desk, ...params })
  return `${pageBase()}/desk.php?${q.toString()}`
}

export function booksUrl() {
  return `${pageBase()}/index.php?module=petty_cash`
}

function buildUrl(resource, { id, query, methodOverride } = {}) {
  const params = new URLSearchParams({ resource, ...(query || {}) })
  if (id) params.set('id', String(id))
  if (methodOverride) params.set('_method', methodOverride)

  const base = apiBase()
  const path = `${base}/index.php?${params.toString()}`
  try {
    // Resolve relative bases (./api) against the current page URL.
    return new URL(path, typeof window !== 'undefined' ? window.location.href : 'http://localhost').toString()
  } catch {
    return path
  }
}

async function parseJson(response) {
  const text = await response.text()
  try {
    return JSON.parse(text)
  } catch {
    const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 160)
    throw new Error(
      snippet.startsWith('<!')
        ? 'API returned HTML instead of JSON. Check that you are still logged in.'
        : snippet === ''
          ? 'API returned an empty response.'
          : `Invalid API response: ${snippet}`,
    )
  }
}

async function request(resource, { id, method = 'GET', query, body } = {}) {
  let httpMethod = method
  let methodOverride = ''
  // Some PHP hosts reject PUT/DELETE — send POST + _method
  if (method === 'PUT' || method === 'DELETE') {
    httpMethod = 'POST'
    methodOverride = method
  }

  const url = buildUrl(resource, { id, query, methodOverride })
  const opts = {
    method: httpMethod,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  }

  if (body != null) {
    opts.headers['Content-Type'] = 'application/json'
    opts.body = JSON.stringify(body)
  }

  let res
  try {
    res = await fetch(url, opts)
  } catch (err) {
    const detail = err && err.message ? err.message : 'Failed to fetch'
    throw new Error(`Cash Book API unreachable (${url}). ${detail}`)
  }

  const data = await parseJson(res)
  if (!res.ok || data.ok === false) {
    throw new Error(data.error || `Request failed (${res.status})`)
  }
  return data
}

export const fetchInit = () => request('init')
export const fetchBooks = (status = 'active') => request('books', { query: { status } })
export const createBook = (body) => request('books', { method: 'POST', body })
export const updateBook = (id, body) => request('books', { id, method: 'PUT', body })

/** Queues a delete request — does not remove the book until an admin approves. */
export const requestDeleteBook = (id, reason = '') =>
  request('delete-requests', { method: 'POST', body: { book_id: id, reason } })

export const approveDeleteRequest = (id) =>
  request('delete-requests', { id, method: 'POST', body: { action: 'approve' } })
export const rejectDeleteRequest = (id) =>
  request('delete-requests', { id, method: 'POST', body: { action: 'reject' } })
export const cancelDeleteRequest = (id) =>
  request('delete-requests', { id, method: 'POST', body: { action: 'cancel' } })

export const fetchEntries = (bookId, query = {}) =>
  request('entries', { query: { book_id: bookId, ...query } })
export const createEntry = (body) => request('entries', { method: 'POST', body })
export const updateEntry = (id, body) => request('entries', { id, method: 'PUT', body })
export const deleteEntry = (id) => request('entries', { id, method: 'DELETE', body: {} })

export const fetchCategories = (entryType) =>
  request('categories', { query: entryType ? { entry_type: entryType } : {} })
export const createCategory = (body) => request('categories', { method: 'POST', body })
export const deleteCategory = (id) => request('categories', { id, method: 'DELETE', body: {} })

export const fetchReport = (query = {}) => request('reports', { query })

export async function importSpreadsheet(bookId, file) {
  const url = buildUrl('import')
  const body = new FormData()
  body.append('book_id', String(bookId))
  body.append('file', file)

  let res
  try {
    res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      body,
    })
  } catch (err) {
    const detail = err && err.message ? err.message : 'Failed to fetch'
    throw new Error(`Cash Book API unreachable (${url}). ${detail}`)
  }

  const data = await parseJson(res)
  if (!res.ok || data.ok === false) {
    throw new Error(data.error || `Request failed (${res.status})`)
  }
  return data
}

export function formatMoney(n) {
  const v = Number(n) || 0
  return v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

export function formatDate(iso) {
  if (!iso) return ''
  const d = new Date(`${iso}T00:00:00`)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })
}

export function todayISO() {
  const d = new Date()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${d.getFullYear()}-${m}-${day}`
}
