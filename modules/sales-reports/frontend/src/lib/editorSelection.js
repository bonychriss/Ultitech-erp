/** Persist TinyMCE caret/range while focus moves to ribbon controls. */
export function saveEditorBookmark(editor) {
  if (!editor?.selection?.getBookmark) return null
  try {
    return editor.selection.getBookmark(2, true)
  } catch {
    return null
  }
}

export function restoreEditorBookmark(editor, bookmark) {
  if (!editor?.selection?.moveToBookmark || !bookmark) return false
  try {
    editor.selection.moveToBookmark(bookmark)
    return true
  } catch {
    return false
  }
}

function canvasScrollEl() {
  return document.querySelector('.word-canvas-scroll')
}

/** Keep the document viewport steady while TinyMCE moves the caret (e.g. after table insert). */
export function preserveCanvasScrollDuring(fn) {
  const el = canvasScrollEl()
  const top = el?.scrollTop ?? 0
  const restore = () => {
    if (el) el.scrollTop = top
  }

  let result
  try {
    result = fn()
  } finally {
    restore()
    requestAnimationFrame(restore)
    setTimeout(restore, 0)
    setTimeout(restore, 50)
    setTimeout(restore, 120)
    setTimeout(restore, 250)
  }
  return result
}

export function focusEditorWithBookmark(editor, bookmark) {
  if (!editor) return
  editor.focus()
  restoreEditorBookmark(editor, bookmark)
}
