/** Click a line once to delete it. Hold the left button and drag to highlight lines, then delete those. */

const HOT = ['sr-line-hot-top', 'sr-line-hot-bottom', 'sr-line-hot-left', 'sr-line-hot-right', 'sr-line-hot']
const MARK = {
  top: 'sr-line-mark-top',
  bottom: 'sr-line-mark-bottom',
  left: 'sr-line-mark-left',
  right: 'sr-line-mark-right',
}
const EDGE_CLASS = {
  top: 'sr-no-line-top',
  bottom: 'sr-no-line-bottom',
  left: 'sr-no-line-left',
  right: 'sr-no-line-right',
}
const EDGE_STYLE = {
  top: 'border-top',
  bottom: 'border-bottom',
  left: 'border-left',
  right: 'border-right',
}
const OPPOSITE = { top: 'bottom', bottom: 'top', left: 'right', right: 'left' }
const HIT = 8

function finishEdit(editor) {
  editor.nodeChanged()
  editor.fire('change')
}

function clearHot(root) {
  if (!root?.querySelectorAll) return
  root.querySelectorAll(HOT.map((name) => `.${name}`).join(',')).forEach((node) => {
    HOT.forEach((name) => node.classList.remove(name))
  })
}

function skipTarget(node) {
  return !node || node.closest?.('.sr-cover-page') || node.closest?.('.sr-page-gap')
}

function hrAt(doc, x, y) {
  const hit = doc.elementFromPoint(x, y)
  const direct = hit?.nodeName === 'HR' ? hit : hit?.closest?.('hr')
  if (direct && !skipTarget(direct)) return direct
  let best = null
  let bestDistance = HIT
  doc.querySelectorAll('hr').forEach((rule) => {
    if (skipTarget(rule)) return
    const rect = rule.getBoundingClientRect()
    if (x < rect.left - 4 || x > rect.right + 4) return
    const distance = Math.abs(y - (rect.top + rect.height / 2))
    if (distance < bestDistance) {
      best = rule
      bestDistance = distance
    }
  })
  return best
}

function edgeAt(doc, x, y) {
  const rule = hrAt(doc, x, y)
  if (rule) return { type: 'hr', node: rule }

  let best = null
  let bestDistance = HIT
  doc.querySelectorAll('td,th').forEach((cell) => {
    if (skipTarget(cell)) return
    const rect = cell.getBoundingClientRect()
    if (x < rect.left - HIT || x > rect.right + HIT || y < rect.top - HIT || y > rect.bottom + HIT) return
    const sides = [
      ['top', Math.abs(y - rect.top), x >= rect.left - 2 && x <= rect.right + 2],
      ['bottom', Math.abs(y - rect.bottom), x >= rect.left - 2 && x <= rect.right + 2],
      ['left', Math.abs(x - rect.left), y >= rect.top - 2 && y <= rect.bottom + 2],
      ['right', Math.abs(x - rect.right), y >= rect.top - 2 && y <= rect.bottom + 2],
    ]
    sides.forEach(([side, distance, inside]) => {
      if (!inside || distance >= bestDistance) return
      best = { type: 'edge', cell, side }
      bestDistance = distance
    })
  })
  return best
}

function neighborAcross(cell, side) {
  const rect = cell.getBoundingClientRect()
  const x = side === 'left' ? rect.left - 3 : side === 'right' ? rect.right + 3 : rect.left + rect.width / 2
  const y = side === 'top' ? rect.top - 3 : side === 'bottom' ? rect.bottom + 3 : rect.top + rect.height / 2
  const hit = cell.ownerDocument.elementFromPoint(x, y)
  const other = hit?.closest?.('td,th')
  if (!other || other === cell || skipTarget(other)) return null
  return other
}

function hideEdge(editor, cell, side) {
  editor.dom.addClass(cell, EDGE_CLASS[side])
  editor.dom.setStyle(cell, EDGE_STYLE[side], '0')
}

const cellIds = new WeakMap()
let cellSeq = 1

