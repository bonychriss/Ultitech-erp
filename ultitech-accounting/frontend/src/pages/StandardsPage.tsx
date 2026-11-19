import {useState, type FormEvent } from 'react'
import {useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {apiRequest } from '../lib/api'
import {useAuth } from '../lib/auth'
import { Button, Card, EmptyState, ErrorBanner, Input, Label, PageHeader, Table  , SectionTitle } from '../components/ui'

export function StandardsPage() {
  const {token, companyId } = useAuth()
  const client = useQueryClient()
  const [error, setError] = useState('')
  const [code, setCode] = useState('INVENTORY')
  const [name, setName] = useState('Inventory measurement')
  const [effectiveFrom, setEffectiveFrom] = useState('2026-01-01')
  const [summary, setSummary] = useState('Inventories measured at the lower of cost and NRV (IAS 2 / SME 13).')

  const frameworks = useQuery({
    queryKey: ['standards-frameworks', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/standards/frameworks', {token, companyId }),
  })
  const policies = useQuery({
    queryKey: ['standards-policies', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/standards/policies', {token, companyId }),
  })
  const disclosures = useQuery({
    queryKey: ['standards-disclosures', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any[] }>('/standards/disclosures', {token, companyId }),
  })
  const checklist = useQuery({
    queryKey: ['standards-checklist', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: any }>('/standards/checklist', {token, companyId }),
  })

  const createPolicy = useMutation({
    mutationFn: () =>
      apiRequest('/standards/policies', {
        method: 'POST',
        token,
        companyId,
        body: {
          code,
          name,
          effective_from: effectiveFrom,
          policy_json: {summary },
          approve: true,
        },
      }),
    onSuccess: async () => {
      setError('')
      await Promise.all([
        client.invalidateQueries({queryKey: ['standards-policies', companyId] }),
        client.invalidateQueries({queryKey: ['standards-checklist', companyId] }),
      ])
    },
    onError: (err) => setError((err as Error).message),
  })

  return (
    <div>
      <PageHeader title="Standards & Policies" subtitle="IFRS / IFRS for SMEs metadata, versioned accounting policies, and disclosure templates" />
      {error ? <ErrorBanner message={error} /> : null}

      <Card>
        <SectionTitle>Framework compliance snapshot</SectionTitle>
        {checklist.data?.data ? (
          <div className="space-y-2 text-sm">
            <div>
              Book framework:{' '}
              <strong>
                {checklist.data.data.framework?.code} — {checklist.data.data.framework?.name}
              </strong>
            </div>
            <div>Standards linked: {checklist.data.data.standards_count}</div>
            <p className="text-[var(--muted)]">{checklist.data.data.note}</p>
          </div>
        ) : (
          <EmptyState title="Loading" body="Loading compliance checklist…" />
        )}
      </Card>

      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <Card>
          <SectionTitle>Frameworks & standards</SectionTitle>
          {(frameworks.data?.data?.length ?? 0) === 0 ? (
            <EmptyState title="No frameworks" body="Catalog standards have not been seeded." />
          ) : (
            <div className="space-y-4">
              {(frameworks.data?.data ?? []).map((fw: any) => (
                <div key={fw.id}>
                  <div className="font-normal">
                    {fw.code} — {fw.name}
                  </div>
                  <ul className="mt-1 list-disc pl-5 text-sm text-[var(--muted)]">
                    {(fw.standards ?? []).slice(0, 8).map((s: any) => (
                      <li key={s.id}>
                        {s.code}: {s.title}
                      </li>
                    ))}
                    {(fw.standards ?? []).length > 8 ? <li>…and more</li> : null}
                  </ul>
                </div>
              ))}
            </div>
          )}
        </Card>

        <Card>
          <SectionTitle>Disclosure templates</SectionTitle>
          {(disclosures.data?.data?.length ?? 0) === 0 ? (
            <EmptyState title="No templates" body="No disclosure templates for this book framework." />
          ) : (
            <Table headers={['Code', 'Title']}>
              {(disclosures.data?.data ?? []).map((d: any) => (
                <tr key={d.id} className="border-t border-[var(--line)]">
                  <td className="px-4 py-3 font-normal">{d.code}</td>
                  <td className="px-4 py-3">{d.template_json?.title ?? '—'}</td>
                </tr>
              ))}
            </Table>
          )}
        </Card>
      </div>

      <div className="mt-6">
        <Card>
          <SectionTitle>Add accounting policy</SectionTitle>
          <form
            className="grid gap-3 md:grid-cols-2"
            onSubmit={(e: FormEvent) => {
              e.preventDefault()
              createPolicy.mutate()
            }}
          >
            <div>
              <Label>Code</Label>
              <Input value={code} onChange={(e) => setCode(e.target.value)} required />
            </div>
            <div>
              <Label>Name</Label>
              <Input value={name} onChange={(e) => setName(e.target.value)} required />
            </div>
            <div>
              <Label>Effective from</Label>
              <Input type="date" value={effectiveFrom} onChange={(e) => setEffectiveFrom(e.target.value)} required />
            </div>
            <div>
              <Label>Policy summary</Label>
              <Input value={summary} onChange={(e) => setSummary(e.target.value)} required />
            </div>
            <div className="md:col-span-2">
              <Button type="submit" disabled={createPolicy.isPending}>
                Save & approve policy version
              </Button>
            </div>
          </form>
        </Card>
      </div>

      <div className="mt-6">
        <SectionTitle>Book policies</SectionTitle>
        {(policies.data?.data?.length ?? 0) === 0 ? (
          <EmptyState title="No policies" body="Create an accounting policy for this book." />
        ) : (
          <Table headers={['Code', 'Name', 'Latest version', 'Status', 'Effective']}>
            {(policies.data?.data ?? []).map((p: any) => {
              const latest = p.versions?.[0]
              return (
                <tr key={p.id} className="border-t border-[var(--line)]">
                  <td className="px-4 py-3 font-normal">{p.code}</td>
                  <td className="px-4 py-3">{p.name}</td>
                  <td className="px-4 py-3">{latest?.version_no ?? '—'}</td>
                  <td className="px-4 py-3 capitalize">{latest?.status ?? '—'}</td>
                  <td className="px-4 py-3">{latest?.effective_from ?? '—'}</td>
                </tr>
              )
            })}
          </Table>
        )}
      </div>
    </div>
  )
}
