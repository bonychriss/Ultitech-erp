import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiRequest, ApiError } from '../lib/api'
import { useAuth } from '../lib/auth'
import { readBoot } from '../lib/boot'
import {
  Button,
  Card,
  EmptyState,
  ErrorBanner,
  Input,
  Label,
  PageHeader,
  Table,
  SectionTitle,
  Select,
} from '../components/ui'

type Account = {
  id: number
  code: string
  name: string
  normal_balance: string
  is_postable: boolean
  is_system?: boolean
  status: string
  account_type_id: number
}

const emptyForm = {
  code: '',
  name: '',
  account_type_id: '5',
  normal_balance: 'debit',
}

/** Next free numeric code in the type band: 1xxx assets, 2xxx liabilities, … */
function nextCoaCode(accountTypeId: string, existing: Array<{ code: string }>): string {
  const series = ({ '1': '1', '2': '2', '3': '3', '4': '4', '5': '5' } as Record<string, string>)[
    accountTypeId
  ] ?? '9'
  let max = Number(`${series}000`)
  for (const row of existing) {
    const code = String(row.code || '')
    if (/^\d+$/.test(code) && code.startsWith(series)) {
      max = Math.max(max, Number(code))
    }
  }
  return String(max + 1)
}

function PlusIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path d="M12 5v14M5 12h14" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
    </svg>
  )
}

function LockIcon() {
  return (
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <rect x="5" y="11" width="14" height="10" rx="2" stroke="currentColor" strokeWidth="2" />
      <path
        d="M8 11V8a4 4 0 0 1 8 0v3"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
      />
    </svg>
  )
}

