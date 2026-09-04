import {
  useCallback,
  useEffect,
  useMemo,
  useState,
} from 'react';
import {
  CalendarDays,
  Coins,
  Download,
  Loader2,
  MoreVertical,
  Package,
  Search,
  Tag,
  X,
} from 'lucide-react';
import { fetchPricelistInit } from '../api/pricelistDesk.js';
import filterIcon from '../assets/filter-icon.png';
import {
  EMPTY_CELL,
  formatMoneyDashboard,
  mapProductsWithEditedPrices,
  PLACEHOLDER_IMG,
} from '../utils/pricelistFormat.js';
import { generatePriceListPdf } from '../utils/pricelistPdf.js';

const EMPTY_LOTTIE_FALLBACK = '/assets/animations/nothing.lottie';

function ProductImage({ src, alt = '' }) {
  const [loaded, setLoaded] = useState(false);
  const [failed, setFailed] = useState(false);
  const imageSrc = failed || !src ? PLACEHOLDER_IMG : src;

  useEffect(() => {
    setLoaded(false);
    setFailed(false);
  }, [src]);

  return (
    <div className={`pl-product-image-wrap${loaded ? ' is-loaded' : ''}`}>
      {!loaded && <span className="pl-image-skeleton" aria-hidden="true" />}
      <img
        src={imageSrc}
        alt={alt}
        className={`pl-product-image${loaded ? ' is-visible' : ''}`}
        loading="lazy"
        decoding="async"
        ref={(node) => {
          if (node && node.complete && node.naturalWidth > 0) {
            setLoaded(true);
          }
        }}
        onLoad={() => setLoaded(true)}
        onError={() => {
          setFailed(true);
          setLoaded(true);
        }}
      />
    </div>
  );
}

function ensureDotLottiePlayer() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  if (customElements.get('dotlottie-wc')) return;
  if (document.getElementById('pl-dotlottie-wc')) return;
  const script = document.createElement('script');
  script.id = 'pl-dotlottie-wc';
  script.type = 'module';
  script.src = 'https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.5/dist/dotlottie-wc.js';
  document.head.appendChild(script);
}

function EmptyProductsState({ animationSrc }) {
  useEffect(() => {
    ensureDotLottiePlayer();
  }, []);

  const src = animationSrc || EMPTY_LOTTIE_FALLBACK;

  return (
    <div className="exp-desk-empty pl-empty">
      <div className="pl-empty-lottie" aria-hidden="true">
        <dotlottie-wc src={src} autoplay loop speed="1" style={{ width: '220px', height: '220px' }} />
      </div>
      <p className="exp-desk-empty-title">No products found</p>
      <p className="exp-desk-empty-sub">Try adjusting search or filters.</p>
    </div>
  );
}

