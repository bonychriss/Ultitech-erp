import {useState, type FormEvent } from 'react'
import {useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {apiRequest } from '../lib/api'
import {useAuth } from '../lib/auth'
import { Button, Card, EmptyState, ErrorBanner, Input, Label, PageHeader, Select, Table  , SectionTitle } from '../components/ui'

export function TaxControlPage() {
  const {token, companyId } = useAuth()
  const client = useQueryClient()
  const [error, setError] = useState('')
  const [taxTypeId, setTaxTypeId] = useState('')
  const [periodStart, setPeriodStart] = useState('2026-01-01')
  const [periodEnd, setPeriodEnd] = useState('2026-03-31')

  const types = useQuery({
    queryKey: ['tax-types', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: Array<{id: number; code: string; name: string }> }>('/tax-types', {token, companyId }),
  })
  const rates = useQuery({
    queryKey: ['tax-rates', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/tax-rates', {token, companyId }),
  })
  const codes = useQuery({
    queryKey: ['tax-codes', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/tax-codes', {token, companyId }),
  })
  const txns = useQuery({
    queryKey: ['tax-transactions', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/tax-transactions', {token, companyId }),
  })
  const returns = useQuery({
    queryKey: ['tax-returns', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/tax-returns', {token, companyId }),
  })

  const prepare = useMutation({
    mutationFn: () =>
      apiRequest('/tax-returns', {
        method: 'POST',
        token,
        companyId,
        body: {
          tax_type_id: Number(taxTypeId),
          period_start: periodStart,
          period_end: periodEnd,
        },
      }),
    onSuccess: async () => {
      setError('')
      await client.invalidateQueries({queryKey: ['tax-returns', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  const reconcile = useMutation({
    mutationFn: (id: number) =>
      apiRequest(`/tax-returns/${id}/reconcile`, {method: 'POST', token, companyId, body: {} }),
    onSuccess: async () => {
      setError('')
      await client.invalidateQueries({queryKey: ['tax-returns', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  return (
    <div>
      <PageHeader title="Tax Control" subtitle="Effective-dated VAT/WHT rates, tax transactions, and return reconciliation" />
      {error ? <ErrorBanner message={error} /> : null}

      <div className="grid gap-6 xl:grid-cols-2">
        <Card>
          <SectionTitle>Jurisdiction rates</SectionTitle>
          {(rates.data?.data?.length ?? 0) === 0 ? (
            <EmptyState title="No rates" body="No tax rates for this book jurisdiction." />
          ) : (
            <Table headers={['Code', 'Name', 'Rate %', 'From', 'To']}>
              {(rates.data?.data ?? []).map((r: any) => (
                <tr key={r.id} className="border-t border-[var(--line)]">
                  <td className="px-4 py-3 font-normal">{r.code}</td>
                  <td className="px-4 py-3">{r.name}</td>
                  <td className="px-4 py-3">{r.rate_percent}</td>
                  <td className="px-4 py-3">{r.effective_from}</td>
                  <td className="px-4 py-3">{r.effective_to ?? '—'}</td>
                </tr>
              ))}
            </Table>
          )}
        </Card>

        <Card>
          <SectionTitle>Company tax codes</SectionTitle>
          {(codes.data?.data?.length ?? 0) === 0 ? (
            <EmptyState title="No codes" body="No tax codes seeded for this company." />
          ) : (
            <Table headers={['Code', 'Name', 'Rate', 'Applies']}>
              {(codes.data?.data ?? []).map((c: any) => (
                <tr key={c.id} className="border-t border-[var(--line)]">
                  <td className="px-4 py-3 font-normal">{c.code}</td>
                  <td className="px-4 py-3">{c.name}</td>
                  <td className="px-4 py-3">{c.tax_rate?.rate_percent ?? '—'}</td>
                  <td className="px-4 py-3">{c.applies_to}</td>
                </tr>
              ))}
            </Table>
          )}
        </Card>
      </div>

      <div className="mt-6">
        <Card>
          <SectionTitle>Prepare VAT return</SectionTitle>
          <form
            className="grid gap-3 md:grid-cols-4"
            onSubmit={(e: FormEvent) => {
              e.preventDefault()
              prepare.mutate()
            }}
          >
            <div>
              <Label>Tax type</Label>
              <Select value={taxTypeId} onChange={(e) => setTaxTypeId(e.target.value)} required>
                <option value="">Select</option>
                {(types.data?.data ?? []).map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.code} — {t.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Period start</Label>
              <Input type="date" value={periodStart} onChange={(e) => setPeriodStart(e.target.value)} required />
            </div>
            <div>
              <Label>Period end</Label>
              <Input type="date" value={periodEnd} onChange={(e) => setPeriodEnd(e.target.value)} required />
            </div>
            <div className="flex items-end">
              <Button type="submit" disabled={prepare.isPending}>
                Prepare return
              </Button>
            </div>
          </form>
        </Card>
      </div>

      <div className="mt-6">
        <SectionTitle>Tax returns</SectionTitle>
        {(returns.data?.data?.length ?? 0) === 0 ? (
          <EmptyState title="No returns" body="Prepare a VAT return for a period with tax transactions." />
        ) : (
          <Table headers={['Number', 'Period', 'Output', 'Input', 'Net', 'Status', '']}>
            {(returns.data?.data ?? []).map((r: any) => (
              <tr key={r.id} className="border-t border-[var(--line)]">
                <td className="px-4 py-3 font-normal">{r.return_number}</td>
                <td className="px-4 py-3">
                  {r.period_start} → {r.period_end}
                </td>
                <td className="px-4 py-3">{r.output_tax}</td>
                <td className="px-4 py-3">{r.input_tax}</td>
                <td className="px-4 py-3">{r.net_tax}</td>
                <td className="px-4 py-3 capitalize">{r.status}</td>
                <td className="px-4 py-3">
                  {r.status === 'draft' ? (
                    <Button variant="secondary" onClick={() => reconcile.mutate(r.id)}>
                      Reconcile
                    </Button>
                  ) : (
                    '—'
                  )}
                </td>
              </tr>
            ))}
          </Table>
        )}
      </div>

      <div className="mt-6">
        <SectionTitle>Tax transactions</SectionTitle>
        {(txns.data?.data?.length ?? 0) === 0 ? (
          <EmptyState title="No transactions" body="Post invoices or bills with a tax code to record VAT." />
        ) : (
          <Table headers={['Date', 'Direction', 'Taxable', 'Tax', 'Rate %', 'Source']}>
            {(txns.data?.data ?? []).map((t: any) => (
              <tr key={t.id} className="border-t border-[var(--line)]">
                <td className="px-4 py-3">{t.tax_date}</td>
                <td className="px-4 py-3 capitalize">{t.direction}</td>
                <td className="px-4 py-3">{t.taxable_amount}</td>
                <td className="px-4 py-3">{t.tax_amount}</td>
                <td className="px-4 py-3">{t.rate_percent}</td>
                <td className="px-4 py-3">
                  {t.source_type}#{t.source_id}
                </td>
              </tr>
            ))}
          </Table>
        )}
      </div>
    </div>
  )
}
