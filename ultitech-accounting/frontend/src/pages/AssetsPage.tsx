import {useState, type FormEvent } from 'react'
import {useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {apiRequest } from '../lib/api'
import {useAuth } from '../lib/auth'
import { Button, Card, EmptyState, ErrorBanner, Input, Label, PageHeader, Select, Table  , SectionTitle } from '../components/ui'

export function AssetsPage() {
  const {token, companyId } = useAuth()
  const client = useQueryClient()
  const [error, setError] = useState('')
  const [categoryId, setCategoryId] = useState('')
  const [name, setName] = useState('Office Desk')
  const [cost, setCost] = useState('1200000')
  const [creditAccountId, setCreditAccountId] = useState('')
  const [periodId, setPeriodId] = useState('')

  const categories = useQuery({
    queryKey: ['asset-categories', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: Array<{id: number; code: string; name: string }> }>('/asset-categories', {token, companyId }),
  })
  const assets = useQuery({
    queryKey: ['fixed-assets', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/fixed-assets', {token, companyId }),
  })
  const accounts = useQuery({
    queryKey: ['accounts', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: Array<{id: number; code: string; name: string }> }>('/accounts', {token, companyId }),
  })
  const years = useQuery({
    queryKey: ['fiscal-years', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: Array<{periods: Array<{id: number; name: string }> }> }>('/fiscal-years', {token, companyId }),
  })

  const periods = (years.data?.data ?? []).flatMap((y) => y.periods ?? [])

  const acquire = useMutation({
    mutationFn: () =>
      apiRequest('/fixed-assets', {
        method: 'POST',
        token,
        companyId,
        body: {
          category_id: Number(categoryId),
          name,
          acquisition_date: new Date().toISOString().slice(0, 10),
          acquisition_cost: cost,
          salvage_value: 0,
          credit_account_id: Number(creditAccountId),
        },
      }),
    onSuccess: async () => {
      setError('')
      await client.invalidateQueries({queryKey: ['fixed-assets', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  const depreciate = useMutation({
    mutationFn: () =>
      apiRequest('/depreciation-runs', {
        method: 'POST',
        token,
        companyId,
        body: {period_id: Number(periodId) },
      }),
    onSuccess: async () => {
      setError('')
      await Promise.all([
        client.invalidateQueries({queryKey: ['fixed-assets', companyId] }),
        client.invalidateQueries({queryKey: ['trial-balance', companyId] }),
      ])
    },
    onError: (err) => setError((err as Error).message),
  })

  return (
    <div>
      <PageHeader title="Fixed Assets" subtitle="Asset register, acquisition and depreciation runs" />
      {error ? <ErrorBanner message={error} /> : null}

      <div className="grid gap-6 xl:grid-cols-2">
        <Card>
          <SectionTitle>Acquire asset</SectionTitle>
          <form
            className="space-y-3"
            onSubmit={(e: FormEvent) => {
              e.preventDefault()
              acquire.mutate()
            }}
          >
            <div>
              <Label>Category</Label>
              <Select value={categoryId} onChange={(e) => setCategoryId(e.target.value)} required>
                <option value="">Select</option>
                {(categories.data?.data ?? []).map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.code} — {c.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Name</Label>
              <Input value={name} onChange={(e) => setName(e.target.value)} required />
            </div>
            <div>
              <Label>Cost</Label>
              <Input value={cost} onChange={(e) => setCost(e.target.value)} required />
            </div>
            <div>
              <Label>Credit account (Bank/AP)</Label>
              <Select value={creditAccountId} onChange={(e) => setCreditAccountId(e.target.value)} required>
                <option value="">Select</option>
                {(accounts.data?.data ?? []).map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.code} — {a.name}
                  </option>
                ))}
              </Select>
            </div>
            <Button disabled={acquire.isPending}>Acquire & post</Button>
          </form>
        </Card>

        <Card>
          <SectionTitle>Depreciation run</SectionTitle>
          <div className="space-y-3">
            <div>
              <Label>Fiscal period</Label>
              <Select value={periodId} onChange={(e) => setPeriodId(e.target.value)} required>
                <option value="">Select</option>
                {periods.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </Select>
            </div>
            <Button disabled={!periodId || depreciate.isPending} onClick={() => depreciate.mutate()}>
              Run depreciation
            </Button>
          </div>
        </Card>
      </div>

      <div className="mt-6">
        {assets.data?.data?.length ? (
          <Table headers={['Code', 'Name', 'Cost', 'Accum Dep', 'NBV', 'Status']}>
            {assets.data.data.map((a: any) => (
              <tr key={a.id} className="border-t border-[var(--line)]">
                <td className="px-4 py-3 font-normal">{a.asset_code}</td>
                <td className="px-4 py-3">{a.name}</td>
                <td className="px-4 py-3">{a.acquisition_cost}</td>
                <td className="px-4 py-3">{a.accum_depreciation}</td>
                <td className="px-4 py-3">{a.net_book_value}</td>
                <td className="px-4 py-3 capitalize">{a.status}</td>
              </tr>
            ))}
          </Table>
        ) : (
          <EmptyState title="No assets" body="Acquire an asset in the Furniture & Equipment category." />
        )}
      </div>
    </div>
  )
}
