import { useQuery } from '@tanstack/react-query'
import { apiRequest } from '../lib/api'
import { useAuth } from '../lib/auth'
import { Card, EmptyState, ErrorBanner, PageHeader, Table } from '../components/ui'

type TrialBalanceResponse = {
  totals: { debit: string; credit: string; balanced: boolean }
  data: Array<{ account_code: string; account_name: string; debit: string; credit: string }>
}

export function TrialBalancePage() {
  const { token, companyId } = useAuth()
  const query = useQuery({
    queryKey: ['trial-balance', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<TrialBalanceResponse>('/trial-balance', { token, companyId }),
  })

  return (
    <div>
      <PageHeader title="Trial Balance" subtitle="Functional currency totals for the active book" />
      {query.isError ? <ErrorBanner message={(query.error as Error).message} /> : null}
      {query.isLoading ? <Card>Loading trial balance…</Card> : null}
      {query.data ? (
        <>
          <Card className="mb-4">
            <div className="flex flex-wrap gap-6 text-sm">
              <div>
                <div className="text-[var(--muted)]">Total debit</div>
                <div className="text-sm font-normal">{query.data.totals.debit}</div>
              </div>
              <div>
                <div className="text-[var(--muted)]">Total credit</div>
                <div className="text-sm font-normal">{query.data.totals.credit}</div>
              </div>
              <div>
                <div className="text-[var(--muted)]">Status</div>
                <div className={`text-sm font-normal ${query.data.totals.balanced ? 'text-[var(--ok)]' : 'text-[var(--danger)]'}`}>
                  {query.data.totals.balanced ? 'Balanced' : 'Out of balance'}
                </div>
              </div>
            </div>
          </Card>
          {query.data.data.length ? (
            <Table headers={['Code', 'Account', 'Debit', 'Credit']}>
              {query.data.data.map((row) => (
                <tr key={`${row.account_code}-${row.account_name}`} className="border-t border-[var(--line)]">
                  <td className="px-4 py-3 font-normal">{row.account_code}</td>
                  <td className="px-4 py-3">{row.account_name}</td>
                  <td className="px-4 py-3">{row.debit}</td>
                  <td className="px-4 py-3">{row.credit}</td>
                </tr>
              ))}
            </Table>
          ) : (
            <EmptyState title="No balances" body="Posted journals will appear here." />
          )}
        </>
      ) : null}
    </div>
  )
}
