import { useEffect, useState } from 'react'
import RibbonToolButton from './RibbonToolButton.jsx'
import { deleteMarkedLines, lineEraserActive, markedLineCount, toggleLineEraser } from '../lib/deleteDocumentLine.js'

function activeCell(editor) {
  if (!editor) return null
  return editor.dom.getParent(editor.selection.getNode(), 'td,th')
}

export default function TableRibbonTools({ editor }) {
  const [inTable, setInTable] = useState(false)
  const [erasing, setErasing] = useState(false)
  const [marked, setMarked] = useState(0)

  useEffect(() => {
    if (!editor) {
      setInTable(false)
      return undefined
    }

    const update = () => {
      setInTable(Boolean(activeCell(editor)))
    }

    const syncEraser = (event) => {
      setErasing(event?.active != null ? Boolean(event.active) : lineEraserActive(editor))
      setMarked(event?.count != null ? event.count : markedLineCount(editor))
    }
    editor.on('NodeChange', update)
    editor.on('sr-line-eraser', syncEraser)
    update()
    syncEraser()
    return () => {
      editor.off('NodeChange', update)
      editor.off('sr-line-eraser', syncEraser)
    }
  }, [editor])

  const run = (cmd, value) => {
    if (!editor || !inTable) return
    editor.execCommand(cmd, false, value)
    editor.focus()
  }

  const applyCellColor = (color) => {
    const cell = activeCell(editor)
    if (!cell) return
    editor.undoManager.transact(() => {
      if (!color) {
        editor.formatter.remove('tablecellbackgroundcolor', { value: null }, cell, true)
        editor.dom.setStyle(cell, 'background-color', '')
      } else {
        editor.formatter.apply('tablecellbackgroundcolor', { value: color }, cell)
        editor.dom.setStyle(cell, 'background-color', color)
        if (cell.nodeName === 'TH') {
          editor.dom.setStyle(cell, 'color', color === '#1a1a2e' ? '#ffffff' : '')
        }
      }
      editor.nodeChanged()
    })
    editor.focus()
  }

  return (
    <div className="word-ribbon-group">
      <span className="word-ribbon-label">
        <i className="bi bi-table" aria-hidden="true" />
        Table
      </span>
      <div className={`word-ribbon-buttons word-ribbon-buttons--tools${inTable ? '' : ' is-disabled'}`}>
        <button type="button" className="word-icon-btn" title="Insert row above" disabled={!inTable} onClick={() => run('mceTableInsertRowBefore')}>
          <i className="bi bi-border-top" aria-hidden="true" />
        </button>
        <button type="button" className="word-icon-btn" title="Insert row below" disabled={!inTable} onClick={() => run('mceTableInsertRowAfter')}>
          <i className="bi bi-border-bottom" aria-hidden="true" />
        </button>
        <button type="button" className="word-icon-btn" title="Delete row" disabled={!inTable} onClick={() => run('mceTableDeleteRow')}>
          <i className="bi bi-dash-lg" aria-hidden="true" />
        </button>
        <RibbonToolButton
          icon="bi-pencil"
          label="Remove"
          title="Click a line to delete it. Hold and drag to highlight several, then click Delete."
          className={erasing ? 'is-open' : ''}
          disabled={!editor}
          onClick={() => toggleLineEraser(editor)}
        />
        <RibbonToolButton
          icon="bi-trash"
          label="Delete"
          title="Delete the highlighted lines"
          disabled={!marked}
          onClick={() => deleteMarkedLines(editor)}
        />
        <button type="button" className="word-icon-btn" title="Insert column left" disabled={!inTable} onClick={() => run('mceTableInsertColBefore')}>
          <i className="bi bi-border-left" aria-hidden="true" />
        </button>
        <button type="button" className="word-icon-btn" title="Insert column right" disabled={!inTable} onClick={() => run('mceTableInsertColAfter')}>
          <i className="bi bi-border-right" aria-hidden="true" />
        </button>
        <button type="button" className="word-icon-btn" title="Delete column" disabled={!inTable} onClick={() => run('mceTableDeleteCol')}>
          <i className="bi bi-x-lg" aria-hidden="true" />
        </button>
        <input
          type="color"
          title="Cell background color"
          defaultValue="#c6d9f1"
          disabled={!inTable}
          onChange={(e) => applyCellColor(e.target.value)}
        />
        <button type="button" className="word-icon-btn" title="Clear cell color" disabled={!inTable} onClick={() => applyCellColor('')}>
          <i className="bi bi-eraser" aria-hidden="true" />
        </button>
        <button type="button" className="word-icon-btn" title="Table properties" disabled={!inTable} onClick={() => run('mceTableProps')}>
          <i className="bi bi-gear" aria-hidden="true" />
        </button>
      </div>
    </div>
  )
}
