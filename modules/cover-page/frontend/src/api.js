function apiBase() {
  if (typeof window !== 'undefined' && window.__COVER_PAGE_API__) {
    return String(window.__COVER_PAGE_API__);
  }
  return '/modules/cover-page/api/index.php';
}

export function getBootData() {
  return (typeof window !== 'undefined' && window.__COVER_PAGE_BOOT__) || {};
}

async function post(action, body) {
  const res = await fetch(`${apiBase()}?action=${encodeURIComponent(action)}`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: JSON.stringify({ ...body, action }),
  });
  const data = await res.json().catch(() => null);
  if (!res.ok || !data?.success) {
    const err = new Error(data?.message || `Request failed (${res.status})`);
    err.fields = data?.errors || {};
    throw err;
  }
  return data;
}

export function saveCover(values) {
  return post('save', values);
}

export function deleteCover(id) {
  return post('delete', { id });
}
