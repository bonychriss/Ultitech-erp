import {useMemo, useState, type FormEvent } from 'react'
import {useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {apiRequest } from '../lib/api'
import {useAuth } from '../lib/auth'
import { Button, Card, EmptyState, ErrorBanner, Input, Label, PageHeader, Select, Table  , SectionTitle } from '../components/ui'

export function ReconciliationPage() {
  const {token, companyId } = useAuth()
  const client = useQueryClient()
  const [error, setError] = useState('')
  const [bankAccountId, setBankAccountId] = useState('')
  const [statementNumber, setStatementNumber] = useState('STMT-001')
  const [closingBalance, setClosingBalance] = useState('0')
  const [lineDebit, setLineDebit] = useState('100000')
  const [lineCredit, setLineCredit] = useState('0')
  const [reconId, setReconId] = useState<number | null>(null)
  const [statementLineId, setStatementLineId] = useState('')
  const [txnId, setTxnId] = useState('')

  const banks = useQuery({
    queryKey: ['bank-accounts', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: Array<{id: number; code: string; name: string }> }>('/bank-accounts', {token, companyId }),
  })

  const statements = useQuery({
    queryKey: ['bank-statements', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/bank-statements', {token, companyId }),
  })

  const unmatched = useQuery({
    queryKey: ['bank-unmatched', companyId, bankAccountId],
    enabled: Boolean(token && companyId && bankAccountId),
    queryFn: () => apiRequest<{data: any[] }>(`/bank-unmatched?bank_account_id=${bankAccountId}`, {token, companyId }),
  })

  const latestStatement = useMemo(() => statements.data?.data?.[0], [statements.data])

  const createStatement = useMutation({
    mutationFn: () =>
      apiRequest('/bank-statements', {
        method: 'POST',
        token,
        companyId,
        body: {
          bank_account_id: Number(bankAccountId),
          statement_number: statementNumber,
          statement_date: new Date().toISOString().slice(0, 10),
          period_start: '2026-01-01',
          period_end: new Date().toISOString().slice(0, 10),
          opening_balance: 0,
          closing_balance: closingBalance,
          lines: [
            {
              line_date: new Date().toISOString().slice(0, 10),
              description: 'Statement line',
              debit: lineDebit,
              credit: lineCredit,
            },
          ],
        },
      }),
    onSuccess: async () => {
      setError('')
      await client.invalidateQueries({queryKey: ['bank-statements', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  const startRecon = useMutation({
    mutationFn: () =>
      apiRequest<{data: {id: number } }>('/bank-reconciliations', {
        method: 'POST',
        token,
        companyId,
        body: {
          bank_account_id: Number(bankAccountId),
          statement_id: latestStatement.id,
        },
      }),
    onSuccess: (res) => {
      setReconId(res.data.id)
      setError('')
    },
    onError: (err) => setError((err as Error).message),
  })

  const match = useMutation({
    mutationFn: () =>
      apiRequest(`/bank-reconciliations/${reconId}/match`, {
        method: 'POST',
        token,
        companyId,
        body: {
          statement_line_id: Number(statementLineId),
          bank_transaction_id: Number(txnId),
        },
      }),
    onSuccess: async () => {
      setError('')
      await Promise.all([
        client.invalidateQueries({queryKey: ['bank-statements', companyId] }),
        client.invalidateQueries({queryKey: ['bank-unmatched', companyId, bankAccountId] }),
      ])
    },
    onError: (err) => setError((err as Error).message),
  })

  const complete = useMutation({
    mutationFn: () =>
      apiRequest(`/bank-reconciliations/${reconId}/complete`, {
        method: 'POST',
        token,
        companyId,
      }),
    onSuccess: async () => {
      setError('')
      await client.invalidateQueries({queryKey: ['bank-statements', companyId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  return (
    <div>
      <PageHeader title="Bank Reconciliation" subtitle="Import statement lines, match transactions, complete reconciliation" />
      {error ? <ErrorBanner message={error} /> : null}

      <div className="grid gap-6 xl:grid-cols-2">
        <Card>
          <SectionTitle>Create statement</SectionTitle>
          <form
            className="space-y-3"
            onSubmit={(e: FormEvent) => {
              e.preventDefault()
              createStatement.mutate()
            }}
          >
            <div>
              <Label>Bank account</Label>
              <Select value={bankAccountId} onChange={(e) => setBankAccountId(e.target.value)} required>
                <option value="">Select</option>
                {(banks.data?.data ?? []).map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.code} — {b.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Statement number</Label>
              <Input value={statementNumber} onChange={(e) => setStatementNumber(e.target.value)} required />
            </div>
            <div>
              <Label>Closing balance</Label>
              <Input value={closingBalance} onChange={(e) => setClosingBalance(e.target.value)} required />
            </div>
            <div className="grid grid-cols-2 gap-2">
              <div>
                <Label>Line debit</Label>
                <Input value={lineDebit} onChange={(e) => setLineDebit(e.target.value)} />
              </div>
              <div>
                <Label>Line credit</Label>
                <Input value={lineCredit} onChange={(e) => setLineCredit(e.target.value)} />
              </div>
            </div>
            <Button disabled={createStatement.isPending}>Save statement</Button>
          </form>
        </Card>

        <Card>
          <SectionTitle>Match & complete</SectionTitle>
          <div className="space-y-3">
            <Button variant="secondary" disabled={!latestStatement || !bankAccountId || startRecon.isPending} onClick={() => startRecon.mutate()}>
              Start reconciliation
            </Button>
            {reconId ? <p className="text-sm text-[var(--muted)]">Reconciliation #{reconId}</p> : null}
            <div>
              <Label>Statement line ID</Label>
              <Select value={statementLineId} onChange={(e) => setStatementLineId(e.target.value)}>
                <option value="">Select</option>
                {(latestStatement?.lines ?? []).map((line: any) => (
                  <option key={line.id} value={line.id}>
                    #{line.id} — {line.description} — D{line.debit}/C{line.credit} {line.is_matched ? '(matched)' : ''}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Bank transaction ID</Label>
              <Select value={txnId} onChange={(e) => setTxnId(e.target.value)}>
                <option value="">Select</option>
                {(unmatched.data?.data ?? []).map((txn: any) => (
                  <option key={txn.id} value={txn.id}>
                    #{txn.id} — {txn.description} — D{txn.debit}/C{txn.credit}
                  </option>
                ))}
              </Select>
            </div>
            <div className="flex gap-2">
              <Button disabled={!reconId || match.isPending} onClick={() => match.mutate()}>
                Match
              </Button>
              <Button variant="secondary" disabled={!reconId || complete.isPending} onClick={() => complete.mutate()}>
                Complete
              </Button>
            </div>
          </div>
        </Card>
      </div>

      <div className="mt-6">
        {statements.data?.data?.length ? (
          <Table headers={['Number', 'Date', 'Bank', 'Closing', 'Status', 'Lines']}>
            {statements.data.data.map((s: any) => (
              <tr key={s.id} className="border-t border-[var(--line)]">
                <td className="px-4 py-3 font-normal">{s.statement_number}</td>
                <td className="px-4 py-3">{String(s.statement_date).slice(0, 10)}</td>
                <td className="px-4 py-3">{s.bank_account_id}</td>
                <td className="px-4 py-3">{s.closing_balance}</td>
                <td className="px-4 py-3 capitalize">{s.status}</td>
                <td className="px-4 py-3">{s.lines?.length ?? 0}</td>
              </tr>
            ))}
          </Table>
        ) : (
          <EmptyState title="No statements" body="Create a bank statement to begin reconciliation." />
        )}
      </div>
    </div>
  )
}
