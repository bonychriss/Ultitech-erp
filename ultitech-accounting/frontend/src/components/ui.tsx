import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode } from 'react'

export function PageHeader({
  title,
  subtitle,
  actions,
  breadcrumb,
  crumbExtra,
}: {
  title: string
  subtitle?: string
  actions?: ReactNode
  breadcrumb?: ReactNode
  /** Renders on the same row as the breadcrumb (e.g. search). */
  crumbExtra?: ReactNode
}) {
  return (
    <div className="ua-page-header mb-3">
      {breadcrumb || crumbExtra ? (
        <div className="ua-page-header__crumb-row">
          {breadcrumb ? <div className="ua-breadcrumb">{breadcrumb}</div> : <div />}
          {crumbExtra}
        </div>
      ) : null}
      <div className="ua-page-header__main flex flex-wrap items-end justify-between gap-2">
        <div>
          <div className="ua-page-title">{title}</div>
          {subtitle ? <div className="ua-page-sub">{subtitle}</div> : null}
        </div>
        {actions}
      </div>
    </div>
  )
}

export function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
  return (
    <div className={`rounded-lg border border-[var(--line)] bg-white p-3 shadow-[0_1px_2px_rgba(16,24,40,0.03)] ${className}`}>
      {children}
    </div>
  )
}

export function Button({
  className = '',
  variant = 'primary',
  pill = false,
  style,
  type = 'button',
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'secondary' | 'danger'
  pill?: boolean
}) {
  const styles =
    variant === 'primary'
      ? 'bg-[var(--brand)] text-white hover:bg-[var(--brand-dark)]'
      : variant === 'danger'
        ? 'bg-[var(--danger)] text-white hover:opacity-90'
        : 'bg-white text-[var(--ink)] border border-[var(--line)] hover:bg-[var(--surface)]'

  return (
    <button
      type={type}
      className={`ua-btn inline-flex items-center justify-center ${pill ? 'ua-btn-pill' : 'ua-btn-square'} py-2 text-sm font-normal transition disabled:cursor-not-allowed disabled:opacity-50 ${styles} ${className}`}
      style={pill ? { borderRadius: 9999, paddingLeft: 18, paddingRight: 18, ...style } : style}
      {...props}
    />
  )
}

export function Input(props: InputHTMLAttributes<HTMLInputElement>) {
  return (
    <input
      className="w-full rounded-md border border-[var(--line)] bg-white px-3 py-2 text-sm outline-none ring-[var(--brand)] focus:ring-2"
      {...props}
    />
  )
}

export function Select(props: React.SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <select
      className="w-full rounded-md border border-[var(--line)] bg-white px-3 py-2 text-sm outline-none ring-[var(--brand)] focus:ring-2"
      {...props}
    />
  )
}

export function Label({ children }: { children: ReactNode }) {
  return <label className="mb-1 block text-xs font-normal uppercase tracking-wide text-[var(--muted)]">{children}</label>
}

export function EmptyState({ title, body }: { title: string; body: string }) {
  return (
    <div className="rounded-lg border border-dashed border-[var(--line)] bg-[var(--surface)] px-3 py-5 text-center">
      <div className="ua-empty-title">{title}</div>
      <div className="ua-empty-body">{body}</div>
    </div>
  )
}

export function ErrorBanner({ message }: { message: string }) {
  return <div className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-[var(--danger)]">{message}</div>
}

export function SectionTitle({ children }: { children: ReactNode }) {
  return <div className="ua-section-title">{children}</div>
}

export function Table({
  headers,
  children,
  scrollable = true,
}: {
  headers: string[]
  children: ReactNode
  /** When false, avoids overflow clipping (needed for row action menus). */
  scrollable?: boolean
}) {
  return (
    <div
      className={`ua-table-scroll max-w-full rounded-lg border border-[var(--line)] bg-white${
        scrollable ? ' overflow-x-auto' : ' overflow-visible'
      }`}
    >
      <table className="w-full min-w-0 text-left text-sm">
        <thead className="bg-[var(--surface)] text-[var(--muted)]">
          <tr>
            {headers.map((header) => (
              <th key={header} className="px-2 py-2 text-sm font-normal whitespace-nowrap">
                {header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="text-sm">{children}</tbody>
      </table>
    </div>
  )
}
