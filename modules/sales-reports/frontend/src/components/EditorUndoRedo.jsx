import { useCallback, useEffect, useState } from 'react'

export default function EditorUndoRedo({
  editor,
  variant = 'titlebar',
  showLabels = true,
}) {
  const [canUndo, setCanUndo] = useState(false)
  const [canRedo, setCanRedo] = useState(false)

  const refresh = useCallback(() => {
    if (!editor?.undoManager) {
      setCanUndo(false)
      setCanRedo(false)
      return
    }
    setCanUndo(editor.undoManager.hasUndo())
    setCanRedo(editor.undoManager.hasRedo())
  }, [editor])

  useEffect(() => {
    if (!editor) return undefined

    refresh()
    const events = ['Undo', 'Redo', 'AddUndo', 'Change', 'input', 'SetContent']
    events.forEach((eventName) => editor.on(eventName, refresh))

    return () => {
      events.forEach((eventName) => editor.off(eventName, refresh))
    }
  }, [editor, refresh])

  function undo() {
    if (!editor?.undoManager || !canUndo) return
    editor.undoManager.undo()
    editor.focus()
    refresh()
  }

  function redo() {
    if (!editor?.undoManager || !canRedo) return
    editor.undoManager.redo()
    editor.focus()
    refresh()
  }

  const isRibbon = variant === 'ribbon'

  return (
    <div className={`word-undo-redo${isRibbon ? ' word-undo-redo--ribbon' : ''}`}>
      <button
        type="button"
        className={isRibbon ? 'word-ribbon-tool' : 'word-title-icon-btn word-undo-btn'}
        title="Undo (Ctrl+Z)"
        aria-label="Undo"
        disabled={!canUndo}
        onClick={undo}
      >
        <i className="bi bi-arrow-counterclockwise" aria-hidden="true" />
        {isRibbon && <span>Undo</span>}
        {!isRibbon && showLabels && <span>Undo</span>}
      </button>
      <button
        type="button"
        className={isRibbon ? 'word-ribbon-tool' : 'word-title-icon-btn word-undo-btn'}
        title="Redo (Ctrl+Y)"
        aria-label="Redo"
        disabled={!canRedo}
        onClick={redo}
      >
        <i className="bi bi-arrow-clockwise" aria-hidden="true" />
        {isRibbon && <span>Redo</span>}
        {!isRibbon && showLabels && <span>Redo</span>}
      </button>
    </div>
  )
}
