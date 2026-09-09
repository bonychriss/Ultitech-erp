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

export function inboxStorageKey(cfg = readLetterCfg()) {
  const slug = String(cfg.companySlug || 'company').trim() || 'company';
  return `letter-inbox:v1:${slug}`;
}

export function directInboxStorageKey(cfg = readLetterCfg()) {
  const slug = String(cfg.companySlug || 'company').trim() || 'company';
  return `letter-direct:v1:${slug}`;
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
      visibility: 'private',
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
  return listLetters(cfg).find((row) => String(row.id) === String(id))
    || listInboxLetters(cfg).find((row) => String(row.id) === String(id))
    || null;
}

export function listInboxLetters(cfg = readLetterCfg()) {
  const me = Number(cfg.user?.id || 0) || 0;

  // Keep any of this user's already-public letters in the public pool.
  listLetters(cfg).forEach((row) => {
    if (normalizeVisibility(row.visibility) === 'public') {
      syncInboxLetter(row, cfg);
    }
  });

  const publicOnes = readLibrary(inboxStorageKey(cfg))
    .filter((row) => normalizeVisibility(row.visibility) === 'public')
    .filter((row) => me <= 0 || Number(row.authorId || 0) !== me);

  const directOnes = readLibrary(directInboxStorageKey(cfg))
    .filter((row) => me > 0 && Number(row.toUserId || 0) === me);

  const byId = new Map();
  publicOnes.forEach((row) => {
    byId.set(String(row.id), { ...row, inboxSource: 'public' });
  });
  directOnes.forEach((row) => {
    byId.set(`${row.id}:${row.toUserId}`, { ...row, inboxSource: 'direct' });
  });

  return [...byId.values()].sort((a, b) =>
    String(b.sharedAt || b.updatedAt || '').localeCompare(String(a.sharedAt || a.updatedAt || ''))
  );
}

export function syncLetterInboxNavDot(cfg = readLetterCfg()) {
  if (typeof document === 'undefined') return;
  const hasMail = listInboxLetters(cfg).length > 0;
  if (typeof window !== 'undefined' && typeof window.updateLetterInboxNavDot === 'function') {
    try {
      window.updateLetterInboxNavDot();
      return;
    } catch {
      /* fall through */
    }
  }
  document.querySelectorAll('a.letter-inbox-nav, a.nav-link[href*="modules/letter/inbox"]').forEach((el) => {
    el.classList.add('letter-inbox-nav');
    el.classList.toggle('has-inbox-mail', hasMail);
  });
}

function syncInboxLetter(letter, cfg) {
  const key = inboxStorageKey(cfg);
  const letters = readLibrary(key);
  const idx = letters.findIndex((row) => String(row.id) === String(letter.id));
  const copy = {
    ...letter,
    visibility: 'public',
  };
  if (idx >= 0) {
    letters[idx] = copy;
  } else {
    letters.unshift(copy);
  }
  writeLibrary(key, letters);
}

function removeInboxLetter(id, cfg) {
  const key = inboxStorageKey(cfg);
  const letters = readLibrary(key).filter((row) => String(row.id) !== String(id));
  writeLibrary(key, letters);
}

function removeDirectShares(id, cfg) {
  const key = directInboxStorageKey(cfg);
  const letters = readLibrary(key).filter((row) => String(row.id) !== String(id));
  writeLibrary(key, letters);
}

export function sendLetterToEmployees(letter, userIds, cfg = readLetterCfg()) {
  if (!letter || !letter.id) return false;
  const ids = [...new Set((userIds || []).map((id) => Number(id) || 0).filter((id) => id > 0))];
  if (!ids.length) return false;

  const now = new Date().toISOString();
  const key = directInboxStorageKey(cfg);
  const rows = readLibrary(key);
  const snapshot = {
    id: String(letter.id),
    title: letter.title || letterDisplayTitle(letter.form || {}),
    form: letter.form || {},
    authorName: String(letter.authorName || letter.form?.signName || cfg.user?.name || '').trim(),
    authorId: Number(letter.authorId || cfg.user?.id || 0) || 0,
    visibility: normalizeVisibility(letter.visibility || 'private'),
    createdAt: letter.createdAt || now,
    updatedAt: letter.updatedAt || now,
    sharedAt: now,
    sharedBy: Number(cfg.user?.id || 0) || 0,
    sharedByName: String(cfg.user?.name || '').trim(),
  };

  ids.forEach((uid) => {
    const copy = { ...snapshot, toUserId: uid };
    const idx = rows.findIndex(
      (row) => String(row.id) === String(letter.id) && Number(row.toUserId || 0) === uid
    );
    if (idx >= 0) {
      rows[idx] = copy;
    } else {
      rows.unshift(copy);
    }
  });

  return writeLibrary(key, rows);
}

export function buildLetterSharePayload(row, cfg = readLetterCfg()) {
  const link = composeAbsoluteHref(cfg, row?.id);
  const title = String(row?.title || letterDisplayTitle(row?.form || {}) || 'Letter').trim();
  const author = String(row?.authorName || cfg.user?.name || '').trim();
  const text = author
    ? `${author} shared a letter with you: ${title}\n${link}`
    : `Shared letter: ${title}\n${link}`;
  return { link, title, text };
}

