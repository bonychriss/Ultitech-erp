import {useState, type FormEvent } from 'react'
import {useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {apiRequest } from '../lib/api'
import {useAuth } from '../lib/auth'
import { Button, Card, EmptyState, ErrorBanner, Input, Label, PageHeader, Select, Table  , SectionTitle } from '../components/ui'

export function GovernancePage() {
  const {token, companyId } = useAuth()
  const client = useQueryClient()
  const [error, setError] = useState('')
  const [periodId, setPeriodId] = useState('')
  const [yearId, setYearId] = useState('')
  const [asOf, setAsOf] = useState('2026-02-28')
  const [checklist, setChecklist] = useState<any[] | null>(null)

  const years = useQuery({
    queryKey: ['fiscal-years', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: Array<{id: number; name: string; periods: Array<{id: number; name: string }> }> }>('/fiscal-years', {token, companyId }),
  })
  const requests = useQuery({
    queryKey: ['approval-requests', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/approval-requests', {token, companyId }),
  })
  const workflows = useQuery({
    queryKey: ['approval-workflows', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/approval-workflows', {token, companyId }),
  })
  const audit = useQuery({
    queryKey: ['audit-logs', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/audit-logs', {token, companyId }),
  })
  const reports = useQuery({
    queryKey: ['compliance-reports', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/compliance-reports', {token, companyId }),
  })
  const periodCloses = useQuery({
    queryKey: ['period-closes', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/period-closes', {token, companyId }),
  })

  const periods = (years.data?.data ?? []).flatMap((y) => (y.periods ?? []).map((p) => ({...p, yearId: y.id })))

  const runChecklist = useMutation({
    mutationFn: () =>
      apiRequest<{data: {items: any[]; status: string } }>('/period-close/checklist', {
        method: 'POST',
        token,
        companyId,
        body: {period_id: Number(periodId) },
      }),
    onSuccess: (res) => {
      setError('')
      setChecklist(res.data.items)
    },
    onError: (err) => setError((err as Error).message),
  })

  const closePeriod = useMutation({
    mutationFn: () => apiRequest('/period-close', {method: 'POST', token, companyId, body: {period_id: Number(periodId) } }),
    onSuccess: async () => {
      setError('')
      await Promise.all([
        client.invalidateQueries({queryKey: ['period-closes', companyId] }),
        client.invalidateQueries({queryKey: ['fiscal-years', companyId] }),
        client.invalidateQueries({queryKey: ['audit-logs', companyId] }),
      ])
    },
    onError: (err) => setError((err as Error).message),
  })

  const closeYear = useMutation({
    mutationFn: () => apiRequest('/year-close', {method: 'POST', token, companyId, body: {fiscal_year_id: Number(yearId) } }),
    onSuccess: async () => {
      setError('')
      await client.invalidateQueries({queryKey: ['fiscal-years', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  const act = useMutation({
    mutationFn: ({id, action }: {id: number; action: 'approve' | 'reject' }) =>
      apiRequest(`/approval-requests/${id}/act`, {method: 'POST', token, companyId, body: {action } }),
    onSuccess: async () => {
      setError('')
      await client.invalidateQueries({queryKey: ['approval-requests', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  const generateCompliance = useMutation({
    mutationFn: () => apiRequest('/compliance-reports', {method: 'POST', token, companyId, body: {as_of_date: asOf } }),
    onSuccess: async () => {
      setError('')
      await client.invalidateQueries({queryKey: ['compliance-reports', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  return (
    <div>
      <PageHeader title="Governance" subtitle="Approvals, month/year-end close, audit trail, and compliance snapshots" />
      {error ? <ErrorBanner message={error} /> : null}

      <div className="grid gap-6 xl:grid-cols-2">
        <Card>
          <SectionTitle>Approval requests</SectionTitle>
          {(workflows.data?.data?.length ?? 0) > 0 ? (
            <p className="mb-3 text-sm text-[var(--muted)]">
              Active workflows: {(workflows.data?.data ?? []).map((w: any) => w.code).join(', ')}
            </p>
          ) : null}
          {(requests.data?.data?.length ?? 0) === 0 ? (
            <EmptyState title="No requests" body="Submit a draft journal for approval from the Journals API/UI flow." />
          ) : (
            <Table headers={['ID', 'Entity', 'Status', 'Step', '']}>
              {(requests.data?.data ?? []).map((r: any) => (
                <tr key={r.id} className="border-t border-[var(--line)]">
                  <td className="px-4 py-3 font-normal">{r.id}</td>
                  <td className="px-4 py-3">
                    {r.entity_type}#{r.entity_id}
                  </td>
                  <td className="px-4 py-3 capitalize">{r.status}</td>
                  <td className="px-4 py-3">{r.current_step}</td>
                  <td className="px-4 py-3 space-x-2">
                    {r.status === 'pending' ? (
                      <>
                        <Button variant="secondary" onClick={() => act.mutate({id: r.id, action: 'approve' })}>
                          Approve
                        </Button>
                        <Button variant="danger" onClick={() => act.mutate({id: r.id, action: 'reject' })}>
                          Reject
                        </Button>
                      </>
                    ) : (
                      '—'
                    )}
                  </td>
                </tr>
              ))}
            </Table>
          )}
        </Card>

        <Card>
          <SectionTitle>Month-end close</SectionTitle>
          <div className="space-y-3">
            <div>
              <Label>Period</Label>
              <Select value={periodId} onChange={(e) => setPeriodId(e.target.value)}>
                <option value="">Select</option>
                {periods.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </Select>
            </div>
            <div className="flex gap-2">
              <Button disabled={!periodId || runChecklist.isPending} onClick={() => runChecklist.mutate()}>
                Run checklist
              </Button>
              <Button disabled={!periodId || closePeriod.isPending} onClick={() => closePeriod.mutate()}>
                Close period
              </Button>
            </div>
            {checklist ? (
              <Table headers={['Check', 'Result', 'Details']}>
                {checklist.map((i) => (
                  <tr key={i.code} className="border-t border-[var(--line)]">
                    <td className="px-4 py-3">{i.label}</td>
                    <td className="px-4 py-3">{i.is_passed ? 'Pass' : 'Fail'}</td>
                    <td className="px-4 py-3">{i.details}</td>
                  </tr>
                ))}
              </Table>
            ) : null}
            <div className="pt-2 text-sm font-normal ua-empty-title">Closed periods</div>
            {(periodCloses.data?.data?.length ?? 0) === 0 ? (
              <EmptyState title="None" body="No period closes yet." />
            ) : (
              <Table headers={['Period ID', 'Status', 'Closed at']}>
                {(periodCloses.data?.data ?? []).map((c: any) => (
                  <tr key={c.id} className="border-t border-[var(--line)]">
                    <td className="px-4 py-3">{c.period_id}</td>
                    <td className="px-4 py-3 capitalize">{c.status}</td>
                    <td className="px-4 py-3">{c.closed_at}</td>
                  </tr>
                ))}
              </Table>
            )}
          </div>
        </Card>
      </div>

      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <Card>
          <SectionTitle>Year-end close</SectionTitle>
          <form
            className="space-y-3"
            onSubmit={(e: FormEvent) => {
              e.preventDefault()
              closeYear.mutate()
            }}
          >
            <div>
              <Label>Fiscal year</Label>
              <Select value={yearId} onChange={(e) => setYearId(e.target.value)} required>
                <option value="">Select</option>
                {(years.data?.data ?? []).map((y) => (
                  <option key={y.id} value={y.id}>
                    {y.name}
                  </option>
                ))}
              </Select>
            </div>
            <Button type="submit" disabled={closeYear.isPending}>
              Close year (P&amp;L → retained earnings)
            </Button>
          </form>
        </Card>

        <Card>
          <SectionTitle>Compliance report</SectionTitle>
          <div className="mb-3 flex gap-2">
            <Input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} />
            <Button disabled={generateCompliance.isPending} onClick={() => generateCompliance.mutate()}>
              Generate
            </Button>
          </div>
          {(reports.data?.data?.length ?? 0) === 0 ? (
            <EmptyState title="No reports" body="Generate a compliance snapshot." />
          ) : (
            <Table headers={['Number', 'As of', 'Passed', 'Failed', 'Status']}>
              {(reports.data?.data ?? []).map((r: any) => (
                <tr key={r.id} className="border-t border-[var(--line)]">
                  <td className="px-4 py-3 font-normal">{r.report_number}</td>
                  <td className="px-4 py-3">{r.as_of_date}</td>
                  <td className="px-4 py-3">{r.passed_count}</td>
                  <td className="px-4 py-3">{r.failed_count}</td>
                  <td className="px-4 py-3 capitalize">{r.status}</td>
                </tr>
              ))}
            </Table>
          )}
        </Card>
      </div>

      <div className="mt-6">
        <SectionTitle>Audit trail</SectionTitle>
        {(audit.data?.data?.length ?? 0) === 0 ? (
          <EmptyState title="No events" body="Audit events appear as users post and close." />
        ) : (
          <Table headers={['When', 'Action', 'Entity', 'User']}>
            {(audit.data?.data ?? []).slice(0, 30).map((a: any) => (
              <tr key={a.id} className="border-t border-[var(--line)]">
                <td className="px-4 py-3">{a.created_at}</td>
                <td className="px-4 py-3">{a.action}</td>
                <td className="px-4 py-3">
                  {a.entity_type}
                  {a.entity_id ? `#${a.entity_id}` : ''}
                </td>
                <td className="px-4 py-3">{a.user_id ?? '—'}</td>
              </tr>
            ))}
          </Table>
        )}
      </div>
    </div>
  )
}
