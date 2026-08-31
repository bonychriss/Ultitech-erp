import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  HiOutlineCube,
  HiExclamationTriangle,
  HiOutlineArchiveBoxXMark,
  HiOutlineQueueList,
  HiOutlineInbox,
  HiOutlineMagnifyingGlass,
  HiOutlineAdjustmentsHorizontal,
  HiOutlinePlus,
  HiOutlineEye,
  HiOutlinePencilSquare,
  HiOutlineDocumentDuplicate,
  HiOutlineTrash,
  HiOutlineTag,
  HiOutlinePhoto,
  HiOutlineXMark,
} from 'react-icons/hi2';
import ProductsDeskSkeleton from './ProductsDeskSkeleton';
import ProductThumb from './ProductThumb';
import './products-desk.css';

function typeLabel(type) {
  if (type === 'vehicle') return 'Truck';
  if (type === 'spare_part') return 'Spare Part';
  return 'General';
}

function typeClass(type) {
  if (type === 'vehicle') return 'prod-desk-type--truck';
  if (type === 'spare_part') return 'prod-desk-type--spare';
  return 'prod-desk-type--general';
}

function formatMoney(n) {
  return Number(n || 0).toLocaleString('en', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

function hasActiveFilters({ category, itemType, supplier, brand, hasItemType }) {
  return Boolean(category || supplier || brand || (hasItemType && itemType));
}

function normalizeSearchQuery(q) {
  return String(q || '').trim().replace(/\s+/g, ' ');
}

function scoreProductMatch(q, name, code, brand = '') {
  const needle = normalizeSearchQuery(q).toLowerCase();
  const n = String(name || '').toLowerCase().trim();
  const c = String(code || '').toLowerCase().trim();
  const b = String(brand || '').toLowerCase().trim();
  if (!needle) return 99;
  if (c === needle) return 0;
  if (n === needle) return 1;
  if (c.startsWith(needle)) return 2;
  if (n.startsWith(needle)) return 3;
  if (n.includes(` ${needle}`)) return 4;
  if (c.includes(needle)) return 5;
  if (n.includes(needle)) return 6;
  if (b.includes(needle)) return 7;
  return 99;
}

function productMatchesQuery(q, product) {
  const tokens = normalizeSearchQuery(q).toLowerCase().split(' ').filter(Boolean);
  if (tokens.length === 0) return false;
  const name = String(product.name || '').toLowerCase();
  const code = String(product.product_code || '').toLowerCase();
  const brand = String(product.brand || '').toLowerCase();
  return tokens.every((t) => name.includes(t) || code.includes(t) || brand.includes(t));
}

export default function ProductsList({ data }) {
  const {
    products = [],
    categories = [],
    suppliers = [],
    brands = [],
    hasItemType = false,
    showCost = false,
    baseUrl = '/staff/stock/',
    searchApiUrl = '',
    filterSearch = '',
    filterCategory = '',
    filterItemType = '',
    filterSupplier = '',
    filterBrand = '',
    totalDuplicateRows = 0,
    isFilteringDuplicates = false,
    bulkImportSuccess = false,
    imported = 0,
    updated = 0,
    created = false,
    stats = {},
    missingImages = null,
  } = data;

  const missingImagesCount = Number(
    missingImages?.count ?? stats?.missing_images_count ?? 0
  );
  const missingImageSamples = Array.isArray(missingImages?.samples)
    ? missingImages.samples
    : [];

  const [search, setSearch] = useState(filterSearch || '');
  const [category, setCategory] = useState(filterCategory || '');
  const [itemType, setItemType] = useState(filterItemType || '');
  const [supplier, setSupplier] = useState(filterSupplier || '');
  const [brand, setBrand] = useState(filterBrand || '');
  const [selectedIds, setSelectedIds] = useState([]);
  const [dupDismissed, setDupDismissed] = useState(false);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [suggestions, setSuggestions] = useState([]);
  const [suggestionsOpen, setSuggestionsOpen] = useState(false);
  const [suggestionsLoading, setSuggestionsLoading] = useState(false);
  const [activeSuggestion, setActiveSuggestion] = useState(-1);
  const [booting, setBooting] = useState(true);
  const [pageLoading, setPageLoading] = useState(false);
  const [missingImagesOpen, setMissingImagesOpen] = useState(false);
  const [glowIds, setGlowIds] = useState(() =>
    products.filter((p) => p.is_recent).map((p) => p.id)
  );
  const filterWrapRef = useRef(null);
  const searchWrapRef = useRef(null);
  const searchAbortRef = useRef(null);

  useEffect(() => {
    if (bulkImportSuccess && window.Swal) {
      window.Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: `Import successful! ${imported} added, ${updated} updated.`,
        showConfirmButton: false,
        timer: 4000,
        timerProgressBar: true,
      });
    }
    if (created && window.Swal) {
      window.Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: 'Product created and shown at top.',
        showConfirmButton: false,
        timer: 3500,
        timerProgressBar: true,
      });
    }
  }, [bulkImportSuccess, imported, updated, created]);

  useEffect(() => {
    const timer = window.setTimeout(() => setBooting(false), 280);
    return () => window.clearTimeout(timer);
  }, []);

  // Show on every page visit while products are missing photos.
  useEffect(() => {
    if (missingImagesCount < 1) {
      setMissingImagesOpen(false);
      return undefined;
    }
    const timer = window.setTimeout(() => setMissingImagesOpen(true), 450);
    return () => window.clearTimeout(timer);
  }, [missingImagesCount]);

  useEffect(() => {
    if (!missingImagesOpen) return undefined;
    const onKey = (e) => {
      if (e.key === 'Escape') setMissingImagesOpen(false);
    };
    document.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [missingImagesOpen]);

  useEffect(() => {
    if (glowIds.length === 0) return undefined;
    const clear = () => setGlowIds([]);
    document.addEventListener('click', clear, { once: true });
    return () => document.removeEventListener('click', clear);
  }, [glowIds.length]);

  useEffect(() => {
    if (!filtersOpen) return undefined;
    const onDown = (e) => {
      if (!filterWrapRef.current?.contains(e.target)) setFiltersOpen(false);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') setFiltersOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [filtersOpen]);

  useEffect(() => {
    const onDown = (e) => {
      if (!searchWrapRef.current?.contains(e.target)) {
        setSuggestionsOpen(false);
        setActiveSuggestion(-1);
      }
    };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, []);

  useEffect(() => {
    const q = normalizeSearchQuery(search);
    if (q.length < 1) {
      setSuggestions([]);
      setSuggestionsOpen(false);
      setSuggestionsLoading(false);
      setActiveSuggestion(-1);
      if (searchAbortRef.current) {
        searchAbortRef.current.abort();
        searchAbortRef.current = null;
      }
      return undefined;
    }

    const api = searchApiUrl || `${baseUrl}modules/products/api/search.php`;
    const timer = window.setTimeout(async () => {
      if (searchAbortRef.current) searchAbortRef.current.abort();
      const controller = new AbortController();
      searchAbortRef.current = controller;
      setSuggestionsLoading(true);
      try {
        const res = await fetch(`${api}?q=${encodeURIComponent(q)}&limit=12`, {
          credentials: 'same-origin',
          signal: controller.signal,
          headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error('Search failed');
        const json = await res.json();
        const rows = Array.isArray(json?.data) ? json.data : [];
        setSuggestions(rows);
        setSuggestionsOpen(true);
        setActiveSuggestion(rows.length ? 0 : -1);
      } catch (err) {
        if (err?.name === 'AbortError') return;
        const local = products
          .filter((p) => productMatchesQuery(q, p))
          .map((p) => ({
            id: p.id,
            name: p.name,
            product_code: p.product_code,
            unit_price: p.unit_price,
            currency: p.currency,
            image_url: p.image_url || '',
            score: scoreProductMatch(q, p.name, p.product_code, p.brand),
          }))
          .sort((a, b) => a.score - b.score || String(a.name).localeCompare(String(b.name)))
          .slice(0, 12);
        setSuggestions(local);
        setSuggestionsOpen(true);
        setActiveSuggestion(local.length ? 0 : -1);
      } finally {
        setSuggestionsLoading(false);
      }
    }, 160);

    return () => {
      window.clearTimeout(timer);
    };
  }, [search, searchApiUrl, baseUrl, products]);

  const brandOptions = useMemo(() => {
    if (brands.length > 0) {
      return brands.map((b) => b.name).filter(Boolean);
    }
    const set = new Set();
    products.forEach((p) => {
      const b = (p.brand || '').trim();
      if (b) set.add(b);
    });
    return Array.from(set).sort((a, b) => a.localeCompare(b));
  }, [brands, products]);

  const filtersActive = hasActiveFilters({
    category,
    itemType,
    supplier,
    brand,
    hasItemType,
  });

  const toggleSelectAll = () => {
    if (selectedIds.length === products.length) {
      setSelectedIds([]);
    } else {
      setSelectedIds(products.map((p) => p.id));
    }
  };

  const toggleSelect = (id) => {
    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]
    );
  };

  const handleBulkDelete = () => {
    if (selectedIds.length === 0) return;
    const count = selectedIds.length;
    const msg = `Delete ${count} selected product${count > 1 ? 's' : ''}? This cannot be undone.`;
    if (window.StockAlert) {
      window.StockAlert.confirm(msg, 'Bulk Delete', () => {
        window.location.href = `delete_bulk.php?ids=${selectedIds.join(',')}`;
      });
    } else if (window.confirm(msg)) {
      window.location.href = `delete_bulk.php?ids=${selectedIds.join(',')}`;
    }
  };

  const confirmDelete = (id) => {
    const url = `delete.php?id=${id}`;
    if (window.StockAlert) {
      window.StockAlert.confirm(
        'Delete this product? This action cannot be undone.',
        'Delete Product',
        () => {
          window.location.href = url;
        }
      );
    } else if (window.confirm('Delete this product?')) {
      window.location.href = url;
    }
  };

  const imageSrc = (product) => {
    if (product.image_url) return product.image_url;
    if (product.main_image) {
      return `${baseUrl}uploads/products/${product.id}/medium/${product.main_image}`;
    }
    return '';
  };

  const openSuggestion = (item) => {
    if (!item?.id) return;
    window.location.href = `view.php?id=${item.id}`;
  };

  const onSearchKeyDown = (e) => {
    if (!suggestionsOpen || suggestions.length === 0) {
      if (e.key === 'Escape') setSuggestionsOpen(false);
      return;
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActiveSuggestion((i) => (i + 1) % suggestions.length);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActiveSuggestion((i) => (i <= 0 ? suggestions.length - 1 : i - 1));
    } else if (e.key === 'Enter' && activeSuggestion >= 0 && suggestions[activeSuggestion]) {
      e.preventDefault();
      openSuggestion(suggestions[activeSuggestion]);
    } else if (e.key === 'Escape') {
      setSuggestionsOpen(false);
      setActiveSuggestion(-1);
    }
  };

  const navigateWithSkeleton = (url) => {
    setPageLoading(true);
    setSuggestionsOpen(false);
    window.location.href = url;
  };

  const submitSearch = (e) => {
    e.preventDefault();
    setSuggestionsOpen(false);
    const params = new URLSearchParams();
    if (isFilteringDuplicates) params.set('show_duplicates', '1');
    if (search.trim()) params.set('search', search.trim());
    if (category) params.set('category', category);
    if (hasItemType && itemType) params.set('item_type', itemType);
    if (supplier) params.set('supplier', supplier);
    if (brand) params.set('brand', brand);
    const qs = params.toString();
    navigateWithSkeleton(qs ? `index.php?${qs}` : 'index.php');
  };

  const applyFilters = () => {
    setFiltersOpen(false);
    const params = new URLSearchParams();
    if (isFilteringDuplicates) params.set('show_duplicates', '1');
    if (search.trim()) params.set('search', search.trim());
    if (category) params.set('category', category);
    if (hasItemType && itemType) params.set('item_type', itemType);
    if (supplier) params.set('supplier', supplier);
    if (brand) params.set('brand', brand);
    const qs = params.toString();
    navigateWithSkeleton(qs ? `index.php?${qs}` : 'index.php');
  };

  const clearFilters = () => {
    navigateWithSkeleton(isFilteringDuplicates ? 'index.php?show_duplicates=1' : 'index.php');
  };

  if (booting || pageLoading) {
    return <ProductsDeskSkeleton rows={8} />;
  }

  return (
    <div className="prod-desk-page">
      <div className="prod-desk-page-header">
        <form className="prod-desk-page-header-search" onSubmit={submitSearch} ref={searchWrapRef} autoComplete="off">
          <div className={`prod-desk-search-field${suggestionsOpen ? ' has-suggestions' : ''}`}>
            <HiOutlineMagnifyingGlass className="prod-desk-search-icon" aria-hidden="true" />
            <input
              type="search"
              className="prod-desk-search-input"
              placeholder="Search products by name or code…"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setSuggestionsOpen(true);
              }}
              onFocus={() => {
                if (search.trim().length >= 1 && suggestions.length > 0) {
                  setSuggestionsOpen(true);
                }
              }}
              onKeyDown={onSearchKeyDown}
              aria-label="Search products"
              aria-autocomplete="list"
              aria-expanded={suggestionsOpen}
              aria-controls="prod-desk-search-suggestions"
            />
            {suggestionsOpen && search.trim().length >= 1 && (
              <div
                id="prod-desk-search-suggestions"
                className="prod-desk-suggestions"
                role="listbox"
                aria-label="Product suggestions"
              >
                {suggestionsLoading && suggestions.length === 0 && (
                  <div className="prod-desk-suggestion-skeleton" aria-hidden="true">
                    {[0, 1, 2, 3].map((i) => (
                      <div key={i} className="prod-desk-suggestion-skeleton-row">
                        <span className="prod-desk-bone prod-desk-bone--thumb" />
                        <span className="prod-desk-skeleton-kpi-text" style={{ flex: 1 }}>
                          <span className="prod-desk-bone prod-desk-bone--name" />
                          <span className="prod-desk-bone prod-desk-bone--code" />
                        </span>
                        <span className="prod-desk-bone prod-desk-bone--cell" style={{ width: '3.5rem' }} />
                      </div>
                    ))}
                  </div>
                )}
                {!suggestionsLoading && suggestions.length === 0 && (
                  <div className="prod-desk-suggestion-empty">No products found</div>
                )}
                {suggestions.map((item, index) => {
                  const img = item.image_url || '';
                  const isActive = index === activeSuggestion;
                  return (
                    <button
                      key={item.id}
                      type="button"
                      role="option"
                      aria-selected={isActive}
                      className={`prod-desk-suggestion${isActive ? ' is-active' : ''}`}
                      onMouseEnter={() => setActiveSuggestion(index)}
                      onClick={() => openSuggestion(item)}
                    >
                      <ProductThumb
                        src={img}
                        className="prod-desk-suggestion-thumb"
                        size={14}
                      />
                      <span className="prod-desk-suggestion-meta">
                        <span className="prod-desk-suggestion-name">{item.name}</span>
                        <span className="prod-desk-suggestion-code">
                          {item.product_code ? `CODE: ${item.product_code}` : 'No code'}
                        </span>
                      </span>
                      <span className="prod-desk-suggestion-price">
                        {formatMoney(item.unit_price)}{' '}
                        <span>{item.currency || 'USD'}</span>
                      </span>
                    </button>
                  );
                })}
                {suggestions.length > 0 && (
                  <button type="submit" className="prod-desk-suggestion-footer">
                    Show all results for “{search.trim()}”
                  </button>
                )}
              </div>
            )}
          </div>
        </form>

        <div className="prod-desk-page-header-actions">
          <div className="prod-desk-filter-wrap" ref={filterWrapRef}>
            <button
              type="button"
              className={`prod-desk-filter-btn${filtersOpen ? ' is-active' : ''}`}
              onClick={() => setFiltersOpen((v) => !v)}
              aria-expanded={filtersOpen}
              title="Filters"
            >
              <HiOutlineAdjustmentsHorizontal size={18} aria-hidden="true" />
              {filtersActive && <span className="prod-desk-filter-dot" aria-hidden="true" />}
            </button>
            {filtersOpen && (
              <div className="prod-desk-filter-panel" role="dialog" aria-label="Product filters">
                <div className="prod-desk-filter-grid">
                  <div>
                    <label htmlFor="prod-filter-category">Category</label>
                    <select
                      id="prod-filter-category"
                      value={category}
                      onChange={(e) => setCategory(e.target.value)}
                    >
                      <option value="">All</option>
                      {categories.map((c) => (
                        <option key={c.id} value={c.id}>
                          {c.name}
                        </option>
                      ))}
                    </select>
                  </div>
                  {hasItemType && (
                    <div>
                      <label htmlFor="prod-filter-type">Type</label>
                      <select
                        id="prod-filter-type"
                        value={itemType}
                        onChange={(e) => setItemType(e.target.value)}
                      >
                        <option value="">All</option>
                        <option value="spare_part">Spare parts</option>
                        <option value="vehicle">Vehicles</option>
                        <option value="general">General</option>
                      </select>
                    </div>
                  )}
                  <div>
                    <label htmlFor="prod-filter-supplier">Supplier</label>
                    <select
                      id="prod-filter-supplier"
                      value={supplier}
                      onChange={(e) => setSupplier(e.target.value)}
                    >
                      <option value="">All</option>
                      {suppliers.map((s) => (
                        <option key={s.id} value={s.id}>
                          {s.name}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label htmlFor="prod-filter-brand">Brand</label>
                    <select
                      id="prod-filter-brand"
                      value={brand}
                      onChange={(e) => setBrand(e.target.value)}
                    >
                      <option value="">All</option>
                      {brandOptions.map((name) => (
                        <option key={name} value={name}>
                          {name}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
                <div className="prod-desk-filter-actions">
                  <button type="button" className="prod-desk-btn prod-desk-btn-secondary" onClick={clearFilters}>
                    Clear
                  </button>
                  <button type="button" className="prod-desk-btn prod-desk-btn-primary" onClick={applyFilters}>
                    Apply
                  </button>
                </div>
              </div>
            )}
          </div>

          <a href="add.php" className="prod-desk-btn prod-desk-btn-primary">
            <HiOutlinePlus size={16} aria-hidden="true" />
            <span className="prod-desk-btn-label-desktop">Add product</span>
            <span className="prod-desk-btn-label-mobile">New</span>
          </a>
        </div>
      </div>

      {totalDuplicateRows > 0 && !isFilteringDuplicates && !dupDismissed && (
        <div className="prod-desk-alert prod-desk-alert--dup">
          <div>
            <div className="prod-desk-alert-title">Duplicate products detected</div>
            <div className="prod-desk-alert-sub">
              Found {totalDuplicateRows} potential duplicate group{totalDuplicateRows === 1 ? '' : 's'}.
            </div>
          </div>
          <div style={{ display: 'flex', gap: '0.5rem' }}>
            <a href="index.php?show_duplicates=1" className="prod-desk-btn prod-desk-btn-primary">
              Resolve now
            </a>
            <button type="button" className="prod-desk-btn prod-desk-btn-secondary" onClick={() => setDupDismissed(true)}>
              Dismiss
            </button>
          </div>
        </div>
      )}

      {isFilteringDuplicates && (
        <div className="prod-desk-alert prod-desk-alert--cleanup">
          <div>
            <div className="prod-desk-alert-title">Duplicate cleanup mode</div>
            <div className="prod-desk-alert-sub">Showing only redundant items.</div>
          </div>
          <a href="index.php" className="prod-desk-btn" style={{ background: '#fff', color: '#0369a1' }}>
            Exit cleanup
          </a>
        </div>
      )}

      <section className="prod-desk-kpi-grid" aria-label="Summary">
        <div className="prod-desk-kpi-card">
          <div className="prod-desk-kpi-icon prod-desk-kpi-icon--violet">
            <HiOutlineCube aria-hidden="true" />
          </div>
          <div>
            <div className="prod-desk-kpi-label">total products</div>
            <div className="prod-desk-kpi-value">{stats.total_count ?? products.length}</div>
          </div>
        </div>
        <div className="prod-desk-kpi-card">
          <div className="prod-desk-kpi-icon prod-desk-kpi-icon--amber">
            <HiExclamationTriangle aria-hidden="true" />
          </div>
          <div>
            <div className="prod-desk-kpi-label">low stock</div>
            <div className="prod-desk-kpi-value">{stats.low_stock_count ?? 0}</div>
          </div>
        </div>
        <div className="prod-desk-kpi-card">
          <div className="prod-desk-kpi-icon prod-desk-kpi-icon--rose">
            <HiOutlineArchiveBoxXMark aria-hidden="true" />
          </div>
          <div>
            <div className="prod-desk-kpi-label">out of stock</div>
            <div className="prod-desk-kpi-value">{stats.out_of_stock_count ?? 0}</div>
          </div>
        </div>
        <div className="prod-desk-kpi-card">
          <div className="prod-desk-kpi-icon prod-desk-kpi-icon--teal">
            <HiOutlineQueueList aria-hidden="true" />
          </div>
          <div>
            <div className="prod-desk-kpi-label">listed now</div>
            <div className="prod-desk-kpi-value">{stats.listed_count ?? products.length}</div>
            <div className="prod-desk-kpi-helper">matching current filters</div>
          </div>
        </div>
      </section>

      <section className="prod-desk-results">
        <div className="prod-desk-results-head">
          <span className="prod-desk-results-count">
            {products.length} {products.length === 1 ? 'result' : 'results'}
          </span>
        </div>

        {products.length === 0 ? (
          <div className="prod-desk-empty">
            <HiOutlineInbox size={28} style={{ color: '#94a3b8' }} aria-hidden="true" />
            <p className="prod-desk-empty-title">No products found</p>
            <p className="prod-desk-empty-sub">Try adjusting your search or filters.</p>
          </div>
        ) : (
          <div className="prod-desk-table-wrap">
            <table className="prod-desk-table">
              <thead>
                <tr>
                  <th style={{ width: 40 }}>
                    <input
                      type="checkbox"
                      checked={products.length > 0 && selectedIds.length === products.length}
                      onChange={toggleSelectAll}
                      aria-label="Select all"
                    />
                  </th>
                  <th>Product</th>
                  {hasItemType && <th>Type</th>}
                  <th>Category / Brand</th>
                  <th>Pricing</th>
                  <th style={{ textAlign: 'center' }}>Stock</th>
                  <th style={{ textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {products.map((product) => {
                  const qty = Number(product.quantity ?? 0);
                  const low = qty <= Number(product.reorder_level ?? 0);
                  const isSelected = selectedIds.includes(product.id);
                  const glowing = glowIds.includes(product.id);
                  const img = imageSrc(product);
                  const brandName = (product.brand || '').trim() || 'No Brand';

                  return (
                    <tr
                      key={product.id}
                      className={[
                        isSelected ? 'is-selected' : '',
                        glowing ? 'is-glow' : '',
                      ]
                        .filter(Boolean)
                        .join(' ')}
                    >
                      <td>
                        <input
                          type="checkbox"
                          checked={isSelected}
                          onChange={() => toggleSelect(product.id)}
                          aria-label={`Select ${product.name}`}
                        />
                      </td>
                      <td>
                        <div className="prod-desk-product">
                          <ProductThumb src={img} className="prod-desk-thumb" size={14} />
                          <div style={{ minWidth: 0 }}>
                            <a href={`view.php?id=${product.id}`} className="prod-desk-name">
                              {product.name}
                            </a>
                            <div className="prod-desk-code">CODE: {product.product_code || '—'}</div>
                          </div>
                        </div>
                      </td>
                      {hasItemType && (
                        <td>
                          <span className={`prod-desk-type ${typeClass(product.item_type)}`}>
                            {typeLabel(product.item_type)}
                          </span>
                        </td>
                      )}
                      <td>
                        <div style={{ fontWeight: 600 }}>{product.category_name || 'N/A'}</div>
                        <div className="prod-desk-muted" style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                          <HiOutlineTag size={11} aria-hidden="true" />
                          {brandName}
                        </div>
                      </td>
                      <td>
                        <div className="prod-desk-muted">Selling</div>
                        <div className="prod-desk-price">
                          {formatMoney(product.unit_price)}{' '}
                          <span style={{ fontSize: '0.7rem', fontWeight: 600 }}>
                            {product.currency || 'USD'}
                          </span>
                        </div>
                        {showCost && (
                          <>
                            <div className="prod-desk-muted" style={{ marginTop: 4 }}>
                              Buying
                            </div>
                            <div className="prod-desk-muted">
                              {formatMoney(product.buying_price)} {product.currency || 'USD'}
                            </div>
                          </>
                        )}
                      </td>
                      <td style={{ textAlign: 'center' }}>
                        <div className={`prod-desk-stock${low ? ' is-low' : ''}`}>{qty}</div>
                        {low && <span className="prod-desk-badge-low">Low stock</span>}
                      </td>
                      <td style={{ textAlign: 'right' }}>
                        <div className="prod-desk-actions">
                          <a href={`view.php?id=${product.id}`} className="prod-desk-icon-btn prod-desk-icon-btn--view" title="View">
                            <HiOutlineEye size={16} aria-hidden="true" />
                          </a>
                          <a href={`edit.php?id=${product.id}`} className="prod-desk-icon-btn prod-desk-icon-btn--edit" title="Edit">
                            <HiOutlinePencilSquare size={16} aria-hidden="true" />
                          </a>
                          <a href={`duplicate.php?id=${product.id}`} className="prod-desk-icon-btn prod-desk-icon-btn--dup" title="Duplicate">
                            <HiOutlineDocumentDuplicate size={16} aria-hidden="true" />
                          </a>
                          <button
                            type="button"
                            className="prod-desk-icon-btn prod-desk-icon-btn--del"
                            title="Delete"
                            onClick={() => confirmDelete(product.id)}
                          >
                            <HiOutlineTrash size={16} aria-hidden="true" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {selectedIds.length > 0 && (
        <div className="prod-desk-bulk-bar">
          <span style={{ fontSize: '0.75rem', fontWeight: 600, borderRight: '1px solid #334155', paddingRight: '0.75rem' }}>
            {selectedIds.length} selected
          </span>
          <button type="button" onClick={() => setSelectedIds([])}>
            Deselect
          </button>
          <button type="button" className="prod-desk-bulk-del" onClick={handleBulkDelete}>
            <HiOutlineTrash size={14} aria-hidden="true" /> Delete
          </button>
        </div>
      )}

      {missingImagesOpen && missingImagesCount > 0 ? (
        <div
          className="prod-desk-modal-backdrop"
          role="presentation"
          onClick={() => setMissingImagesOpen(false)}
        >
          <div
            className="prod-desk-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="prod-desk-missing-images-title"
            onClick={(e) => e.stopPropagation()}
          >
            <button
              type="button"
              className="prod-desk-modal-close"
              onClick={() => setMissingImagesOpen(false)}
              aria-label="Close"
            >
              <HiOutlineXMark size={18} />
            </button>
            <div className="prod-desk-modal-icon" aria-hidden="true">
              <HiOutlinePhoto size={28} />
            </div>
            <h2 id="prod-desk-missing-images-title" className="prod-desk-modal-title">
              Add product images
            </h2>
            <p className="prod-desk-modal-text">
              {missingImagesCount === 1
                ? '1 product currently has no photo on this list. Open it and upload an image.'
                : `${missingImagesCount} products currently have no photo on this list. Open any product below and upload an image.`}
            </p>
            {missingImageSamples.length > 0 ? (
              <ul className="prod-desk-modal-list">
                {missingImageSamples.map((item) => (
                  <li key={item.id}>
                    <a href={`edit.php?id=${item.id}`}>
                      <span className="prod-desk-modal-list-name">{item.name || 'Untitled'}</span>
                      {item.product_code ? (
                        <span className="prod-desk-modal-list-code">{item.product_code}</span>
                      ) : null}
                    </a>
                  </li>
                ))}
                {missingImagesCount > missingImageSamples.length ? (
                  <li className="prod-desk-modal-list-more">
                    +{missingImagesCount - missingImageSamples.length} more
                  </li>
                ) : null}
              </ul>
            ) : null}
            <div className="prod-desk-modal-actions">
              <button
                type="button"
                className="prod-desk-modal-btn prod-desk-modal-btn--secondary"
                style={{ borderRadius: 9999 }}
                onClick={() => setMissingImagesOpen(false)}
              >
                Remind me later
              </button>
              <a
                href={
                  missingImageSamples[0]?.id
                    ? `edit.php?id=${missingImageSamples[0].id}`
                    : 'edit.php'
                }
                className="prod-desk-modal-btn prod-desk-modal-btn--primary"
                style={{ borderRadius: 9999 }}
              >
                Add images
              </a>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
