const TINYMCE_BASE = 'https://cdn.jsdelivr.net/npm/tinymce@7'

let loadPromise = null

export function getTinyMceBase() {
  return TINYMCE_BASE
}

/** Load TinyMCE once; script tag is never removed (avoids removeChild errors). */
export function loadTinyMce() {
  if (typeof window !== 'undefined' && window.tinymce) {
    return Promise.resolve(window.tinymce)
  }
  if (loadPromise) {
    return loadPromise
  }
  loadPromise = new Promise((resolve, reject) => {
    const done = () => {
      if (window.tinymce) resolve(window.tinymce)
      else reject(new Error('TinyMCE loaded but is unavailable'))
    }
    const existing = document.querySelector('script[data-sr-tinymce-loader="1"]')
    if (existing) {
      if (window.tinymce) {
        done()
        return
      }
      existing.addEventListener('load', done, { once: true })
      existing.addEventListener('error', () => reject(new Error('Failed to load TinyMCE')), { once: true })
      return
    }
    const script = document.createElement('script')
    script.src = `${TINYMCE_BASE}/tinymce.min.js`
    script.dataset.srTinymceLoader = '1'
    script.referrerPolicy = 'origin'
    script.async = true
    script.onload = done
    script.onerror = () => reject(new Error('Failed to load TinyMCE'))
    document.head.appendChild(script)
  })
  return loadPromise
}

export function destroyTinyMceEditor(editor) {
  if (!editor) return
  try {
    editor.remove()
  } catch {
    // TinyMCE may already be detached from the DOM.
  }
}
