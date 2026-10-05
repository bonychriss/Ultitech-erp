/** Stop the page from shifting when the editor is focused or a table is clicked. */

function documentScroller() {
  return document.querySelector('.word-scroll')
}

export function preventEditorScrollJump(editor) {
  if (!editor) return

  const hold = () => {
    const el = documentScroller()
    const top = el?.scrollTop ?? 0
    const winTop = window.scrollY || 0
    const restore = () => {
      if (el && el.scrollTop !== top) el.scrollTop = top
      if ((window.scrollY || 0) !== winTop) window.scrollTo(0, winTop)
    }
    restore()
    requestAnimationFrame(restore)
    requestAnimationFrame(() => requestAnimationFrame(restore))
    setTimeout(restore, 0)
    setTimeout(restore, 50)
    setTimeout(restore, 120)
    setTimeout(restore, 250)
  }

  editor.on('ScrollIntoView', (event) => {
    event.preventDefault()
  })
  if (editor.selection) editor.selection.scrollIntoView = () => {}

  editor.on('mousedown touchstart focus NodeChange', hold)
}
