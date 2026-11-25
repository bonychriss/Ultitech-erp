import {useState } from 'react'
import {useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {apiRequest } from '../lib/api'
import {useAuth } from '../lib/auth'
import { Button, Card, EmptyState, ErrorBanner, Input, Label, PageHeader, Table  , SectionTitle } from '../components/ui'

export function ErpIntegrationPage() {
  const {token, companyId } = useAuth()
  const client = useQueryClient()
  const [error, setError] = useState('')
  const [journalLimit, setJournalLimit] = useState('500')
  const [testResult, setTestResult] = useState<any>(null)

  const status = useQuery({
    queryKey: ['erp-status', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any }>('/erp/status', {token, companyId }),
  })

  const test = useMutation({
    mutationFn: () => apiRequest<{data: any }>('/erp/test', {method: 'POST', token, companyId, body: {} }),
    onSuccess: async (res) => {
      setError('')
      setTestResult(res.data)
      await client.invalidateQueries({queryKey: ['erp-status', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  const pull = useMutation({
    mutationFn: () =>
      apiRequest<{data: any }>('/erp/pull', {
        method: 'POST',
        token,
        companyId,
        body: {
          scopes: ['accounts', 'customers', 'suppliers', 'invoices', 'journals', 'wallets'],
          journal_limit: Number(journalLimit),
        },
      }),
    onSuccess: async () => {
      setError('')
      await Promise.all([
        client.invalidateQueries({queryKey: ['erp-status', companyId] }),
        client.invalidateQueries({queryKey: ['accounts', companyId] }),
        client.invalidateQueries({queryKey: ['customers', companyId] }),
        client.invalidateQueries({queryKey: ['customer-invoices', companyId] }),
        client.invalidateQueries({queryKey: ['journals', companyId] }),
      ])
    },
    onError: (err) => setError((err as Error).message),
  })

  const data = status.data?.data
  const runs = data?.recent_runs ?? []

  return (
    <div>
      <PageHeader
        title="ERP Integration"
        subtitle="Read-only pull from UltiTech ERP. Existing ERP accounting is never changed or deleted."
      />
      {error ? <ErrorBanner message={error} /> : null}

      <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
        Safety mode: Accounting only runs <strong>SELECT</strong> against the ERP tenant database (
        <code>new_trading_voucher-…</code>). After you confirm sync works, we can plan cutover — not now.
      </div>

      <div className="grid gap-6 xl:grid-cols-2">
        <Card>
          <SectionTitle>Connection</SectionTitle>
          <div className="space-y-2 text-sm">
            <div>
              Sync enabled: <strong>{data?.enabled ? 'yes' : 'no'}</strong>
            </div>
            <div>
              ERP database: <strong>{data?.connection?.erp_database ?? '—'}</strong>
            </div>
            <div>
              Read-only flag: <strong>{data?.connection?.read_only ? 'true' : '—'}</strong>
            </div>
            <div>
              Last test: {data?.connection?.last_test_status ?? '—'} — {data?.connection?.last_test_message ?? ''}
            </div>
          </div>
          <div className="mt-4 flex flex-wrap gap-2">
            <Button disabled={test.isPending} onClick={() => test.mutate()}>
              Test ERP read-only link
            </Button>
          </div>
          {testResult ? (
            <pre className="mt-3 overflow-auto rounded-lg bg-[var(--surface)] p-3 text-xs">{JSON.stringify(testResult, null, 2)}</pre>
          ) : null}
        </Card>

        <Card>
          <SectionTitle>Pull into Accounting</SectionTitle>
          <p className="mb-3 text-sm text-[var(--muted)]">
            Imports chart of accounts, customers, suppliers, sales invoices, and a limited batch of GL journals into UltiTech Accounting
            only.
          </p>
          <div className="mb-3">
            <Label>Journal batch limit</Label>
            <Input value={journalLimit} onChange={(e) => setJournalLimit(e.target.value)} />
          </div>
          <Button disabled={pull.isPending || !data?.enabled} onClick={() => pull.mutate()}>
            {pull.isPending ? 'Pulling…' : 'Run read-only sync'}
          </Button>
          {!data?.enabled ? (
            <p className="mt-2 text-sm text-[var(--danger)]">Set ERP_SYNC_ENABLED=true in Accounting backend .env</p>
          ) : null}
        </Card>
      </div>

      <div className="mt-6">
        <SectionTitle>Recent sync runs</SectionTitle>
        {runs.length === 0 ? (
          <EmptyState title="No runs yet" body="Test the connection, then pull a small journal batch first." />
        ) : (
          <Table headers={['Run', 'Status', 'Stats', 'Finished']}>
            {runs.map((r: any) => (
              <tr key={r.id} className="border-t border-[var(--line)]">
                <td className="px-4 py-3 font-normal">{r.run_number}</td>
                <td className="px-4 py-3 capitalize">{r.status}</td>
                <td className="px-4 py-3 text-xs">
                  A:{r.stats_json?.accounts_upserted ?? 0} C:{r.stats_json?.customers_upserted ?? 0} S:
                  {r.stats_json?.suppliers_upserted ?? 0} J:{r.stats_json?.journals_imported ?? 0}/skip
                  {r.stats_json?.journals_skipped ?? 0}
                </td>
                <td className="px-4 py-3">{r.finished_at ?? '—'}</td>
              </tr>
            ))}
          </Table>
        )}
      </div>
    </div>
  )
}