export function AccountsPage() {
  const { token, companyId, bookId } = useAuth()
  const client = useQueryClient()
  const boot = readBoot()
  const ready = Boolean(token && companyId)
  const prefetched = Array.isArray(boot.prefetch?.accounts) ? boot.prefetch!.accounts! : []

  const [showForm, setShowForm] = useState(false)
  const [error, setError] = useState('')
  const [form, setForm] = useState(emptyForm)

  const accounts = useQuery({
    queryKey: ['accounts', companyId, bookId],
    enabled: ready,
    initialData: prefetched.length ? { data: prefetched } : undefined,
    queryFn: () =>
      apiRequest<{ data: Account[] }>('/accounts?scope=working', { token, companyId, bookId }),
  })

  const rows = (accounts.data?.data?.length ? accounts.data.data : prefetched) ?? []

  const resetForm = () => {
    setForm(emptyForm)
    setError('')
  }

  const closeForm = () => {
    resetForm()
    setShowForm(false)
  }

  const create = useMutation({
    mutationFn: () =>
      apiRequest('/accounts', {
        method: 'POST',
        token,
        companyId,
        bookId,
        body: {
          ...form,
          account_type_id: Number(form.account_type_id),
          is_postable: true,
          allow_manual_entry: true,
        },
      }),
    onSuccess: async () => {
      closeForm()
      await client.invalidateQueries({ queryKey: ['accounts', companyId, bookId] })
    },
    onError: (err) => setError((err as Error).message),
  })

  const onSubmit = (event: FormEvent) => {
    event.preventDefault()
    create.mutate()
  }

  const codesKey = rows.map((row) => row.code).join('|')

  useEffect(() => {
    if (!showForm) return
    setForm((prev) => {
      const nextCode = nextCoaCode(prev.account_type_id, rows)
      return prev.code === nextCode ? prev : { ...prev, code: nextCode }
    })
  }, [showForm, form.account_type_id, codesKey])

  useEffect(() => {
    if (!showForm) return
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') closeForm()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [showForm])

  const apiFailed = accounts.isError && rows.length === 0
  const apiErr = apiFailed
    ? (accounts.error as ApiError | Error | null)?.message || 'Unable to load chart of accounts.'
    : null

  return (
    <div className="ua-page-wrap max-w-full min-w-0 overflow-x-hidden">
      <PageHeader
        title="Chart of Accounts"
        subtitle="System accounts used by posting are locked. You can still add custom accounts."
        actions={
          <Button
            type="button"
            pill
            disabled={!ready}
            onClick={() => {
              setError('')
              setShowForm(true)
            }}
            className="gap-1.5"
          >
            <PlusIcon />
            Create account
          </Button>
        }
      />

      {!ready && rows.length === 0 ? (
        <ErrorBanner message="Company session not ready. Re-open Accounting from ERP." />
      ) : null}
      {apiErr ? <ErrorBanner message={apiErr} /> : null}

      {showForm ? (
        <div
          className="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto bg-slate-900/45 p-4 sm:items-center"
          role="presentation"
          onClick={closeForm}
        >
          <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="ua-new-account-title"
            className="w-full max-w-md rounded-lg border border-[var(--line)] bg-white p-4 shadow-xl"
            onClick={(event) => event.stopPropagation()}
          >
            <div id="ua-new-account-title" className="mb-3 text-base font-normal text-[var(--ink)]">
              New account
            </div>

            {error ? <ErrorBanner message={error} /> : null}

            <form className="grid gap-3" onSubmit={onSubmit}>
              <input type="hidden" name="code" value={form.code} readOnly />
              <div>
                <Label>Name</Label>
                <Input
                  value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                  required
                  autoFocus
                />
              </div>
              <div>
                <Label>Account type</Label>
                <Select
                  value={form.account_type_id}
                  onChange={(e) => {
                    const account_type_id = e.target.value
                    const normal_balance =
                      account_type_id === '1' || account_type_id === '5' ? 'debit' : 'credit'
                    setForm({ ...form, account_type_id, normal_balance })
                  }}
                  required
                >
                  <option value="1">Asset</option>
                  <option value="2">Liability</option>
                  <option value="3">Equity</option>
                  <option value="4">Revenue</option>
                  <option value="5">Expense</option>
                </Select>
              </div>
              <div>
                <Label>Normal balance</Label>
                <Select
                  value={form.normal_balance}
                  onChange={(e) => setForm({ ...form, normal_balance: e.target.value })}
                  required
                >
                  <option value="debit">Debit</option>
                  <option value="credit">Credit</option>
                </Select>
              </div>
              <div className="mt-1 flex flex-wrap gap-2">
                <Button type="submit" disabled={!ready || create.isPending} className="gap-1.5">
                  <PlusIcon />
                  {create.isPending ? 'Saving…' : 'Create account'}
                </Button>
                <Button type="button" variant="secondary" onClick={closeForm}>
                  Cancel
                </Button>
              </div>
            </form>
          </div>
        </div>
      ) : null}

      <Card className="min-w-0 max-w-full">
        <SectionTitle>Accounts ({rows.length})</SectionTitle>
        {accounts.isLoading && rows.length === 0 ? (
          <div className="text-xs text-[var(--muted)]">Loading accounts…</div>
        ) : rows.length ? (
          <Table headers={['Code', 'Name', 'Balance', 'Postable', 'Status']}>
            {rows.map((account) => {
              const locked = Boolean(account.is_system)
              return (
                <tr key={account.id} className="border-t border-[var(--line)]">
                  <td className="truncate px-2 py-2 font-normal">{account.code}</td>
                  <td className="px-2 py-2">
                    <div className="flex min-w-0 items-center gap-2">
                      <span className="truncate">{account.name}</span>
                      {locked ? (
                        <span
                          className="inline-flex shrink-0 text-[var(--muted)]"
                          title="System account — locked"
                          aria-label="System account — locked"
                        >
                          <LockIcon />
                        </span>
                      ) : null}
                    </div>
                  </td>
                  <td className="px-2 py-2 capitalize">{account.normal_balance}</td>
                  <td
                    className={`px-2 py-2 ${
                      account.is_postable ? 'text-[var(--ok)]' : 'text-[var(--danger)]'
                    }`}
                  >
                    {account.is_postable ? 'Yes' : 'No'}
                  </td>
                  <td
                    className={`px-2 py-2 capitalize ${
                      account.status === 'active'
                        ? 'text-[var(--ok)]'
                        : 'text-[var(--muted)]'
                    }`}
                  >
                    {account.status}
                  </td>
                </tr>
              )
            })}
          </Table>
        ) : (
          <EmptyState title="No accounts" body="Re-open Accounting from ERP so the chart of accounts can load." />
        )}
      </Card>
    </div>
  )
}
