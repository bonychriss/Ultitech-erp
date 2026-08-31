function decodeHtmlEntities(str) {
  const el = document.createElement('textarea')
  el.innerHTML = str
  return el.value
}

function unwrapSectionNodes(root) {
  root.querySelectorAll('section.sr-section, section[data-section-key], div.sr-section[data-section-id]').forEach((node) => {
    const parent = node.parentNode
    if (!parent) return
    while (node.firstChild) {
      parent.insertBefore(node.firstChild, node)
    }
    node.remove()
  })
}

/** ERP blocks are stored with contenteditable=false for live refresh; unwrap so tables are fully editable. */
function unwrapErpBlocks(root) {
  root.querySelectorAll('.sr-erp-block').forEach((block) => {
    const parent = block.parentNode
    if (!parent) return
    while (block.firstChild) {
      parent.insertBefore(block.firstChild, block)
    }
    block.remove()
  })
}

/** Normalize stored report HTML so TinyMCE renders it as a document, not raw source. */
export function prepareHtmlForEditor(html) {
  if (!html || typeof html !== 'string') return ''

  let out = html.trim()

  if (out.includes('&lt;')) {
    const decoded = decodeHtmlEntities(out)
    if (decoded.includes('<div') || decoded.includes('<h2') || decoded.includes('<table')) {
      out = decoded.trim()
    }
  }

  if (/^<pre[^>]*>/i.test(out)) {
    out = out.replace(/^<pre[^>]*>/i, '').replace(/<\/pre>$/i, '')
    out = decodeHtmlEntities(out).trim()
  }

  if (typeof DOMParser !== 'undefined') {
    try {
      const doc = new DOMParser().parseFromString(`<div id="sr-root">${out}</div>`, 'text/html')
      const root = doc.getElementById('sr-root')
      if (root) {
        unwrapSectionNodes(root)
        unwrapErpBlocks(root)
        const parsed = root.innerHTML.trim()
        if (parsed !== '') {
          out = parsed
        }
      }
    } catch {
      out = out.replace(/<section\b[^>]*>/gi, '').replace(/<\/section>/gi, '')
    }
  } else {
    out = out.replace(/<section\b[^>]*>/gi, '').replace(/<\/section>/gi, '')
  }

  return out.trim()
}

export function loadHtmlIntoEditor(editor, html) {
  const next = prepareHtmlForEditor(html) || '<p></p>'
  const body = editor.getBody()
  if (!body) return
  body.innerHTML = next
  if (typeof editor.nodeChanged === 'function') {
    editor.nodeChanged()
  }
  if (editor.undoManager && typeof editor.undoManager.clear === 'function') {
    editor.undoManager.clear()
  }
}
