import type { CompanySummary } from './api'
import type { ErpEmbedState } from './erpEmbed'

export type BootBankAccount = {
  id: number
  code: string
  name: string
  bank_name?: string | null
  account_number?: string | null
  currency_code: string
  account_type?: string
  status?: string | null
  balance?: number | null
  balance_source?: string | null
  balance_direction?: 'up' | 'down' | null
  balance_delta?: number | null
  previous_balance?: number | null
  balance_changed_at?: string | null
  balance_change_basis?: 'opening' | 'last_seen' | null
  opening_balance?: number | null
  opening_date?: string | null
  linked_gl?: string | null
  logo_url?: string | null
  logo_path?: string | null
  erp_financial_account_id?: number | null
  gl_account?: { id?: number; code: string; name: string } | null
}

export type BootCashAccount = {
  id: number
  code: string
  name: string
  currency_code: string
  status?: string | null
  balance?: number | null
  balance_source?: string | null
  balance_direction?: 'up' | 'down' | null
  balance_delta?: number | null
  previous_balance?: number | null
  balance_changed_at?: string | null
  balance_change_basis?: 'opening' | 'last_seen' | null
  opening_balance?: number | null
  opening_date?: string | null
  linked_gl?: string | null
  logo_url?: string | null
  logo_path?: string | null
  erp_financial_account_id?: number | null
  gl_account?: { id?: number; code: string; name: string } | null
}

export type BootInstitution = {
  slug: string
  name: string
  type: string
  aliases?: string[]
  logo_url?: string
}

export type UltitechBoot = {
  hostedInErp?: boolean
  apiBase?: string
  proxyApiBase?: string | null
  upstreamApiBase?: string
  token?: string
  user?: { id: number; uuid: string; name: string; email: string } | null
  companies?: CompanySummary[]
  companyId?: number | null
  company_id?: number | null
  bookId?: number | null
  classicAccountingUrl?: string
  companySlug?: string
  institutions?: BootInstitution[]
  prefetch?: {
    bank_accounts?: BootBankAccount[]
    cash_accounts?: BootCashAccount[]
    accounts?: Array<{
      id: number
      code: string
      name: string
      normal_balance: string
      is_postable: boolean
      is_system?: boolean
      status: string
      account_type_id: number
    }>
  }
  embed?: {
    mode?: string
    back_url?: string
    erp_company_id?: number
    erp_company_slug?: string
    erp_company_name?: string
    erp_db?: string
    company_logo_url?: string
    locked_company?: boolean
  }
}

declare global {
  interface Window {
    __ULTITECH_ACCOUNTING_CFG__?: UltitechBoot
  }
}

export function readBoot(): UltitechBoot {
  if (typeof window !== 'undefined' && window.__ULTITECH_ACCOUNTING_CFG__) {
    return window.__ULTITECH_ACCOUNTING_CFG__
  }
  try {
    const el = document.getElementById('ultitech-accounting-boot')
    if (el?.textContent) {
      return JSON.parse(el.textContent) as UltitechBoot
    }
  } catch {
    /* ignore */
  }
  return {}
}

export function bootToEmbed(boot: UltitechBoot): ErpEmbedState | null {
  if (!boot.hostedInErp) return null
  const e = boot.embed ?? {}
  return {
    mode: 'erp',
    backUrl: e.back_url || boot.classicAccountingUrl || '',
    erpCompanyId: e.erp_company_id ?? null,
    erpCompanySlug: e.erp_company_slug || boot.companySlug || '',
    erpCompanyName: e.erp_company_name || '',
    erpDb: e.erp_db || '',
    companyLogoUrl: e.company_logo_url || '',
    lockedCompany: e.locked_company !== false,
  }
}
