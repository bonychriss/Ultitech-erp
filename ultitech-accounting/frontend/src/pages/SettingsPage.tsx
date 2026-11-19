import {useQuery } from '@tanstack/react-query'
import {apiRequest } from '../lib/api'
import {useAuth } from '../lib/auth'
import { Card, ErrorBanner, PageHeader, Table  , SectionTitle } from '../components/ui'

export function SettingsPage() {
  const {token, companyId } = useAuth()

  const books = useQuery({
    queryKey: ['books', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () => apiRequest<{data: Array<{id: number; code: string; name: string; is_primary: boolean; functional_currency_code: string }> }>('/books', {token, companyId }),
  })

  const years = useQuery({
    queryKey: ['fiscal-years', companyId],
    enabled: Boolean(token && companyId),
    queryFn: () =>
      apiRequest<{data: Array<{id: number; name: string; start_date: string; end_date: string; status: string; periods: unknown[] }> }>(
        '/fiscal-years',
        {token, companyId },
      ),
  })

  return (
    <div>
      <PageHeader title="Settings" subtitle="Books, fiscal calendar and foundation configuration" />
      {books.isError || years.isError ? <ErrorBanner message="Unable to load settings." /> : null}

      <div className="grid gap-6">
        <Card>
          <SectionTitle>Accounting books</SectionTitle>
          {books.data ? (
            <Table headers={['Code', 'Name', 'Currency', 'Primary']}>
              {books.data.data.map((book) => (
                <tr key={book.id} className="border-t border-[var(--line)]">
                  <td className="px-4 py-3 font-normal">{book.code}</td>
                  <td className="px-4 py-3">{book.name}</td>
                  <td className="px-4 py-3">{book.functional_currency_code}</td>
                  <td className="px-4 py-3">{book.is_primary ? 'Yes' : 'No'}</td>
                </tr>
              ))}
            </Table>
          ) : (
            <div>Loading books…</div>
          )}
        </Card>

        <Card>
          <SectionTitle>Fiscal years</SectionTitle>
          {years.data ? (
            <Table headers={['Name', 'Start', 'End', 'Periods', 'Status']}>
              {years.data.data.map((year) => (
                <tr key={year.id} className="border-t border-[var(--line)]">
                  <td className="px-4 py-3 font-normal">{year.name}</td>
                  <td className="px-4 py-3">{String(year.start_date).slice(0, 10)}</td>
                  <td className="px-4 py-3">{String(year.end_date).slice(0, 10)}</td>
                  <td className="px-4 py-3">{year.periods?.length ?? 0}</td>
                  <td className="px-4 py-3 capitalize">{year.status}</td>
                </tr>
              ))}
            </Table>
          ) : (
            <div>Loading fiscal years…</div>
          )}
        </Card>
      </div>
    </div>
  )
}
