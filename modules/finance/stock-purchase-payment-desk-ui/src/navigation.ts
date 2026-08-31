export function readPoIdFromUrl(): number | null {
  const raw = new URLSearchParams(window.location.search).get('po_id');
  if (!raw) return null;
  const id = Number(raw);
  return Number.isFinite(id) && id > 0 ? id : null;
}

export function deskListUrl(): string {
  const search = new URLSearchParams(window.location.search);
  search.delete('po_id');
  const query = search.toString();
  return query ? `${window.location.pathname}?${query}` : window.location.pathname;
}

export function poViewUrl(poId: number): string {
  const search = new URLSearchParams(window.location.search);
  search.set('po_id', String(poId));
  const query = search.toString();
  return query ? `${window.location.pathname}?${query}` : window.location.pathname;
}

export function stripPoIdFromUrl(): void {
  if (!readPoIdFromUrl()) return;
  window.history.replaceState({}, '', deskListUrl());
}
