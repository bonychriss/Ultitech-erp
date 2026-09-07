export function getSettingsCfg() {
  return window.__ADMIN_SETTINGS_CFG__ || {}
}

export async function saveSystemFont(apiUrl, systemFont) {
  const res = await fetch(apiUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ system_font: systemFont }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok || !data.ok) {
    throw new Error(data.message || 'Could not save font.')
  }
  return data
}

export async function executeFactoryReset(apiUrl, confirmReset) {
  const res = await fetch(apiUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ confirm_reset: confirmReset }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok || !data.ok) {
    throw new Error(data.message || 'Factory reset failed.')
  }
  return data
}
