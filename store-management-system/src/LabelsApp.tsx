import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  AlertCircle,
  CheckCircle2,
  ChevronLeft,
  Eye,
  FileDown,
  Image as ImageIcon,
  Loader2,
  Package,
  Share2,
  SlidersHorizontal,
  Star,
  X,
} from 'lucide-react';
import { fetchLabelProducts, fetchLabelsInit } from './api';
import type { LabelPerPageOption, LabelProduct, LabelsInitData } from './types';
import './labels.css';

type PlacedFilter = 'all' | 'placed' | 'unplaced';

function parseDownloadError(response: Response, rawText: string): string {
  const text = rawText.trim();
  if (response.status === 429 || /too many requests/i.test(text)) {
    return 'Too many download requests. Please wait a minute, then try again.';
  }
  if (text.startsWith('{')) {
    try {
      const json = JSON.parse(text) as { message?: string; error?: string };
      return json.message || json.error || 'Failed to generate PDF.';
    } catch {
      // fall through
    }
  }
  return text || 'Failed to generate PDF.';
}

function absoluteImageUrl(url: string): string {
  if (!url.trim()) return '';
  if (/^https?:\/\//i.test(url)) return url;
  try {
    return new URL(url, window.location.href).href;
  } catch {
    return url;
  }
}

function LabelPreviewContent({
  product,
  perPage,
}: {
  product: LabelProduct;
  perPage: number;
}) {
  const image = absoluteImageUrl(product.imageUrl);
  return (
    <div className={`sms-label-preview-sheet layout-${perPage}`}>
      {image ? (
        <img src={image} alt="" className="sms-label-preview-image" />
      ) : (
        <div className="sms-label-preview-image sms-label-preview-image--empty">
          <ImageIcon className="w-8 h-8" aria-hidden="true" />
        </div>
      )}
      <div className="sms-label-preview-details">
        <div className="sms-label-preview-line">PRODUCT CODE: {product.productCode}</div>
        <div className="sms-label-preview-line">PRODUCT NAME : {product.name}</div>
        <div className="sms-label-preview-line">CATEGORY : {product.categoryName}</div>
        <div className="sms-label-preview-line">SIZE(s) :</div>
      </div>
    </div>
  );
}

export default function LabelsApp() {
  const [init, setInit] = useState<LabelsInitData | null>(null);
  const [products, setProducts] = useState<LabelProduct[]>([]);
  const [placedCount, setPlacedCount] = useState(0);
  const [loading, setLoading] = useState(true);
  const [loadingProducts, setLoadingProducts] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [appliedSearch, setAppliedSearch] = useState('');
  const [appliedCategoryId, setAppliedCategoryId] = useState(0);
  const [appliedPlaced, setAppliedPlaced] = useState<PlacedFilter>('all');
  const [perPage, setPerPage] = useState(1);

  const [draftSearch, setDraftSearch] = useState('');
  const [draftCategoryId, setDraftCategoryId] = useState(0);
  const [draftPlaced, setDraftPlaced] = useState<PlacedFilter>('all');
  const [draftPerPage, setDraftPerPage] = useState(1);
  const [filtersOpen, setFiltersOpen] = useState(false);

  const [selected, setSelected] = useState<Record<string, number>>({});
  const [previewModalProduct, setPreviewModalProduct] = useState<LabelProduct | null>(null);
  const [starLoadingId, setStarLoadingId] = useState<string | null>(null);

  const [downloading, setDownloading] = useState(false);
  const [downloadOverlayOpen, setDownloadOverlayOpen] = useState(false);
  const [downloadStatus, setDownloadStatus] = useState('Processing...');
  const [downloadProgress, setDownloadProgress] = useState(0);
  const [downloadError, setDownloadError] = useState<string | null>(null);

  const [successOpen, setSuccessOpen] = useState(false);
  const [successFilename, setSuccessFilename] = useState('');
  const [successBlob, setSuccessBlob] = useState<Blob | null>(null);

  const loadProducts = useCallback(async (filters: {
    search: string;
    categoryId: number;
    placed: PlacedFilter;
  }) => {
    setLoadingProducts(true);
    setError(null);
    try {
      const data = await fetchLabelProducts({
        search: filters.search || undefined,
        categoryId: filters.categoryId || undefined,
        placed: filters.placed,
      });
      setProducts(data.products);
      setPlacedCount(data.placedCount);
      setSelected((prev) => {
        const next: Record<string, number> = {};
        for (const [id, qty] of Object.entries(prev)) {
          const product = data.products.find((p) => p.id === id);
          if (product && !product.labelPlaced) {
            next[id] = qty;
          }
        }
        return next;
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load products');
    } finally {
      setLoadingProducts(false);
    }
  }, []);

  useEffect(() => {
    (async () => {
      setLoading(true);
      try {
        const data = await fetchLabelsInit();
        setInit(data);
        setPerPage(data.perPageOptions[0]?.value ?? 1);
        setDraftPerPage(data.perPageOptions[0]?.value ?? 1);
        await loadProducts({ search: '', categoryId: 0, placed: 'all' });
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to initialize labels');
      } finally {
        setLoading(false);
      }
    })();
  }, [loadProducts]);

  const activeFilterCount = useMemo(() => {
    let count = 0;
    if (appliedSearch) count += 1;
    if (appliedCategoryId > 0) count += 1;
    if (appliedPlaced !== 'all') count += 1;
    return count;
  }, [appliedSearch, appliedCategoryId, appliedPlaced]);

  const selectableProducts = useMemo(
    () => products.filter((p) => !p.labelPlaced),
    [products]
  );

  const allSelected = selectableProducts.length > 0
    && selectableProducts.every((p) => selected[p.id] !== undefined);

  const selectedCount = Object.keys(selected).length;

  const applyFilters = async () => {
    setAppliedSearch(draftSearch.trim());
    setAppliedCategoryId(draftCategoryId);
    setAppliedPlaced(draftPlaced);
    setPerPage(draftPerPage);
    setFiltersOpen(false);
    await loadProducts({
      search: draftSearch.trim(),
      categoryId: draftCategoryId,
      placed: draftPlaced,
    });
  };

  const clearFilters = async () => {
    setDraftSearch('');
    setDraftCategoryId(0);
    setDraftPlaced('all');
    setDraftPerPage(init?.perPageOptions[0]?.value ?? 1);
    setAppliedSearch('');
    setAppliedCategoryId(0);
    setAppliedPlaced('all');
    setPerPage(init?.perPageOptions[0]?.value ?? 1);
    setFiltersOpen(false);
    await loadProducts({ search: '', categoryId: 0, placed: 'all' });
  };

  const toggleSelectAll = (checked: boolean) => {
    if (!checked) {
      setSelected({});
      return;
    }
    const next: Record<string, number> = {};
    selectableProducts.forEach((p) => {
      next[p.id] = selected[p.id] ?? 1;
    });
    setSelected(next);
  };

  const toggleProduct = (product: LabelProduct, checked: boolean) => {
    setSelected((prev) => {
      const next = { ...prev };
      if (checked) {
        next[product.id] = prev[product.id] ?? 1;
      } else {
        delete next[product.id];
      }
      return next;
    });
  };

  const setProductQty = (productId: string, qty: number) => {
    setSelected((prev) => ({
      ...prev,
      [productId]: Math.max(1, Math.min(99, qty)),
    }));
  };

  const togglePlaced = async (product: LabelProduct) => {
    if (!init?.labelStarUrl) return;
    setStarLoadingId(product.id);
    try {
      const body = new FormData();
      body.append('product_id', product.id);
      body.append('placed', product.labelPlaced ? '0' : '1');
      const response = await fetch(init.labelStarUrl, {
        method: 'POST',
        body,
        credentials: 'same-origin',
      });
      const data = await response.json();
      if (!response.ok || !data.success) {
        throw new Error(data.error || 'Could not update label status.');
      }
      setProducts((rows) =>
        rows.map((row) =>
          row.id === product.id ? { ...row, labelPlaced: Boolean(data.placed) } : row
        )
      );
      setPlacedCount((count) => count + (data.placed ? 1 : -1));
      if (data.placed) {
        setSelected((prev) => {
          const next = { ...prev };
          delete next[product.id];
          return next;
        });
        if (previewModalProduct?.id === product.id) {
          setPreviewModalProduct(null);
        }
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not update label status.');
    } finally {
      setStarLoadingId(null);
    }
  };

  const downloadPdf = async () => {
    if (!init?.labelDownloadUrl || selectedCount === 0 || downloading) return;

    setDownloading(true);
    setDownloadOverlayOpen(true);
    setDownloadError(null);
    setDownloadStatus('Processing...');
    setDownloadProgress(15);

    try {
      const formData = new FormData();
      formData.append('per_page', String(perPage));
      Object.entries(selected).forEach(([id, qty]) => {
        formData.append('product_ids[]', id);
        formData.append(`quantities[${id}]`, String(qty));
      });

      setDownloadProgress(35);
      setDownloadStatus('Preparing your PDF...');

      const response = await fetch(init.labelDownloadUrl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });

      const contentType = (response.headers.get('Content-Type') || '').toLowerCase();
      if (!response.ok || !contentType.includes('application/pdf')) {
        const errorText = await response.text();
        throw new Error(parseDownloadError(response, errorText));
      }

      setDownloadProgress(75);
      setDownloadStatus('Almost ready...');
      const blob = await response.blob();
      if (!blob || blob.size === 0) {
        throw new Error('Generated PDF is empty.');
      }

      const disposition = response.headers.get('Content-Disposition') || '';
      const match = disposition.match(/filename="?([^"]+)"?/i);
      const filename = match?.[1] || `product-labels-${new Date().toISOString().slice(0, 10)}.pdf`;

      const objectUrl = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = objectUrl;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(objectUrl);

      setDownloadProgress(100);
      setDownloadStatus('Download complete!');
      setSuccessBlob(blob);
      setSuccessFilename(filename);
      if (window.matchMedia('(max-width: 767.98px)').matches) {
        setSuccessOpen(true);
      } else {
        await new Promise((resolve) => setTimeout(resolve, 900));
      }
    } catch (err) {
      const message = err instanceof Error ? err.message : 'PDF download failed.';
      setDownloadError(message);
      setDownloadStatus(message);
      await new Promise((resolve) => setTimeout(resolve, 2500));
    } finally {
      setDownloading(false);
      if (!successOpen) {
        setDownloadOverlayOpen(false);
        setDownloadProgress(0);
        setDownloadStatus('Processing...');
        setDownloadError(null);
      }
    }
  };

  if (loading) {
    return (
      <div className="sms-desk-page sms-desk-boot-loading" role="status">
        <Loader2 className="sms-desk-boot-spinner" aria-hidden="true" />
        <span>Loading labels...</span>
      </div>
    );
  }

  return (
    <div className="sms-desk-page sms-labels-page">
      <div className="sms-labels-head">
        <h1 className="sms-labels-title">Product Labels</h1>
        <p className="sms-labels-sub">Create printable PDF labels with product image and code</p>
      </div>

      {error && (
        <div className="sms-desk-flash sms-desk-flash-error" role="alert">
          <AlertCircle className="w-4 h-4 shrink-0" />
          <span>{error}</span>
        </div>
      )}

      <div className="sms-label-toolbar">
        <div className="sms-filter-wrap">
          <button
            type="button"
            className="sms-filters-pill-btn"
            onClick={() => setFiltersOpen((open) => !open)}
            aria-expanded={filtersOpen}
          >
            <SlidersHorizontal className="w-4 h-4" aria-hidden="true" />
            <span>Filters</span>
            {activeFilterCount > 0 && <span className="sms-filter-badge">{activeFilterCount}</span>}
          </button>

          {filtersOpen && (
            <div className="sms-modal-backdrop" role="presentation" onClick={() => setFiltersOpen(false)}>
              <aside
                className="sms-filter-panel"
                role="dialog"
                aria-modal="true"
                aria-labelledby="sms-label-filters-title"
                onClick={(e) => e.stopPropagation()}
                style={{
                  position: 'fixed',
                  left: 0,
                  top: 0,
                  bottom: 0,
                  width: 'min(380px, 92vw)',
                  zIndex: 10051,
                  transform: 'none',
                }}
              >
                <div className="sms-filter-panel-head">
                  <button
                    type="button"
                    className="sms-filter-close-btn"
                    onClick={() => setFiltersOpen(false)}
                    aria-label="Close filters"
                  >
                    <ChevronLeft className="w-4 h-4" />
                  </button>
                  <h2 id="sms-label-filters-title">Filters</h2>
                </div>
                <div className="sms-filter-panel-body">
                  <div className="sms-filter-field">
                    <label htmlFor="label-filter-search">Search</label>
                    <input
                      id="label-filter-search"
                      type="text"
                      value={draftSearch}
                      onChange={(e) => setDraftSearch(e.target.value)}
                      placeholder="Product name or code..."
                    />
                  </div>
                  <div className="sms-filter-field">
                    <label htmlFor="label-filter-category">Category</label>
                    <select
                      id="label-filter-category"
                      value={draftCategoryId}
                      onChange={(e) => setDraftCategoryId(Number(e.target.value))}
                    >
                      <option value={0}>All Categories</option>
                      {(init?.categories ?? []).map((cat) => (
                        <option key={cat.id} value={cat.id}>{cat.name}</option>
                      ))}
                    </select>
                  </div>
                  <div className="sms-filter-field">
                    <label htmlFor="label-filter-placed">Label status</label>
                    <select
                      id="label-filter-placed"
                      value={draftPlaced}
                      onChange={(e) => setDraftPlaced(e.target.value as PlacedFilter)}
                    >
                      <option value="all">All products</option>
                      <option value="unplaced">Not placed yet</option>
                      <option value="placed">Already placed</option>
                    </select>
                  </div>
                  <div className="sms-filter-field">
                    <label htmlFor="label-filter-per-page">Products per page</label>
                    <select
                      id="label-filter-per-page"
                      value={draftPerPage}
                      onChange={(e) => setDraftPerPage(Number(e.target.value))}
                    >
                      {(init?.perPageOptions ?? []).map((opt: LabelPerPageOption) => (
                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                      ))}
                    </select>
                  </div>
                  <div className="sms-filter-actions">
                    <button type="button" className="sms-filter-apply-btn" onClick={applyFilters}>
                      Apply Filters
                    </button>
                    <button type="button" className="sms-filter-clear-btn" onClick={clearFilters}>
                      Clear
                    </button>
                  </div>
                </div>
              </aside>
            </div>
          )}
        </div>

        <div className="sms-label-actions">
          <label className="text-sm text-slate-600 flex items-center gap-2">
            <input
              type="checkbox"
              className="rounded border-slate-300"
              checked={allSelected}
              onChange={(e) => toggleSelectAll(e.target.checked)}
            />
            Select all
          </label>
          <button
            type="button"
            className="sms-label-btn-generate"
            disabled={selectedCount === 0 || downloading}
            onClick={downloadPdf}
          >
            {downloading ? <Loader2 className="w-4 h-4 animate-spin" /> : <FileDown className="w-4 h-4" />}
            Download PDF
          </button>
        </div>
      </div>

      <div className="sms-label-list-card">
        <div className="sms-label-table-head">
          <h3 className="font-bold text-slate-700">Products</h3>
          <div className="flex items-center gap-2 flex-wrap">
            <span className="sms-label-badge sms-label-badge--amber">
              <Star className="w-3 h-3" aria-hidden="true" />
              {placedCount} placed
            </span>
            <span className="sms-label-badge sms-label-badge--slate">
              {products.length} items
              {loadingProducts ? ' - refreshing...' : ''}
            </span>
          </div>
        </div>

        {products.length === 0 ? (
          <div className="sms-label-list-empty">No products found.</div>
        ) : (
          <ul className="sms-label-list">
            {products.map((product) => {
              const isSelected = selected[product.id] !== undefined;
              const hidden = product.labelPlaced;
              return (
                <li
                  key={product.id}
                  className={`sms-label-list-item${product.labelPlaced ? ' is-placed' : ''}${isSelected ? ' is-selected' : ''}`}
                >
                  <div className="sms-label-list-main">
                    <button
                      type="button"
                      className={`sms-label-star-btn${product.labelPlaced ? ' is-placed' : ''}`}
                      disabled={starLoadingId === product.id}
                      onClick={() => togglePlaced(product)}
                      aria-label={product.labelPlaced ? 'Label placed' : 'Mark label as placed'}
                    >
                      <Star className={`w-4 h-4${product.labelPlaced ? ' fill-current' : ''}`} />
                    </button>

                    {!hidden && (
                      <input
                        type="checkbox"
                        className="sms-label-list-check"
                        checked={isSelected}
                        onChange={(e) => toggleProduct(product, e.target.checked)}
                        aria-label={`Select ${product.name}`}
                      />
                    )}

                    {product.imageUrl ? (
                      <img src={product.imageUrl} alt="" className="sms-label-thumb" loading="lazy" />
                    ) : (
                      <div className="sms-label-thumb-fallback">
                        <Package className="w-4 h-4" aria-hidden="true" />
                      </div>
                    )}

                    <div className="sms-label-list-copy">
                      <div className="sms-label-list-name">{product.name}</div>
                      <div className="sms-label-list-meta">
                        <span>{product.categoryName}</span>
                        <span className="sms-label-list-code">{product.productCode}</span>
                      </div>
                    </div>
                  </div>

                  <div className="sms-label-list-actions">
                    {!hidden && (
                      <label className="sms-label-qty-wrap">
                        <span className="sms-label-qty-label">Labels</span>
                        <input
                          type="number"
                          className="sms-label-qty-input"
                          value={selected[product.id] ?? 1}
                          min={1}
                          max={99}
                          disabled={!isSelected}
                          onChange={(e) => setProductQty(product.id, Number(e.target.value))}
                        />
                      </label>
                    )}
                    <button
                      type="button"
                      className="sms-desk-btn sms-desk-btn-secondary sms-desk-btn-sm sms-btn-rounded"
                      onClick={() => setPreviewModalProduct(product)}
                    >
                      <Eye className="w-3.5 h-3.5" />
                      Preview
                    </button>
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </div>

      {previewModalProduct && (
        <div className="sms-modal-backdrop" role="presentation" onClick={() => setPreviewModalProduct(null)}>
          <div
            className="sms-modal sms-label-preview-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="sms-label-preview-title"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="sms-modal-head">
              <div>
                <h2 id="sms-label-preview-title" className="sms-modal-title">Label Preview</h2>
                <p className="sms-modal-sub">{previewModalProduct.name}</p>
              </div>
              <button
                type="button"
                className="sms-modal-close sms-modal-close-circle"
                onClick={() => setPreviewModalProduct(null)}
                aria-label="Close preview"
              >
                <X className="w-4 h-4" />
              </button>
            </div>
            <div className="sms-modal-body">
              <LabelPreviewContent product={previewModalProduct} perPage={perPage} />
              <p className="sms-label-preview-hint">
                Layout uses <strong>{perPage}</strong> product{perPage === 1 ? '' : 's'} per PDF page.
                Select products and click <strong>Download PDF</strong> to generate labels.
              </p>
            </div>
          </div>
        </div>
      )}

      {(downloadOverlayOpen || downloadError) && (
        <div className={`sms-label-download-overlay${downloadOverlayOpen || downloadError ? ' is-active' : ''}`} role="status">
          <div className="sms-label-download-modal">
            {downloadError ? (
              <AlertCircle className="w-10 h-10 text-red-600 mx-auto mb-3" />
            ) : (
              <div className="sms-label-download-spinner" aria-hidden="true" />
            )}
            <p className="font-semibold text-slate-800">{downloadError || downloadStatus}</p>
            {!downloadError && (
              <div className="sms-download-progress mt-4">
                <div className="sms-download-bar" style={{ width: `${downloadProgress}%` }} />
              </div>
            )}
          </div>
        </div>
      )}

      {successOpen && successBlob && (
        <div className="sms-modal-backdrop" role="presentation" onClick={() => setSuccessOpen(false)}>
          <div className="sms-status-popup" onClick={(e) => e.stopPropagation()}>
            <button type="button" className="sms-modal-close sms-modal-close-circle" onClick={() => setSuccessOpen(false)} aria-label="Close">
              <X className="w-4 h-4" />
            </button>
            <div className="sms-status-popup-icon sms-status-popup-icon--success">
              <CheckCircle2 className="w-7 h-7" />
            </div>
            <h2 className="sms-status-popup-title">Download successful!</h2>
            <p className="sms-status-popup-message">{successFilename}</p>
            {navigator.share && (
              <button
                type="button"
                className="sms-btn-primary sms-btn-rounded w-full mb-2"
                onClick={async () => {
                  try {
                    const file = new File([successBlob], successFilename, { type: 'application/pdf' });
                    await navigator.share({ files: [file], title: 'Product Labels' });
                  } catch {
                    // user cancelled
                  }
                }}
              >
                <Share2 className="w-4 h-4 inline mr-2" />
                Share PDF
              </button>
            )}
            <button type="button" className="sms-desk-btn sms-desk-btn-secondary sms-btn-rounded w-full" onClick={() => setSuccessOpen(false)}>
              Done
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
