import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { apiRequest } from '../lib/api'
import { useAuth } from '../lib/auth'
import { type ErpEmbedState } from '../lib/erpEmbed'
import { Button, Card } from '../components/ui'

export function ErpHandoffPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const { applyBridgeSession } = useAuth()
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const payload = params.get('payload')
    const sig = params.get('sig')
    const back = params.get('back') ?? ''
    if (!payload || !sig) {
      setError('Missing ERP bridge credentials. Open Accounting again from the ERP hub.')
      return
    }

    let cancelled = false
    ;(async () => {
      try {
        const result = await apiRequest<{
          token: string
          user: { id: number; uuid: string; name: string; email: string }
          companies: Array<{
            id: number
            uuid: string
            code: string
            legal_name: string
            role?: string
            permissions?: string[]
            is_default?: boolean
          }>
          company_id: number
          embed: {
            mode: string
            back_url?: string
            erp_company_id?: number
            erp_company_slug?: string
            erp_company_name?: string
            erp_db?: string
            company_logo_url?: string
            locked_company?: boolean
          }
        }>('/auth/erp-bridge', {
          method: 'POST',
          body: { payload, sig, back_url: back || undefined },
        })

        if (cancelled) return

        const embed: ErpEmbedState = {
          mode: 'erp',
          backUrl: result.embed?.back_url || back,
          erpCompanyId: result.embed?.erp_company_id ?? null,
          erpCompanySlug: result.embed?.erp_company_slug ?? '',
          erpCompanyName: result.embed?.erp_company_name ?? '',
          erpDb: result.embed?.erp_db ?? '',
          companyLogoUrl: result.embed?.company_logo_url ?? '',
          lockedCompany: Boolean(result.embed?.locked_company),
        }

        applyBridgeSession({
          token: result.token,
          user: result.user,
          companies: result.companies,
          companyId: result.company_id,
          embed,
        })

        navigate('/', { replace: true })
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'ERP bridge failed')
        }
      }
    })()

    return () => {
      cancelled = true
    }
  }, [params, applyBridgeSession, navigate])

  return (
    <div className="flex min-h-screen items-center justify-center bg-[var(--surface)] px-4">
      <Card className="w-full max-w-md space-y-3 p-6">
        <div className="text-xs uppercase tracking-[0.18em] text-[var(--muted)]">ERP bridge</div>
        <div className="text-sm font-normal ua-page-title">Connecting company…</div>
        {error ? (
          <>
            <p className="text-sm text-[var(--danger)]">{error}</p>
            <div className="flex gap-2">
              {params.get('back') ? (
                <Button onClick={() => (window.location.href = String(params.get('back')))}>
                  Back to ERP Accounting
                </Button>
              ) : null}
              <Button variant="secondary" onClick={() => navigate('/login')}>
                Standalone login
              </Button>
            </div>
          </>
        ) : (
          <p className="text-sm text-[var(--muted)]">Signing you into UltiTech Accounting with your ERP company.</p>
        )}
      </Card>
    </div>
  )
}