export function whatsappShareUrl(row, cfg = readLetterCfg(), phone = '') {
  const { text } = buildLetterSharePayload(row, cfg);
  const digits = String(phone || '').replace(/[^\d]/g, '');
  if (digits) {
    return `https://wa.me/${digits}?text=${encodeURIComponent(text)}`;
  }
  return `https://wa.me/?text=${encodeURIComponent(text)}`;
}

export function emailShareUrl(row, cfg = readLetterCfg(), email = '') {
  const { link, title, text } = buildLetterSharePayload(row, cfg);
  const subject = `Letter: ${title}`;
  const body = text || link;
  const to = String(email || '').trim();
  return `mailto:${encodeURIComponent(to)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
}

export async function publishLetterPublic(row, cfg = readLetterCfg()) {
  if (!row || !row.id) return false;
  const next = {
    ...row,
    visibility: 'public',
  };
  const ok = upsertLetter(next, cfg);
  if (!ok) return false;
  const { link } = buildLetterSharePayload(next, cfg);
  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(link);
    }
  } catch {
    /* ignore */
  }
  return true;
}

export function normalizeVisibility(value) {
  return String(value || '').toLowerCase() === 'public' ? 'public' : 'private';
}

export function upsertLetter(letter, cfg = readLetterCfg()) {
  if (!letter || !letter.id) return false;
  const key = lettersStorageKey(cfg);
  const letters = listLetters(cfg);
  const now = new Date().toISOString();
  const title = letter.title || letterDisplayTitle(letter.form || {});
  const idx = letters.findIndex((row) => String(row.id) === String(letter.id));
  const prev = idx >= 0 ? letters[idx] : null;
  const next = {
    id: String(letter.id),
    title,
    form: letter.form || {},
    authorName: String(
      letter.authorName
      || prev?.authorName
      || letter.form?.signName
      || cfg.user?.name
      || ''
    ).trim(),
    authorId: Number(
      letter.authorId
      || prev?.authorId
      || cfg.user?.id
      || 0
    ) || 0,
    visibility: normalizeVisibility(
      letter.visibility !== undefined
        ? letter.visibility
        : (prev?.visibility || 'private')
    ),
    createdAt: (prev && prev.createdAt) || letter.createdAt || now,
    updatedAt: now,
  };
  if (idx >= 0) {
    letters[idx] = next;
  } else {
    letters.unshift(next);
  }
  const ok = writeLibrary(key, letters);
  if (!ok) return false;
  if (next.visibility === 'public') {
    syncInboxLetter(next, cfg);
  } else {
    removeInboxLetter(next.id, cfg);
  }
  return true;
}

export function deleteLetter(id, cfg = readLetterCfg()) {
  if (!id) return false;
  const key = lettersStorageKey(cfg);
  const letters = listLetters(cfg).filter((row) => String(row.id) !== String(id));
  const ok = writeLibrary(key, letters);
  removeInboxLetter(id, cfg);
  removeDirectShares(id, cfg);
  return ok;
}

export function formatListDate(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return String(iso);
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const yyyy = d.getFullYear();
  return `${dd}-${mm}-${yyyy}`;
}

export function formatListDateTime(iso) {
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

export function composeAbsoluteHref(cfg = readLetterCfg(), letterId = '') {
  const path = composeHref(cfg, letterId);
  try {
    return new URL(path, window.location.origin).toString();
  } catch {
    return path;
  }
}

export async function shareLetter(row, cfg = readLetterCfg()) {
  return publishLetterPublic(row, cfg);
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

const LETTER_FLASH_KEY = 'letter-flash:v1';

export function setLetterFlash(message) {
  if (typeof window === 'undefined') return;
  const text = String(message || '').trim();
  if (!text) return;
  try {
    window.sessionStorage.setItem(LETTER_FLASH_KEY, JSON.stringify({
      message: text,
      at: Date.now(),
    }));
  } catch {
    /* ignore */
  }
}

export function consumeLetterFlash() {
  if (typeof window === 'undefined') return '';
  try {
    const raw = window.sessionStorage.getItem(LETTER_FLASH_KEY);
    window.sessionStorage.removeItem(LETTER_FLASH_KEY);
    if (!raw) return '';
    const parsed = JSON.parse(raw);
    const message = String(parsed?.message || '').trim();
    const at = Number(parsed?.at || 0);
    if (!message) return '';
    if (at > 0 && Date.now() - at > 60_000) return '';
    return message;
  } catch {
    return '';
  }
}

export function notifyLetterSentAndGoToList(cfg = readLetterCfg(), count = 1) {
  const n = Math.max(1, Number(count) || 1);
  setLetterFlash(n === 1 ? 'Letter sent.' : `Letter sent to ${n} employees.`);
  window.location.href = listHref(cfg);
}

export function inboxHref(cfg = readLetterCfg()) {
  const base = String(cfg.inboxUrl || '').trim();
  if (base) return base;
  try {
    const url = new URL(window.location.href);
    url.searchParams.set('desk', 'inbox');
    url.searchParams.delete('id');
    return url.pathname + '?' + url.searchParams.toString();
  } catch {
    return 'inbox.php?module=letter';
  }
}
