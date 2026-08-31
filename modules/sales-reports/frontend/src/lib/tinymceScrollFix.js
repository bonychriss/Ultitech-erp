/** Stop the editor from jumping scroll when tables are clicked or the table toolbar opens. */
export function preventEditorScrollJump(editor) {
  if (!editor?.selection || !editor?.dom) return

  const scrollParent = () => document.querySelector('.word-canvas-scroll')

  const isInTable = (node) => {
    if (!node) return false
    return Boolean(editor.dom.getParent(node, 'td,th,table,caption'))
  }

  const lockScroll = () => {
    const el = scrollParent()
    if (!el) return () => {}
    const top = el.scrollTop
    const restore = () => {
      el.scrollTop = top
    }
    restore()
    return restore
  }

  // TinyMCE scrolls the parent page when the caret moves � skip that inside tables.
  editor.selection.scrollIntoView = () => {}

  editor.on('mousedown touchstart', (e) => {
    if (!isInTable(e.target)) return
    const restore = lockScroll()
    const run = () => restore()
    editor.on('mouseup touchend', run, { once: true })
    requestAnimationFrame(run)
    setTimeout(run, 0)
    setTimeout(run, 50)
    setTimeout(run, 120)
    setTimeout(run, 250)
  })

  editor.on('NodeChange', () => {
    const node = editor.selection?.getNode?.()
    if (!isInTable(node)) return
    const restore = lockScroll()
    requestAnimationFrame(restore)
    requestAnimationFrame(() => requestAnimationFrame(restore))
  })

  editor.on('ResizeEditor', () => {
    const node = editor.selection?.getNode?.()
    if (!isInTable(node)) return
    lockScroll()
  })
}
