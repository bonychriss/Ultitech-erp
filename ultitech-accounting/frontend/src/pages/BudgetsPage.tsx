import {useState, type FormEvent } from 'react'
import {useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {apiRequest } from '../lib/api'
import {useAuth } from '../lib/auth'
import { Button, Card, EmptyState, ErrorBanner, Input, Label, PageHeader, Select, Table  , SectionTitle } from '../components/ui'

export function BudgetsPage() {
  const {token, companyId } = useAuth()
  const client = useQueryClient()
  const [error, setError] = useState('')
  const [code, setCode] = useState('BUD-2026')
  const [name, setName] = useState('FY2026 Operating Budget')
  const [yearId, setYearId] = useState('')
  const [periodId, setPeriodId] = useState('')
  const [accountId, setAccountId] = useState('')
  const [amount, setAmount] = useState('500000')
  const [selectedBudget, setSelectedBudget] = useState<number | null>(null)

  const years = useQuery({
    queryKey: ['fiscal-years', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: Array<{id: number; name: string; periods: Array<{id: number; name: string }> }> }>('/fiscal-years', {token, companyId }),
  })
  const accounts = useQuery({
    queryKey: ['accounts', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: Array<{id: number; code: string; name: string }> }>('/accounts', {token, companyId }),
  })
  const budgets = useQuery({
    queryKey: ['budgets', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/budgets', {token, companyId }),
  })
  const versus = useQuery({
    queryKey: ['budget-vs', companyId, selectedBudget],
    enabled: Boolean(token && companyId && selectedBudget),
    queryFn: () => apiRequest<{data: any[] }>(`/budgets/${selectedBudget}/versus-actual`, {token, companyId }),
  })

  const periods = (years.data?.data ?? []).flatMap((y) => (y.periods ?? []).map((p) => ({...p, yearId: y.id })))

  const create = useMutation({
    mutationFn: () =>
      apiRequest('/budgets', {
        method: 'POST',
        token,
        companyId,
        body: {
          fiscal_year_id: Number(yearId),
          code,
          name,
          lines: [{period_id: Number(periodId), account_id: Number(accountId), amount }],
        },
      }),
    onSuccess: async () => {
      setError('')
      await client.invalidateQueries({queryKey: ['budgets', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  const approve = useMutation({
    mutationFn: (id: number) => apiRequest(`/budgets/${id}/approve`, {method: 'POST', token, companyId, body: {} }),
    onSuccess: async () => {
      setError('')
      await client.invalidateQueries({queryKey: ['budgets', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  return (
    <div>
      <PageHeader title="Budgets" subtitle="Budget versions and budget versus actual by account and period" />
      {error ? <ErrorBanner message={error} /> : null}

      <div className="grid gap-6 xl:grid-cols-2">
        <Card>
          <SectionTitle>Create budget</SectionTitle>
          <form
            className="space-y-3"
            onSubmit={(e: FormEvent) => {
              e.preventDefault()
              create.mutate()
            }}
          >
            <div>
              <Label>Fiscal year</Label>
              <Select
                value={yearId}
                onChange={(e) => {
                  setYearId(e.target.value)
                  setPeriodId('')
                }}
                required
              >
                <option value="">Select</option>
                {(years.data?.data ?? []).map((y) => (
                  <option key={y.id} value={y.id}>
                    {y.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Code</Label>
              <Input value={code} onChange={(e) => setCode(e.target.value)} required />
            </div>
            <div>
              <Label>Name</Label>
              <Input value={name} onChange={(e) => setName(e.target.value)} required />
            </div>
            <div>
              <Label>Period</Label>
              <Select value={periodId} onChange={(e) => setPeriodId(e.target.value)} required>
                <option value="">Select</option>
                {periods
                  .filter((p) => !yearId || String(p.yearId) === yearId)
                  .map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name}
                    </option>
                  ))}
              </Select>
            </div>
            <div>
              <Label>Account</Label>
              <Select value={accountId} onChange={(e) => setAccountId(e.target.value)} required>
                <option value="">Select</option>
                {(accounts.data?.data ?? []).map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.code} — {a.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Amount</Label>
              <Input value={amount} onChange={(e) => setAmount(e.target.value)} required />
            </div>
            <Button disabled={create.isPending}>Save budget</Button>
          </form>
        </Card>

        <Card>
          <SectionTitle>Budgets</SectionTitle>
          {(budgets.data?.data?.length ?? 0) === 0 ? (
            <EmptyState title="No budgets" body="Create a draft budget for the fiscal year." />
          ) : (
            <Table headers={['Code', 'Name', 'Status', '']}>
              {(budgets.data?.data ?? []).map((b: any) => (
                <tr key={b.id} className="border-t border-[var(--line)]">
                  <td className="px-4 py-3 font-normal">{b.code}</td>
                  <td className="px-4 py-3">{b.name}</td>
                  <td className="px-4 py-3 capitalize">{b.status}</td>
                  <td className="px-4 py-3 space-x-2">
                    {b.status === 'draft' ? (
                      <Button variant="secondary" onClick={() => approve.mutate(b.id)}>
                        Approve
                      </Button>
                    ) : null}
                    <Button variant="secondary" onClick={() => setSelectedBudget(b.id)}>
                      Vs actual
                    </Button>
                  </td>
                </tr>
              ))}
            </Table>
          )}
        </Card>
      </div>

      {selectedBudget ? (
        <div className="mt-6">
          <SectionTitle>Budget vs actual</SectionTitle>
          {(versus.data?.data?.length ?? 0) === 0 ? (
            <EmptyState title="No lines" body="Budget has no lines or is still loading." />
          ) : (
            <Table headers={['Period', 'Account', 'Budget', 'Actual', 'Variance']}>
              {(versus.data?.data ?? []).map((r: any, i: number) => (
                <tr key={i} className="border-t border-[var(--line)]">
                  <td className="px-4 py-3">{r.period}</td>
                  <td className="px-4 py-3">
                    {r.account_code} — {r.account_name}
                  </td>
                  <td className="px-4 py-3">{r.budget}</td>
                  <td className="px-4 py-3">{r.actual}</td>
                  <td className="px-4 py-3">{r.variance}</td>
                </tr>
              ))}
            </Table>
          )}
        </div>
      ) : null}
    </div>
  )
}
