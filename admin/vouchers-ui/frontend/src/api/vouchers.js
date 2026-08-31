function getApiBase() {
  if (typeof window !== 'undefined' && window.__VOUCHERS_API_BASE__) {
    return String(window.__VOUCHERS_API_BASE__).replace(/\/$/, '')
  }
  return './api'
}

export function getModule() {
  if (typeof window !== 'undefined' && window.__VOUCHERS_MODULE__) {
    return String(window.__VOUCHERS_MODULE__)
  }
  return ''
}

/**
 * Deployment config injected by the PHP shell (admin vs employee).
 * Falls back to the admin defaults so the admin page keeps working
 * even when no config object is provided.
 */
export function getConfig() {
  if (typeof window !== 'undefined' && window.__VOUCHERS_CFG__ && typeof window.__VOUCHERS_CFG__ === 'object') {
    return window.__VOUCHERS_CFG__
  }
  return {}
}

export function getReferenceToggleUrl() {
  if (typeof window !== 'undefined' && window.__VOUCHERS_REFERENCE_TOGGLE_URL__) {
    return String(window.__VOUCHERS_REFERENCE_TOGGLE_URL__)
  }
  return '/toggle-voucher-reference.php'
}

export function getSuggestUrl() {
  if (typeof window !== 'undefined' && window.__VOUCHERS_SUGGEST_URL__) {
    return String(window.__VOUCHERS_SUGGEST_URL__)
  }
  return ''
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

export async function fetchVouchers(query = {}) {
  const params = new URLSearchParams()
  if (query.search) params.set('search', query.search)
  if (query.status) params.set('status', query.status)
  if (query.from_date) params.set('from_date', query.from_date)
  if (query.to_date) params.set('to_date', query.to_date)
  if (query.sort) params.set('sort', query.sort)
  if (query.page) params.set('page', String(query.page))
  if (query.prefix !== undefined && query.prefix !== null && query.prefix !== '') {
    params.set('prefix', query.prefix)
  }
  const qs = params.toString()
  const url = `${getApiBase()}/list.php${qs ? `?${qs}` : ''}`
  const res = await fetch(url, { credentials: 'same-origin' })
  const data = await parseJson(res)
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`)
  }
  return data
}

export async function fetchSuggestions(q) {
  const base = getSuggestUrl()
  if (!base) return []
  const url = `${base}?q=${encodeURIComponent(q || '')}&limit=12`
  try {
    const res = await fetch(url, { credentials: 'same-origin' })
    const data = await parseJson(res)
    return Array.isArray(data.suggestions) ? data.suggestions : []
  } catch {
    return []
  }
}

export async function aiSearch(query) {
  const res = await fetch(`${getApiBase()}/ai-search.php`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ query }),
  })
  const data = await parseJson(res)
  if (!data || data.ok === false) {
    throw new Error((data && data.error) || 'AI search failed.')
  }
  return data
}

export async function toggleReference(voucherId) {
  const res = await fetch(getReferenceToggleUrl(), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'voucher_id=' + encodeURIComponent(voucherId),
  })
  const data = await parseJson(res)
  if (!data || !data.ok) {
    throw new Error((data && data.error) || 'Could not update reference mark.')
  }
  return data
}
