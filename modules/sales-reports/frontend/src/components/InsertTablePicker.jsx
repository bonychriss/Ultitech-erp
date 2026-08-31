import { useEffect, useRef, useState } from 'react'
import {
  focusEditorWithBookmark,
  preserveCanvasScrollDuring,
  saveEditorBookmark,
} from '../lib/editorSelection.js'

const GRID_SIZE = 10

export default function InsertTablePicker({ editor }) {
  const [open, setOpen] = useState(false)
  const [hover, setHover] = useState({ rows: 0, cols: 0 })
  const wrapRef = useRef(null)
  const bookmarkRef = useRef(null)

  function captureSelection() {
    if (!editor) return
    bookmarkRef.current = saveEditorBookmark(editor)
  }

  useEffect(() => {
    if (!open) return undefined

    function handleOutsideClick(e) {
      if (wrapRef.current && !wrapRef.current.contains(e.target)) {
        setOpen(false)
        setHover({ rows: 0, cols: 0 })
      }
    }

    document.addEventListener('mousedown', handleOutsideClick)
    return () => document.removeEventListener('mousedown', handleOutsideClick)
  }, [open])

  function insertTable(rows, cols) {
    if (!editor || rows < 1 || cols < 1) return
    preserveCanvasScrollDuring(() => {
      focusEditorWithBookmark(editor, bookmarkRef.current)
      editor.execCommand('mceInsertTable', false, { rows, columns: cols })
    })
    editor.focus()
    setOpen(false)
    setHover({ rows: 0, cols: 0 })
  }

  function openDialog() {
    if (!editor) return
    setOpen(false)
    setHover({ rows: 0, cols: 0 })
    focusEditorWithBookmark(editor, bookmarkRef.current)
    editor.execCommand('mceInsertTableDialog')
    editor.focus()
  }

  const label = hover.rows > 0 && hover.cols > 0
    ? `${hover.cols} x ${hover.rows} Table`
    : 'Insert table'

  return (
    <div className="word-table-picker-wrap" ref={wrapRef}>
      <button
        type="button"
        className={`word-ribbon-tool word-table-picker-trigger${open ? ' is-open' : ''}`}
        onMouseDown={() => captureSelection()}
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        aria-haspopup="true"
        title="Insert table"
        aria-label="Table"
      >
        <i className="bi bi-table" aria-hidden="true" />
        <span>Table</span>
      </button>

      {open && (
        <div className="word-table-picker-dropdown" role="dialog" aria-label="Insert table">
          <div className="word-table-picker-body">
            <div
              className="word-table-picker-grid"
              onMouseLeave={() => setHover({ rows: 0, cols: 0 })}
            >
              {Array.from({ length: GRID_SIZE }, (_, rowIndex) =>
                Array.from({ length: GRID_SIZE }, (_, colIndex) => {
                  const row = rowIndex + 1
                  const col = colIndex + 1
                  const active = row <= hover.rows && col <= hover.cols

                  return (
                    <button
                      key={`${row}-${col}`}
                      type="button"
                      className={`word-table-picker-cell${active ? ' is-active' : ''}`}
                      aria-label={`${col} columns by ${row} rows`}
                      onMouseDown={(e) => e.preventDefault()}
                      onMouseEnter={() => setHover({ rows: row, cols: col })}
                      onClick={() => insertTable(row, col)}
                    />
                  )
                }))}
            </div>

            <div className="word-table-picker-label">{label}</div>
          </div>

          <button
            type="button"
            className="word-table-picker-more"
            onMouseDown={(e) => e.preventDefault()}
            onClick={openDialog}
          >
            Insert Table...
          </button>
        </div>
      )}
    </div>
  )
}
