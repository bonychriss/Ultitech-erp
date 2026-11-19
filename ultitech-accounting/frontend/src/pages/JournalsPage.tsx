import {useMemo, useState, type FormEvent } from 'react'
import {useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {apiRequest } from '../lib/api'
import {useAuth } from '../lib/auth'
import { Button, Card, EmptyState, ErrorBanner, Input, Label, PageHeader, Select, Table  , SectionTitle } from '../components/ui'

type Account = {id: number; code: string; name: string }
type Journal = {
  id: number
  journal_number: string
  journal_date: string
  description: string
  status: string
  total_debit: string
  total_credit: string
}

export function JournalsPage() {
  const {token, companyId } = useAuth()
  const client = useQueryClient()
  const [error, setError] = useState('')
  const [description, setDescription] = useState('Manual journal')
  const [debitAccount, setDebitAccount] = useState('')
  const [creditAccount, setCreditAccount] = useState('')
  const [amount, setAmount] = useState('100000')

  const accounts = useQuery({
    queryKey: ['accounts', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: Account[] }>('/accounts', {token, companyId }),
  })

  const journals = useQuery({
    queryKey: ['journals', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: Journal[] }>('/journals', {token, companyId }),
  })

  const accountOptions = useMemo(() => accounts.data?.data ?? [], [accounts.data])

  const createAndPost = useMutation({
    mutationFn: async () => {
      const draft = await apiRequest<{data: Journal }>('/journals', {
        method: 'POST',
        token,
        companyId,
        body: {
          journal_date: new Date().toISOString().slice(0, 10),
          description,
          currency_code: 'TZS',
          lines: [
            {account_id: Number(debitAccount), debit: amount, credit: '0' },
            {account_id: Number(creditAccount), debit: '0', credit: amount },
          ],
        },
      })

      return apiRequest<{data: Journal }>(`/journals/${draft.data.id}/post`, {
        method: 'POST',
        token,
        companyId,
        idempotencyKey: crypto.randomUUID(),
      })
    },
    onSuccess: async () => {
      setError('')
      await Promise.all([
        client.invalidateQueries({queryKey: ['journals', companyId] }),
        client.invalidateQueries({queryKey: ['trial-balance', companyId] }),
      ])
    },
    onError: (err) => setError((err as Error).message),
  })

  const reverse = useMutation({
    mutationFn: (id: number) =>
      apiRequest(`/journals/${id}/reverse`, {
        method: 'POST',
        token,
        companyId,
        body: {reason: 'UI reversal' },
      }),
    onSuccess: async () => {
      await Promise.all([
        client.invalidateQueries({queryKey: ['journals', companyId] }),
        client.invalidateQueries({queryKey: ['trial-balance', companyId] }),
      ])
    },
    onError: (err) => setError((err as Error).message),
  })

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    createAndPost.mutate()
  }

  return (
    <div>
      <PageHeader title="Journals" subtitle="Create, post and reverse general ledger entries" />
      {error ? <ErrorBanner message={error} /> : null}
      <div className="grid gap-6 xl:grid-cols-[360px_1fr]">
        <Card>
          <SectionTitle>Quick post</SectionTitle>
          <form className="space-y-3" onSubmit={onSubmit}>
            <div>
              <Label>Description</Label>
              <Input value={description} onChange={(e) => setDescription(e.target.value)} required />
            </div>
            <div>
              <Label>Debit account</Label>
              <Select value={debitAccount} onChange={(e) => setDebitAccount(e.target.value)} required>
                <option value="">Select account</option>
                {accountOptions.map((account) => (
                  <option key={account.id} value={account.id}>
                    {account.code} — {account.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Credit account</Label>
              <Select value={creditAccount} onChange={(e) => setCreditAccount(e.target.value)} required>
                <option value="">Select account</option>
                {accountOptions.map((account) => (
                  <option key={`c-${account.id}`} value={account.id}>
                    {account.code} — {account.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Amount (TZS)</Label>
              <Input value={amount} onChange={(e) => setAmount(e.target.value)} required />
            </div>
            <Button disabled={createAndPost.isPending}>{createAndPost.isPending ? 'Posting…' : 'Create & post'}</Button>
          </form>
        </Card>

        {journals.isLoading ? <Card>Loading journals…</Card> : null}
        {journals.data?.data?.length ? (
          <Table headers={['Number', 'Date', 'Description', 'Debit', 'Credit', 'Status', '']}>
            {journals.data.data.map((journal) => (
              <tr key={journal.id} className="border-t border-[var(--line)]">
                <td className="px-4 py-3 font-normal">{journal.journal_number}</td>
                <td className="px-4 py-3">{String(journal.journal_date).slice(0, 10)}</td>
                <td className="px-4 py-3">{journal.description}</td>
                <td className="px-4 py-3">{journal.total_debit}</td>
                <td className="px-4 py-3">{journal.total_credit}</td>
                <td className="px-4 py-3 capitalize">{journal.status}</td>
                <td className="px-4 py-3">
                  {journal.status === 'posted' ? (
                    <Button variant="secondary" onClick={() => reverse.mutate(journal.id)}>
                      Reverse
                    </Button>
                  ) : null}
                </td>
              </tr>
            ))}
          </Table>
        ) : (
          !journals.isLoading && <EmptyState title="No journals" body="Post the first balanced journal entry." />
        )}
      </div>
    </div>
  )
}
