import React, { useMemo, useState } from 'react';
import {
  HiOutlineDocumentChartBar,
  HiOutlineArrowLeft,
  HiOutlineArrowDownTray,
  HiOutlineCube,
  HiOutlineMagnifyingGlass,
  HiOutlineMapPin,
  HiOutlineTag,
  HiOutlineCircleStack,
} from 'react-icons/hi2';

export default function StockReport({ data }) {
  const { stocks = [], total_usd = 0, total_tzs = 0, links = {} } = data;
  const dashboardHref = links.dashboard || '../../dashboard.php';
  const exportCsvHref = links.exportCsv || 'export_stock.php';
  const [query, setQuery] = useState('');
  const [categoryFilter, setCategoryFilter] = useState('all');
  const [locationFilter, setLocationFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState('all');
  const [page, setPage] = useState(1);
  const pageSize = 10;

  const formatMoney = (val, currency) => {
    const sym = currency === 'TZS' ? 'TSh ' : '$';
    return sym + Number(val || 0).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };

  const normalizedRows = useMemo(
    () =>
      stocks.map((row) => {
        const qty = Number(row.quantity || 0);
        let status = 'Out of Stock';
        if (qty > 0 && qty <= 100) status = 'Low Stock';
        if (qty > 100) status = 'In Stock';
        return { ...row, qty, status };
      }),
    [stocks]
  );

  const categories = useMemo(() => {
    const vals = Array.from(new Set(normalizedRows.map((r) => (r.category || '').trim()).filter(Boolean)));
    return vals.sort((a, b) => a.localeCompare(b));
  }, [normalizedRows]);

  const locations = useMemo(() => {
    const vals = Array.from(new Set(normalizedRows.map((r) => (r.location || '').trim()).filter(Boolean)));
    return vals.sort((a, b) => a.localeCompare(b));
  }, [normalizedRows]);

  const totalProducts = normalizedRows.length;
  const totalQty = normalizedRows.reduce((sum, r) => sum + (Number.isFinite(r.qty) ? r.qty : 0), 0);
  const totalInventoryTzs = Number(total_tzs || 0);

  const filteredRows = useMemo(() => {
    const q = query.trim().toLowerCase();
    return normalizedRows.filter((row) => {
      const matchesQuery =
        !q ||
        (row.product_code || '').toLowerCase().includes(q) ||
        (row.name || '').toLowerCase().includes(q);
      const matchesCategory = categoryFilter === 'all' || (row.category || '') === categoryFilter;
      const matchesLocation = locationFilter === 'all' || (row.location || '') === locationFilter;
      const matchesStatus = statusFilter === 'all' || row.status === statusFilter;
      return matchesQuery && matchesCategory && matchesLocation && matchesStatus;
    });
  }, [normalizedRows, query, categoryFilter, locationFilter, statusFilter]);

  const pageCount = Math.max(1, Math.ceil(filteredRows.length / pageSize));
  const currentPage = Math.min(page, pageCount);
  const pagedRows = filteredRows.slice((currentPage - 1) * pageSize, currentPage * pageSize);

  const statusClass = (status) => {
    if (status === 'In Stock') return 'bg-emerald-100 text-emerald-700';
    if (status === 'Low Stock') return 'bg-amber-100 text-amber-700';
    return 'bg-rose-100 text-rose-700';
  };

  const resetFilters = () => {
    setQuery('');
    setCategoryFilter('all');
    setLocationFilter('all');
    setStatusFilter('all');
    setPage(1);
  };

  return (
    <div className="min-h-full w-full bg-slate-50">
      <header className="sticky top-0 z-10 bg-white/95 backdrop-blur border-b border-slate-200">
        <div className="page-container flex flex-wrap items-center justify-between gap-3 py-3">
          <div>
            <h1 className="text-2xl font-semibold text-slate-800">Stock Level Report</h1>
            <p className="text-sm text-slate-500 mt-0.5">Simple overview of current stock by product.</p>
          </div>
          <div className="flex items-center gap-2">
            <a href={dashboardHref} className="btn-secondary text-sm py-2 px-3 rounded-lg">
              <HiOutlineArrowLeft className="w-4 h-4" /> Back
            </a>
            <a href={exportCsvHref} className="btn-primary text-sm py-2 px-3 rounded-lg" title="Export to CSV">
              <HiOutlineArrowDownTray className="w-4 h-4" /> Export CSV
            </a>
          </div>
        </div>
      </header>

      <div className="page-container py-6 space-y-5">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div className="card p-5 border border-slate-200 bg-white">
            <div className="flex items-center justify-between">
              <div>
                <div className="text-xs font-medium text-slate-500 uppercase tracking-wide">Total Products</div>
                <div className="text-2xl font-semibold text-slate-800 mt-1">{totalProducts.toLocaleString('en')}</div>
              </div>
              <div className="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                <HiOutlineCube className="w-6 h-6" />
              </div>
            </div>
          </div>
          <div className="card p-5 border border-slate-200 bg-white">
            <div className="flex items-center justify-between">
              <div>
                <div className="text-xs font-medium text-slate-500 uppercase tracking-wide">Total Quantity in Stock</div>
                <div className="text-2xl font-semibold text-slate-800 mt-1">{totalQty.toLocaleString('en')}</div>
              </div>
              <div className="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                <HiOutlineCircleStack className="w-6 h-6" />
              </div>
            </div>
          </div>
          <div className="card p-5 border border-slate-200 bg-white">
            <div className="flex items-center justify-between">
              <div>
                <div className="text-xs font-medium text-slate-500 uppercase tracking-wide">Total Inventory Value (TZS)</div>
                <div className="text-2xl font-semibold text-slate-800 mt-1">
                  TZS {totalInventoryTzs.toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                </div>
              </div>
              <div className="w-11 h-11 rounded-xl bg-violet-50 text-violet-600 flex items-center justify-center">
                <HiOutlineDocumentChartBar className="w-6 h-6" />
              </div>
            </div>
          </div>
        </div>

        <div className="card border border-slate-200 bg-white p-4">
          <div className="grid grid-cols-1 md:grid-cols-5 gap-3">
            <label className="md:col-span-2 flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-lg px-3">
              <HiOutlineMagnifyingGlass className="w-4 h-4 text-slate-400" />
              <input
                value={query}
                onChange={(e) => {
                  setQuery(e.target.value);
                  setPage(1);
                }}
                placeholder="Search by product name or code..."
                className="w-full bg-transparent py-2.5 text-sm outline-none"
              />
            </label>
            <label className="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-lg px-3">
              <HiOutlineTag className="w-4 h-4 text-slate-400" />
              <select
                value={categoryFilter}
                onChange={(e) => {
                  setCategoryFilter(e.target.value);
                  setPage(1);
                }}
                className="w-full bg-transparent py-2.5 text-sm outline-none"
              >
                <option value="all">All Categories</option>
                {categories.map((c) => (
                  <option key={c} value={c}>
                    {c}
                  </option>
                ))}
              </select>
            </label>
            <label className="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-lg px-3">
              <HiOutlineMapPin className="w-4 h-4 text-slate-400" />
              <select
                value={locationFilter}
                onChange={(e) => {
                  setLocationFilter(e.target.value);
                  setPage(1);
                }}
                className="w-full bg-transparent py-2.5 text-sm outline-none"
              >
                <option value="all">All Locations</option>
                {locations.map((l) => (
                  <option key={l} value={l}>
                    {l}
                  </option>
                ))}
              </select>
            </label>
            <div className="flex items-center gap-2">
              <select
                value={statusFilter}
                onChange={(e) => {
                  setStatusFilter(e.target.value);
                  setPage(1);
                }}
                className="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2.5 text-sm outline-none"
              >
                <option value="all">All Status</option>
                <option value="In Stock">In Stock</option>
                <option value="Low Stock">Low Stock</option>
                <option value="Out of Stock">Out of Stock</option>
              </select>
              <button type="button" onClick={resetFilters} className="btn-secondary text-sm py-2.5 px-3 rounded-lg whitespace-nowrap">
                Reset
              </button>
            </div>
          </div>
        </div>

        <div className="card border border-slate-200 bg-white overflow-hidden">
          <div className="overflow-x-auto">
            <table className="table-stock w-full min-w-[700px]">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Product</th>
                  <th>Category</th>
                  <th>Location</th>
                  <th className="text-center">Quantity</th>
                  <th className="text-end">Buying Price</th>
                  <th className="text-end">Total Value</th>
                  <th className="text-center">Status</th>
                </tr>
              </thead>
              <tbody>
                {pagedRows.map((row, i) => {
                  const sym = (row.currency || 'USD') === 'TZS' ? 'TSh ' : '$';
                  return (
                    <tr key={`${row.product_code}-${i}`}>
                      <td className="font-mono text-xs">{row.product_code}</td>
                      <td className="font-medium">{row.name}</td>
                      <td className="text-slate-600">{row.category || 'N/A'}</td>
                      <td className="text-slate-600">{row.location || '—'}</td>
                      <td className="text-center font-medium">{row.qty != null ? Number(row.qty) : '—'}</td>
                      <td className="text-end">{formatMoney(row.buying_price, row.currency)}</td>
                      <td className="text-end font-semibold">{formatMoney(row.total_value, row.currency)}</td>
                      <td className="text-center">
                        <span className={`inline-flex items-center px-2 py-1 rounded-md text-xs font-medium ${statusClass(row.status)}`}>
                          {row.status}
                        </span>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {filteredRows.length === 0 && (
            <div className="text-center py-12 text-slate-500">No stock data.</div>
          )}
          {filteredRows.length > 0 && (
            <div className="px-4 py-3 border-t border-slate-200 flex items-center justify-between text-sm text-slate-500">
              <span>
                Showing {(currentPage - 1) * pageSize + 1} to {Math.min(currentPage * pageSize, filteredRows.length)} of{' '}
                {filteredRows.length} products
              </span>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={currentPage <= 1}
                  className="px-2.5 py-1 border rounded-md disabled:opacity-50"
                >
                  &lt;
                </button>
                <span className="text-slate-700 font-medium">{currentPage}</span>
                <button
                  type="button"
                  onClick={() => setPage((p) => Math.min(pageCount, p + 1))}
                  disabled={currentPage >= pageCount}
                  className="px-2.5 py-1 border rounded-md disabled:opacity-50"
                >
                  &gt;
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
