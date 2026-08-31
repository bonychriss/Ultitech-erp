/** Row/column background colors on the TinyMCE table context toolbar. */

const COLOR_PRESETS = [
  { title: 'Light blue', value: '#c6d9f1' },
  { title: 'Light green', value: '#d9ead3' },
  { title: 'Light yellow', value: '#fff2cc' },
  { title: 'Light orange', value: '#fce5cd' },
  { title: 'Light gray', value: '#efefef' },
  { title: 'White', value: '#ffffff' },
  { title: 'Navy header', value: '#1a1a2e' },
]

const svg = (body) =>
  `<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">${body}</svg>`

const ROW_COLOR_ICON = svg(`
  <rect x="4" y="5" width="16" height="14" rx="1" fill="#f8fafc" stroke="#64748b" stroke-width="1"/>
  <line x1="4" y1="10" x2="20" y2="10" stroke="#94a3b8" stroke-width="0.75"/>
  <line x1="4" y1="15" x2="20" y2="15" stroke="#94a3b8" stroke-width="0.75"/>
  <line x1="10" y1="5" x2="10" y2="19" stroke="#94a3b8" stroke-width="0.75"/>
  <rect x="4" y="10.5" width="16" height="4" fill="#c6d9f1" stroke="#2563eb" stroke-width="0.75"/>
`)

const COL_COLOR_ICON = svg(`
  <rect x="4" y="5" width="16" height="14" rx="1" fill="#f8fafc" stroke="#64748b" stroke-width="1"/>
  <line x1="4" y1="10" x2="20" y2="10" stroke="#94a3b8" stroke-width="0.75"/>
  <line x1="4" y1="15" x2="20" y2="15" stroke="#94a3b8" stroke-width="0.75"/>
  <line x1="10" y1="5" x2="10" y2="19" stroke="#94a3b8" stroke-width="0.75"/>
  <rect x="9.5" y="5" width="5" height="14" fill="#c6d9f1" stroke="#2563eb" stroke-width="0.75"/>
`)

function activeCell(editor) {
  return editor.dom.getParent(editor.selection.getNode(), 'td,th')
}

function cellsInRow(editor, cell) {
  const row = editor.dom.getParent(cell, 'tr')
  if (!row) return []
  return Array.from(row.querySelectorAll('td,th'))
}

function cellsInColumn(editor, cell) {
  const table = editor.dom.getParent(cell, 'table')
  const row = editor.dom.getParent(cell, 'tr')
  if (!table || !row) return []
  const index = cell.cellIndex
  if (index < 0) return []
  const cells = []
  table.querySelectorAll('tr').forEach((tr) => {
    const match = tr.cells[index]
    if (match) cells.push(match)
  })
  return cells
}

function applyBackground(editor, cells, color) {
  if (!cells.length) return
  editor.undoManager.transact(() => {
    cells.forEach((cell) => {
      if (!color) {
        editor.formatter.remove('tablecellbackgroundcolor', { value: null }, cell, true)
        editor.dom.setStyle(cell, 'background-color', '')
        editor.dom.setStyle(cell, 'background', '')
      } else {
        editor.formatter.apply('tablecellbackgroundcolor', { value: color }, cell)
        editor.dom.setStyle(cell, 'background-color', color)
        if (cell.nodeName === 'TH') {
          editor.dom.setStyle(cell, 'color', color === '#1a1a2e' ? '#ffffff' : '')
        }
      }
    })
    editor.nodeChanged()
  })
}

function buildColorMenu(onPick) {
  const colors = COLOR_PRESETS.map((entry) => ({
    text: entry.title,
    value: entry.value,
    type: 'choiceitem',
  }))

  return [{
    type: 'fancymenuitem',
    fancytype: 'colorswatch',
    initData: {
      colors,
      allowCustomColors: true,
    },
    onAction: (data) => {
      const value = data.value === 'remove' ? '' : (data.value || '')
      onPick(value)
    },
  }]
}

function setupInCell(editor, api) {
  const update = () => {
    api.setEnabled(Boolean(activeCell(editor)) && editor.selection.isEditable())
  }
  editor.on('NodeChange', update)
  update()
  return () => editor.off('NodeChange', update)
}

export function registerTableRowColumnColor(editor) {
  editor.ui.registry.addIcon('table-row-background-color', ROW_COLOR_ICON)
  editor.ui.registry.addIcon('table-col-background-color', COL_COLOR_ICON)

  editor.ui.registry.addMenuButton('tablerowbackgroundcolor', {
    icon: 'table-row-background-color',
    tooltip: 'Row color',
    fetch: (callback) => {
      callback(buildColorMenu((color) => {
        const cell = activeCell(editor)
        if (!cell) return
        applyBackground(editor, cellsInRow(editor, cell), color)
      }))
    },
    onSetup: (api) => setupInCell(editor, api),
  })

  editor.ui.registry.addMenuButton('tablecolbackgroundcolor', {
    icon: 'table-col-background-color',
    tooltip: 'Column color',
    fetch: (callback) => {
      callback(buildColorMenu((color) => {
        const cell = activeCell(editor)
        if (!cell) return
        applyBackground(editor, cellsInColumn(editor, cell), color)
      }))
    },
    onSetup: (api) => setupInCell(editor, api),
  })
}
