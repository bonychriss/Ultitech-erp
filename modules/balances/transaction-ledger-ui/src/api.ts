import type { TxAiSearchResponse, TxFilters, TxInit, TxListResponse } from './types';

function getApiBase(): string {
  if (typeof window !== 'undefined' && window.__TL_API_BASE__) {
    return window.__TL_API_BASE__;
  }
  return import.meta.env.DEV ? '/api/index.php' : './transaction-ledger-ui/api/index.php';
}

function readPageParams(): URLSearchParams {
  return new URLSearchParams(window.location.search);
}

function withPageParams(url: string): string {
  const params = readPageParams();
  const module = params.get('module');
  const companySlug = params.get('company_slug');
  const extra: string[] = [];
  if (module) extra.push(`module=${encodeURIComponent(module)}`);
  if (companySlug) extra.push(`company_slug=${encodeURIComponent(companySlug)}`);
  if (extra.length === 0) return url;
  const joiner = url.includes('?') ? '&' : '?';
  return `${url}${joiner}${extra.join('&')}`;
}

async function parseJson<T>(response: Response): Promise<T> {
  const text = await response.text();
  let data: { success?: boolean; error?: string };
  try {
    data = JSON.parse(text) as { success?: boolean; error?: string };
  } catch {
    const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 160);
    const hint = response.url ? ` (${response.url})` : '';
    throw new Error(
      snippet.startsWith('<!')
        ? `API returned HTML instead of JSON${hint}. Check that the transaction ledger API URL is correct and you are still logged in.`
        : snippet === ''
          ? `API returned an empty response${hint}.`
          : `Invalid API response${hint}: ${snippet}`,
    );
  }
  if (!response.ok || data.success === false) {
    throw new Error(data.error || `Request failed (${response.status})`);
  }
  return data as T;
}

function apiUrl(action: string, queryParams?: URLSearchParams): string {
  const base = getApiBase();
  const joiner = base.includes('?') ? '&' : '?';
  let url = `${base}${joiner}action=${encodeURIComponent(action)}`;
  if (queryParams) {
    queryParams.delete('action');
    const extra = queryParams.toString();
    if (extra) url += `&${extra}`;
  }
  return withPageParams(url);
}

function filtersToParams(filters: TxFilters): URLSearchParams {
  const params = new URLSearchParams();
  (Object.entries(filters) as Array<[keyof TxFilters, string | number]>).forEach(([key, value]) => {
    if (value === '' || value === 0) return;
    params.set(key, String(value));
  });
  return params;
}

export async function fetchInit(filters: TxFilters): Promise<TxInit> {
  const params = filtersToParams({ ...filters, page: 1 });
  params.delete('page');
  params.delete('per_page');
  const response = await fetch(apiUrl('init', params), { credentials: 'same-origin' });
  return parseJson<TxInit>(response);
}

export async function fetchTransactions(filters: TxFilters): Promise<TxListResponse> {
  const response = await fetch(apiUrl('list', filtersToParams(filters)), { credentials: 'same-origin' });
  return parseJson<TxListResponse>(response);
}

export async function fetchAiSearch(query: string, perPage = 'all'): Promise<TxAiSearchResponse> {
  const response = await fetch(apiUrl('ai_search'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ q: query, per_page: perPage }),
  });
  return parseJson<TxAiSearchResponse>(response);
}
