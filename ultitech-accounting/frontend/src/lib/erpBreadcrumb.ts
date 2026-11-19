export type ErpCrumb = {
  label: string
  href?: string | null
}

let baseCrumbs: ErpCrumb[] | null = null

function parseBaseCrumbs(nav: HTMLElement): ErpCrumb[] {
  const nodes = Array.from(nav.childNodes)
  const items: ErpCrumb[] = []
  for (const node of nodes) {
    if (!(node instanceof HTMLElement)) continue
    if (
      node.classList.contains('erp-auto-breadcrumb-sep') ||
      node.classList.contains('vv-breadcrumb-sep')
    ) {
      continue
    }
    const label = (node.textContent || '').trim()
    if (!label) continue
    if (node instanceof HTMLAnchorElement) {
      items.push({ label, href: node.getAttribute('href') })
    } else {
      items.push({ label, href: null })
    }
  }
  if (items.length >= 2) return items.slice(0, 2)
  if (items.length === 1) return items
  return [
    { label: 'Home', href: null },
    { label: 'Accounting', href: null },
  ]
}

function renderCrumbs(
  nav: HTMLElement,
  items: ErpCrumb[],
  variant: 'auto' | 'vv' = 'auto',
) {
  const sepClass = variant === 'vv' ? 'vv-breadcrumb-sep' : 'erp-auto-breadcrumb-sep'
  const linkClass =
    variant === 'vv'
      ? 'vv-breadcrumb-link erp-nav-back-ignore'
      : 'erp-auto-breadcrumb-link erp-nav-back-ignore'
  const currentClass = variant === 'vv' ? 'vv-breadcrumb-current' : 'erp-auto-breadcrumb-current'
  const textClass = variant === 'vv' ? 'vv-breadcrumb-current' : 'erp-auto-breadcrumb-text'

  nav.replaceChildren()
  items.forEach((item, idx) => {
    if (idx > 0) {
      const sep = document.createElement('span')
      sep.className = sepClass
      sep.setAttribute('aria-hidden', 'true')
      sep.textContent = '/'
      nav.appendChild(sep)
    }
    const isLast = idx === items.length - 1
    if (!isLast && item.href) {
      const a = document.createElement('a')
      a.className = linkClass
      a.href = item.href
      a.textContent = item.label
      nav.appendChild(a)
      return
    }
    const span = document.createElement('span')
    span.className = isLast ? currentClass : textClass
    if (isLast) span.setAttribute('aria-current', 'page')
    span.textContent = item.label
    nav.appendChild(span)
  })
}

/** Map SPA path → crumbs after Home / Accounting. */
export function crumbsForPath(pathname: string): ErpCrumb[] {
  const path = pathname.replace(/\/+$/, '') || '/'
  if (path === '/banking/movements') {
    return [
      { label: 'Balances', href: '#/banking' },
      { label: 'Money in and out' },
    ]
  }
  if (path === '/banking') {
    return [{ label: 'Balances' }]
  }
  if (path === '/revenue') return [{ label: 'Revenue' }]
  if (path === '/purchases') return [{ label: 'Purchases' }]
  if (path === '/journals') return [{ label: 'Journal Entries' }]
  if (path === '/tax') return [{ label: 'VAT Control' }]
  if (path === '/reconciliation') return [{ label: 'Reconciliation' }]
  if (path === '/assets') return [{ label: 'Fixed Assets' }]
  if (path === '/inventory') return [{ label: 'Inventory' }]
  if (path === '/accounts') return [{ label: 'General Ledger / CoA' }]
  if (path === '/trial-balance') return [{ label: 'Trial Balance' }]
  if (path === '/financial-statements') return [{ label: 'Statements' }]
  if (path === '/budgets') return [{ label: 'Budgets' }]
  if (path === '/erp') return [{ label: 'ERP Link' }]
  if (path === '/settings') return [{ label: 'Accounting Settings' }]
  if (path === '/standards') return [{ label: 'Standards' }]
  if (path === '/advanced') return [{ label: 'Advanced' }]
  if (path === '/governance') return [{ label: 'Governance' }]
  return []
}

export function titleForPath(pathname: string): string {
  const path = pathname.replace(/\/+$/, '') || '/'
  if (path === '/banking/movements') return 'Money in and out'
  if (path === '/banking') return 'Cash & Bank'
  if (path === '/revenue') return 'Revenue'
  if (path === '/purchases') return 'Purchases'
  if (path === '/journals') return 'Journal Entries'
  if (path === '/tax') return 'VAT Control'
  if (path === '/reconciliation') return 'Reconciliation'
  if (path === '/assets') return 'Fixed Assets'
  if (path === '/inventory') return 'Inventory'
  if (path === '/accounts') return 'General Ledger / CoA'
  if (path === '/trial-balance') return 'Trial Balance'
  if (path === '/financial-statements') return 'Financial Statements'
  if (path === '/budgets') return 'Budgets'
  if (path === '/erp') return 'ERP Link'
  if (path === '/settings') return 'Accounting Settings'
  if (path === '/standards') return 'Standards'
  if (path === '/advanced') return 'Advanced'
  if (path === '/governance') return 'Governance'
  return 'Accounting'
}

function ensureHeaderBreadcrumbNav(): HTMLElement | null {
  const heading = document.querySelector(
    '.employee-header.employee-header--accounting-desk .employee-header-page-heading',
  ) as HTMLElement | null
  if (!heading) return null

  let subtitle = heading.querySelector('.employee-header-page-subtitle') as HTMLElement | null
  if (!subtitle) {
    subtitle = document.createElement('p')
    subtitle.className = 'employee-header-page-subtitle mb-0'
    heading.appendChild(subtitle)
  }

  let nav = subtitle.querySelector('nav.vv-breadcrumb') as HTMLElement | null
  if (!nav) {
    nav = document.createElement('nav')
    nav.className = 'vv-breadcrumb exp-desk-breadcrumb'
    nav.setAttribute('aria-label', 'Breadcrumb')
    subtitle.replaceChildren(nav)
  }
  return nav
}

function syncHeaderTitle(pathname: string) {
  const title = document.querySelector(
    '.employee-header.employee-header--accounting-desk .employee-header-page-title',
  ) as HTMLElement | null
  if (title) title.textContent = titleForPath(pathname)
}

export function syncErpHostBreadcrumb(pathname: string) {
  if (typeof document === 'undefined') return

  const extra = crumbsForPath(pathname)
  const autoNav = document.getElementById('erp-auto-breadcrumb')
  if (autoNav) {
    if (!baseCrumbs) baseCrumbs = parseBaseCrumbs(autoNav)
    renderCrumbs(autoNav, [...baseCrumbs, ...extra], 'auto')
  } else if (!baseCrumbs) {
    baseCrumbs = [
      { label: 'Home', href: null },
      { label: 'Accounting', href: null },
    ]
  }

  syncHeaderTitle(pathname)
  const headerNav = ensureHeaderBreadcrumbNav()
  if (headerNav && baseCrumbs) {
    renderCrumbs(headerNav, [...baseCrumbs, ...extra], 'vv')
  }
}
