/** Page sheets are white. The break is empty so the desk background shows through. */
const DEFAULT_PAGE_HEIGHT = 980
const PAGE_PAD_Y = 72 + 96

const timers = new WeakMap()
const fontHooks = new WeakSet()

function createGap(doc) {
  const gap = doc.createElement('div')
  gap.className = 'sr-page-gap'
  gap.setAttribute('contenteditable', 'false')
  gap.setAttribute('data-sr-page-gap', '1')
  gap.setAttribute(
    'style',
    'display:block;clear:both;height:36px;margin:0;padding:0;background:transparent;border:0;outline:0;box-shadow:none;overflow:hidden;line-height:0;font-size:0;',
  )
  return gap
}

function unwrapEditorPages(body) {
  body.querySelectorAll('.sr-editor-page').forEach((page) => {
    const parent = page.parentNode
    if (!parent) return
    while (page.firstChild) parent.insertBefore(page.firstChild, page)
    page.remove()
  })
  body.querySelectorAll('.sr-page-gap').forEach((node) => node.remove())
}

function wrapEditorPage(doc, elements) {
  const page = doc.createElement('div')
  page.className = 'sr-editor-page'
  page.setAttribute('data-sr-editor-page', '1')
  elements[0].before(page)
  elements.forEach((el) => page.appendChild(el))
  return page
}

function clampOffset(node, offset) {
  if (!node) return 0
  if (node.nodeType === Node.TEXT_NODE) {
    return Math.max(0, Math.min(offset, node.length))
  }
  return Math.max(0, Math.min(offset, node.childNodes.length))
}

function rememberSelection(editor, body) {
  try {
    const rng = editor.selection?.getRng?.()
    if (!rng || !body.contains(rng.startContainer)) return null
    return {
      start: rng.startContainer,
      startOffset: rng.startOffset,
      end: rng.endContainer,
      endOffset: rng.endOffset,
    }
  } catch {
    return null
  }
}

function restoreSelection(editor, body, saved) {
  if (!saved?.start?.isConnected) return
  try {
    const rng = body.ownerDocument.createRange()
    const end = saved.end?.isConnected ? saved.end : saved.start
    rng.setStart(saved.start, clampOffset(saved.start, saved.startOffset))
    rng.setEnd(end, clampOffset(end, saved.end?.isConnected ? saved.endOffset : saved.startOffset))
    editor.selection.setRng(rng)
  } catch {
    /* caret can sit on a node that moved */
  }
}

function fitEditorFrame(editor) {
  const doc = editor?.getDoc?.()
  const body = editor?.getBody?.()
  const iframe = editor?.iframeElement
  if (!doc || !body || !iframe) return
  const height = Math.max(body.scrollHeight, doc.documentElement?.scrollHeight || 0, 1056) + 24
  iframe.style.height = `${height}px`
  const area = iframe.closest?.('.tox-edit-area')
  if (area) area.style.height = `${height}px`
  const container = editor.getContainer?.()
  if (container) container.style.height = 'auto'
}

function hookImages(editor, body) {
  body.querySelectorAll('img').forEach((img) => {
    if (img.complete) return
    img.addEventListener('load', () => scheduleEditorPages(editor), { once: true })
  })
}

function hookFonts(editor) {
  if (fontHooks.has(editor)) return
  fontHooks.add(editor)
  const fonts = editor.getDoc?.()?.fonts
  if (fonts?.ready) {
    fonts.ready.then(() => scheduleEditorPages(editor)).catch(() => {})
  }
}

function blockHeight(el, win) {
  const cs = win.getComputedStyle(el)
  const mt = parseFloat(cs.marginTop) || 0
  const mb = parseFloat(cs.marginBottom) || 0
  return el.offsetHeight + mt + mb
}

function needsForcedBreak(el, win) {
  const cs = win.getComputedStyle(el)
  return cs.pageBreakBefore === 'always' || cs.breakBefore === 'page' || el.classList.contains('sr-rep-appendix')
}

/**
 * Insert a desk-colored break after the cover and again at each following page.
 * Breaks are editor-only and are stripped when the document is saved.
 */
export function layoutEditorPages(editor) {
  const body = editor?.getBody?.()
  const win = editor?.getWin?.()
  if (!body || !win) return

  const scrollY = win.scrollY || 0
  const saved = rememberSelection(editor, body)

  const run = () => {
    unwrapEditorPages(body)

    const blocks = [...body.children]
    const cover = blocks.find((el) => el.classList.contains('sr-cover-page'))
    const coverHeight = cover?.offsetHeight || 0
    const pageHeight = coverHeight >= 700 && coverHeight <= 1400 ? coverHeight : DEFAULT_PAGE_HEIGHT
    const innerHeight = Math.max(480, pageHeight - PAGE_PAD_Y)
    const doc = body.ownerDocument
    const groups = []
    let current = []
    let used = 0

    blocks.forEach((el) => {
      if (el.classList.contains('sr-cover-page') || el.classList.contains('sr-page-gap')) {
        if (current.length) groups.push(current)
        if (el.classList.contains('sr-cover-page')) groups.push([el])
        current = []
        used = 0
        return
      }
      const h = blockHeight(el, win)
      const forced = current.length > 0 && needsForcedBreak(el, win)
      if (current.length && (forced || used + h > innerHeight)) {
        groups.push(current)
        current = [el]
        used = h
        return
      }
      current.push(el)
      used += h
    })
    if (current.length) groups.push(current)

    let previous = null
    groups.forEach((group) => {
      const isCover = group.length === 1 && group[0].classList.contains('sr-cover-page')
      const page = isCover ? group[0] : wrapEditorPage(doc, group)
      if (previous) page.before(createGap(doc))
      previous = page
    })

    hookImages(editor, body)
  }

  if (typeof editor.undoManager?.ignore === 'function') {
    editor.undoManager.ignore(run)
  } else {
    run()
  }

  win.scrollTo(0, scrollY)
  restoreSelection(editor, body, saved)
  hookFonts(editor)
  fitEditorFrame(editor)
  requestAnimationFrame(() => fitEditorFrame(editor))
}

export function scheduleEditorPages(editor) {
  if (!editor || typeof editor.getBody !== 'function' || !editor.getBody()) return
  const prev = timers.get(editor)
  if (prev) clearTimeout(prev)
  timers.set(editor, setTimeout(() => {
    timers.delete(editor)
    if (!editor.getBody?.()) return
    layoutEditorPages(editor)
  }, 80))
}
