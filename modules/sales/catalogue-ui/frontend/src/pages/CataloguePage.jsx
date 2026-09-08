import { useEffect, useMemo, useRef, useState } from 'react'
import { CFG } from '../config.js'

const PAGE_SIZE_OPTIONS = [10, 20, 50, 100, 200]
const PAGINATION_WINDOW = 5

function selectionDraftKey(storageKey) {
  return `${storageKey}_draft`
}

function readStoredSelection(storageKey) {
  const map = {}
  const ingest = (list) => {
    if (!Array.isArray(list)) return
    list.forEach((row) => {
      const id = Number(row?.product_id ?? row?.id ?? 0)
      const qty = Math.max(0, parseInt(row?.quantity, 10) || 0)
      if (id > 0 && qty > 0) map[id] = qty
    })
  }
  try {
    ingest(JSON.parse(localStorage.getItem(selectionDraftKey(storageKey)) || '[]'))
  } catch {
    // ignore
  }
  try {
    ingest(JSON.parse(localStorage.getItem(storageKey) || '[]'))
  } catch {
    // ignore
  }
  try {
    const selected = new URLSearchParams(window.location.search).get('selected') || ''
    selected.split(',').forEach((part) => {
      const id = Number(String(part).trim())
      if (id > 0 && map[id] == null) map[id] = 1
    })
  } catch {
    // ignore
  }
  return map
}

function writeSelectionDraft(storageKey, selectedQtys) {
  const items = Object.entries(selectedQtys || {})
    .filter(([, qty]) => (Number(qty) || 0) > 0)
    .map(([id, quantity]) => ({
      product_id: Number(id),
      quantity: Math.max(1, Number(quantity) || 1),
    }))
  try {
    localStorage.setItem(selectionDraftKey(storageKey), JSON.stringify(items))
  } catch {
    // ignore
  }
}

function paginationPageNumbers(currentPage, totalPages, windowSize = PAGINATION_WINDOW) {
  if (totalPages <= 1) return totalPages >= 1 ? [1] : []
  if (totalPages <= windowSize) return Array.from({ length: totalPages }, (_, i) => i + 1)
  const block = Math.floor((currentPage - 1) / windowSize)
  const start = block * windowSize + 1
  const end = Math.min(start + windowSize - 1, totalPages)
  return Array.from({ length: end - start + 1 }, (_, i) => start + i)
}

function stockBadge(qty) {
  const n = parseFloat(qty) || 0
  if (n <= 0) return { label: 'Out of stock', cls: 'is-out' }
  if (n <= 5) return { label: 'Low stock', cls: 'is-low' }
  return { label: 'In stock', cls: 'is-in' }
}

function formatPrice(value) {
  return new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value || 0)
}

function ProductImage({ src, alt, className, placeholderImage, variant = 'grid' }) {
  const [loaded, setLoaded] = useState(false)
  const [currentSrc, setCurrentSrc] = useState(src || placeholderImage)
  const imgRef = useRef(null)

  useEffect(() => {
    setLoaded(false)
    setCurrentSrc(src || placeholderImage)
  }, [src, placeholderImage])

  useEffect(() => {
    const el = imgRef.current
    if (el?.complete && el.naturalWidth > 0) setLoaded(true)
  }, [currentSrc])

  return (
    <span className={`cat-img-wrap cat-img-wrap--${variant}${loaded ? ' is-loaded' : ''}`}>
      {!loaded && <span className="cat-img-skeleton" aria-hidden="true" />}
      <img
        ref={imgRef}
        src={currentSrc}
        alt={alt || ''}
        className={className}
        loading="lazy"
        decoding="async"
        onLoad={() => setLoaded(true)}
        onError={(e) => {
          if (placeholderImage && e.currentTarget.src !== placeholderImage) {
            setLoaded(false)
            setCurrentSrc(placeholderImage)
            return
          }
          setLoaded(true)
        }}
      />
    </span>
  )
}

