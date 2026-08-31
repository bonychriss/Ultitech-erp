function apiBase() {
  if (typeof window !== 'undefined' && window.__BACKUP_API_BASE__) {
    return String(window.__BACKUP_API_BASE__).replace(/\/$/, '');
  }
  return '/modules/backup/api/index.php';
}

function withModule(url) {
  const module = new URLSearchParams(window.location.search).get('module');
  if (!module) return url;
  const sep = url.includes('?') ? '&' : '?';
  return `${url}${sep}module=${encodeURIComponent(module)}`;
}

export function getBootData() {
  return (typeof window !== 'undefined' && window.__BACKUP_BOOT__) || {};
}

export async function fetchBackupList() {
  const res = await fetch(withModule(`${apiBase()}?action=list`), {
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
  });
  const data = await res.json().catch(() => null);
  if (!res.ok || !data?.success) {
    throw new Error(data?.message || `Request failed (${res.status})`);
  }
  return data.data;
}

/**
 * Create backup with live NDJSON progress events.
 * @param {(info: {percent:number, label:string}) => void} onProgress
 */
export async function createBackup(onProgress) {
  const res = await fetch(withModule(`${apiBase()}?action=create`), {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/x-ndjson, application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: JSON.stringify({ action: 'create' }),
  });

  if (!res.ok) {
    let message = `Backup failed (${res.status})`;
    try {
      const errData = await res.json();
      if (errData?.message) message = errData.message;
    } catch {
      // ignore
    }
    throw new Error(message);
  }

  const contentType = String(res.headers.get('content-type') || '');
  // Fallback for non-streaming JSON responses.
  if (contentType.includes('application/json') && !contentType.includes('ndjson')) {
    const data = await res.json();
    if (!data?.success) {
      throw new Error(data?.message || 'Backup failed.');
    }
    if (typeof onProgress === 'function') {
      onProgress({ percent: 100, label: 'Backup ready' });
    }
    return data;
  }

  if (!res.body || typeof res.body.getReader !== 'function') {
    const text = await res.text();
    return parseNdjsonResult(text, onProgress);
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  let finalPayload = null;

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    const lines = buffer.split('\n');
    buffer = lines.pop() || '';
    for (const line of lines) {
      const parsed = consumeProgressLine(line, onProgress);
      if (parsed?.type === 'done' || parsed?.type === 'error') {
        finalPayload = parsed;
      }
    }
  }

  if (buffer.trim()) {
    const parsed = consumeProgressLine(buffer, onProgress);
    if (parsed?.type === 'done' || parsed?.type === 'error') {
      finalPayload = parsed;
    }
  }

  if (!finalPayload) {
    throw new Error('Backup ended without a final result.');
  }
  if (finalPayload.type === 'error' || finalPayload.success === false) {
    throw new Error(finalPayload.message || 'Backup failed.');
  }
  return finalPayload;
}

function consumeProgressLine(line, onProgress) {
  const trimmed = String(line || '').trim();
  if (!trimmed) return null;
  let event = null;
  try {
    event = JSON.parse(trimmed);
  } catch {
    return null;
  }
  if (!event || typeof event !== 'object') return null;

  if (event.type === 'progress' && typeof onProgress === 'function') {
    onProgress({
      percent: Number(event.percent) || 0,
      label: String(event.label || 'Working...'),
    });
  }
  return event;
}

function parseNdjsonResult(text, onProgress) {
  let finalPayload = null;
  for (const line of String(text || '').split('\n')) {
    const parsed = consumeProgressLine(line, onProgress);
    if (parsed?.type === 'done' || parsed?.type === 'error') {
      finalPayload = parsed;
    }
  }
  if (!finalPayload) {
    throw new Error('Backup ended without a final result.');
  }
  if (finalPayload.type === 'error' || finalPayload.success === false) {
    throw new Error(finalPayload.message || 'Backup failed.');
  }
  return finalPayload;
}

export async function deleteBackup(id) {
  const res = await fetch(withModule(`${apiBase()}?action=delete`), {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: JSON.stringify({ action: 'delete', id }),
  });
  const data = await res.json().catch(() => null);
  if (!res.ok || !data?.success) {
    throw new Error(data?.message || `Delete failed (${res.status})`);
  }
  return data;
}

export function downloadBackupUrl(id) {
  return withModule(`${apiBase()}?action=download&id=${encodeURIComponent(id)}`);
}

/** Open a reliable full-file download (avoids empty files from the HTML download attribute). */
export function triggerBackupDownload(id) {
  const url = downloadBackupUrl(id);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.rel = 'noopener';
  // Do not set download= for large same-origin binary responses; let Content-Disposition name the file.
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
}
