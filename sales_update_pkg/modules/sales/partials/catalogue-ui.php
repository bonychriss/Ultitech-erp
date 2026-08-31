<?php
$addSelectedLabel = $docType === 'purchase' ? 'purchase order' : ($docType === 'invoice' ? 'invoice' : 'quotation');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Catalogue | Sales</title>
    <script>tailwind.config = { corePlugins: { preflight: false } };</script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= htmlspecialchars(sales_app_url('assets/css/style.css')) ?>" rel="stylesheet">
    <script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone@7.23.9/babel.min.js"></script>
    <style>
        html:has(body.page-sales-catalogue), body.page-sales-catalogue { background: #f8f9fc !important; }
        body.page-sales-catalogue { font-family: 'Outfit', system-ui, sans-serif; color: #374151; min-height: 100vh; }
        body.page-sales-catalogue .layout-main-wrapper { background: #f8f9fc; width: 100%; }
        body.page-sales-catalogue .layout-main-wrapper > .flex-grow-1 { flex: 1 1 0%; min-width: 0; width: 100%; background: #f8f9fc; }
        body.page-sales-catalogue header.employee-header { background: #f8f9fc !important; box-shadow: none !important; border-bottom: 1px solid rgba(15,23,42,.06); }
        html body .main-content.sales-catalogue-shell { padding: 1rem 1rem 2rem !important; max-width: none !important; width: 100% !important; min-width: 0; background: #f8f9fc; }
        @media (min-width: 993px) { html body .main-content.sales-catalogue-shell { padding: 1.25rem 1.75rem 2rem !important; } }
        .cat-checkbox { appearance: none; width: 1.05rem; height: 1.05rem; border: 1.5px solid #d1d5db; border-radius: .25rem; background: #fff; cursor: pointer; flex-shrink: 0; }
        .cat-checkbox:checked { background: #2563eb; border-color: #2563eb; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='white'%3E%3Cpath fill-rule='evenodd' d='M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z'/%3E%3C/svg%3E"); background-size: 100% 100%; }
        .cat-page-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 2.25rem; height: 2.25rem; padding: 0 .5rem; font-size: .875rem; font-weight: 600; color: #374151; border: 1px solid #e5e7eb; background: #fff; cursor: pointer; }
        .cat-page-btn:first-of-type { border-radius: .375rem 0 0 .375rem; }
        .cat-page-btn:last-of-type { border-radius: 0 .375rem .375rem 0; }
        .cat-page-btn + .cat-page-btn { margin-left: -1px; }
        .cat-page-btn.is-active { background: #2563eb; border-color: #2563eb; color: #fff; z-index: 1; position: relative; }
        .cat-page-btn.is-disabled { color: #9ca3af; cursor: default; pointer-events: none; }
        .cat-page-btn:hover:not(.is-active):not(.is-disabled) { background: #f9fafb; }
        .cat-filter-label { display: block; font-size: 11px; font-weight: 600; color: #6b7280; margin-bottom: 4px; }
        .cat-filter-select { width: 100%; border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.5rem 2rem 0.5rem 0.75rem; font-size: 14px; background: #fff; color: #111827; }
        .cat-view-toggle {
            display: inline-flex;
            align-items: stretch;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 9999px;
            padding: 0;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
            gap: 0;
            isolation: isolate;
        }
        .cat-view-toggle-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 32px;
            border: none;
            border-radius: 0;
            background: transparent;
            color: #6b7280;
            font-size: 15px;
            line-height: 1;
            cursor: pointer;
            transition: background 0.15s ease, color 0.15s ease;
            flex-shrink: 0;
        }
        .cat-view-toggle-btn:first-child.is-active {
            border-radius: 9999px 0 0 9999px;
        }
        .cat-view-toggle-btn:last-child.is-active {
            border-radius: 0 9999px 9999px 0;
        }
        .cat-view-toggle-btn:hover:not(.is-active) {
            background: #f3f4f6;
            color: #4b5563;
        }
        .cat-view-toggle-btn.is-active {
            background: #2563eb;
            color: #fff;
            box-shadow: none;
        }
        .cat-view-toggle-btn:focus-visible {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
        }
    </style>
</head>
<body class="page-sales-catalogue text-slate-800 antialiased">
<?php include __DIR__ . '/../../../includes/header_employee.php'; ?>
<div class="main-content sales-catalogue-shell" id="root"></div>
<script>
window.APP_DATA = {
    products: <?= json_encode($products) ?>,
    categories: <?= json_encode(array_values($categories)) ?>,
    returnUrl: <?= json_encode($returnUrl) ?>,
    docType: <?= json_encode($docType) ?>,
    docLabel: <?= json_encode($docLabel) ?>,
    addSelectedLabel: <?= json_encode($addSelectedLabel) ?>,
    placeholderImage: <?= json_encode($placeholderImage) ?>
};
</script>
<script type="text/babel">
const { useState, useMemo, useEffect } = React;
const PAGE_SIZE_OPTIONS = [10, 20, 50];
const PAGINATION_WINDOW = 5;

function paginationPageNumbers(currentPage, totalPages, windowSize = PAGINATION_WINDOW) {
    if (totalPages <= 1) return totalPages >= 1 ? [1] : [];
    if (totalPages <= windowSize) return Array.from({ length: totalPages }, (_, i) => i + 1);
    const block = Math.floor((currentPage - 1) / windowSize);
    const start = block * windowSize + 1;
    const end = Math.min(start + windowSize - 1, totalPages);
    return Array.from({ length: end - start + 1 }, (_, i) => start + i);
}

function stockBadge(qty) {
    const n = parseFloat(qty) || 0;
    if (n <= 0) return { label: 'Out of stock', cls: 'bg-red-100 text-red-700 border-red-200' };
    if (n <= 5) return { label: 'Low stock', cls: 'bg-amber-100 text-amber-800 border-amber-200' };
    return { label: 'In stock', cls: 'bg-emerald-100 text-emerald-800 border-emerald-200' };
}

function ProductCard({ product, quantity, onQtyChange, onToggleCheck }) {
    const { id, product_code, name, selling_price, stock_quantity } = product;
    const img = product.image_url || window.APP_DATA.placeholderImage;
    const badge = stockBadge(stock_quantity);
    const checked = quantity > 0;

    return (
        <div className={`relative bg-white rounded-xl border overflow-hidden flex flex-col h-full transition-shadow ${checked ? 'border-blue-500 ring-2 ring-blue-500/15 shadow-md' : 'border-gray-200 hover:shadow-lg'}`}>
            <div className="absolute top-2.5 left-2.5 z-10">
                <input type="checkbox" className="cat-checkbox" checked={checked} onChange={() => onToggleCheck(id)} aria-label="Select product" />
            </div>
            <div className="absolute top-2.5 right-2.5 z-10 text-[10px] font-semibold text-gray-500 bg-white/95 px-1.5 py-0.5 rounded border border-gray-100">
                {product_code || 'N/A'}
            </div>
            <div className="flex items-center justify-center bg-white px-4 pt-10 pb-3 min-h-[140px]">
                <img src={img} alt={name} className="max-h-[120px] w-full object-contain"
                    onError={(e) => { e.target.onerror = null; e.target.src = window.APP_DATA.placeholderImage; }} />
            </div>
            <div className="px-3 pb-3 flex-1 flex flex-col gap-2">
                <h3 className="text-sm font-semibold text-gray-900 leading-snug line-clamp-2 min-h-[2.5rem]">{name}</h3>
                <span className={`inline-flex w-fit text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full border ${badge.cls}`}>{badge.label}</span>
                <div className="flex items-end justify-between gap-2 mt-auto pt-2">
                    <span className="text-base font-bold text-blue-600 tabular-nums">
                        {new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(selling_price || 0)}
                    </span>
                    <div className="flex items-center border border-gray-200 rounded-lg bg-gray-50 shrink-0">
                        <button type="button" onClick={() => onQtyChange(id, Math.max(0, quantity - 1))}
                            className="w-7 h-7 flex items-center justify-center text-gray-600 hover:bg-white rounded-l-lg text-xs"><i className="fas fa-minus"></i></button>
                        <input type="number" min="0" value={quantity}
                            onChange={(e) => onQtyChange(id, Math.max(0, parseInt(e.target.value, 10) || 0))}
                            className="w-9 text-center text-sm font-bold bg-transparent border-0 focus:ring-0 py-1" />
                        <button type="button" onClick={() => onQtyChange(id, quantity + 1)}
                            className="w-7 h-7 flex items-center justify-center text-gray-600 hover:bg-white rounded-r-lg text-xs"><i className="fas fa-plus"></i></button>
                    </div>
                </div>
            </div>
        </div>
    );
}

function ProductListRow({ product, quantity, onQtyChange, onToggleCheck }) {
    const { id, product_code, name, selling_price, stock_quantity } = product;
    const img = product.image_url || window.APP_DATA.placeholderImage;
    const badge = stockBadge(stock_quantity);
    return (
        <tr className="border-b border-gray-100 hover:bg-gray-50/80">
            <td className="px-3 py-3 w-10"><input type="checkbox" className="cat-checkbox" checked={quantity > 0} onChange={() => onToggleCheck(id)} /></td>
            <td className="px-3 py-3 w-16"><img src={img} alt="" className="w-12 h-12 object-contain rounded border border-gray-100 bg-white"
                onError={(e) => { e.target.onerror = null; e.target.src = window.APP_DATA.placeholderImage; }} /></td>
            <td className="px-3 py-3 text-xs text-gray-500 font-mono">{product_code || ''}</td>
            <td className="px-3 py-3 text-sm font-medium text-gray-900">{name}</td>
            <td className="px-3 py-3"><span className={`text-[10px] font-bold uppercase px-2 py-0.5 rounded-full border ${badge.cls}`}>{badge.label}</span></td>
            <td className="px-3 py-3 text-sm font-bold text-blue-600 text-right tabular-nums">{new Intl.NumberFormat('en-US', { minimumFractionDigits: 2 }).format(selling_price || 0)}</td>
            <td className="px-3 py-3 text-right">
                <div className="inline-flex items-center border border-gray-200 rounded-lg bg-gray-50">
                    <button type="button" onClick={() => onQtyChange(id, Math.max(0, quantity - 1))} className="w-7 h-7 text-xs text-gray-600"><i className="fas fa-minus"></i></button>
                    <input type="number" min="0" value={quantity} onChange={(e) => onQtyChange(id, Math.max(0, parseInt(e.target.value, 10) || 0))}
                        className="w-9 text-center text-sm font-bold border-0 bg-transparent focus:ring-0" />
                    <button type="button" onClick={() => onQtyChange(id, quantity + 1)} className="w-7 h-7 text-xs text-gray-600"><i className="fas fa-plus"></i></button>
                </div>
            </td>
        </tr>
    );
}

function CatalogueApp() {
    const { products, categories, returnUrl, docType, docLabel, addSelectedLabel, placeholderImage } = window.APP_DATA;
    const [searchTerm, setSearchTerm] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [stockFilter, setStockFilter] = useState('');
    const [sortBy, setSortBy] = useState('default');
    const [viewMode, setViewMode] = useState('grid');
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [selectedQtys, setSelectedQtys] = useState({});

    const storageKey = docType === 'purchase' ? 'purchase_catalogue_items' : 'sales_catalogue_items';

    useEffect(() => { setPage(1); }, [searchTerm, categoryFilter, stockFilter, sortBy, pageSize]);

    const filteredProducts = useMemo(() => {
        let list = [...products];
        const q = searchTerm.trim().toLowerCase();
        if (q) {
            list = list.filter(p =>
                (p.name || '').toLowerCase().includes(q) ||
                (p.product_code || '').toLowerCase().includes(q) ||
                (p.description || '').toLowerCase().includes(q)
            );
        }
        if (categoryFilter) {
            list = list.filter(p => (p.category_name || '') === categoryFilter);
        }
        if (stockFilter === 'in') list = list.filter(p => (parseFloat(p.stock_quantity) || 0) > 5);
        else if (stockFilter === 'low') list = list.filter(p => { const n = parseFloat(p.stock_quantity) || 0; return n > 0 && n <= 5; });
        else if (stockFilter === 'out') list = list.filter(p => (parseFloat(p.stock_quantity) || 0) <= 0);

        if (sortBy === 'name') list.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
        else if (sortBy === 'price_asc') list.sort((a, b) => (parseFloat(a.selling_price) || 0) - (parseFloat(b.selling_price) || 0));
        else if (sortBy === 'price_desc') list.sort((a, b) => (parseFloat(b.selling_price) || 0) - (parseFloat(a.selling_price) || 0));
        else if (sortBy === 'stock') list.sort((a, b) => (parseFloat(b.stock_quantity) || 0) - (parseFloat(a.stock_quantity) || 0));
        return list;
    }, [products, searchTerm, categoryFilter, stockFilter, sortBy]);

    const pageCount = Math.max(1, Math.ceil(filteredProducts.length / pageSize));
    const safePage = Math.min(page, pageCount);
    const pageNumbers = useMemo(() => paginationPageNumbers(safePage, pageCount), [safePage, pageCount]);
    const pagedProducts = useMemo(() => {
        const start = (safePage - 1) * pageSize;
        return filteredProducts.slice(start, start + pageSize);
    }, [filteredProducts, safePage, pageSize]);

    const totalSelectedQty = useMemo(() => Object.values(selectedQtys).reduce((s, q) => s + (q > 0 ? 1 : 0), 0), [selectedQtys]);

    const handleQtyChange = (id, qty) => setSelectedQtys(prev => ({ ...prev, [id]: qty }));
    const handleToggleCheck = (id) => {
        setSelectedQtys(prev => {
            const cur = prev[id] || 0;
            return { ...prev, [id]: cur > 0 ? 0 : 1 };
        });
    };

    const handleSendToDoc = () => {
        const items = products.filter(p => (selectedQtys[p.id] || 0) > 0).map(p => ({ product_id: p.id, quantity: selectedQtys[p.id] }));
        if (items.length === 0) { alert('Please select at least one product.'); return; }
        localStorage.setItem(storageKey, JSON.stringify(items));
        const pickedIds = items.map(i => Number(i.product_id) || 0).filter(id => id > 0);
        let targetUrl = returnUrl;
        try {
            const url = new URL(returnUrl, window.location.origin);
            if (pickedIds.length > 0) {
                url.searchParams.set('catalogue_product_ids', pickedIds.join(','));
            }
            targetUrl = url.toString();
        } catch (e) {
            if (pickedIds.length > 0) {
                targetUrl += (returnUrl.includes('?') ? '&' : '?') + 'catalogue_product_ids=' + encodeURIComponent(pickedIds.join(','));
            }
        }
        window.location.href = targetUrl;
    };

    const rangeStart = filteredProducts.length === 0 ? 0 : (safePage - 1) * pageSize + 1;
    const rangeEnd = Math.min(safePage * pageSize, filteredProducts.length);

    return (
        <div className="max-w-[1600px] mx-auto space-y-4 pb-8">
            <div className="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                <div className="flex items-start gap-3 min-w-0">
                    <a href={returnUrl} className="shrink-0 w-10 h-10 flex items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 hover:text-blue-600 hover:border-blue-300 shadow-sm" title="Back">
                        <i className="fas fa-arrow-left"></i>
                    </a>
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-bold text-gray-900 m-0">Sales Catalogue</h1>
                            <span className="inline-flex px-2.5 py-1 rounded-md text-[11px] font-bold bg-blue-600 text-white uppercase tracking-wider">{docLabel}</span>
                        </div>
                        <p className="text-sm text-gray-500 mt-1 mb-0">
                            Select quantities then click <strong className="text-gray-700">Add selected</strong> to build your {addSelectedLabel}.
                        </p>
                    </div>
                </div>
                <button type="button" onClick={handleSendToDoc}
                    className={`shrink-0 inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg text-sm font-semibold shadow-sm transition-colors text-white ${totalSelectedQty > 0 ? 'bg-[#7C3AED] hover:bg-[#6D28D9]' : 'bg-[#7C3AED] hover:bg-[#6D28D9] opacity-90'}`}>
                    <i className="fas fa-cart-shopping"></i>
                    Add selected ({totalSelectedQty})
                </button>
            </div>

            <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-4 items-end">
                    <div className="lg:col-span-5">
                        <label className="cat-filter-label">Search</label>
                        <div className="relative">
                            <i className="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input type="text" placeholder="Search products by name, code or keyword..."
                                value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)}
                                className="w-full pl-10 pr-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500" />
                        </div>
                    </div>
                    <div className="lg:col-span-2">
                        <label className="cat-filter-label">Category</label>
                        <select className="cat-filter-select" value={categoryFilter} onChange={(e) => setCategoryFilter(e.target.value)}>
                            <option value="">All categories</option>
                            {categories.map(c => <option key={c} value={c}>{c}</option>)}
                        </select>
                    </div>
                    <div className="lg:col-span-2">
                        <label className="cat-filter-label">Stock status</label>
                        <select className="cat-filter-select" value={stockFilter} onChange={(e) => setStockFilter(e.target.value)}>
                            <option value="">All stock</option>
                            <option value="in">In stock</option>
                            <option value="low">Low stock</option>
                            <option value="out">Out of stock</option>
                        </select>
                    </div>
                    <div className="lg:col-span-2">
                        <label className="cat-filter-label">Sort by</label>
                        <select className="cat-filter-select" value={sortBy} onChange={(e) => setSortBy(e.target.value)}>
                            <option value="default">Default</option>
                            <option value="name">Name</option>
                            <option value="price_asc">Price: low to high</option>
                            <option value="price_desc">Price: high to low</option>
                            <option value="stock">Stock level</option>
                        </select>
                    </div>
                    <div className="lg:col-span-1 flex lg:justify-end">
                        <button type="button" className="w-full lg:w-auto inline-flex items-center justify-center gap-2 px-3 py-2.5 border border-gray-200 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            <i className="fas fa-sliders-h text-gray-500"></i> Filters
                        </button>
                    </div>
                </div>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 px-1">
                <p className="text-sm text-gray-600 m-0 tabular-nums">
                    <span className="font-semibold text-gray-800">{filteredProducts.length}</span> shown / <span className="font-semibold text-gray-800">{products.length}</span> total
                </p>
                <div className="cat-view-toggle" role="group" aria-label="View mode">
                    <button
                        type="button"
                        title="Grid view"
                        aria-pressed={viewMode === 'grid'}
                        onClick={() => setViewMode('grid')}
                        className={`cat-view-toggle-btn${viewMode === 'grid' ? ' is-active' : ''}`}
                    >
                        <i className="fas fa-table-cells" aria-hidden="true"></i>
                    </button>
                    <button
                        type="button"
                        title="List view"
                        aria-pressed={viewMode === 'list'}
                        onClick={() => setViewMode('list')}
                        className={`cat-view-toggle-btn${viewMode === 'list' ? ' is-active' : ''}`}
                    >
                        <i className="fas fa-list-ul" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            {filteredProducts.length === 0 ? (
                <div className="bg-white rounded-xl border border-gray-200 py-16 text-center">
                    <i className="fas fa-box-open text-4xl text-gray-300 mb-3"></i>
                    <h3 className="text-lg font-bold text-gray-900">No products found</h3>
                    <p className="text-gray-500 text-sm mt-1">Try adjusting search or filters.</p>
                    <button type="button" onClick={() => { setSearchTerm(''); setCategoryFilter(''); setStockFilter(''); }}
                        className="mt-4 text-sm font-semibold text-blue-600 hover:underline">Clear filters</button>
                </div>
            ) : viewMode === 'list' ? (
                <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left">
                            <thead className="bg-gray-50 text-xs font-semibold text-gray-500 uppercase border-b border-gray-200">
                                <tr>
                                    <th className="px-3 py-3 w-10"></th>
                                    <th className="px-3 py-3">Image</th>
                                    <th className="px-3 py-3">Code</th>
                                    <th className="px-3 py-3">Product</th>
                                    <th className="px-3 py-3">Stock</th>
                                    <th className="px-3 py-3 text-right">Price</th>
                                    <th className="px-3 py-3 text-right">Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                                {pagedProducts.map(p => (
                                    <ProductListRow key={p.id} product={p} quantity={selectedQtys[p.id] || 0}
                                        onQtyChange={handleQtyChange} onToggleCheck={handleToggleCheck} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            ) : (
                <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                    {pagedProducts.map(p => (
                        <ProductCard key={p.id} product={p} quantity={selectedQtys[p.id] || 0}
                            onQtyChange={handleQtyChange} onToggleCheck={handleToggleCheck} />
                    ))}
                </div>
            )}

            {filteredProducts.length > 0 && (
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pt-2">
                    <p className="text-sm text-gray-500 m-0 hidden sm:block">
                        Showing {rangeStart} to {rangeEnd} of {filteredProducts.length}
                    </p>
                    <div className="flex flex-wrap items-center justify-center gap-0 mx-auto sm:mx-0">
                        <button type="button" className={`cat-page-btn ${safePage <= 1 ? 'is-disabled' : ''}`}
                            disabled={safePage <= 1} onClick={() => setPage(p => Math.max(1, p - 1))} aria-label="Previous page">
                            <i className="fas fa-chevron-left text-xs"></i>
                        </button>
                        {pageNumbers.map(pn => (
                            <button key={pn} type="button" className={`cat-page-btn ${pn === safePage ? 'is-active' : ''}`}
                                onClick={() => setPage(pn)}>{pn}</button>
                        ))}
                        {pageCount > pageNumbers[pageNumbers.length - 1] && (
                            <>
                                <span className="cat-page-btn is-disabled px-1">...</span>
                                <button type="button" className="cat-page-btn" onClick={() => setPage(pageCount)}>{pageCount}</button>
                            </>
                        )}
                        <button type="button" className={`cat-page-btn ${safePage >= pageCount ? 'is-disabled' : ''}`}
                            disabled={safePage >= pageCount} onClick={() => setPage(p => Math.min(pageCount, p + 1))} aria-label="Next page">
                            <i className="fas fa-chevron-right text-xs"></i>
                        </button>
                    </div>
                    <div className="flex items-center justify-center sm:justify-end gap-2 text-sm text-gray-600">
                        <select value={pageSize} onChange={(e) => setPageSize(parseInt(e.target.value, 10))}
                            className="border border-gray-200 rounded-lg px-2 py-1.5 text-sm bg-white font-medium">
                            {PAGE_SIZE_OPTIONS.map(n => <option key={n} value={n}>{n} per page</option>)}
                        </select>
                    </div>
                </div>
            )}
        </div>
    );
}

ReactDOM.createRoot(document.getElementById('root')).render(<CatalogueApp />);
</script>
</div><!-- .flex-grow-1 -->
</div><!-- .layout-main-wrapper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

