const RAW = (typeof window !== 'undefined' && window.__SYSTEM_FONT_CFG__) || {}

export const CFG = {
  apiUrl: RAW.apiUrl || 'system-font-ui/api/index.php',
  backUrl: RAW.backUrl || '../personalization/index.php?module=personalization',
  module: RAW.module || 'personalization',
  selectedKey: typeof RAW.selectedKey === 'string' ? RAW.selectedKey : '',
  effectiveKey: RAW.effectiveKey || '',
  isPersonalChoice: Boolean(RAW.isPersonalChoice),
  companyFont: RAW.companyFont || { key: 'poppins', label: 'Poppins', stack: "'Poppins', sans-serif" },
  effectiveFont: RAW.effectiveFont || RAW.companyFont || { key: 'poppins', label: 'Poppins', stack: "'Poppins', sans-serif" },
  fonts: Array.isArray(RAW.fonts) ? RAW.fonts : [],
}

export function companyDefaultLabel() {
  return `Company default (${CFG.companyFont.label || 'Poppins'})`
}

export function fontById(key) {
  if (!key) return CFG.companyFont
  return CFG.fonts.find((f) => f.id === key) || CFG.companyFont
}

export function labelForKey(key) {
  if (!key) return companyDefaultLabel()
  return fontById(key).label || key
}

export function stackForKey(key) {
  if (!key) return CFG.companyFont.stack || "'Poppins', sans-serif"
  return fontById(key).stack || CFG.companyFont.stack || "'Poppins', sans-serif"
}
