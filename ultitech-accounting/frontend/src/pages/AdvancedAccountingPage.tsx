import {useState, type FormEvent } from 'react'
import {useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {apiRequest } from '../lib/api'
import {useAuth } from '../lib/auth'
import { Button, Card, EmptyState, ErrorBanner, Input, Label, PageHeader, Select, Table  , SectionTitle } from '../components/ui'

export function AdvancedAccountingPage() {
  const {token, companyId } = useAuth()
  const client = useQueryClient()
  const [error, setError] = useState('')
  const [ccCode, setCcCode] = useState('SALES')
  const [ccName, setCcName] = useState('Sales')
  const [periodId, setPeriodId] = useState('')
  const [leaseName, setLeaseName] = useState('Office Lease')
  const [payment, setPayment] = useState('100000')
  const [term, setTerm] = useState('12')
  const [rate, setRate] = useState('12')
  const [rouId, setRouId] = useState('')
  const [liabId, setLiabId] = useState('')
  const [intId, setIntId] = useState('')
  const [deprId, setDeprId] = useState('')
  const [cashId, setCashId] = useState('')
  const [assetId, setAssetId] = useState('')
  const [recoverable, setRecoverable] = useState('500000')

  const costCenters = useQuery({
    queryKey: ['cost-centers', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/cost-centers', {token, companyId }),
  })
  const ccPl = useQuery({
    queryKey: ['cost-center-pl', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () =>
      apiRequest<{data: any[] }>('/cost-centers/profit-and-loss?from=2026-01-01&to=2026-12-31', {token, companyId }),
  })
  const years = useQuery({
    queryKey: ['fiscal-years', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: Array<{periods: Array<{id: number; name: string }> }> }>('/fiscal-years', {token, companyId }),
  })
  const accounts = useQuery({
    queryKey: ['accounts', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: Array<{id: number; code: string; name: string }> }>('/accounts', {token, companyId }),
  })
  const fx = useQuery({
    queryKey: ['fx-revaluations', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/fx-revaluations', {token, companyId }),
  })
  const leases = useQuery({
    queryKey: ['leases', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/leases', {token, companyId }),
  })
  const assets = useQuery({
    queryKey: ['fixed-assets', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/fixed-assets', {token, companyId }),
  })
  const groups = useQuery({
    queryKey: ['consolidation-groups', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/consolidation-groups', {token, companyId }),
  })

  const periods = (years.data?.data ?? []).flatMap((y) => y.periods ?? [])

  const createCc = useMutation({
    mutationFn: () => apiRequest('/cost-centers', {method: 'POST', token, companyId, body: {code: ccCode, name: ccName } }),
    onSuccess: async () => {
      setError('')
      await client.invalidateQueries({queryKey: ['cost-centers', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  const runFx = useMutation({
    mutationFn: () => apiRequest('/fx-revaluations', {method: 'POST', token, companyId, body: {period_id: Number(periodId) } }),
    onSuccess: async () => {
      setError('')
      await client.invalidateQueries({queryKey: ['fx-revaluations', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  const createLease = useMutation({
    mutationFn: () =>
      apiRequest('/leases', {
        method: 'POST',
        token,
        companyId,
        body: {
          name: leaseName,
          commencement_date: '2026-01-01',
          term_months: Number(term),
          payment_amount: payment,
          annual_rate_percent: rate,
          rou_account_id: Number(rouId),
          liability_account_id: Number(liabId),
          interest_account_id: Number(intId),
          depreciation_account_id: Number(deprId),
        },
      }),
    onSuccess: async () => {
      setError('')
      await client.invalidateQueries({queryKey: ['leases', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  const postLease = useMutation({
    mutationFn: (id: number) =>
      apiRequest(`/leases/${id}/post-period`, {
        method: 'POST',
        token,
        companyId,
        body: {period_no: 1, cash_account_id: Number(cashId) },
      }),
    onSuccess: async () => {
      setError('')
      await client.invalidateQueries({queryKey: ['leases', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  const impair = useMutation({
    mutationFn: () =>
      apiRequest('/impairments', {
        method: 'POST',
        token,
        companyId,
        body: {
          asset_id: Number(assetId),
          test_date: '2026-06-30',
          recoverable_amount: recoverable,
        },
      }),
    onSuccess: async () => {
      setError('')
      await client.invalidateQueries({queryKey: ['fixed-assets', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  return (
    <div>
      <PageHeader title="Advanced Accounting" subtitle="Cost centers, FX revaluation, leases, impairment, and consolidation" />
      {error ? <ErrorBanner message={error} /> : null}

      <div className="grid gap-6 xl:grid-cols-2">
        <Card>
          <SectionTitle>Cost centers</SectionTitle>
          <form
            className="mb-4 flex gap-2"
            onSubmit={(e: FormEvent) => {
              e.preventDefault()
              createCc.mutate()
            }}
          >
            <Input value={ccCode} onChange={(e) => setCcCode(e.target.value)} placeholder="Code" required />
            <Input value={ccName} onChange={(e) => setCcName(e.target.value)} placeholder="Name" required />
            <Button type="submit">Add</Button>
          </form>
          {(costCenters.data?.data?.length ?? 0) === 0 ? (
            <EmptyState title="None" body="Add a cost center." />
          ) : (
            <Table headers={['Code', 'Name']}>
              {(costCenters.data?.data ?? []).map((c: any) => (
                <tr key={c.id} className="border-t border-[var(--line)]">
                  <td className="px-4 py-3 font-normal">{c.code}</td>
                  <td className="px-4 py-3">{c.name}</td>
                </tr>
              ))}
            </Table>
          )}
          <div className="mb-2 mt-4 text-sm font-normal ua-empty-title">P&amp;L by cost center (FY2026)</div>
          <Table headers={['Center', 'Revenue', 'Expense', 'Net']}>
            {(ccPl.data?.data ?? []).map((r: any) => (
              <tr key={r.cost_center_id} className="border-t border-[var(--line)]">
                <td className="px-4 py-3">
                  {r.code} — {r.name}
                </td>
                <td className="px-4 py-3">{r.revenue}</td>
                <td className="px-4 py-3">{r.expense}</td>
                <td className="px-4 py-3">{r.net}</td>
              </tr>
            ))}
          </Table>
        </Card>

        <Card>
          <SectionTitle>FX revaluation</SectionTitle>
          <div className="mb-3 space-y-3">
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
            <Button disabled={!periodId || runFx.isPending} onClick={() => runFx.mutate()}>
              Run revaluation
            </Button>
          </div>
          {(fx.data?.data?.length ?? 0) === 0 ? (
            <EmptyState title="No runs" body="Post foreign-currency journals first, then revalue." />
          ) : (
            <Table headers={['Date', 'Gain', 'Loss', 'Status']}>
              {(fx.data?.data ?? []).map((r: any) => (
                <tr key={r.id} className="border-t border-[var(--line)]">
                  <td className="px-4 py-3">{r.revaluation_date}</td>
                  <td className="px-4 py-3">{r.total_gain}</td>
                  <td className="px-4 py-3">{r.total_loss}</td>
                  <td className="px-4 py-3 capitalize">{r.status}</td>
                </tr>
              ))}
            </Table>
          )}
        </Card>
      </div>

      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <Card>
          <SectionTitle>IFRS 16 leases</SectionTitle>
          <form
            className="space-y-3"
            onSubmit={(e: FormEvent) => {
              e.preventDefault()
              createLease.mutate()
            }}
          >
            <Input value={leaseName} onChange={(e) => setLeaseName(e.target.value)} placeholder="Name" required />
            <div className="grid grid-cols-3 gap-2">
              <Input value={payment} onChange={(e) => setPayment(e.target.value)} placeholder="Payment" />
              <Input value={term} onChange={(e) => setTerm(e.target.value)} placeholder="Months" />
              <Input value={rate} onChange={(e) => setRate(e.target.value)} placeholder="Rate %" />
            </div>
            {[
              ['ROU asset', rouId, setRouId],
              ['Liability', liabId, setLiabId],
              ['Interest', intId, setIntId],
              ['Depreciation', deprId, setDeprId],
            ].map(([label, value, setter]) => (
              <div key={label as string}>
                <Label>{label as string}</Label>
                <Select value={value as string} onChange={(e) => (setter as any)(e.target.value)} required>
                  <option value="">Select</option>
                  {(accounts.data?.data ?? []).map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.code} — {a.name}
                    </option>
                  ))}
                </Select>
              </div>
            ))}
            <Button type="submit">Create lease</Button>
          </form>
          <div className="mt-4">
            <Label>Cash account for payment posting</Label>
            <Select value={cashId} onChange={(e) => setCashId(e.target.value)}>
              <option value="">Select</option>
              {(accounts.data?.data ?? []).map((a) => (
                <option key={a.id} value={a.id}>
                  {a.code} — {a.name}
                </option>
              ))}
            </Select>
          </div>
          {(leases.data?.data?.length ?? 0) === 0 ? (
            <div className="mt-3">
              <EmptyState title="No leases" body="Create a lease to generate the payment schedule." />
            </div>
          ) : (
            <Table headers={['Number', 'Liability', 'ROU NBV', '']}>
              {(leases.data?.data ?? []).map((l: any) => (
                <tr key={l.id} className="border-t border-[var(--line)]">
                  <td className="px-4 py-3 font-normal">{l.lease_number}</td>
                  <td className="px-4 py-3">{l.liability_balance}</td>
                  <td className="px-4 py-3">{l.rou_net_book_value}</td>
                  <td className="px-4 py-3">
                    <Button variant="secondary" disabled={!cashId} onClick={() => postLease.mutate(l.id)}>
                      Post period 1
                    </Button>
                  </td>
                </tr>
              ))}
            </Table>
          )}
        </Card>

        <Card>
          <SectionTitle>Impairment & consolidation</SectionTitle>
          <div className="space-y-3">
            <div>
              <Label>Fixed asset</Label>
              <Select value={assetId} onChange={(e) => setAssetId(e.target.value)}>
                <option value="">Select</option>
                {(assets.data?.data ?? []).map((a: any) => (
                  <option key={a.id} value={a.id}>
                    {a.asset_code} — NBV {a.net_book_value}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Recoverable amount</Label>
              <Input value={recoverable} onChange={(e) => setRecoverable(e.target.value)} />
            </div>
            <Button disabled={!assetId || impair.isPending} onClick={() => impair.mutate()}>
              Post impairment
            </Button>
          </div>
          <div className="mb-2 mt-6 text-sm font-normal ua-empty-title">Consolidation groups</div>
          {(groups.data?.data?.length ?? 0) === 0 ? (
            <EmptyState title="No groups" body="Create groups via API or next UI iteration; parent book additive consolidation is available." />
          ) : (
            <Table headers={['Code', 'Name', 'Members']}>
              {(groups.data?.data ?? []).map((g: any) => (
                <tr key={g.id} className="border-t border-[var(--line)]">
                  <td className="px-4 py-3 font-normal">{g.code}</td>
                  <td className="px-4 py-3">{g.name}</td>
                  <td className="px-4 py-3">{g.members?.length ?? 0}</td>
                </tr>
              ))}
            </Table>
          )}
        </Card>
      </div>
    </div>
  )
}
