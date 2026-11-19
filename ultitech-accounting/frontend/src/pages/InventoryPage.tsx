import {useState, type FormEvent } from 'react'
import {useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {apiRequest } from '../lib/api'
import {useAuth } from '../lib/auth'
import { Button, Card, EmptyState, ErrorBanner, Input, Label, PageHeader, Select, Table  , SectionTitle } from '../components/ui'

export function InventoryPage() {
  const {token, companyId } = useAuth()
  const client = useQueryClient()
  const [error, setError] = useState('')
  const [itemId, setItemId] = useState('')
  const [qty, setQty] = useState('10')
  const [unitCost, setUnitCost] = useState('5000')
  const [creditAccountId, setCreditAccountId] = useState('')
  const [issueQty, setIssueQty] = useState('2')

  const items = useQuery({
    queryKey: ['inventory-items', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/inventory-items', {token, companyId }),
  })
  const valuation = useQuery({
    queryKey: ['inventory-valuation', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<any>('/inventory-valuation', {token, companyId }),
  })
  const accounts = useQuery({
    queryKey: ['accounts', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: Array<{id: number; code: string; name: string }> }>('/accounts', {token, companyId }),
  })

  const receive = useMutation({
    mutationFn: () =>
      apiRequest(`/inventory-items/${itemId}/receive`, {
        method: 'POST',
        token,
        companyId,
        body: {
          movement_date: new Date().toISOString().slice(0, 10),
          quantity: qty,
          unit_cost: unitCost,
          credit_account_id: Number(creditAccountId),
          reference: 'RCV',
        },
      }),
    onSuccess: async () => {
      setError('')
      await Promise.all([
        client.invalidateQueries({queryKey: ['inventory-items', companyId] }),
        client.invalidateQueries({queryKey: ['inventory-valuation', companyId] }),
      ])
    },
    onError: (err) => setError((err as Error).message),
  })

  const issue = useMutation({
    mutationFn: () =>
      apiRequest(`/inventory-items/${itemId}/issue`, {
        method: 'POST',
        token,
        companyId,
        body: {
          movement_date: new Date().toISOString().slice(0, 10),
          quantity: issueQty,
          reference: 'ISS',
        },
      }),
    onSuccess: async () => {
      setError('')
      await Promise.all([
        client.invalidateQueries({queryKey: ['inventory-items', companyId] }),
        client.invalidateQueries({queryKey: ['inventory-valuation', companyId] }),
      ])
    },
    onError: (err) => setError((err as Error).message),
  })

  return (
    <div>
      <PageHeader title="Inventory Accounting" subtitle="Receipts, issues, FIFO / weighted average valuation" />
      {error ? <ErrorBanner message={error} /> : null}

      <div className="grid gap-6 xl:grid-cols-2">
        <Card>
          <SectionTitle>Receive stock</SectionTitle>
          <form
            className="space-y-3"
            onSubmit={(e: FormEvent) => {
              e.preventDefault()
              receive.mutate()
            }}
          >
            <div>
              <Label>Item</Label>
              <Select value={itemId} onChange={(e) => setItemId(e.target.value)} required>
                <option value="">Select</option>
                {(items.data?.data ?? []).map((i: any) => (
                  <option key={i.id} value={i.id}>
                    {i.sku} — {i.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Quantity</Label>
              <Input value={qty} onChange={(e) => setQty(e.target.value)} required />
            </div>
            <div>
              <Label>Unit cost</Label>
              <Input value={unitCost} onChange={(e) => setUnitCost(e.target.value)} required />
            </div>
            <div>
              <Label>Credit account</Label>
              <Select value={creditAccountId} onChange={(e) => setCreditAccountId(e.target.value)} required>
                <option value="">Select</option>
                {(accounts.data?.data ?? []).map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.code} — {a.name}
                  </option>
                ))}
              </Select>
            </div>
            <Button disabled={receive.isPending}>Receive & post</Button>
          </form>
        </Card>

        <Card>
          <SectionTitle>Issue stock (COGS)</SectionTitle>
          <form
            className="space-y-3"
            onSubmit={(e: FormEvent) => {
              e.preventDefault()
              issue.mutate()
            }}
          >
            <div>
              <Label>Item</Label>
              <Select value={itemId} onChange={(e) => setItemId(e.target.value)} required>
                <option value="">Select</option>
                {(items.data?.data ?? []).map((i: any) => (
                  <option key={`i-${i.id}`} value={i.id}>
                    {i.sku} — qty {i.quantity_on_hand}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Quantity</Label>
              <Input value={issueQty} onChange={(e) => setIssueQty(e.target.value)} required />
            </div>
            <Button disabled={issue.isPending}>Issue & post COGS</Button>
          </form>
        </Card>
      </div>

      <div className="mt-6">
        <Card className="mb-4">
          <div className="text-sm text-[var(--muted)]">Inventory valuation total</div>
          <div className="ua-stat font-normal">{valuation.data?.totals?.inventory_value ?? '0.0000'}</div>
        </Card>
        {valuation.data?.data?.length ? (
          <Table headers={['SKU', 'Name', 'Method', 'Qty', 'Unit cost', 'Value']}>
            {valuation.data.data.map((row: any) => (
              <tr key={row.id} className="border-t border-[var(--line)]">
                <td className="px-4 py-3 font-normal">{row.sku}</td>
                <td className="px-4 py-3">{row.name}</td>
                <td className="px-4 py-3">{row.valuation_method}</td>
                <td className="px-4 py-3">{row.quantity_on_hand}</td>
                <td className="px-4 py-3">{row.unit_cost}</td>
                <td className="px-4 py-3">{row.total_value}</td>
              </tr>
            ))}
          </Table>
        ) : (
          <EmptyState title="No inventory value" body="Receive stock to populate valuation." />
        )}
      </div>
    </div>
  )
}
