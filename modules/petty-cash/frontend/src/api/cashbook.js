function apiBase() {
  const raw = typeof window !== 'undefined' ? window.__CASHBOOK_API_BASE__ : ''
  return String(raw || '').replace(/\/$/, '')
}

function pageBase() {
  const raw = typeof window !== 'undefined' ? window.__CASHBOOK_PAGE_BASE__ : ''
  return String(raw || '').replace(/\/$/, '')
}

export function deskUrl(desk, params = {}) {
  const q = new URLSearchParams({ module: 'petty_cash', desk, ...params })
  return `${pageBase()}/desk.php?${q.toString()}`
}

export function booksUrl() {
  return `${pageBase()}/index.php?module=petty_cash`
}

async function request(resource, { id, method = 'GET', query, body } = {}) {
  const base = apiBase()
  const params = new URLSearchParams({ resource, ...(query || {}) })
  if (id) params.set('id', String(id))

  let httpMethod = method
  const opts = {
    method: httpMethod,
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  }

  if (body != null) {
    // Some PHP hosts reject PUT/DELETE — send POST + _method
    if (method === 'PUT' || method === 'DELETE') {
      opts.method = 'POST'
      params.set('_method', method)
    }
    opts.headers['Content-Type'] = 'application/json'
    opts.body = JSON.stringify(body)
  }

  const res = await fetch(`${base}/index.php?${params.toString()}`, opts)
  const data = await res.json().catch(() => ({}))
  if (!res.ok || data.ok === false) {
    throw new Error(data.error || `Request failed (${res.status})`)
  }
  return data
}

export const fetchInit = () => request('init')
export const fetchBooks = (status = 'active') => request('books', { query: { status } })
export const createBook = (body) => request('books', { method: 'POST', body })
export const updateBook = (id, body) => request('books', { id, method: 'PUT', body })
export const deleteBook = (id) => request('books', { id, method: 'DELETE', body: {} })

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
  const base = apiBase()
  const params = new URLSearchParams({ resource: 'import' })
  const body = new FormData()
  body.append('book_id', String(bookId))
  body.append('file', file)

  const res = await fetch(`${base}/index.php?${params.toString()}`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
    body,
  })
  const data = await res.json().catch(() => ({}))
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
