/**
 * Copy text to clipboard with mobile-safe fallbacks.
 * navigator.clipboard often fails on iOS/Android over http:// or without focus.
 * @param {string} text
 * @param {HTMLInputElement|HTMLTextAreaElement|null} [inputEl]
 * @returns {Promise<boolean>}
 */
export async function copyTextToClipboard(text, inputEl = null) {
  const value = String(text || '')
  if (!value) return false

  if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function' && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(value)
      return true
    } catch {
      /* fall through */
    }
  }

  try {
    const el = inputEl && typeof inputEl.select === 'function'
      ? inputEl
      : (() => {
        const ta = document.createElement('textarea')
        ta.value = value
        ta.setAttribute('readonly', '')
        ta.style.position = 'fixed'
        ta.style.top = '0'
        ta.style.left = '0'
        ta.style.width = '1px'
        ta.style.height = '1px'
        ta.style.padding = '0'
        ta.style.border = 'none'
        ta.style.outline = 'none'
        ta.style.boxShadow = 'none'
        ta.style.background = 'transparent'
        ta.style.opacity = '0'
        document.body.appendChild(ta)
        return ta
      })()

    const created = el !== inputEl
    const prevReadOnly = el.readOnly
    el.readOnly = false
    el.focus()
    el.select()
    el.setSelectionRange(0, value.length)
    const ok = document.execCommand('copy')
    el.readOnly = prevReadOnly
    if (created) el.remove()
    return ok
  } catch {
    return false
  }
}

/**
 * Prefer native share sheet on mobile when available.
 * @param {{ title?: string, text?: string, url: string }} payload
 * @returns {Promise<'shared'|'copied'|'cancelled'|'failed'>}
 */
export async function shareOrCopyLink(payload, inputEl = null) {
  const url = String(payload?.url || '')
  if (!url) return 'failed'

  if (typeof navigator.share === 'function') {
    try {
      await navigator.share({
        title: payload.title || 'Delivery documents',
        text: payload.text || url,
        url,
      })
      return 'shared'
    } catch (err) {
      if (err && (err.name === 'AbortError' || err.name === 'NotAllowedError')) {
        // User dismissed share sheet — still try copy as backup only if aborted without share
        if (err.name === 'AbortError') return 'cancelled'
      }
      /* fall through to copy */
    }
  }

  const ok = await copyTextToClipboard(url, inputEl)
  return ok ? 'copied' : 'failed'
}
