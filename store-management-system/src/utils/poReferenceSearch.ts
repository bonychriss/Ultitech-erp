import type { ProductPoReference } from '../types';

export function poReferenceMatchesSearch(ref: ProductPoReference, query: string): boolean {
  const term = query.trim().toLowerCase();
  if (term === '') {
    return true;
  }

  const haystack = [
    ref.poNumber,
    ref.poReference,
    ref.supplierName,
    ref.receiveStatus,
    ref.source,
    String(ref.qtyRemaining),
    String(ref.qtyOrdered),
  ]
    .join(' ')
    .toLowerCase();

  return haystack.includes(term);
}

export function filterPoReferencesBySearch(
  references: ProductPoReference[],
  query: string
): ProductPoReference[] {
  if (!query.trim()) {
    return references;
  }
  return references.filter((ref) => poReferenceMatchesSearch(ref, query));
}
