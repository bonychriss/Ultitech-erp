function getApiBase() {
  if (typeof window !== 'undefined' && window.__PAYROLL_API_BASE__) {
    return String(window.__PAYROLL_API_BASE__).replace(/\/$/, '');
  }
  return './api';
}

function getPageBase() {
  if (typeof window !== 'undefined' && window.__PAYROLL_PAGE_BASE__) {
    return String(window.__PAYROLL_PAGE_BASE__).replace(/\/$/, '');
  }
  const api = getApiBase();
  if (api.endsWith('/api')) {
    return api.slice(0, -4);
  }
  return '.';
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
  const res = await fetch(`${getApiBase()}/desk-init.php`, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
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
  const base = getPageBase();
  const path = `${base}/${String(file || '').replace(/^\.\//, '').replace(/^\//, '')}`;
  return `${path}?${params.toString()}`;
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

export async function fetchEmailJobStatus(jobId) {
  const res = await fetch(
    `${getApiBase()}/email-job-status.php?id=${encodeURIComponent(String(jobId))}`,
    {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    },
  );
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

/**
 * Start background payslip email processing.
 * The endpoint acknowledges quickly (status=running); SMTP continues after that.
 */
export async function startEmailJob(jobId) {
  const res = await fetch(`${getApiBase()}/email-job-process.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ id: String(jobId) }),
  });
  const data = await parseJson(res).catch(() => ({}));
  if (!res.ok || data.error || data.success === false) {
    throw new Error(data.error || data.message || `Email worker failed (${res.status})`);
  }
  return data;
}

function sleep(ms) {
  return new Promise((resolve) => {
    window.setTimeout(resolve, ms);
  });
}

/**
 * Poll background payslip email job until done/failed (keeps UI busy for animation).
 */
export async function waitForEmailJob(jobId, { intervalMs = 700, timeoutMs = 10 * 60 * 1000, onProgress } = {}) {
  const started = Date.now();
  let last = null;
  let stalledQueuedMs = 0;
  while (Date.now() - started < timeoutMs) {
    last = await fetchEmailJobStatus(jobId);
    if (typeof onProgress === 'function') {
      onProgress(last);
    }
    const status = String(last.status || '');
    if (status === 'done' || status === 'failed') {
      return last;
    }
    if (status === 'queued') {
      stalledQueuedMs += intervalMs;
      if (stalledQueuedMs >= 45000) {
        throw new Error('Email worker did not start. Please try again.');
      }
    } else {
      stalledQueuedMs = 0;
    }
    await sleep(intervalMs);
  }
  throw new Error(last?.message || 'Email send is taking too long. Check your inbox and try again if needed.');
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

export async function fetchMyPayslipsInit() {
  const res = await fetch(`${getApiBase()}/my-payslips-init.php`, { credentials: 'same-origin' });
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

export async function fetchRunInit() {
  const res = await fetch(`${getApiBase()}/run-init.php`, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function generatePayrollRun(payload) {
  const res = await fetch(`${getApiBase()}/run-generate.php`, {
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

export async function fetchPayslipEdit(id) {
  const res = await fetch(`${getApiBase()}/payslip-get.php?id=${encodeURIComponent(String(id))}`, {
    credentials: 'same-origin',
  });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function fetchPayslipViewMeta(id) {
  const res = await fetch(`${getApiBase()}/payslip-view-init.php?id=${encodeURIComponent(String(id))}`, {
    credentials: 'same-origin',
  });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function savePayslipEdit(payload) {
  const res = await fetch(`${getApiBase()}/payslip-save.php`, {
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

export function resolvePayslipId() {
  if (typeof window !== 'undefined' && window.__PAYROLL_PAYSLIP_ID__) {
    return Number(window.__PAYROLL_PAYSLIP_ID__) || 0;
  }
  if (typeof window !== 'undefined') {
    const fromQuery = new URLSearchParams(window.location.search).get('id');
    return fromQuery ? Number(fromQuery) || 0 : 0;
  }
  return 0;
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

export async function fetchSettings() {
  const res = await fetch(`${getApiBase()}/settings-get.php`, { credentials: 'same-origin' });
  const data = await parseJson(res);
  if (!res.ok || data.error) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

export async function saveSettingsAction(payload) {
  const res = await fetch(`${getApiBase()}/settings-save.php`, {
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
