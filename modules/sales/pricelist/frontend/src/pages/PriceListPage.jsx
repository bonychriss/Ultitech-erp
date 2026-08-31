import {
  useCallback,
  useEffect,
  useMemo,
  useState,
} from 'react';
import {
  CalendarDays,
  ChevronLeft,
  ChevronRight,
  Coins,
  Download,
  Filter,
  Loader2,
  MoreVertical,
  Package,
  Printer,
  RotateCcw,
  Search,
  Settings,
  Tag,
  X,
} from 'lucide-react';
import { fetchPricelistInit } from '../api/pricelistDesk.js';
import {
  buildVisiblePageNumbers,
  EMPTY_CELL,
  formatCurrency,
  formatMoneyDashboard,
  mapProductsWithEditedPrices,
  PLACEHOLDER_IMG,
} from '../utils/pricelistFormat.js';
import { generatePriceListPdf } from '../utils/pricelistPdf.js';

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
  const [showMoreFilters, setShowMoreFilters] = useState(false);
  const [minPrice, setMinPrice] = useState('');
  const [maxPrice, setMaxPrice] = useState('');
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const [menuRowId, setMenuRowId] = useState(null);

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
  const urls = init?.urls || {};

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

  const totalPages = Math.max(1, Math.ceil(filteredProducts.length / pageSize) || 1);
  const currentPage = Math.min(page, totalPages);

  const paginatedProducts = useMemo(() => {
    const start = (currentPage - 1) * pageSize;
    return filteredProducts.slice(start, start + pageSize);
  }, [filteredProducts, currentPage, pageSize]);

  const visiblePageNumbers = useMemo(
    () => buildVisiblePageNumbers(totalPages, currentPage),
    [totalPages, currentPage],
  );

  useEffect(() => {
    const onDocClick = (event) => {
      if (!event.target.closest('[data-pl-row-menu]')) setMenuRowId(null);
    };
    document.addEventListener('click', onDocClick);
    return () => document.removeEventListener('click', onDocClick);
  }, []);

  useEffect(() => {
    if (page > totalPages) setPage(totalPages);
  }, [filteredProducts.length, pageSize, page, totalPages]);

  useEffect(() => {
    setPage(1);
  }, [search, categoryFilter, brandFilter, activeOnly, minPrice, maxPrice, pageSize]);

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
    setShowMoreFilters(false);
  }

  function handlePriceChange(id, newPrice) {
    setProducts((prev) => prev.map(
      (product) => (product.id === id
        ? { ...product, edited_price: parseFloat(newPrice) || 0 }
        : product),
    ));
  }

  function resetPrices() {
    setProducts((prev) => prev.map(
      (product) => ({ ...product, edited_price: product.selling_price }),
    ));
  }

  async function handleGeneratePdf() {
    setIsGenerating(true);
    setIsModalOpen(false);
    setGenStats({ progress: 5, time: '0.0', speed: '1.2' });

    const start = Date.now();
    const timer = window.setInterval(() => {
      setGenStats((prev) => {
        const elapsed = ((Date.now() - start) / 1000).toFixed(1);
        const newProgress = Math.min(99, parseFloat(prev.progress) + (Math.random() * 1.5));
        return {
          progress: newProgress,
          time: elapsed,
          speed: (Math.random() * 2 + 1.5).toFixed(1),
        };
      });
    }, 150);

    let failed = false;
    try {
      await generatePriceListPdf({
        products,
        customerName,
        company: init?.company,
        currency,
        logoUrl: init?.logo_url,
        currentUser: init?.current_user,
        onProgress: (value) => setGenStats((prev) => ({ ...prev, progress: value })),
      });
      setGenStats((prev) => ({ ...prev, progress: 100 }));
    } catch (err) {
      failed = true;
      window.alert(err instanceof Error ? err.message : 'Failed to generate PDF.');
    } finally {
      window.clearInterval(timer);
      window.setTimeout(() => {
        setIsGenerating(false);
        setGenStats({ progress: 0, time: 0, speed: 0 });
      }, failed ? 0 : 1000);
    }
  }

  const startIdx = filteredProducts.length === 0 ? 0 : (currentPage - 1) * pageSize + 1;
  const endIdx = filteredProducts.length === 0
    ? 0
    : Math.min(filteredProducts.length, currentPage * pageSize);

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
    <div className="exp-desk-page pl-page">
      <div className="pl-toolbar pl-no-print">
        {urls.settings && (
          <a href={urls.settings} className="pl-settings-link" title="Sales settings">
            <Settings size={18} aria-hidden="true" />
          </a>
        )}
        <div className="pl-header-actions">
          <button type="button" className="exp-desk-btn exp-desk-btn-secondary" onClick={resetPrices}>
            <RotateCcw size={16} aria-hidden="true" />
            Reset prices
          </button>
          <button type="button" className="exp-desk-btn exp-desk-btn-primary" onClick={() => setIsModalOpen(true)}>
            <Download size={16} aria-hidden="true" />
            Download PDF
          </button>
          <button type="button" className="exp-desk-btn exp-desk-btn-secondary" onClick={() => window.print()}>
            <Printer size={16} aria-hidden="true" />
            Print
          </button>
        </div>
      </div>

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
          <select className="pl-select" value={categoryFilter} onChange={(e) => setCategoryFilter(e.target.value)}>
            <option value="">All Categories</option>
            {categoryOptions.map((option) => (
              <option key={option} value={option}>{option}</option>
            ))}
          </select>
          <select className="pl-select" value={brandFilter} onChange={(e) => setBrandFilter(e.target.value)}>
            <option value="">All Brands</option>
            {brandOptions.map((option) => (
              <option key={option} value={option}>{option}</option>
            ))}
          </select>
          <button
            type="button"
            className={`pl-filter-toggle${showMoreFilters ? ' is-active' : ''}`}
            onClick={() => setShowMoreFilters((value) => !value)}
          >
            <Filter size={16} aria-hidden="true" />
            More Filters
          </button>
        </div>

        {showMoreFilters && (
          <div className="pl-more-filters">
            <label>
              <span>Min price</span>
              <input type="number" step="any" value={minPrice} onChange={(e) => setMinPrice(e.target.value)} placeholder="0" />
            </label>
            <label>
              <span>Max price</span>
              <input type="number" step="any" value={maxPrice} onChange={(e) => setMaxPrice(e.target.value)} placeholder="Any" />
            </label>
          </div>
        )}

        <div className="pl-filter-chips">
          {activeOnly && (
            <span className="pl-chip">
              Active items only
              <button type="button" onClick={() => setActiveOnly(false)} aria-label="Remove filter">
                <X size={12} />
              </button>
            </span>
          )}
          {!activeOnly && (
            <button type="button" className="pl-chip-link" onClick={() => setActiveOnly(true)}>
              Show active items only
            </button>
          )}
          {(search || categoryFilter || brandFilter || minPrice || maxPrice || !activeOnly) && (
            <button type="button" className="pl-clear-link" onClick={clearAllFilters}>Clear all</button>
          )}
        </div>
      </div>

      <section className="exp-desk-results pl-table-section">
        {filteredProducts.length === 0 ? (
          <div className="exp-desk-empty pl-empty">
            <Tag className="pl-empty-icon" aria-hidden="true" />
            <p className="exp-desk-empty-title">No products found</p>
            <p className="exp-desk-empty-sub">Try adjusting search or filters.</p>
          </div>
        ) : (
          <>
            <div className="exp-desk-table-wrap">
              <table className="exp-desk-table pl-table">
                <thead>
                  <tr>
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
                  {paginatedProducts.map((product, index) => {
                    const rowNum = (currentPage - 1) * pageSize + index + 1;
                    return (
                      <tr key={product.id}>
                        <td className="pl-col-num">{rowNum}</td>
                        <td className="pl-col-image">
                          <img
                            src={product.image_url || PLACEHOLDER_IMG}
                            alt=""
                            className="pl-product-image"
                            onError={(event) => { event.currentTarget.src = PLACEHOLDER_IMG; }}
                          />
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
                            className={`pl-price-input${product.edited_price !== product.selling_price ? ' is-edited' : ''}`}
                          />
                          {product.edited_price !== product.selling_price && (
                            <div className="pl-price-was">Was {formatCurrency(product.selling_price, currency)}</div>
                          )}
                        </td>
                        <td className="pl-col-action" data-pl-row-menu>
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
              <div className="pl-pagination-controls">
                <div className="pl-page-buttons">
                  <button type="button" disabled={currentPage <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>
                    <ChevronLeft size={16} />
                  </button>
                  {visiblePageNumbers.map((item, idx) => (
                    item === 'ellipsis' ? (
                      <span key={`ellipsis-${idx}`} className="pl-page-ellipsis">...</span>
                    ) : (
                      <button
                        key={`page-${item}-${idx}`}
                        type="button"
                        className={currentPage === item ? 'is-active' : ''}
                        onClick={() => setPage(item)}
                      >
                        {item}
                      </button>
                    )
                  ))}
                  <button type="button" disabled={currentPage >= totalPages} onClick={() => setPage((p) => Math.min(totalPages, p + 1))}>
                    <ChevronRight size={16} />
                  </button>
                </div>
                <select value={pageSize} onChange={(e) => setPageSize(Number(e.target.value))} className="pl-select pl-page-size">
                  {[10, 25, 50, 100].map((size) => (
                    <option key={size} value={size}>{size} / page</option>
                  ))}
                </select>
              </div>
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
            <p className="pl-modal-help">This will appear at the top of the price list.</p>
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
            <p>Compiling {products.length} products with images...</p>
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
