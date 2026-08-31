function getApiBase() {
  if (typeof window !== 'undefined' && window.__PAYROLL_API_BASE__) {
    return String(window.__PAYROLL_API_BASE__).replace(/\/$/, '');
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

export async function fetchDeskInit() {
  const res = await fetch(`${getApiBase()}/desk-init.php`, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function approveRun(id) {
  const res = await fetch(`${getApiBase()}/approve-run.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ id }),
  });
  const data = await parseJson(res);
  if (!res.ok || data.error || data.success === false) {
    throw new Error(data.error || data.message || `Request failed (${res.status})`);
  }
  return data;
}

export async function deleteRun(id) {
  const res = await fetch(`${getApiBase()}/delete-run.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ id }),
  });
  const data = await parseJson(res);
  if (!res.ok || data.error || data.success === false) {
    throw new Error(data.error || data.message || `Request failed (${res.status})`);
  }
  return data;
}

export function deskPageUrl(file, extraParams = {}) {
  const params = new URLSearchParams({ module: 'payroll', ...extraParams });
  return `./${file}?${params.toString()}`;
}

export function buildViewRunUrl(runId, links = {}) {
  const base = links.viewRunBase || deskPageUrl('view_run.php');
  const sep = base.includes('?') ? '&' : '?';
  return `${base}${sep}id=${encodeURIComponent(String(runId))}`;
}

export function resolveRunId() {
  if (typeof window !== 'undefined' && window.__PAYROLL_RUN_ID__) {
    return Number(window.__PAYROLL_RUN_ID__) || 0;
  }
  if (typeof window !== 'undefined') {
    const fromQuery = new URLSearchParams(window.location.search).get('id');
    return fromQuery ? Number(fromQuery) || 0 : 0;
  }
  return 0;
}

export async function fetchRun(id) {
  const res = await fetch(`${getApiBase()}/run-get.php?id=${encodeURIComponent(String(id))}`, {
    credentials: 'same-origin',
  });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function runAction(payload) {
  const res = await fetch(`${getApiBase()}/run-action.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(payload),
  });
  const data = await parseJson(res);
  if (!res.ok || data.error || data.success === false) {
    throw new Error(data.error || data.message || `Request failed (${res.status})`);
  }
  return data;
}

export function buildPayslipUrl(payslipId, links = {}, extra = {}) {
  const base = links.payslipBase || deskPageUrl('payslip.php');
  try {
    const url = new URL(base, typeof window !== 'undefined' ? window.location.origin : 'http://localhost');
    url.searchParams.set('module', 'payroll');
    url.searchParams.set('id', String(payslipId));
    Object.entries(extra).forEach(([key, value]) => {
      url.searchParams.set(key, String(value));
    });
    return `${url.pathname}?${url.searchParams.toString()}`;
  } catch {
    const params = new URLSearchParams({ module: 'payroll', id: String(payslipId), ...extra });
    return `./payslip.php?${params.toString()}`;
  }
}

export function buildEditPayslipUrl(payslipId, links = {}) {
  const base = links.editPayslipBase || deskPageUrl('edit_payslip.php');
  try {
    const url = new URL(base, typeof window !== 'undefined' ? window.location.origin : 'http://localhost');
    url.searchParams.set('module', 'payroll');
    url.searchParams.set('id', String(payslipId));
    return `${url.pathname}?${url.searchParams.toString()}`;
  } catch {
    return `./edit_payslip.php?module=payroll&id=${encodeURIComponent(String(payslipId))}`;
  }
}

export function formatMoney(value, currencyCode = 'TZS') {
  const amount = Number(value) || 0;
  return `${currencyCode} ${amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function formatAmount(value) {
  const amount = Number(value) || 0;
  return amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export async function fetchSalariesInit() {
  const res = await fetch(`${getApiBase()}/salaries-init.php`, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function fetchSalaryEmployee(id) {
  const res = await fetch(`${getApiBase()}/salary-get.php?id=${encodeURIComponent(String(id))}`, {
    credentials: 'same-origin',
  });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function saveSalary(payload) {
  const res = await fetch(`${getApiBase()}/salary-save.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(payload),
  });
  const data = await parseJson(res);
  if (!res.ok || data.error || data.success === false) {
    throw new Error(data.error || data.message || `Request failed (${res.status})`);
  }
  return data;
}

export function buildEditSalaryUrl(employeeId, links = {}) {
  // Always use module page URL; ignore API-relative editSalaryBase from desk-init.
  const base = deskPageUrl('edit_salary.php');
  const sep = base.includes('?') ? '&' : '?';
  return `${base}${sep}id=${encodeURIComponent(String(employeeId))}`;
}

export function resolveEmployeeId() {
  if (typeof window !== 'undefined' && window.__PAYROLL_EMPLOYEE_ID__) {
    return Number(window.__PAYROLL_EMPLOYEE_ID__) || 0;
  }
  if (typeof window !== 'undefined') {
    const fromQuery = new URLSearchParams(window.location.search).get('id');
    return fromQuery ? Number(fromQuery) || 0 : 0;
  }
  return 0;
}
