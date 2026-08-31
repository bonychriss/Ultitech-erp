import type { Product } from '../types';

/** Match products by name, code/SKU, id, category, description, or unit. */
export function productMatchesSearch(product: Product, query: string): boolean {
  const term = query.trim().toLowerCase();
  if (term === '') {
    return true;
  }

  const haystack = [
    product.name,
    product.sku,
    product.id,
    product.category,
    product.description,
    product.unit,
    String(product.stock),
    String(product.minStock),
    String(product.price),
    String(product.cost),
  ]
    .join(' ')
    .toLowerCase();

  return haystack.includes(term);
}

export function filterProductsBySearch(products: Product[], query: string): Product[] {
  if (!query.trim()) {
    return products;
  }
  return products.filter((p) => productMatchesSearch(p, query));
}
