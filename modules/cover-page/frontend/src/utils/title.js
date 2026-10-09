/** "FILE" is a fixed part of the cover title; strip it if a user typed it. */
export function documentNameWithoutFile(name) {
  return String(name || '').trim().replace(/(\s+|^)file\s*$/i, '').trim();
}

export function coverTitle(name) {
  const base = documentNameWithoutFile(name);
  return base ? `${base} File` : 'File';
}
