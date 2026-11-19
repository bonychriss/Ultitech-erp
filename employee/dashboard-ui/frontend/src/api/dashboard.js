function getApiBase() {
  if (typeof window !== 'undefined' && window.__DASHBOARD_API_BASE__) {
    return String(window.__DASHBOARD_API_BASE__).replace(/\/$/, '')
  }
  if (typeof document !== 'undefined') {
    const el = document.getElementById('root')
    const fromDom = el && el.getAttribute('data-dashboard-api')
    if (fromDom) {
      return String(fromDom).replace(/\/$/, '')
    }
  }
  // /{company}/admin/... or /{appBase}/{company}/admin/... (e.g. /public_html/ultimate/admin)
  if (typeof window !== 'undefined' && window.location && window.location.pathname) {
    const path = String(window.location.pathname)
    const adminMatch = path.match(/^(.*?)\/admin(?:\/|$)/i)
    if (adminMatch && adminMatch[1]) {
      return `${adminMatch[1].replace(/\/$/, '')}/admin/dashboard-ui/api`
    }
    const employeeMatch = path.match(/^(.*?)\/employee(?:\/|$)/i)
    if (employeeMatch && employeeMatch[1]) {
      return `${employeeMatch[1].replace(/\/$/, '')}/employee/dashboard-ui/api`
    }
  }
  // Same folder as dashboard.php (not ./api, which 404s as HTML)
  return './dashboard-ui/api'
}

export function getConfig() {
  if (typeof window !== 'undefined' && window.__DASHBOARD_CFG__ && typeof window.__DASHBOARD_CFG__ === 'object') {
    return window.__DASHBOARD_CFG__
  }
  return {}
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

const JSON_HEADERS = {
  Accept: 'application/json',
  'X-Requested-With': 'XMLHttpRequest',
}

export async function fetchDashboard() {
  const cfg = getConfig()
  const qs = cfg && cfg.ownVouchersOnly === true ? '?mine=1' : ''
  const res = await fetch(`${getApiBase()}/init.php${qs}`, {
    credentials: 'include',
    headers: { ...JSON_HEADERS },
  })
  const data = await parseJson(res)
  if (res.status === 401 || res.status === 403) {
    throw new Error(data.message || data.error || 'Session expired. Please refresh and log in again.')
  }
  if (!res.ok || data.error || data.success === false) {
    throw new Error(data.error || data.message || `Request failed (${res.status})`)
  }
  return data
}

export async function aiSearch(query) {
  const res = await fetch(`${getApiBase()}/ai-search.php`, {
    method: 'POST',
    credentials: 'include',
    headers: { ...JSON_HEADERS, 'Content-Type': 'application/json' },
    body: JSON.stringify({ query }),
  })
  return parseJson(res)
}

function getReferenceToggleUrl() {
  const cfg = getConfig()
  if (cfg && cfg.referenceToggleUrl) {
    return String(cfg.referenceToggleUrl)
  }
  return '/toggle-voucher-reference.php'
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
