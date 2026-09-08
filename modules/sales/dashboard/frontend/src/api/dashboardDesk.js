function getApiBase() {
  if (typeof window !== 'undefined' && window.__SALES_DASHBOARD_API_BASE__) {
    return String(window.__SALES_DASHBOARD_API_BASE__).replace(/\/$/, '')
  }
  return './api'
}

function getInitUrl(params = {}) {
  const qs =
    params instanceof URLSearchParams
      ? params.toString()
      : new URLSearchParams(params).toString()

  if (typeof window !== 'undefined' && window.__SALES_DASHBOARD_INIT_URL__) {
    const base = String(window.__SALES_DASHBOARD_INIT_URL__)
    if (!qs) return base
    return base + (base.includes('?') ? '&' : '?') + qs
  }

  return `${getApiBase()}/init.php${qs ? `?${qs}` : ''}`
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

export async function fetchDashboardInit(params = {}) {
  const res = await fetch(getInitUrl(params), { credentials: 'same-origin' })
  const data = await parseJson(res)
  if (!res.ok || data.error || data.ok === false) {
    throw new Error(data.error || `Request failed (${res.status})`)
  }
  return data
}
