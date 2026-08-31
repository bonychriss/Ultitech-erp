import type { TransferFormState, TransferInit } from './types';

function getApiBase(): string {
  if (typeof window !== 'undefined' && window.__TF_API_BASE__) {
    return window.__TF_API_BASE__;
  }
  return import.meta.env.DEV ? '/api/index.php' : './transfer-ui/api/index.php';
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
  let data: { success?: boolean; error?: string; message?: string };
  try {
    data = JSON.parse(text) as { success?: boolean; error?: string; message?: string };
  } catch {
    const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 160);
    const hint = response.url ? ` (${response.url})` : '';
    throw new Error(
      snippet.startsWith('<!')
        ? `API returned HTML instead of JSON${hint}. Check the transfer API URL and login session.`
        : snippet === ''
          ? `API returned an empty response${hint}.`
          : `Invalid API response${hint}: ${snippet}`,
    );
  }
  if (!response.ok || data.success === false) {
    throw new Error(data.error || data.message || `Request failed (${response.status})`);
  }
  return data as T;
}

function apiUrl(action: string): string {
  const base = getApiBase();
  const joiner = base.includes('?') ? '&' : '?';
  return withPageParams(`${base}${joiner}action=${encodeURIComponent(action)}`);
}

export async function fetchInit(): Promise<TransferInit> {
  const response = await fetch(apiUrl('init'), { credentials: 'same-origin' });
  return parseJson<TransferInit>(response);
}

export async function createTransfer(
  form: TransferFormState,
): Promise<{ message: string; historyUrl: string; transferUrl: string }> {
  const response = await fetch(apiUrl('create'), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      transfer_date: form.transferDate,
      reference_no: form.referenceNo,
      description: form.description,
      from_account: form.fromAccount,
      to_account: form.toAccount,
      currency: form.currency,
      amount: form.amount,
      exchange_rate: form.exchangeRate,
    }),
  });
  const data = await parseJson<{ message?: string; historyUrl?: string; transferUrl?: string }>(response);
  return {
    message: String(data.message ?? 'Transfer created successfully.'),
    historyUrl: String(data.historyUrl ?? 'transactions.php'),
    transferUrl: String(data.transferUrl ?? 'transfer.php'),
  };
}
