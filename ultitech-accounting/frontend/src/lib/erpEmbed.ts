export type ErpEmbedState = {
  mode: 'erp' | 'standalone'
  backUrl: string
  erpCompanyId: number | null
  erpCompanySlug: string
  erpCompanyName: string
  erpDb: string
  companyLogoUrl: string
  lockedCompany: boolean
}

const EMBED_KEY = 'ultitech_accounting_erp_embed'

const empty: ErpEmbedState = {
  mode: 'standalone',
  backUrl: '',
  erpCompanyId: null,
  erpCompanySlug: '',
  erpCompanyName: '',
  erpDb: '',
  companyLogoUrl: '',
  lockedCompany: false,
}

export function loadErpEmbed(): ErpEmbedState {
  try {
    const raw = sessionStorage.getItem(EMBED_KEY)
    if (!raw) return empty
    const parsed = JSON.parse(raw) as Partial<ErpEmbedState>
    return {
      ...empty,
      ...parsed,
      mode: parsed.mode === 'erp' ? 'erp' : 'standalone',
      lockedCompany: Boolean(parsed.lockedCompany),
    }
  } catch {
    return empty
  }
}

export function saveErpEmbed(state: ErpEmbedState | null) {
  if (!state || state.mode !== 'erp') {
    sessionStorage.removeItem(EMBED_KEY)
    return
  }
  sessionStorage.setItem(EMBED_KEY, JSON.stringify(state))
}

export function clearErpEmbed() {
  sessionStorage.removeItem(EMBED_KEY)
}