function cellId(node) {
  if (!cellIds.has(node)) cellIds.set(node, cellSeq++)
  return cellIds.get(node)
}

function targetKey(target) {
  if (target.type === 'hr') return `hr:${cellId(target.node)}`
  const neighbor = neighborAcross(target.cell, target.side)
  let cell = target.cell
  let side = target.side
  if ((side === 'bottom' || side === 'right') && neighbor) {
    cell = neighbor
    side = OPPOSITE[side]
  }
  return `${side}:${cellId(cell)}`
}

function setEdgeMark(cell, side, on) {
  cell.classList.toggle(MARK[side], on)
  if (!on) cell.classList.remove(MARK[side])
}

function applyMark(target, on) {
  if (target.type === 'hr') {
    target.node.classList.toggle('sr-line-mark', on)
    if (!on) target.node.classList.remove('sr-line-mark')
    return
  }
  setEdgeMark(target.cell, target.side, on)
  const neighbor = neighborAcross(target.cell, target.side)
  if (neighbor) setEdgeMark(neighbor, OPPOSITE[target.side], on)
}

function notify(editor) {
  editor.fire('sr-line-eraser', {
    active: Boolean(editor._srEraseLines),
    count: markedLineCount(editor),
  })
}

export function markedLineCount(editor) {
  const body = editor?.getBody?.()
  if (!body) return 0
  let count = body.querySelectorAll('hr.sr-line-mark').length
  body.querySelectorAll('td,th').forEach((cell) => {
    Object.keys(MARK).forEach((side) => {
      if (!cell.classList.contains(MARK[side])) return
      const neighbor = neighborAcross(cell, side)
      if (neighbor?.classList.contains(MARK[OPPOSITE[side]]) && (side === 'bottom' || side === 'right')) return
      count += 1
    })
  })
  return count
}

function clearMarks(editor) {
  const body = editor.getBody?.()
  if (!body) return
  body.querySelectorAll('[class*="sr-line-mark"]').forEach((node) => {
    ;[...node.classList].forEach((name) => {
      if (name.startsWith('sr-line-mark')) node.classList.remove(name)
    })
  })
}

export function deleteMarkedLines(editor) {
  const body = editor?.getBody?.()
  if (!body || markedLineCount(editor) === 0) return false
  const rules = [...body.querySelectorAll('hr.sr-line-mark')]
  const cells = [...body.querySelectorAll('td,th')]
  editor.undoManager.transact(() => {
    rules.forEach((rule) => editor.dom.remove(rule))
    cells.forEach((cell) => {
      Object.keys(MARK).forEach((side) => {
        if (!cell.classList.contains(MARK[side])) return
        cell.classList.remove(MARK[side])
        hideEdge(editor, cell, side)
      })
    })
  })
  finishEdit(editor)
  notify(editor)
  return true
}

function highlight(editor, x, y) {
  const body = editor.getBody()
  const doc = editor.getDoc()
  if (!body || !doc) return
  clearHot(body)
  const target = edgeAt(doc, x, y)
  if (!target) return
  if (target.type === 'hr') {
    target.node.classList.add('sr-line-hot')
    return
  }
  target.cell.classList.add(`sr-line-hot-${target.side}`)
}

function eraseTarget(editor, target) {
  if (!target) return false
  clearHot(editor.getBody())
  if (target.type === 'hr') {
    editor.undoManager.transact(() => {
      editor.dom.remove(target.node)
    })
    finishEdit(editor)
    notify(editor)
    return true
  }
  const neighbor = neighborAcross(target.cell, target.side)
  editor.undoManager.transact(() => {
    target.cell.classList.remove(MARK[target.side])
    hideEdge(editor, target.cell, target.side)
    if (neighbor) {
      neighbor.classList.remove(MARK[OPPOSITE[target.side]])
      hideEdge(editor, neighbor, OPPOSITE[target.side])
    }
  })
  finishEdit(editor)
  notify(editor)
  return true
}

