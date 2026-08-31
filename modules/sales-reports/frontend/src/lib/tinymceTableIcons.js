/** Filled table-toolbar icons (24×24) — clearer than default TinyMCE line art. */
const svg = (body) =>
  `<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">${body}</svg>`

const grid = (x, y, w, h) =>
  `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="1" fill="#f8fafc" stroke="#64748b" stroke-width="1"/>`

const gridLines = (x, y, w, h, rows, cols) => {
  let lines = ''
  const cellW = w / cols
  const cellH = h / rows
  for (let r = 1; r < rows; r += 1) {
    const ly = y + r * cellH
    lines += `<line x1="${x}" y1="${ly}" x2="${x + w}" y2="${ly}" stroke="#94a3b8" stroke-width="0.75"/>`
  }
  for (let c = 1; c < cols; c += 1) {
    const lx = x + c * cellW
    lines += `<line x1="${lx}" y1="${y}" x2="${lx}" y2="${y + h}" stroke="#94a3b8" stroke-width="0.75"/>`
  }
  return lines
}

export const TINYMCE_TABLE_ICONS = {
  table: svg(`
    ${grid(4, 5, 16, 14)}
    ${gridLines(4, 5, 16, 14, 3, 3)}
    <circle cx="18" cy="7" r="3" fill="#2563eb"/>
    <path d="M18 5.5v3M16.5 7h3" stroke="#fff" stroke-width="1.2" stroke-linecap="round"/>
  `),

  'table-delete-table': svg(`
    ${grid(4, 5, 16, 14)}
    ${gridLines(4, 5, 16, 14, 3, 3)}
    <circle cx="17" cy="17" r="5" fill="#dc2626"/>
    <path d="M15.2 15.2l3.6 3.6M18.8 15.2l-3.6 3.6" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/>
  `),

  'table-insert-row-above': svg(`
    <rect x="5" y="3" width="14" height="4" rx="1" fill="#dcfce7" stroke="#16a34a" stroke-width="1"/>
    <path d="M12 4.2v1.6M11.2 5.8h1.6" stroke="#16a34a" stroke-width="1.2" stroke-linecap="round"/>
    ${grid(4, 9, 16, 10)}
    ${gridLines(4, 9, 16, 10, 2, 3)}
  `),

  'table-insert-row-after': svg(`
    ${grid(4, 5, 16, 10)}
    ${gridLines(4, 5, 16, 10, 2, 3)}
    <rect x="5" y="17" width="14" height="4" rx="1" fill="#dcfce7" stroke="#16a34a" stroke-width="1"/>
    <path d="M12 18.2v1.6M11.2 19.8h1.6" stroke="#16a34a" stroke-width="1.2" stroke-linecap="round"/>
  `),

  'table-delete-row': svg(`
    ${grid(4, 5, 16, 14)}
    ${gridLines(4, 5, 16, 14, 3, 3)}
    <rect x="4" y="10" width="16" height="4.5" rx="0.5" fill="#fee2e2" stroke="#dc2626" stroke-width="1"/>
    <path d="M10.5 12.2h3M10.5 12.8h3" stroke="#dc2626" stroke-width="1.2" stroke-linecap="round"/>
  `),

  'table-insert-column-before': svg(`
    <rect x="2" y="6" width="4" height="12" rx="1" fill="#dcfce7" stroke="#16a34a" stroke-width="1"/>
    <path d="M4 11.2v1.6M3.2 12h1.6" stroke="#16a34a" stroke-width="1.2" stroke-linecap="round"/>
    ${grid(8, 5, 12, 14)}
    ${gridLines(8, 5, 12, 14, 3, 2)}
  `),

  'table-insert-column-after': svg(`
    ${grid(4, 5, 12, 14)}
    ${gridLines(4, 5, 12, 14, 3, 2)}
    <rect x="18" y="6" width="4" height="12" rx="1" fill="#dcfce7" stroke="#16a34a" stroke-width="1"/>
    <path d="M20 11.2v1.6M19.2 12h1.6" stroke="#16a34a" stroke-width="1.2" stroke-linecap="round"/>
  `),

  'table-delete-column': svg(`
    ${grid(4, 5, 16, 14)}
    ${gridLines(4, 5, 16, 14, 3, 3)}
    <rect x="10" y="5" width="4.5" height="14" rx="0.5" fill="#fee2e2" stroke="#dc2626" stroke-width="1"/>
    <path d="M11.8 11v3M12.8 11v3" stroke="#dc2626" stroke-width="1.2" stroke-linecap="round"/>
  `),
}

export function registerTinyMceTableIcons(editor) {
  Object.entries(TINYMCE_TABLE_ICONS).forEach(([name, iconSvg]) => {
    editor.ui.registry.addIcon(name, iconSvg)
  })
}
