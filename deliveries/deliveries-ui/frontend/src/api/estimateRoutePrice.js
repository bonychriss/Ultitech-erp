import { CFG } from '../config.js'

async function parseJson(response) {
  const text = await response.text()
  try {
    return JSON.parse(text)
  } catch {
    throw new Error('Invalid server response.')
  }
}

export function resolveEstimateRoutePriceUrl() {
  return CFG.estimateRoutePriceUrl || ''
}

/**
 * @param {{
 *   pickup?: string,
 *   destination?: string,
 *   pickupLat?: number|null,
 *   pickupLng?: number|null,
 *   destinationLat?: number|null,
 *   destinationLng?: number|null,
 *   pricing?: object,
 * }} payload
 */
export async function fetchRoutePriceEstimate(payload) {
  const url = resolveEstimateRoutePriceUrl()
  if (!url) {
    throw new Error('Route price estimate is not configured.')
  }

  const res = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(payload),
  })

  const data = await parseJson(res)
  if (!data?.ok) {
    throw new Error(data?.error || 'Could not estimate route price.')
  }
  return data.data || {}
}

export function formatMoney(amount, currency = 'TZS') {
  const value = Number(amount)
  if (Number.isNaN(value)) return '-'
  return `${value.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })} ${currency}`
}
