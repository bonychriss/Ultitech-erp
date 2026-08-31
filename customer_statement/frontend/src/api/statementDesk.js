function getApiBase() {
  if (typeof window !== 'undefined' && window.__STATEMENT_API_BASE__) {
    return String(window.__STATEMENT_API_BASE__).replace(/\/$/, '');
  }
  return './api';
}

async function parseJson(response) {
  const text = await response.text();
  try {
    return JSON.parse(text);
  } catch {
    const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 160);
    throw new Error(
      snippet.startsWith('<!')
        ? 'API returned HTML instead of JSON. Check that you are still logged in.'
        : snippet === ''
          ? 'API returned an empty response.'
          : `Invalid API response: ${snippet}`,
    );
  }
}

export async function fetchStatementInit(params = {}) {
  const qs = params instanceof URLSearchParams
    ? params.toString()
    : new URLSearchParams(params).toString();
  const res = await fetch(`${getApiBase()}/init.php${qs ? `?${qs}` : ''}`, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export function buildStatementQuery(filters, module) {
  const params = new URLSearchParams();
  if (module) params.set('module', module);
  if (filters.date_from) params.set('date_from', filters.date_from);
  if (filters.date_to) params.set('date_to', filters.date_to);
  (filters.customer_ids || []).forEach((id) => {
    params.append('customer_ids[]', String(id));
  });
  return params;
}

export async function downloadStatementExcel({ companyName, filters, module }) {
  if (!filters?.customer_ids?.length) {
    throw new Error('Select at least one customer before downloading Excel.');
  }

  const params = buildStatementQuery(filters, module);
  const payload = await fetchStatementInit(params);

  if (!payload.statement?.selected_customers?.length) {
    throw new Error('No statement data found for the selected filters.');
  }

  const { exportStatementExcel } = await import('./statementExcelExport');
  return exportStatementExcel({
    companyName: payload.company_name || companyName || 'Company',
    statement: payload.statement,
  });
}
