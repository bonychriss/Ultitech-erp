import { useQuery } from '@tanstack/react-query'
import { apiRequest } from '../lib/api'
import { useAuth } from '../lib/auth'
import { Card, EmptyState, ErrorBanner, PageHeader } from '../components/ui'

type TrialBalanceResponse = {
  totals: { debit: string; credit: string; balanced: boolean }
  data: Array<{ account_code: string; account_name: string; debit: string; credit: string }>
}

export function DashboardPage() {
  const { token, companyId } = useAuth()
  const query = useQuery({
    queryKey: ['trial-balance', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () =>
      apiRequest<TrialBalanceResponse>('/trial-balance', {
        token,
        companyId,
      }),
  })

  return (
    <div>
      <PageHeader title="Financial overview" subtitle="Milestone 01 foundation dashboard" />
      {query.isError ? <ErrorBanner message={(query.error as Error).message} /> : null}
      {query.isLoading ? <Card>Loading balances…</Card> : null}
      {query.data ? (
        <div className="grid gap-4 md:grid-cols-3">
          <Card>
            <div className="ua-section-title">Total debits</div>
            <div className="ua-stat">{query.data.totals.debit}</div>
          </Card>
          <Card>
            <div className="ua-section-title">Total credits</div>
            <div className="ua-stat">{query.data.totals.credit}</div>
          </Card>
          <Card>
            <div className="ua-section-title">Trial balance</div>
            <div className={`ua-stat ${query.data.totals.balanced ? 'text-[var(--ok)]' : 'text-[var(--danger)]'}`}>
              {query.data.totals.balanced ? 'Balanced' : 'Out of balance'}
            </div>
          </Card>
        </div>
      ) : null}
      {query.data && query.data.data.length === 0 ? (
        <div className="mt-6">
          <EmptyState title="No posted activity yet" body="Create and post a journal to populate the overview." />
        </div>
      ) : null}
    </div>
  )
}
