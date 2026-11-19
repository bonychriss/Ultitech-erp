import {useMemo, useState, type FormEvent } from 'react'
import {useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {apiRequest } from '../lib/api'
import {useAuth } from '../lib/auth'
import { Button, Card, EmptyState, ErrorBanner, Input, Label, PageHeader, Select, Table  , SectionTitle } from '../components/ui'

type Supplier = {id: number; code: string; name: string }
type Account = {id: number; code: string; name: string }
type Bill = {
  id: number
  bill_number: string
  bill_date: string
  total: string
  balance_due: string
  status: string
  supplier?: Supplier
}
type BankAccount = {id: number; code: string; name: string }

export function PurchasesPage() {
  const {token, companyId } = useAuth()
  const client = useQueryClient()
  const [error, setError] = useState('')
  const [supplierId, setSupplierId] = useState('')
  const [amount, setAmount] = useState('150000')
  const [description, setDescription] = useState('Office supplies')
  const [expenseAccountId, setExpenseAccountId] = useState('')
  const [paymentBillId, setPaymentBillId] = useState('')
  const [paymentAmount, setPaymentAmount] = useState('')
  const [bankAccountId, setBankAccountId] = useState('')

  const suppliers = useQuery({
    queryKey: ['suppliers', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: Supplier[] }>('/suppliers', {token, companyId }),
  })
  const accounts = useQuery({
    queryKey: ['accounts', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: Account[] }>('/accounts', {token, companyId }),
  })
  const bills = useQuery({
    queryKey: ['supplier-bills', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: Bill[] }>('/supplier-bills', {token, companyId }),
  })
  const banks = useQuery({
    queryKey: ['bank-accounts', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: BankAccount[] }>('/bank-accounts', {token, companyId }),
  })

  const expenseAccounts = useMemo(
    () => (accounts.data?.data ?? []).filter((a) => a.code.startsWith('5')),
    [accounts.data],
  )
  const openBills = useMemo(
    () => (bills.data?.data ?? []).filter((b) => ['posted', 'partial'].includes(b.status)),
    [bills.data],
  )

  const createBill = useMutation({
    mutationFn: () =>
      apiRequest('/supplier-bills', {
        method: 'POST',
        token,
        companyId,
        body: {
          supplier_id: Number(supplierId),
          bill_date: new Date().toISOString().slice(0, 10),
          currency_code: 'TZS',
          description,
          lines: [
            {
              description,
              quantity: 1,
              unit_price: amount,
              expense_account_id: Number(expenseAccountId),
            },
          ],
        },
      }),
    onSuccess: async () => {
      setError('')
      await Promise.all([
        client.invalidateQueries({queryKey: ['supplier-bills', companyId] }),
        client.invalidateQueries({queryKey: ['trial-balance', companyId] }),
      ])
    },
    onError: (err) => setError((err as Error).message),
  })

  const createPayment = useMutation({
    mutationFn: () => {
      const bill = openBills.find((b) => String(b.id) === paymentBillId)
      if (!bill) throw new Error('Select a bill')
      return apiRequest('/supplier-payments', {
        method: 'POST',
        token,
        companyId,
        body: {
          supplier_id: bill.supplier?.id,
          payment_date: new Date().toISOString().slice(0, 10),
          currency_code: 'TZS',
          amount: paymentAmount || bill.balance_due,
          bank_account_id: Number(bankAccountId),
          allocations: [
            {
              bill_id: bill.id,
              amount: paymentAmount || bill.balance_due,
            },
          ],
        },
      })
    },
    onSuccess: async () => {
      setError('')
      await Promise.all([
        client.invalidateQueries({queryKey: ['supplier-bills', companyId] }),
        client.invalidateQueries({queryKey: ['trial-balance', companyId] }),
        client.invalidateQueries({queryKey: ['bank-transactions', companyId] }),
      ])
    },
    onError: (err) => setError((err as Error).message),
  })

  return (
    <div>
      <PageHeader title="Purchases" subtitle="Suppliers, bills and payments" />
      {error ? <ErrorBanner message={error} /> : null}

      <div className="grid gap-6 xl:grid-cols-2">
        <Card>
          <SectionTitle>Post supplier bill</SectionTitle>
          <form
            className="space-y-3"
            onSubmit={(e: FormEvent) => {
              e.preventDefault()
              createBill.mutate()
            }}
          >
            <div>
              <Label>Supplier</Label>
              <Select value={supplierId} onChange={(e) => setSupplierId(e.target.value)} required>
                <option value="">Select supplier</option>
                {(suppliers.data?.data ?? []).map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.code} — {s.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Expense account</Label>
              <Select value={expenseAccountId} onChange={(e) => setExpenseAccountId(e.target.value)} required>
                <option value="">Select account</option>
                {expenseAccounts.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.code} — {a.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Description</Label>
              <Input value={description} onChange={(e) => setDescription(e.target.value)} required />
            </div>
            <div>
              <Label>Amount (TZS)</Label>
              <Input value={amount} onChange={(e) => setAmount(e.target.value)} required />
            </div>
            <Button disabled={createBill.isPending}>{createBill.isPending ? 'Posting…' : 'Create & post bill'}</Button>
          </form>
        </Card>

        <Card>
          <SectionTitle>Pay supplier</SectionTitle>
          <form
            className="space-y-3"
            onSubmit={(e: FormEvent) => {
              e.preventDefault()
              createPayment.mutate()
            }}
          >
            <div>
              <Label>Open bill</Label>
              <Select value={paymentBillId} onChange={(e) => setPaymentBillId(e.target.value)} required>
                <option value="">Select bill</option>
                {openBills.map((bill) => (
                  <option key={bill.id} value={bill.id}>
                    {bill.bill_number} — {bill.supplier?.name} — due {bill.balance_due}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Bank account</Label>
              <Select value={bankAccountId} onChange={(e) => setBankAccountId(e.target.value)} required>
                <option value="">Select bank</option>
                {(banks.data?.data ?? []).map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.code} — {b.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Amount (optional = full balance)</Label>
              <Input value={paymentAmount} onChange={(e) => setPaymentAmount(e.target.value)} />
            </div>
            <Button disabled={createPayment.isPending}>{createPayment.isPending ? 'Posting…' : 'Create & post payment'}</Button>
          </form>
        </Card>
      </div>

      <div className="mt-6">
        {bills.isLoading ? <Card>Loading bills…</Card> : null}
        {bills.data?.data?.length ? (
          <Table headers={['Number', 'Supplier', 'Date', 'Total', 'Balance', 'Status']}>
            {bills.data.data.map((bill) => (
              <tr key={bill.id} className="border-t border-[var(--line)]">
                <td className="px-4 py-3 font-normal">{bill.bill_number}</td>
                <td className="px-4 py-3">{bill.supplier?.name}</td>
                <td className="px-4 py-3">{String(bill.bill_date).slice(0, 10)}</td>
                <td className="px-4 py-3">{bill.total}</td>
                <td className="px-4 py-3">{bill.balance_due}</td>
                <td className="px-4 py-3 capitalize">{bill.status}</td>
              </tr>
            ))}
          </Table>
        ) : (
          !bills.isLoading && <EmptyState title="No bills yet" body="Post a supplier bill to start payables." />
        )}
      </div>
    </div>
  )
}
