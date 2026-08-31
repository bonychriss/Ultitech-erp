import { CFG } from '../config.js'

async function parseJson(response) {
  const text = await response.text()
  try {
    return JSON.parse(text)
  } catch {
    throw new Error('Invalid server response.')
  }
}

export function resolveAiSearchNotesUrl() {
  if (CFG.aiSearchNotesUrl) return CFG.aiSearchNotesUrl
  if (CFG.aiSearchUrl) return CFG.aiSearchUrl
  return ''
}

export async function aiSearchDeliveryNotes(query) {
  const url = resolveAiSearchNotesUrl()
  if (!url) {
    throw new Error('AI search is not configured.')
  }
  const res = await fetch(url, {
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