export default function PriceListPage() {
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [products, setProducts] = useState([]);
  const [search, setSearch] = useState('');
  const [customerName, setCustomerName] = useState('');
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isGenerating, setIsGenerating] = useState(false);
  const [genStats, setGenStats] = useState({ progress: 0, time: '0.0', speed: '1.2' });
  const [categoryFilter, setCategoryFilter] = useState('');
  const [brandFilter, setBrandFilter] = useState('');
  const [activeOnly, setActiveOnly] = useState(true);
  const [showFilterPanel, setShowFilterPanel] = useState(false);
  const [minPrice, setMinPrice] = useState('');
  const [maxPrice, setMaxPrice] = useState('');
  const [menuRowId, setMenuRowId] = useState(null);
  const [selectMode, setSelectMode] = useState(false);
  const [selectedIds, setSelectedIds] = useState(() => new Set());
  const [pdfItemCount, setPdfItemCount] = useState(0);

  const loadInit = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const params = new URLSearchParams(window.location.search);
      const payload = await fetchPricelistInit(params);
      setInit(payload);
      setProducts(mapProductsWithEditedPrices(payload.products));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load price list.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadInit();
  }, [loadInit]);

  const currency = init?.currency || 'TZS';

  const categoryOptions = useMemo(() => {
    const set = new Set();
    products.forEach((product) => {
      const name = (product.category_name || '').trim();
      if (name) set.add(name);
    });
    return Array.from(set).sort((a, b) => a.localeCompare(b));
  }, [products]);

  const brandOptions = useMemo(() => {
    const set = new Set();
    products.forEach((product) => {
      const name = (product.brand_name || '').trim();
      if (name) set.add(name);
    });
    return Array.from(set).sort((a, b) => a.localeCompare(b));
  }, [products]);

  const filteredProducts = useMemo(() => {
    const q = search.trim().toLowerCase();
    const minN = minPrice === '' ? null : Number(minPrice);
    const maxN = maxPrice === '' ? null : Number(maxPrice);
    return products.filter((product) => {
      if (activeOnly && product.is_catalog_active === false) return false;
      if (categoryFilter && (product.category_name || '').trim() !== categoryFilter) return false;
      if (brandFilter && (product.brand_name || '').trim() !== brandFilter) return false;
      const edited = Number(product.edited_price) || 0;
      if (minN !== null && !Number.isNaN(minN) && edited < minN) return false;
      if (maxN !== null && !Number.isNaN(maxN) && edited > maxN) return false;
      if (!q) return true;
      return (
        (product.name || '').toLowerCase().includes(q)
        || (product.description || '').toLowerCase().includes(q)
        || (product.product_code || '').toLowerCase().includes(q)
        || (product.category_name || '').toLowerCase().includes(q)
        || (product.brand_name || '').toLowerCase().includes(q)
      );
    });
  }, [search, products, categoryFilter, brandFilter, activeOnly, minPrice, maxPrice]);

  useEffect(() => {
    const onDocClick = (event) => {
      if (!event.target.closest('[data-pl-row-menu]')) setMenuRowId(null);
    };
    document.addEventListener('click', onDocClick);
    return () => document.removeEventListener('click', onDocClick);
  }, []);

  const dashboardStats = useMemo(() => {
    const totalProducts = products.length;
    const pricedItems = products.filter(
      (product) => Number(product.selling_price) > 0 || Number(product.edited_price) > 0,
    ).length;
    const totalValue = products.reduce(
      (acc, product) => acc + (Number(product.edited_price) || 0),
      0,
    );
    let lastLabel = EMPTY_CELL;
    const iso = init?.meta?.last_updated_iso;
    if (iso) {
      try {
        lastLabel = new Date(iso).toLocaleDateString('en-US', {
          month: 'short',
          day: 'numeric',
          year: 'numeric',
        });
      } catch {
        lastLabel = EMPTY_CELL;
      }
    }
    return { totalProducts, pricedItems, totalValue, lastLabel };
  }, [products, init?.meta]);

  function resetRowPrice(id) {
    setProducts((prev) => prev.map(
      (product) => (product.id === id
        ? { ...product, edited_price: product.selling_price }
        : product),
    ));
    setMenuRowId(null);
  }

  function clearAllFilters() {
    setSearch('');
    setCategoryFilter('');
    setBrandFilter('');
    setActiveOnly(true);
    setMinPrice('');
    setMaxPrice('');
    setShowFilterPanel(false);
  }

  function handlePriceChange(id, newPrice) {
    setProducts((prev) => prev.map(
      (product) => (product.id === id
        ? { ...product, edited_price: parseFloat(newPrice) || 0 }
        : product),
    ));
  }

  const selectedCount = selectedIds.size;

  const activeFilterCount = [
    categoryFilter,
    brandFilter,
    minPrice,
    maxPrice,
    !activeOnly,
  ].filter(Boolean).length;

  const allFilteredSelected = filteredProducts.length > 0
    && filteredProducts.every((product) => selectedIds.has(product.id));

  function enterSelectMode() {
    setSelectMode(true);
    setSelectedIds(new Set());
    setMenuRowId(null);
    setIsModalOpen(false);
  }

  function exitSelectMode() {
    setSelectMode(false);
    setSelectedIds(new Set());
    setIsModalOpen(false);
  }

  function toggleProductSelected(id) {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function selectAllFiltered() {
    setSelectedIds(new Set(filteredProducts.map((product) => product.id)));
  }

  function clearSelection() {
    setSelectedIds(new Set());
  }

  function openPrepareModal() {
    if (selectedCount === 0) {
      window.alert('Select at least one product for the price list PDF.');
      return;
    }
    setIsModalOpen(true);
  }

  async function handleGeneratePdf() {
    const selectedProducts = products.filter((product) => selectedIds.has(product.id));
    if (selectedProducts.length === 0) {
      window.alert('Select at least one product for the price list PDF.');
      return;
    }

    setIsGenerating(true);
    setIsModalOpen(false);
    setPdfItemCount(selectedProducts.length);
    setGenStats({ progress: 1, time: '0.0', speed: '0' });

    const start = Date.now();
    const timer = window.setInterval(() => {
      setGenStats((prev) => ({
        ...prev,
        time: ((Date.now() - start) / 1000).toFixed(1),
      }));
    }, 250);

    let failed = false;
    try {
      await generatePriceListPdf({
        products: selectedProducts,
        customerName,
        company: init?.company,
        currency,
        logoUrl: init?.logo_url,
        currentUser: init?.current_user,
        onProgress: (value) => setGenStats((prev) => ({
          ...prev,
          progress: Math.max(Number(prev.progress) || 0, Number(value) || 0),
        })),
      });
      setGenStats((prev) => ({ ...prev, progress: 100 }));
      setSelectMode(false);
      setSelectedIds(new Set());
    } catch (err) {
      failed = true;
      window.alert(err instanceof Error ? err.message : 'Failed to generate PDF.');
    } finally {
      window.clearInterval(timer);
      window.setTimeout(() => {
        setIsGenerating(false);
        setGenStats({ progress: 0, time: 0, speed: 0 });
      }, failed ? 0 : 600);
    }
  }

  const startIdx = filteredProducts.length === 0 ? 0 : 1;
  const endIdx = filteredProducts.length;

  if (loading && !init) {
    return (
      <div className="exp-desk-page exp-desk-boot-loading" role="status">
        <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
        <span>Loading price list...</span>
      </div>
    );
  }

  if (error && !init) {
    return (
      <div className="exp-desk-page">
        <div className="exp-desk-flash exp-desk-flash-error" role="alert">{error}</div>
      </div>
    );
  }

  return (
    <div className={`exp-desk-page pl-page${selectMode ? ' pl-selecting' : ''}`}>
      <div className="pl-toolbar pl-no-print">
        <div className="pl-header-actions">
          {selectMode ? (
            <>
              <button type="button" className="exp-desk-btn exp-desk-btn-secondary" onClick={exitSelectMode}>
                Cancel
              </button>
              <button
                type="button"
                className="exp-desk-btn exp-desk-btn-primary"
                onClick={openPrepareModal}
                disabled={selectedCount === 0}
              >
                <Download size={16} aria-hidden="true" />
                Continue ({selectedCount})
              </button>
            </>
          ) : (
            <>
              <button
                type="button"
                className={`pl-filter-btn${showFilterPanel ? ' is-active' : ''}${activeFilterCount > 0 ? ' has-filters' : ''}`}
                onClick={() => setShowFilterPanel((value) => !value)}
                aria-expanded={showFilterPanel}
                aria-controls="pl-filter-panel"
              >
                <img src={filterIcon} alt="" className="pl-filter-btn-icon" width={18} height={18} />
                Filters
                {activeFilterCount > 0 && (
                  <span className="pl-filter-btn-badge">{activeFilterCount}</span>
                )}
              </button>
              <button type="button" className="exp-desk-btn exp-desk-btn-primary pl-btn-rounded" onClick={enterSelectMode}>
                <Download size={16} aria-hidden="true" />
                Download PDF
              </button>
            </>
          )}
        </div>
      </div>

      {selectMode && (
        <div className="pl-select-banner pl-no-print" role="status">
          <div className="pl-select-banner-text">
            Select products to include in the price list PDF.
          </div>
          <div className="pl-select-banner-actions">
            <button
              type="button"
              className="pl-chip-link"
              onClick={allFilteredSelected ? clearSelection : selectAllFiltered}
            >
              {allFilteredSelected ? 'Clear all' : `Select all (${filteredProducts.length})`}
            </button>
          </div>
        </div>
      )}

      <div className="pl-filters pl-no-print">
        <div className="pl-filter-row">
          <div className="exp-desk-search-field pl-search-field">
            <Search className="exp-desk-search-icon" size={16} aria-hidden="true" />
            <input
              type="search"
              className="exp-desk-search-input"
              placeholder="Search name, code, description..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
            {search.trim() && (
              <button type="button" className="exp-desk-search-clear" onClick={() => setSearch('')} aria-label="Clear search">
                <X size={14} aria-hidden="true" />
              </button>
            )}
          </div>
        </div>

        {showFilterPanel && (
          <div className="pl-filter-panel" id="pl-filter-panel">
            <div className="pl-filter-panel-grid">
              <label>
                <span>Category</span>
                <select className="pl-select" value={categoryFilter} onChange={(e) => setCategoryFilter(e.target.value)}>
                  <option value="">All Categories</option>
                  {categoryOptions.map((option) => (
                    <option key={option} value={option}>{option}</option>
                  ))}
                </select>
              </label>
              <label>
                <span>Brand</span>
                <select className="pl-select" value={brandFilter} onChange={(e) => setBrandFilter(e.target.value)}>
                  <option value="">All Brands</option>
                  {brandOptions.map((option) => (
                    <option key={option} value={option}>{option}</option>
                  ))}
                </select>
              </label>
              <label>
                <span>Min price</span>
                <input type="number" step="any" value={minPrice} onChange={(e) => setMinPrice(e.target.value)} placeholder="0" />
              </label>
              <label>
                <span>Max price</span>
                <input type="number" step="any" value={maxPrice} onChange={(e) => setMaxPrice(e.target.value)} placeholder="Any" />
              </label>
              <label className="pl-filter-check">
                <input
                  type="checkbox"
                  checked={activeOnly}
                  onChange={(e) => setActiveOnly(e.target.checked)}
                />
                <span>Active items only</span>
              </label>
            </div>
            {(categoryFilter || brandFilter || minPrice || maxPrice || !activeOnly) && (
              <div className="pl-filter-panel-foot">
                <button type="button" className="pl-clear-link" onClick={clearAllFilters}>Clear all filters</button>
              </div>
            )}
          </div>
        )}

        {(categoryFilter || brandFilter || minPrice || maxPrice || !activeOnly) && !showFilterPanel && (
          <div className="pl-filter-chips">
            {categoryFilter && (
              <span className="pl-chip">
                {categoryFilter}
                <button type="button" onClick={() => setCategoryFilter('')} aria-label="Remove category filter">
                  <X size={12} />
                </button>
              </span>
            )}
            {brandFilter && (
              <span className="pl-chip">
                {brandFilter}
                <button type="button" onClick={() => setBrandFilter('')} aria-label="Remove brand filter">
                  <X size={12} />
                </button>
              </span>
            )}
            {(minPrice || maxPrice) && (
              <span className="pl-chip">
                Price{minPrice ? ` ≥ ${minPrice}` : ''}{maxPrice ? ` ≤ ${maxPrice}` : ''}
                <button
                  type="button"
                  onClick={() => { setMinPrice(''); setMaxPrice(''); }}
                  aria-label="Remove price filter"
                >
                  <X size={12} />
                </button>
              </span>
            )}
            {!activeOnly && (
              <span className="pl-chip">
                Including inactive
                <button type="button" onClick={() => setActiveOnly(true)} aria-label="Show active only">
                  <X size={12} />
                </button>
              </span>
            )}
            <button type="button" className="pl-clear-link" onClick={clearAllFilters}>Clear all</button>
          </div>
        )}
      </div>

      {!selectMode && (
        <div className="pl-kpi-grid pl-no-print">
          <div className="pl-kpi-card">
            <span className="pl-kpi-icon pl-kpi-icon--violet"><Package size={18} /></span>
            <div>
              <div className="pl-kpi-value">{dashboardStats.totalProducts}</div>
              <div className="pl-kpi-label">Total Products</div>
            </div>
          </div>
          <div className="pl-kpi-card">
            <span className="pl-kpi-icon pl-kpi-icon--blue"><Tag size={18} /></span>
            <div>
              <div className="pl-kpi-value">{dashboardStats.pricedItems}</div>
              <div className="pl-kpi-label">Priced Items</div>
            </div>
          </div>
          <div className="pl-kpi-card">
            <span className="pl-kpi-icon pl-kpi-icon--green"><Coins size={18} /></span>
            <div>
              <div className="pl-kpi-value pl-kpi-value--money">{formatMoneyDashboard(dashboardStats.totalValue, currency)}</div>
              <div className="pl-kpi-label">Total Value (Approx.)</div>
            </div>
          </div>
          <div className="pl-kpi-card">
            <span className="pl-kpi-icon pl-kpi-icon--amber"><CalendarDays size={18} /></span>
            <div>
              <div className="pl-kpi-value">{dashboardStats.lastLabel}</div>
              <div className="pl-kpi-label">Last Updated</div>
            </div>
          </div>
        </div>
      )}

      <section className="exp-desk-results pl-table-section">
        {filteredProducts.length === 0 ? (
          <EmptyProductsState animationSrc={init?.empty_animation_url} />
        ) : (
          <>
            <div className="exp-desk-table-wrap">
              <table className="exp-desk-table pl-table">
                <thead>
                  <tr>
                    {selectMode && (
                      <th className="pl-col-check">
                        <input
                          type="checkbox"
                          className="pl-row-check"
                          checked={allFilteredSelected}
                          onChange={allFilteredSelected ? clearSelection : selectAllFiltered}
                          aria-label="Select all products"
                        />
                      </th>
                    )}
                    <th className="pl-col-num">#</th>
                    <th className="pl-col-image">Image</th>
                    <th>Product Details</th>
                    <th>Code</th>
                    <th>Category</th>
                    <th className="pl-col-price">Unit Price ({currency})</th>
                    <th className="pl-col-action">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredProducts.map((product, index) => {
                    const rowNum = index + 1;
                    const isSelected = selectedIds.has(product.id);
                    return (
                      <tr
                        key={product.id}
                        className={selectMode && isSelected ? 'is-selected' : undefined}
                        onClick={selectMode ? () => toggleProductSelected(product.id) : undefined}
                      >
                        {selectMode && (
                          <td className="pl-col-check" onClick={(event) => event.stopPropagation()}>
                            <input
                              type="checkbox"
                              className="pl-row-check"
                              checked={isSelected}
                              onChange={() => toggleProductSelected(product.id)}
                              aria-label={`Select ${product.name || 'product'}`}
                            />
                          </td>
                        )}
                        <td className="pl-col-num">{rowNum}</td>
                        <td className="pl-col-image">
                          <ProductImage src={product.image_url || PLACEHOLDER_IMG} />
                        </td>
                        <td>
                          <div className="exp-desk-cell-main">{product.name}</div>
                          <div className="exp-desk-cell-sub">{product.description || EMPTY_CELL}</div>
                        </td>
                        <td><span className="exp-desk-ref">{product.product_code || EMPTY_CELL}</span></td>
                        <td>{(product.category_name || '').trim() || EMPTY_CELL}</td>
                        <td className="pl-col-price">
                          <input
                            type="number"
                            step="any"
                            value={product.edited_price}
                            onChange={(e) => handlePriceChange(product.id, e.target.value)}
                            onClick={(event) => event.stopPropagation()}
                            className={`pl-price-input${product.edited_price !== product.selling_price ? ' is-edited' : ''}`}
                          />
                        </td>
                        <td className="pl-col-action" data-pl-row-menu onClick={(event) => event.stopPropagation()}>
                          <div className="pl-row-menu-wrap">
                            <button
                              type="button"
                              className="pl-row-menu-btn"
                              aria-label="Row actions"
                              onClick={(event) => {
                                event.stopPropagation();
                                setMenuRowId(menuRowId === product.id ? null : product.id);
                              }}
                            >
                              <MoreVertical size={16} />
                            </button>
                            {menuRowId === product.id && (
                              <div className="pl-row-menu">
                                <button type="button" onClick={() => resetRowPrice(product.id)}>
                                  Reset row price
                                </button>
                              </div>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            <div className="pl-pagination pl-no-print">
              <p>
                Showing <strong>{startIdx}</strong> to <strong>{endIdx}</strong> of{' '}
                <strong>{filteredProducts.length}</strong> products
              </p>
            </div>
          </>
        )}
      </section>

      {isModalOpen && (
        <div className="pl-modal-backdrop pl-no-print" role="presentation">
          <div className="pl-modal" role="dialog" aria-labelledby="pl-modal-title">
            <div className="pl-modal-head">
              <h2 id="pl-modal-title">Prepare PDF</h2>
              <button type="button" className="pl-modal-close" onClick={() => setIsModalOpen(false)} aria-label="Close">
                <X size={20} />
              </button>
            </div>
            <label className="pl-modal-label" htmlFor="pl-customer-name">Client Name</label>
            <input
              id="pl-customer-name"
              type="text"
              placeholder="Enter customer name..."
              value={customerName}
              onChange={(e) => setCustomerName(e.target.value)}
              className="pl-modal-input"
              autoFocus
            />
            <p className="pl-modal-help">
              This will appear at the top of the price list. PDF will include {selectedCount} selected product{selectedCount === 1 ? '' : 's'}.
            </p>
            <div className="pl-modal-actions">
              <button type="button" className="exp-desk-btn exp-desk-btn-secondary" onClick={() => setIsModalOpen(false)}>
                Cancel
              </button>
              <button type="button" className="exp-create-btn-save" onClick={handleGeneratePdf}>
                Download PDF
              </button>
            </div>
          </div>
        </div>
      )}

      {isGenerating && (
        <div className="pl-generating pl-no-print" role="status">
          <div className="pl-generating-inner">
            <div className="pl-generating-ring">
              <Loader2 className="pl-generating-spinner" />
              <span>{Math.floor(genStats.progress)}%</span>
            </div>
            <h2>Generating Price List</h2>
            <p>Loading product photos, then building PDF for {pdfItemCount} items...</p>
            <div className="pl-generating-bar">
              <div style={{ width: `${genStats.progress}%` }} />
            </div>
            <div className="pl-generating-meta">
              <span>{genStats.time}s elapsed</span>
              <span>{Math.floor(genStats.progress)}% complete</span>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
