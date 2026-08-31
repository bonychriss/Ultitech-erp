export const CFG = window.__SALES_REPORTS_CFG__ || {}

/** Merge query params onto a URL that may already include ?module=... */
export function buildUrl(base, query = {}) {
  if (!base) return '#'
  const qIndex = base.indexOf('?')
  const path = qIndex >= 0 ? base.slice(0, qIndex) : base
  const params = new URLSearchParams(qIndex >= 0 ? base.slice(qIndex + 1) : '')
  Object.entries(query).forEach(([key, value]) => {
    if (value != null && value !== '') {
      params.set(key, String(value))
    }
  })
  const qs = params.toString()
  return qs ? `${path}?${qs}` : path
}

export function apiUrl(path, query = {}) {
  const base = (CFG.urls?.apiBase || 'api/').replace(/\/?$/, '/')
  return buildUrl(`${base}${path.replace(/^\//, '')}`, { module: CFG.module || 'analytics', ...query })
}

export function exportUrl(reportId, format) {
  return buildUrl(CFG.urls?.export || 'api/export.php', {
    id: reportId,
    format,
    module: CFG.module || 'analytics',
  })
}

export function pageUrl(type, id) {
  const templates = {
    create: CFG.urls?.create,
    editor: buildUrl(CFG.urls?.editor, { id, module: CFG.module || 'analytics' }),
    export: exportUrl(id, ''),
  }
  return templates[type] || '#'
}