function ProductCard({ product, quantity, placeholderImage, onQtyChange, onToggleCheck }) {
  const { id, product_code, name, selling_price, stock_quantity } = product
  const img = product.image_url || placeholderImage
  const badge = stockBadge(stock_quantity)
  const checked = quantity > 0

  return (
    <div className={`cat-product${checked ? ' is-selected' : ''}`}>
      <div className="cat-product-media">
        <label className="cat-product-check">
          <input type="checkbox" className="cat-checkbox" checked={checked} onChange={() => onToggleCheck(id)} aria-label={`Select ${name}`} />
        </label>
        <button type="button" className="cat-product-image-btn" onClick={() => onToggleCheck(id)} title={name}>
          <ProductImage
            src={img}
            alt={name}
            className="cat-product-image"
            placeholderImage={placeholderImage}
            variant="grid"
          />
        </button>
        <div className="cat-qty cat-qty--under-image">
          <button type="button" onClick={() => onQtyChange(id, Math.max(0, quantity - 1))} aria-label="Decrease quantity">
            <i className="fas fa-minus" />
          </button>
          <input
            type="number"
            min="0"
            value={quantity}
            onChange={(e) => onQtyChange(id, Math.max(0, parseInt(e.target.value, 10) || 0))}
            aria-label="Quantity"
          />
          <button type="button" onClick={() => onQtyChange(id, quantity + 1)} aria-label="Increase quantity">
            <i className="fas fa-plus" />
          </button>
        </div>
      </div>
      <div className="cat-product-meta">
        <div className="cat-product-code">{product_code || 'N/A'}</div>
        <h3 className="cat-product-name">{name}</h3>
        <span className={`cat-stock-pill ${badge.cls}`}>{badge.label}</span>
        <div className="cat-product-footer">
          <span className="cat-product-price">{formatPrice(selling_price)}</span>
        </div>
      </div>
    </div>
  )
}

function ProductListRow({ product, quantity, placeholderImage, onQtyChange, onToggleCheck }) {
  const { id, product_code, name, selling_price, stock_quantity } = product
  const img = product.image_url || placeholderImage
  const badge = stockBadge(stock_quantity)

  return (
    <tr>
      <td>
        <input type="checkbox" className="cat-checkbox" checked={quantity > 0} onChange={() => onToggleCheck(id)} />
      </td>
      <td>
        <ProductImage
          src={img}
          alt=""
          className="cat-list-thumb"
          placeholderImage={placeholderImage}
          variant="list"
        />
      </td>
      <td className="cat-mono">{product_code || '-'}</td>
      <td className="cat-list-name">{name}</td>
      <td>
        <span className={`cat-stock-pill ${badge.cls}`}>{badge.label}</span>
      </td>
      <td className="text-right cat-product-price">{formatPrice(selling_price)}</td>
      <td className="text-right">
        <div className="cat-qty cat-qty--inline">
          <button type="button" onClick={() => onQtyChange(id, Math.max(0, quantity - 1))}>
            <i className="fas fa-minus" />
          </button>
          <input
            type="number"
            min="0"
            value={quantity}
            onChange={(e) => onQtyChange(id, Math.max(0, parseInt(e.target.value, 10) || 0))}
          />
          <button type="button" onClick={() => onQtyChange(id, quantity + 1)}>
            <i className="fas fa-plus" />
          </button>
        </div>
      </td>
    </tr>
  )
}

