import type { DashboardInit } from './types';

function getApiBase(): string {
  if (typeof window !== 'undefined' && window.__LD_API_BASE__) {
    return window.__LD_API_BASE__;
  }
  return import.meta.env.DEV ? '/api/index.php' : './liquidity-dashboard-ui/api/index.php';
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
        ? `API returned HTML instead of JSON${hint}. Check that the liquidity dashboard API URL is correct and you are still logged in.`
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

function apiUrl(action: string): string {
  const base = getApiBase();
  const joiner = base.includes('?') ? '&' : '?';
  return withPageParams(`${base}${joiner}action=${encodeURIComponent(action)}`);
}

export async function fetchInit(): Promise<DashboardInit> {
  const response = await fetch(apiUrl('init'), { credentials: 'same-origin' });
  return parseJson<DashboardInit>(response);
}
