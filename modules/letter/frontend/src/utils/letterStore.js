/** Multi-letter localStorage store (per company + user). */

export function readLetterCfg() {
  if (typeof window !== 'undefined' && window.__LETTER_CFG__ && typeof window.__LETTER_CFG__ === 'object') {
    return window.__LETTER_CFG__;
  }
  return {};
}

export function lettersStorageKey(cfg = readLetterCfg()) {
  const slug = String(cfg.companySlug || 'company').trim() || 'company';
  const uid = Number(cfg.user?.id || 0) || 0;
  return `letter-library:v1:${slug}:${uid}`;
}

function legacyDraftKey(cfg = readLetterCfg()) {
  const slug = String(cfg.companySlug || 'company').trim() || 'company';
  const uid = Number(cfg.user?.id || 0) || 0;
  return `letter-draft:v1:${slug}:${uid}`;
}

export function createLetterId() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  return `ltr-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

export function letterDisplayTitle(form = {}) {
  const subject = String(form.subject || '').trim();
  if (subject) {
    return subject.toUpperCase().startsWith('REF:') ? subject : `REF: ${subject}`;
  }
  const recipient = String(form.recipientName || form.recipientCompany || '').trim();
  if (recipient) return recipient;
  return 'Untitled letter';
}

function readLibrary(key) {
  if (typeof window === 'undefined') return [];
  try {
    const raw = window.localStorage.getItem(key);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed.filter((row) => row && typeof row === 'object' && row.id);
  } catch {
    return [];
  }
}

function writeLibrary(key, letters) {
  if (typeof window === 'undefined') return false;
  try {
    window.localStorage.setItem(key, JSON.stringify(letters));
    return true;
  } catch {
    return false;
  }
}

function migrateLegacyDraft(cfg, key) {
  const existing = readLibrary(key);
  if (existing.length) return existing;
  try {
    const raw = window.localStorage.getItem(legacyDraftKey(cfg));
    if (!raw) return existing;
    const form = JSON.parse(raw);
    if (!form || typeof form !== 'object' || Array.isArray(form)) return existing;
    const now = new Date().toISOString();
    const migrated = [{
      id: createLetterId(),
      title: letterDisplayTitle(form),
      form,
      createdAt: now,
      updatedAt: now,
    }];
    writeLibrary(key, migrated);
    return migrated;
  } catch {
    return existing;
  }
}

export function listLetters(cfg = readLetterCfg()) {
  const key = lettersStorageKey(cfg);
  const letters = migrateLegacyDraft(cfg, key);
  return [...letters].sort((a, b) => String(b.updatedAt || '').localeCompare(String(a.updatedAt || '')));
}

export function getLetter(id, cfg = readLetterCfg()) {
  if (!id) return null;
  return listLetters(cfg).find((row) => String(row.id) === String(id)) || null;
}

export function upsertLetter(letter, cfg = readLetterCfg()) {
  if (!letter || !letter.id) return false;
  const key = lettersStorageKey(cfg);
  const letters = listLetters(cfg);
  const now = new Date().toISOString();
  const title = letter.title || letterDisplayTitle(letter.form || {});
  const next = {
    id: String(letter.id),
    title,
    form: letter.form || {},
    createdAt: letter.createdAt || now,
    updatedAt: now,
  };
  const idx = letters.findIndex((row) => String(row.id) === next.id);
  if (idx >= 0) {
    next.createdAt = letters[idx].createdAt || next.createdAt;
    letters[idx] = next;
  } else {
    letters.unshift(next);
  }
  return writeLibrary(key, letters);
}

export function deleteLetter(id, cfg = readLetterCfg()) {
  if (!id) return false;
  const key = lettersStorageKey(cfg);
  const letters = listLetters(cfg).filter((row) => String(row.id) !== String(id));
  return writeLibrary(key, letters);
}

export function formatListDate(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return String(iso);
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const yyyy = d.getFullYear();
  const hh = String(d.getHours()).padStart(2, '0');
  const mi = String(d.getMinutes()).padStart(2, '0');
  return `${dd}-${mm}-${yyyy} ${hh}:${mi}`;
}

export function composeHref(cfg = readLetterCfg(), letterId = '') {
  const base = String(cfg.composeUrl || '').trim();
  if (!base) {
    const url = new URL(window.location.href);
    url.searchParams.set('desk', 'compose');
    if (letterId) url.searchParams.set('id', letterId);
    else url.searchParams.delete('id');
    return url.pathname + '?' + url.searchParams.toString();
  }
  try {
    const url = new URL(base, window.location.origin);
    if (letterId) url.searchParams.set('id', letterId);
    else url.searchParams.delete('id');
    return url.pathname + url.search + url.hash;
  } catch {
    const join = base.includes('?') ? '&' : '?';
    return letterId ? `${base}${join}id=${encodeURIComponent(letterId)}` : base;
  }
}

export function listHref(cfg = readLetterCfg()) {
  const base = String(cfg.listUrl || '').trim();
  if (base) return base;
  try {
    const url = new URL(window.location.href);
    url.searchParams.delete('desk');
    url.searchParams.delete('id');
    return url.pathname + (url.search ? url.search : '');
  } catch {
    return 'index.php?module=letter';
  }
}
