import type {
  CategoryYearsPayload,
  DashboardInit,
  MonthDetailPayload,
  TransactionsPayload,
  YearMonthsPayload,
} from './types';

function getApiBase(): string {
  if (typeof window !== 'undefined' && window.__VAT_API_BASE__) {
    return window.__VAT_API_BASE__;
  }
  return import.meta.env.DEV ? '/api/index.php' : './vat-control-ui/api/index.php';
}

function readPageParams(): URLSearchParams {
  return new URLSearchParams(window.location.search);
}

function withPageParams(url: string, extraParams: Record<string, string | number | undefined> = {}): string {
  const params = readPageParams();
  const module = params.get('module');
  let companySlug = params.get('company_slug');
  if (!companySlug && typeof window !== 'undefined' && window.__VAT_COMPANY_SLUG__) {
    companySlug = window.__VAT_COMPANY_SLUG__;
  }
  const extra: string[] = [];
  if (module) extra.push(`module=${encodeURIComponent(module)}`);
  if (companySlug) extra.push(`company_slug=${encodeURIComponent(companySlug)}`);
  for (const [key, value] of Object.entries(extraParams)) {
    if (value === undefined || value === '') continue;
    extra.push(`${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);
  }
  if (extra.length === 0) return url;
  const joiner = url.includes('?') ? '&' : '?';
  return `${url}${joiner}${extra.join('&')}`;
}

async function parseJson<T>(response: Response): Promise<T> {
  const text = await response.text();
  let data: { ok?: boolean; error?: string };
  try {
    data = JSON.parse(text) as { ok?: boolean; error?: string };
  } catch {
    const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 160);
    const hint = response.url ? ` (${response.url})` : '';
    throw new Error(
      snippet.startsWith('<!')
        ? `API returned HTML instead of JSON${hint}. Check that the VAT Control API URL is correct and you are still logged in.`
        : snippet === ''
          ? `API returned an empty response${hint}.`
          : `Invalid API response${hint}: ${snippet}`,
    );
  }
  if (!response.ok || data.ok === false) {
    throw new Error(data.error || `Request failed (${response.status})`);
  }
  return data as T;
}

function apiUrl(action: string, extra: Record<string, string | number | undefined> = {}): string {
  const base = getApiBase();
  const joiner = base.includes('?') ? '&' : '?';
  return withPageParams(`${base}${joiner}action=${encodeURIComponent(action)}`, extra);
}

export async function fetchInit(): Promise<DashboardInit> {
  const response = await fetch(apiUrl('init'), { credentials: 'same-origin' });
  return parseJson<DashboardInit>(response);
}

export async function fetchCategoryYears(category: string): Promise<CategoryYearsPayload> {
  const response = await fetch(apiUrl('category_years', { category }), { credentials: 'same-origin' });
  return parseJson<CategoryYearsPayload>(response);
}

export async function fetchYearMonths(year: number, category?: string): Promise<YearMonthsPayload> {
  const response = await fetch(
    apiUrl('year_months', { year, category: category || undefined }),
    { credentials: 'same-origin' },
  );
  return parseJson<YearMonthsPayload>(response);
}

export async function fetchMonthDetail(ym: string): Promise<MonthDetailPayload> {
  const response = await fetch(apiUrl('month_detail', { ym }), { credentials: 'same-origin' });
  return parseJson<MonthDetailPayload>(response);
}

export async function fetchTransactions(ym: string, source: string): Promise<TransactionsPayload> {
  const response = await fetch(apiUrl('transactions', { ym, source }), { credentials: 'same-origin' });
  return parseJson<TransactionsPayload>(response);
}

export async function postReconcilePeriod(ym: string): Promise<MonthDetailPayload> {
  const response = await fetch(apiUrl('reconcile_period'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ym }),
  });
  return parseJson<MonthDetailPayload>(response);
}

export async function postClosePeriod(ym: string): Promise<MonthDetailPayload> {
  const response = await fetch(apiUrl('close_period'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ym }),
  });
  return parseJson<MonthDetailPayload>(response);
}
