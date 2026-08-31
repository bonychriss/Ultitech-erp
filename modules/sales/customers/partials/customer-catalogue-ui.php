<?php
/** @var array $customers */
/** @var array $popularity */
/** @var string $returnUrl */
/** @var string $docType */
/** @var string $docLabel */
/** @var bool $multiSelect */
/** @var string $addSelectedLabel */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Catalogue | Sales</title>
    <script>tailwind.config = { corePlugins: { preflight: false } };</script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= htmlspecialchars(sales_app_url('assets/css/style.css')) ?>" rel="stylesheet">
    <script src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script src="https://unpkg.com/@babel/standalone@7.23.9/babel.min.js"></script>
    <style>
        html:has(body.page-customer-catalogue), body.page-customer-catalogue { background: #f8f9fc !important; }
        body.page-customer-catalogue { font-family: 'Outfit', system-ui, sans-serif; color: #374151; min-height: 100vh; }
        body.page-customer-catalogue .layout-main-wrapper { background: #f8f9fc; width: 100%; }
        body.page-customer-catalogue .layout-main-wrapper > .flex-grow-1 { flex: 1 1 0%; min-width: 0; width: 100%; background: #f8f9fc; }
        body.page-customer-catalogue header.employee-header { background: #f8f9fc !important; box-shadow: none !important; border-bottom: 1px solid rgba(15,23,42,.06); }
        html body .main-content.customer-catalogue-shell { padding: 1rem 1rem 2rem !important; max-width: none !important; width: 100% !important; min-width: 0; background: #f8f9fc; }
        @media (min-width: 993px) { html body .main-content.customer-catalogue-shell { padding: 1.25rem 1.75rem 2rem !important; } }
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
        .cat-view-toggle { display: inline-flex; align-items: stretch; background: #fff; border: 1px solid #e5e7eb; border-radius: 9999px; overflow: hidden; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08); isolation: isolate; }
        .cat-view-toggle-btn { display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 32px; border: none; border-radius: 0; background: transparent; color: #6b7280; font-size: 15px; cursor: pointer; flex-shrink: 0; }
        .cat-view-toggle-btn:first-child.is-active { border-radius: 9999px 0 0 9999px; }
        .cat-view-toggle-btn:last-child.is-active { border-radius: 0 9999px 9999px 0; }
        .cat-view-toggle-btn:hover:not(.is-active) { background: #f3f4f6; color: #4b5563; }
        .cat-view-toggle-btn.is-active { background: #2563eb; color: #fff; }
        .cust-avatar { width: 72px; height: 72px; border-radius: 9999px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 20px; color: #0f172a; border: 1px solid rgba(15, 23, 42, 0.06); margin: 0 auto; }
    </style>
</head>
<body class="page-customer-catalogue text-slate-800 antialiased">
<?php include __DIR__ . '/../../../../includes/header_employee.php'; ?>
<div class="main-content customer-catalogue-shell" id="root"></div>
<script>
window.APP_DATA = {
    customers: <?= json_encode($customers) ?>,
    returnUrl: <?= json_encode($returnUrl) ?>,
    docType: <?= json_encode($docType) ?>,
    docLabel: <?= json_encode($docLabel) ?>,
    addSelectedLabel: <?= json_encode($addSelectedLabel) ?>,
    multiSelect: <?= $multiSelect ? 'true' : 'false' ?>,
    customerViewBase: <?= json_encode(sales_module_url('customers/view.php')) ?>
};
</script>
<script type="text/babel">
const { useState, useMemo, useEffect } = React;
const PAGE_SIZE_OPTIONS = [12, 24, 48];
const PAGINATION_WINDOW = 5;

function paginationPageNumbers(currentPage, totalPages, windowSize = PAGINATION_WINDOW) {
    if (totalPages <= 1) return totalPages >= 1 ? [1] : [];
    if (totalPages <= windowSize) return Array.from({ length: totalPages }, (_, i) => i + 1);
    const block = Math.floor((currentPage - 1) / windowSize);
    const start = block * windowSize + 1;
    const end = Math.min(start + windowSize - 1, totalPages);
    return Array.from({ length: end - start + 1 }, (_, i) => start + i);
}

function getInitials(name) {
    const s = (name || '').trim();
    if (!s) return 'CU';
    const words = s.split(/\s+/).filter(Boolean);
    if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
    return (words[0][0] + words[1][0]).toUpperCase();
}

function hashToHue(val) {
    const str = String(val ?? '');
    let h = 0;
    for (let i = 0; i < str.length; i++) h = (h * 31 + str.charCodeAt(i)) >>> 0;
    return h % 360;
}

function CustomerCard({ customer, selected, onToggle, viewBase }) {
    const { id, customer_code, company_name, contact_person, phone, email, invoice_count } = customer;
    return (
        <div className={`relative bg-white rounded-xl border overflow-hidden flex flex-col h-full transition-shadow cursor-pointer ${selected ? 'border-blue-500 ring-2 ring-blue-500/15 shadow-md' : 'border-gray-200 hover:shadow-lg'}`}
            onClick={() => onToggle(id)} role="button" tabIndex={0}
            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onToggle(id); } }}>
            <div className="absolute top-2.5 left-2.5 z-10" onClick={(e) => e.stopPropagation()}>
                <input type="checkbox" className="cat-checkbox" checked={selected} onChange={() => onToggle(id)} aria-label="Select customer" />
            </div>
            <div className="absolute top-2.5 right-2.5 z-10 text-[10px] font-semibold text-gray-500 bg-white/95 px-1.5 py-0.5 rounded border border-gray-100">
                {customer_code || 'N/A'}
            </div>
            <div className="flex items-center justify-center bg-white px-4 pt-10 pb-3 min-h-[120px]">
                <div className="cust-avatar" style={{ background: `hsl(${hashToHue(id)} 90% 92%)` }} title={company_name}>
                    {getInitials(company_name)}
                </div>
            </div>
            <div className="px-3 pb-3 flex-1 flex flex-col gap-2">
                <h3 className="text-sm font-semibold text-gray-900 leading-snug line-clamp-2 min-h-[2.5rem] text-center">{company_name}</h3>
                {contact_person ? <p className="text-xs text-gray-500 text-center truncate m-0">{contact_person}</p> : null}
                {invoice_count > 0 ? (
                    <span className="inline-flex w-fit mx-auto text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full border bg-blue-50 text-blue-700 border-blue-200">
                        {invoice_count} invoices (6 mo)
                    </span>
                ) : null}
                <div className="mt-auto pt-2 flex justify-center">
                    <a href={`${viewBase}?id=${id}&module=sales`} className="text-xs font-semibold text-blue-600 hover:underline"
                        onClick={(e) => e.stopPropagation()}>View details</a>
                </div>
            </div>
        </div>
    );
}

function CustomerListRow({ customer, selected, onToggle, viewBase }) {
    const { id, customer_code, company_name, contact_person, phone, email } = customer;
    return (
        <tr className="border-b border-gray-100 hover:bg-gray-50/80 cursor-pointer" onClick={() => onToggle(id)}>
            <td className="px-3 py-3 w-10" onClick={(e) => e.stopPropagation()}>
                <input type="checkbox" className="cat-checkbox" checked={selected} onChange={() => onToggle(id)} />
            </td>
            <td className="px-3 py-3 w-16">
                <div className="w-10 h-10 rounded-full flex items-center justify-center text-xs font-bold"
                    style={{ background: `hsl(${hashToHue(id)} 90% 92%)` }}>{getInitials(company_name)}</div>
            </td>
            <td className="px-3 py-3 text-xs text-gray-500 font-mono">{customer_code || ''}</td>
            <td className="px-3 py-3 text-sm font-medium text-gray-900">{company_name}</td>
            <td className="px-3 py-3 text-sm text-gray-600">{contact_person || ''}</td>
            <td className="px-3 py-3 text-sm text-gray-600">{phone || ''}</td>
            <td className="px-3 py-3 text-sm text-gray-600 truncate max-w-[160px]">{email || ''}</td>
            <td className="px-3 py-3 text-right">
                <a href={`${viewBase}?id=${id}&module=sales`} className="text-xs font-semibold text-blue-600 hover:underline"
                    onClick={(e) => e.stopPropagation()}>Details</a>
            </td>
        </tr>
    );
}

function CustomerCatalogueApp() {
    const { customers, returnUrl, docLabel, addSelectedLabel, multiSelect, customerViewBase } = window.APP_DATA;
    const [searchTerm, setSearchTerm] = useState('');
    const [sortBy, setSortBy] = useState('name');
    const [viewMode, setViewMode] = useState('grid');
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(24);
    const [selectedIds, setSelectedIds] = useState({});

    useEffect(() => {
        if (multiSelect) {
            try {
                const saved = JSON.parse(localStorage.getItem('selected_customer_ids') || '[]');
                if (Array.isArray(saved)) {
                    const map = {};
                    saved.forEach(id => { if (id) map[Number(id)] = true; });
                    setSelectedIds(map);
                }
            } catch (e) {}
        }
    }, [multiSelect]);

    useEffect(() => { setPage(1); }, [searchTerm, sortBy, pageSize]);

    const filteredCustomers = useMemo(() => {
        let list = [...customers];
        const q = searchTerm.trim().toLowerCase();
        if (q) {
            list = list.filter(c =>
                (c.company_name || '').toLowerCase().includes(q) ||
                (c.contact_person || '').toLowerCase().includes(q) ||
                (c.customer_code || '').toLowerCase().includes(q) ||
                (c.email || '').toLowerCase().includes(q) ||
                (c.phone || '').toLowerCase().includes(q)
            );
        }
        if (sortBy === 'name') list.sort((a, b) => (a.company_name || '').localeCompare(b.company_name || ''));
        else if (sortBy === 'code') list.sort((a, b) => (a.customer_code || '').localeCompare(b.customer_code || ''));
        else if (sortBy === 'activity') list.sort((a, b) => (parseInt(b.invoice_count, 10) || 0) - (parseInt(a.invoice_count, 10) || 0));
        return list;
    }, [customers, searchTerm, sortBy]);

    const pageCount = Math.max(1, Math.ceil(filteredCustomers.length / pageSize));
    const safePage = Math.min(page, pageCount);
    const pageNumbers = useMemo(() => paginationPageNumbers(safePage, pageCount), [safePage, pageCount]);
    const pagedCustomers = useMemo(() => {
        const start = (safePage - 1) * pageSize;
        return filteredCustomers.slice(start, start + pageSize);
    }, [filteredCustomers, safePage, pageSize]);

    const selectedList = useMemo(() => Object.keys(selectedIds).filter(k => selectedIds[k]).map(Number), [selectedIds]);
    const totalSelected = selectedList.length;

    const handleToggle = (id) => {
        const numId = Number(id);
        if (!numId) return;
        setSelectedIds(prev => {
            if (multiSelect) {
                const next = { ...prev };
                if (next[numId]) delete next[numId];
                else next[numId] = true;
                return next;
            }
            return prev[numId] ? {} : { [numId]: true };
        });
    };

    const redirectWithSelected = (ids) => {
        try {
            const url = new URL(returnUrl, window.location.origin);
            url.searchParams.delete('customer_id');
            url.searchParams.delete('customer_ids[]');
            url.searchParams.delete('customer_ids');
            ids.forEach(cid => url.searchParams.append('customer_ids[]', String(cid)));
            window.location.href = url.toString();
        } catch (e) {
            const qs = ids.map(id => 'customer_ids%5B%5D=' + encodeURIComponent(String(id))).join('&');
            const joiner = returnUrl.includes('?') ? '&' : '?';
            window.location.href = returnUrl + joiner + qs;
        }
    };

    const handleSendToDoc = () => {
        if (totalSelected === 0) {
            alert('Please select at least one customer.');
            return;
        }
        if (multiSelect) {
            localStorage.setItem('selected_customer_ids', JSON.stringify(selectedList));
            redirectWithSelected(selectedList);
            return;
        }
        const id = selectedList[0];
        localStorage.setItem('selected_customer_id', String(id));
        try {
            const url = new URL(returnUrl, window.location.origin);
            url.searchParams.set('customer_id', String(id));
            window.location.href = url.toString();
        } catch (e) {
            const joiner = returnUrl.includes('?') ? '&' : '?';
            window.location.href = returnUrl + joiner + 'customer_id=' + encodeURIComponent(String(id));
        }
    };

    const rangeStart = filteredCustomers.length === 0 ? 0 : (safePage - 1) * pageSize + 1;
    const rangeEnd = Math.min(safePage * pageSize, filteredCustomers.length);

    return (
        <div className="max-w-[1600px] mx-auto space-y-4 pb-8">
            <div className="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                <div className="flex items-start gap-3 min-w-0">
                    <a href={returnUrl} className="shrink-0 w-10 h-10 flex items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 hover:text-blue-600 hover:border-blue-300 shadow-sm" title="Back">
                        <i className="fas fa-arrow-left"></i>
                    </a>
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-bold text-gray-900 m-0">Customer Catalogue</h1>
                            <span className="inline-flex px-2.5 py-1 rounded-md text-[11px] font-bold bg-blue-600 text-white uppercase tracking-wider">{docLabel}</span>
                        </div>
                        <p className="text-sm text-gray-500 mt-1 mb-0">
                            Select {multiSelect ? 'customers' : 'a customer'} then click <strong className="text-gray-700">Add selected</strong> for your {addSelectedLabel}.
                        </p>
                    </div>
                </div>
                <button type="button" onClick={handleSendToDoc}
                    className="shrink-0 inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg text-sm font-semibold shadow-sm transition-colors text-white bg-[#7C3AED] hover:bg-[#6D28D9]">
                    <i className="fas fa-user-check"></i>
                    Add selected ({totalSelected})
                </button>
            </div>

            <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-4 items-end">
                    <div className="lg:col-span-8">
                        <label className="cat-filter-label">Search</label>
                        <div className="relative">
                            <i className="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input type="text" placeholder="Search by company, contact, code, phone or email..."
                                value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)}
                                className="w-full pl-10 pr-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500" />
                        </div>
                    </div>
                    <div className="lg:col-span-4">
                        <label className="cat-filter-label">Sort by</label>
                        <select className="cat-filter-select" value={sortBy} onChange={(e) => setSortBy(e.target.value)}>
                            <option value="name">Company name</option>
                            <option value="code">Customer code</option>
                            <option value="activity">Recent activity</option>
                        </select>
                    </div>
                </div>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 px-1">
                <p className="text-sm text-gray-600 m-0 tabular-nums">
                    <span className="font-semibold text-gray-800">{filteredCustomers.length}</span> shown / <span className="font-semibold text-gray-800">{customers.length}</span> active
                </p>
                <div className="cat-view-toggle" role="group" aria-label="View mode">
                    <button type="button" title="Grid view" aria-pressed={viewMode === 'grid'} onClick={() => setViewMode('grid')}
                        className={`cat-view-toggle-btn${viewMode === 'grid' ? ' is-active' : ''}`}><i className="fas fa-table-cells"></i></button>
                    <button type="button" title="List view" aria-pressed={viewMode === 'list'} onClick={() => setViewMode('list')}
                        className={`cat-view-toggle-btn${viewMode === 'list' ? ' is-active' : ''}`}><i className="fas fa-list-ul"></i></button>
                </div>
            </div>

            {filteredCustomers.length === 0 ? (
                <div className="bg-white rounded-xl border border-gray-200 py-16 text-center">
                    <i className="fas fa-users-slash text-4xl text-gray-300 mb-3"></i>
                    <h3 className="text-lg font-bold text-gray-900">No customers found</h3>
                    <p className="text-gray-500 text-sm mt-1">Try adjusting your search.</p>
                    <button type="button" onClick={() => setSearchTerm('')} className="mt-4 text-sm font-semibold text-blue-600 hover:underline">Clear search</button>
                </div>
            ) : viewMode === 'list' ? (
                <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left">
                            <thead className="bg-gray-50 text-xs font-semibold text-gray-500 uppercase border-b border-gray-200">
                                <tr>
                                    <th className="px-3 py-3 w-10"></th>
                                    <th className="px-3 py-3"></th>
                                    <th className="px-3 py-3">Code</th>
                                    <th className="px-3 py-3">Company</th>
                                    <th className="px-3 py-3">Contact</th>
                                    <th className="px-3 py-3">Phone</th>
                                    <th className="px-3 py-3">Email</th>
                                    <th className="px-3 py-3 text-right"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {pagedCustomers.map(c => (
                                    <CustomerListRow key={c.id} customer={c} selected={!!selectedIds[c.id]}
                                        onToggle={handleToggle} viewBase={customerViewBase} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            ) : (
                <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                    {pagedCustomers.map(c => (
                        <CustomerCard key={c.id} customer={c} selected={!!selectedIds[c.id]}
                            onToggle={handleToggle} viewBase={customerViewBase} />
                    ))}
                </div>
            )}

            {filteredCustomers.length > 0 && (
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pt-2">
                    <p className="text-sm text-gray-500 m-0 hidden sm:block">Showing {rangeStart} to {rangeEnd} of {filteredCustomers.length}</p>
                    <div className="flex flex-wrap items-center justify-center gap-0 mx-auto sm:mx-0">
                        <button type="button" className={`cat-page-btn ${safePage <= 1 ? 'is-disabled' : ''}`} disabled={safePage <= 1}
                            onClick={() => setPage(p => Math.max(1, p - 1))} aria-label="Previous page"><i className="fas fa-chevron-left text-xs"></i></button>
                        {pageNumbers.map(pn => (
                            <button key={pn} type="button" className={`cat-page-btn ${pn === safePage ? 'is-active' : ''}`} onClick={() => setPage(pn)}>{pn}</button>
                        ))}
                        {pageCount > (pageNumbers[pageNumbers.length - 1] || 0) && (
                            <>
                                <span className="cat-page-btn is-disabled px-1">...</span>
                                <button type="button" className="cat-page-btn" onClick={() => setPage(pageCount)}>{pageCount}</button>
                            </>
                        )}
                        <button type="button" className={`cat-page-btn ${safePage >= pageCount ? 'is-disabled' : ''}`} disabled={safePage >= pageCount}
                            onClick={() => setPage(p => Math.min(pageCount, p + 1))} aria-label="Next page"><i className="fas fa-chevron-right text-xs"></i></button>
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

ReactDOM.createRoot(document.getElementById('root')).render(<CustomerCatalogueApp />);
</script>
</div><!-- .flex-grow-1 -->
</div><!-- .layout-main-wrapper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

