function getApiBase() {
  if (typeof window !== 'undefined' && window.__DASHBOARD_API_BASE__) {
    return String(window.__DASHBOARD_API_BASE__).replace(/\/$/, '')
  }
  return './api'
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

export async function fetchDashboard() {
  const cfg = getConfig()
  const qs = cfg && cfg.ownVouchersOnly === true ? '?mine=1' : ''
  const res = await fetch(`${getApiBase()}/init.php${qs}`, { credentials: 'same-origin' })
  const data = await parseJson(res)
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`)
  }
  return data
}

export async function aiSearch(query) {
  const res = await fetch(`${getApiBase()}/ai-search.php`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
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