function stopLineEraser(editor) {
  const bag = editor?._srEraseLines
  if (!bag) return
  const doc = editor.getDoc?.()
  if (doc) {
    doc.removeEventListener('mousemove', bag.move, true)
    doc.removeEventListener('mousedown', bag.down, true)
    doc.removeEventListener('mouseup', bag.up, true)
    doc.removeEventListener('keydown', bag.key, true)
  }
  bag.parentDoc?.removeEventListener('keydown', bag.key, true)
  bag.parentDoc?.removeEventListener('mouseup', bag.up, true)
  editor._srEraseLines = null
  const body = editor.getBody?.()
  if (body) {
    clearHot(body)
    body.classList.remove('sr-erasing')
  }
  notify(editor)
}

function startLineEraser(editor) {
  const doc = editor.getDoc()
  const body = editor.getBody()
  if (!doc || !body) return
  body.classList.add('sr-erasing')
  const paint = { down: false, dragging: false, startX: 0, startY: 0, last: '' }
  const move = (event) => {
    highlight(editor, event.clientX, event.clientY)
    if (!paint.down) return
    const moved = Math.hypot(event.clientX - paint.startX, event.clientY - paint.startY)
    if (!paint.dragging && moved < 5) return
    paint.dragging = true
    const target = edgeAt(doc, event.clientX, event.clientY)
    if (!target) return
    const key = targetKey(target)
    if (key === paint.last) return
    paint.last = key
    applyMark(target, true)
    notify(editor)
  }
  const down = (event) => {
    if (event.button !== 0) return
    event.preventDefault()
    event.stopPropagation()
    paint.down = true
    paint.dragging = false
    paint.startX = event.clientX
    paint.startY = event.clientY
    paint.last = ''
  }
  const up = () => {
    if (!paint.down) return
    const dragged = paint.dragging
    const x = paint.startX
    const y = paint.startY
    paint.down = false
    paint.dragging = false
    paint.last = ''
    if (!dragged) eraseTarget(editor, edgeAt(doc, x, y))
  }
  const key = (event) => {
    const tag = event.target?.tagName
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return
    if (event.key === 'Escape') {
      event.preventDefault()
      clearMarks(editor)
      stopLineEraser(editor)
      return
    }
    if ((event.key === 'Delete' || event.key === 'Backspace') && markedLineCount(editor) > 0) {
      event.preventDefault()
      deleteMarkedLines(editor)
    }
  }
  const parentDoc = editor.getContainer?.()?.ownerDocument
  doc.addEventListener('mousemove', move, true)
  doc.addEventListener('mousedown', down, true)
  doc.addEventListener('mouseup', up, true)
  doc.addEventListener('keydown', key, true)
  if (parentDoc && parentDoc !== doc) {
    parentDoc.addEventListener('keydown', key, true)
    parentDoc.addEventListener('mouseup', up, true)
  }
  editor._srEraseLines = { move, down, up, key, parentDoc }
  editor.focus()
  editor.fire('sr-line-eraser', { active: true })
}

export function lineEraserActive(editor) {
  return Boolean(editor?._srEraseLines)
}

export function toggleLineEraser(editor) {
  if (!editor) return false
  if (editor._srEraseLines) {
    stopLineEraser(editor)
    return false
  }
  startLineEraser(editor)
  return true
}

export function registerLineDeletion(editor) {
  editor.on('remove', () => stopLineEraser(editor))
  editor.on('GetContent', (event) => {
    if (typeof event.content !== 'string' || !event.content.includes('sr-line-')) return
    const parsed = new DOMParser().parseFromString(`<div id="sr-root">${event.content}</div>`, 'text/html')
    const root = parsed.getElementById('sr-root')
    if (!root) return
    root.querySelectorAll('[class*="sr-line-"]').forEach((node) => {
      ;[...node.classList].forEach((name) => {
        if (name.startsWith('sr-line-mark') || name.startsWith('sr-line-hot')) node.classList.remove(name)
      })
      if (node.getAttribute('class') === '') node.removeAttribute('class')
    })
    event.content = root.innerHTML
  })
}
