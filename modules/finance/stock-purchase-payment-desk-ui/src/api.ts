import type { DeskFilters, DeskInit, PurchaseOrderDetails, PurchaseOrderRow } from './types';

function getApiBase(): string {
  if (typeof window !== 'undefined' && window.__SPPD_API_BASE__) {
    return window.__SPPD_API_BASE__;
  }
  return import.meta.env.DEV ? '/api/index.php' : './stock-purchase-payment-desk-ui/api/index.php';
}

function readPageParams(): URLSearchParams {
  return new URLSearchParams(window.location.search);
}

function withPageParams(url: string): string {
  const params = readPageParams();
  const tab = params.get('tab');
  const module = params.get('module');
  const companySlug = params.get('company_slug');
  const extra: string[] = [];
  if (tab) extra.push(`tab=${encodeURIComponent(tab)}`);
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
        ? `API returned HTML instead of JSON${hint}. Check that the payment desk API URL is correct and you are still logged in.`
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

export async function fetchInit(): Promise<DeskInit> {
  const response = await fetch(apiUrl('init'), {
    credentials: 'same-origin',
  });
  return parseJson<DeskInit>(response);
}

export async function fetchOrders(filters: DeskFilters): Promise<{ orders: PurchaseOrderRow[]; count: number }> {
  const params = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== '') params.set(key, value);
  });

  const response = await fetch(apiUrl('list', params), {
    credentials: 'same-origin',
  });
  const data = await parseJson<{ orders?: PurchaseOrderRow[]; count?: number }>(response);
  return {
    orders: Array.isArray(data.orders) ? data.orders : [],
    count: typeof data.count === 'number' ? data.count : 0,
  };
}

export async function fetchPurchaseOrderDetails(poId: number): Promise<PurchaseOrderDetails> {
  const params = new URLSearchParams();
  params.set('po_id', String(poId));

  const response = await fetch(apiUrl('details', params), {
    credentials: 'same-origin',
  });
  const data = await parseJson<{ details?: PurchaseOrderDetails }>(response);
  if (!data.details?.order) {
    throw new Error('Purchase order details were not returned.');
  }
  return {
    ...data.details,
    attachments: Array.isArray(data.details.attachments) ? data.details.attachments : [],
  };
}

export async function payPurchaseOrder(formData: FormData): Promise<{ message: string; paymentNumber: string }> {
  formData.set('action', 'pay');
  const pageParams = readPageParams();
  if (pageParams.get('tab')) formData.set('tab', pageParams.get('tab')!);
  if (pageParams.get('module')) formData.set('module', pageParams.get('module')!);

  const response = await fetch(apiUrl('pay'), {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
  });
  const data = await parseJson<{ message: string; paymentNumber: string }>(response);
  return { message: data.message, paymentNumber: data.paymentNumber };
}