export default function CataloguePage() {
  const initial = CFG.data || {}
  const [data, setData] = useState(initial)
  const [loading, setLoading] = useState(!initial.products)

  const products = data.products || []
  const categories = data.categories || []
  const returnUrl = data.returnUrl || ''
  const docLabel = data.docLabel || 'Quotation'
  const addSelectedLabel = data.addSelectedLabel || 'quotation'
  const placeholderImage = data.placeholderImage || ''
  const storageKey = data.storageKey || 'sales_catalogue_items'

  const [searchTerm, setSearchTerm] = useState('')
  const [categoryFilter, setCategoryFilter] = useState('')
  const [stockFilter, setStockFilter] = useState('')
  const [sortBy, setSortBy] = useState('default')
  const [viewMode, setViewMode] = useState('grid')
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(200)
  const [selectedQtys, setSelectedQtys] = useState(() => readStoredSelection(storageKey))
  const skipSelectionPersist = useRef(true)

  useEffect(() => {
    if (skipSelectionPersist.current) {
      skipSelectionPersist.current = false
      return
    }
    writeSelectionDraft(storageKey, selectedQtys)
  }, [storageKey, selectedQtys])

  useEffect(() => {
    if (initial.products) return undefined
    if (!CFG.initUrl) {
      setLoading(false)
      return undefined
    }
    let alive = true
    ;(async () => {
      try {
        const res = await fetch(CFG.initUrl, { headers: { Accept: 'application/json' } })
        const payload = await res.json()
        if (alive && payload?.ok && payload.data) setData(payload.data)
      } catch {
        // keep empty state
      } finally {
        if (alive) setLoading(false)
      }
    })()
    return () => { alive = false }
  }, [initial.products])

  useEffect(() => { setPage(1) }, [searchTerm, categoryFilter, stockFilter, sortBy, pageSize])

  const filteredProducts = useMemo(() => {
    let list = [...products]
    const q = searchTerm.trim().toLowerCase()
    if (q) {
      list = list.filter((p) =>
        (p.name || '').toLowerCase().includes(q)
        || (p.product_code || '').toLowerCase().includes(q)
        || (p.description || '').toLowerCase().includes(q))
    }
    if (categoryFilter) list = list.filter((p) => (p.category_name || '') === categoryFilter)
    if (stockFilter === 'in') list = list.filter((p) => (parseFloat(p.stock_quantity) || 0) > 5)
    else if (stockFilter === 'low') {
      list = list.filter((p) => {
        const n = parseFloat(p.stock_quantity) || 0
        return n > 0 && n <= 5
      })
    } else if (stockFilter === 'out') list = list.filter((p) => (parseFloat(p.stock_quantity) || 0) <= 0)

    if (sortBy === 'name') list.sort((a, b) => (a.name || '').localeCompare(b.name || ''))
    else if (sortBy === 'price_asc') list.sort((a, b) => (parseFloat(a.selling_price) || 0) - (parseFloat(b.selling_price) || 0))
    else if (sortBy === 'price_desc') list.sort((a, b) => (parseFloat(b.selling_price) || 0) - (parseFloat(a.selling_price) || 0))
    else if (sortBy === 'stock') list.sort((a, b) => (parseFloat(b.stock_quantity) || 0) - (parseFloat(a.stock_quantity) || 0))
    return list
  }, [products, searchTerm, categoryFilter, stockFilter, sortBy])

  const pageCount = Math.max(1, Math.ceil(filteredProducts.length / pageSize))
  const safePage = Math.min(page, pageCount)
  const pageNumbers = useMemo(() => paginationPageNumbers(safePage, pageCount), [safePage, pageCount])
  const pagedProducts = useMemo(() => {
    const start = (safePage - 1) * pageSize
    return filteredProducts.slice(start, start + pageSize)
  }, [filteredProducts, safePage, pageSize])

  const totalSelectedQty = useMemo(
    () => Object.values(selectedQtys).reduce((s, q) => s + (q > 0 ? 1 : 0), 0),
    [selectedQtys],
  )

  const handleQtyChange = (id, qty) => setSelectedQtys((prev) => ({ ...prev, [id]: qty }))
  const handleToggleCheck = (id) => {
    setSelectedQtys((prev) => {
      const cur = prev[id] || 0
      return { ...prev, [id]: cur > 0 ? 0 : 1 }
    })
  }

  function handleSendToDoc() {
    const items = products
      .filter((p) => (selectedQtys[p.id] || 0) > 0)
      .map((p) => ({ product_id: p.id, quantity: selectedQtys[p.id] }))
    if (items.length === 0) {
      window.alert('Please select at least one product.')
      return
    }
    writeSelectionDraft(storageKey, selectedQtys)
    localStorage.setItem(storageKey, JSON.stringify(items))
    const pickedIds = items.map((i) => Number(i.product_id) || 0).filter((id) => id > 0)
    let targetUrl = returnUrl
    try {
      const url = new URL(returnUrl, window.location.origin)
      if (pickedIds.length > 0) {
        url.searchParams.set('catalogue_product_ids', pickedIds.join(','))
      }
      targetUrl = url.toString()
    } catch {
      if (pickedIds.length > 0) {
        targetUrl += `${returnUrl.includes('?') ? '&' : '?'}catalogue_product_ids=${encodeURIComponent(pickedIds.join(','))}`
      }
    }
    window.location.href = targetUrl
  }

  const rangeStart = filteredProducts.length === 0 ? 0 : (safePage - 1) * pageSize + 1
  const rangeEnd = Math.min(safePage * pageSize, filteredProducts.length)

  if (loading) {
    return (
      <div className="cat-loading">
        <i className="fas fa-spinner fa-spin" />
        <p>Loading catalogue...</p>
      </div>
    )
  }

  return (
    <div className="cat-page">
      <div className="cat-toolbar">
        <div className="cat-toolbar-left">
          <a href={returnUrl} className="cat-back" title="Back">
            <i className="fas fa-arrow-left" />
          </a>
          <div>
            <div className="cat-title-row">
              <h1 className="cat-title">Sales Catalogue</h1>
              <span className="cat-doc-badge">{docLabel}</span>
            </div>
            <p className="cat-subtitle">
              Select quantities then click <strong>Add selected</strong> to build your {addSelectedLabel}.
            </p>
          </div>
        </div>
        <button type="button" onClick={handleSendToDoc} className="cat-add-btn">
          <i className="fas fa-cart-shopping" />
          Add selected ({totalSelectedQty})
        </button>
      </div>

      <div className="cat-filters">
        <div className="cat-filter-search">
          <label className="cat-filter-label">Search</label>
          <div className="cat-search-wrap">
            <i className="fas fa-search" />
            <input
              type="text"
              placeholder="Search products by name, code or keyword..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
            />
          </div>
        </div>
        <div>
          <label className="cat-filter-label">Category</label>
          <select className="cat-filter-select" value={categoryFilter} onChange={(e) => setCategoryFilter(e.target.value)}>
            <option value="">All categories</option>
            {categories.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>
        <div>
          <label className="cat-filter-label">Stock status</label>
          <select className="cat-filter-select" value={stockFilter} onChange={(e) => setStockFilter(e.target.value)}>
            <option value="">All stock</option>
            <option value="in">In stock</option>
            <option value="low">Low stock</option>
            <option value="out">Out of stock</option>
          </select>
        </div>
        <div>
          <label className="cat-filter-label">Sort by</label>
          <select className="cat-filter-select" value={sortBy} onChange={(e) => setSortBy(e.target.value)}>
            <option value="default">Default</option>
            <option value="name">Name</option>
            <option value="price_asc">Price: low to high</option>
            <option value="price_desc">Price: high to low</option>
            <option value="stock">Stock level</option>
          </select>
        </div>
      </div>

      <div className="cat-view-bar">
        <p className="cat-count">
          <span>{filteredProducts.length}</span> shown / <span>{products.length}</span> total
        </p>
        <div className="cat-view-toggle" role="group" aria-label="View mode">
          <button
            type="button"
            title="Grid view"
            aria-pressed={viewMode === 'grid'}
            onClick={() => setViewMode('grid')}
            className={`cat-view-toggle-btn${viewMode === 'grid' ? ' is-active' : ''}`}
          >
            <i className="fas fa-table-cells" aria-hidden="true" />
          </button>
          <button
            type="button"
            title="List view"
            aria-pressed={viewMode === 'list'}
            onClick={() => setViewMode('list')}
            className={`cat-view-toggle-btn${viewMode === 'list' ? ' is-active' : ''}`}
          >
            <i className="fas fa-list-ul" aria-hidden="true" />
          </button>
        </div>
      </div>

      {filteredProducts.length === 0 ? (
        <div className="cat-empty">
          <i className="fas fa-box-open" />
          <h3>No products found</h3>
          <p>Try adjusting search or filters.</p>
          <button
            type="button"
            onClick={() => { setSearchTerm(''); setCategoryFilter(''); setStockFilter('') }}
            className="cat-link-btn"
          >
            Clear filters
          </button>
        </div>
      ) : viewMode === 'list' ? (
        <div className="cat-list-wrap">
          <table className="cat-list-table">
            <thead>
              <tr>
                <th />
                <th>Image</th>
                <th>Code</th>
                <th>Product</th>
                <th>Stock</th>
                <th className="text-right">Price</th>
                <th className="text-right">Qty</th>
              </tr>
            </thead>
            <tbody>
              {pagedProducts.map((p) => (
                <ProductListRow
                  key={p.id}
                  product={p}
                  quantity={selectedQtys[p.id] || 0}
                  placeholderImage={placeholderImage}
                  onQtyChange={handleQtyChange}
                  onToggleCheck={handleToggleCheck}
                />
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="cat-product-grid">
          {pagedProducts.map((p) => (
            <ProductCard
              key={p.id}
              product={p}
              quantity={selectedQtys[p.id] || 0}
              placeholderImage={placeholderImage}
              onQtyChange={handleQtyChange}
              onToggleCheck={handleToggleCheck}
            />
          ))}
        </div>
      )}

      {filteredProducts.length > 0 && (
        <div className="cat-pagination">
          <p className="cat-range">
            Showing {rangeStart} to {rangeEnd} of {filteredProducts.length}
          </p>
          <div className="cat-page-group">
            <button
              type="button"
              className={`cat-page-btn ${safePage <= 1 ? 'is-disabled' : ''}`}
              disabled={safePage <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              aria-label="Previous page"
            >
              <i className="fas fa-chevron-left" />
            </button>
            {pageNumbers.map((pn) => (
              <button
                key={pn}
                type="button"
                className={`cat-page-btn ${pn === safePage ? 'is-active' : ''}`}
                onClick={() => setPage(pn)}
              >
                {pn}
              </button>
            ))}
            {pageCount > pageNumbers[pageNumbers.length - 1] && (
              <>
                <span className="cat-page-btn is-disabled">...</span>
                <button type="button" className="cat-page-btn" onClick={() => setPage(pageCount)}>{pageCount}</button>
              </>
            )}
            <button
              type="button"
              className={`cat-page-btn ${safePage >= pageCount ? 'is-disabled' : ''}`}
              disabled={safePage >= pageCount}
              onClick={() => setPage((p) => Math.min(pageCount, p + 1))}
              aria-label="Next page"
            >
              <i className="fas fa-chevron-right" />
            </button>
          </div>
          <div className="cat-page-size">
            <select
              value={pageSize}
              onChange={(e) => setPageSize(parseInt(e.target.value, 10))}
            >
              {PAGE_SIZE_OPTIONS.map((n) => <option key={n} value={n}>{n} per page</option>)}
            </select>
          </div>
        </div>
      )}
    </div>
  )
}
