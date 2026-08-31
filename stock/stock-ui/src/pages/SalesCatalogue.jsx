import React, { useState, useMemo } from 'react';
import { HiOutlineArrowLeft, HiOutlineCheck, HiOutlineMagnifyingGlass, HiOutlineStar } from 'react-icons/hi2';

export default function SalesCatalogue({ data }) {
  const {
    products = [],
    returnUrl = '',
    docType = 'quote',
    docLabel = 'Quotation',
    invoiceCounts = {},
    qtyCounts = {},
    maxInvoiceCount = 0,
    maxQtyCount = 0,
    baseUrl = '/staff/stock/',
  } = data;

  const [search, setSearch] = useState('');
  const [quantities, setQuantities] = useState({});

  const storageKey = docType === 'purchase' ? 'purchase_catalogue_items' : 'sales_catalogue_items';

  const filteredProducts = useMemo(() => {
    if (!search.trim()) return products;
    const q = search.toLowerCase();
    return products.filter(
      (p) =>
        (p.name || '').toLowerCase().includes(q) ||
        (p.product_code || '').toLowerCase().includes(q)
    );
  }, [products, search]);

  const getRating = (productId) => {
    const pid = Number(productId);
    const cnt = invoiceCounts[pid] ?? 0;
    const qty = qtyCounts[pid] ?? 0;
    if (maxInvoiceCount <= 0 && maxQtyCount <= 0) return 0;
    const invoiceScore = maxInvoiceCount > 0 ? cnt / maxInvoiceCount : 0;
    const qtyScore = maxQtyCount > 0 ? qty / maxQtyCount : 0;
    const score = 0.7 * qtyScore + 0.3 * invoiceScore;
    return Math.min(4, Math.ceil(score * 4));
  };

  const setQty = (productId, deltaOrValue) => {
    const id = String(productId);
    setQuantities((prev) => {
      const current = prev[id] ?? 0;
      const next = typeof deltaOrValue === 'function' ? deltaOrValue(current) : deltaOrValue;
      const num = Math.max(0, Number(next) || 0);
      if (num === 0) {
        const nextState = { ...prev };
        delete nextState[id];
        return nextState;
      }
      return { ...prev, [id]: num };
    });
  };

  const selectedCount = useMemo(() => {
    return Object.values(quantities).filter((q) => q > 0).length;
  }, [quantities]);

  const sendToDoc = () => {
    const items = Object.entries(quantities)
      .filter(([, q]) => q > 0)
      .map(([product_id, qty]) => ({ product_id: Number(product_id), quantity: Number(qty) }));
    if (items.length === 0) {
      if (window.Swal) Swal.fire({ icon: 'info', title: 'No items selected', text: 'Add quantities to products first.' });
      else alert('Add quantities to products first.');
      return;
    }
    try {
      localStorage.setItem(storageKey, JSON.stringify(items));
    } catch (e) {
      if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: 'Could not save selection.' });
      else alert('Could not save selection.');
      return;
    }
    if (returnUrl) {
      window.location.href = returnUrl;
    } else {
      if (window.Swal) Swal.fire({ icon: 'warning', title: 'Return URL not specified.' });
      else alert('Return URL not specified.');
    }
  };

  const placeholderImg = '/staff/assets/images/placeholder.png';

  return (
    <div className="min-h-full w-full bg-white">
      <header className="sticky top-0 z-10 bg-white/95 backdrop-blur border-b border-slate-200">
        <div className="page-container flex flex-wrap items-center justify-between gap-3 py-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-800">
              Sales Catalogue
              <span className="ml-2 inline-flex px-1.5 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">NEW</span>
            </h1>
            <p className="text-sm text-slate-500 mt-0.5">Select products and add quantities</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <a href={returnUrl || '/staff/modules/sales/orders/create.php?mode=new'} className="btn-secondary text-sm py-1.5 px-3">
              <HiOutlineArrowLeft className="w-4 h-4" /> Back
            </a>
            <button type="button" onClick={sendToDoc} className="btn-primary text-sm py-1.5 px-3">
              <HiOutlineCheck className="w-4 h-4" /> Send to {docLabel}
              {selectedCount > 0 && (
                <span className="ml-1.5 inline-flex items-center justify-center rounded-full bg-white/20 px-1.5 text-xs">
                  {selectedCount}
                </span>
              )}
            </button>
          </div>
        </div>
      </header>

      <div className="page-container py-4">
        <div className="card p-4 mb-4">
          <div className="flex flex-wrap items-center gap-4">
            <div className="flex-1 min-w-[200px] relative">
              <HiOutlineMagnifyingGlass className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
              <input
                type="text"
                className="input-base w-full pl-9"
                placeholder="Search by name or code"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
            <p className="text-sm text-slate-500">
              Selected items will be sent to the {docLabel} form.
            </p>
          </div>
        </div>

        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3 sm:gap-4">
          {filteredProducts.map((p) => {
            const qty = quantities[String(p.id)] ?? 0;
            const imgSrc = p.main_image
              ? `${baseUrl}uploads/products/${p.id}/medium/${p.main_image}`
              : placeholderImg;
            const rating = getRating(p.id);
            return (
              <div
                key={p.id}
                className={`rounded-lg border bg-white p-3 flex flex-col gap-2 transition-all ${
                  qty > 0 ? 'border-primary-500 ring-1 ring-primary-500/30 shadow-md' : 'border-slate-200 hover:border-slate-300'
                }`}
              >
                <div className="flex justify-between items-center text-xs text-slate-500">
                  <span>{p.product_code || 'N/A'}</span>
                  <span>{Number(p.stock_quantity ?? 0)} units</span>
                </div>
                <div className="flex items-center gap-2 min-h-[44px]">
                  <img
                    src={imgSrc}
                    alt=""
                    className="w-12 h-12 sm:w-14 sm:h-14 rounded-lg border border-slate-200 object-cover flex-shrink-0"
                    onError={(e) => {
                      e.target.src = placeholderImg;
                    }}
                  />
                  <span className="text-sm font-medium text-slate-800 line-clamp-2">{p.name}</span>
                </div>
                <div className="flex justify-between items-center text-sm">
                  <span className="text-slate-500">Unit price</span>
                  <span className="font-semibold text-slate-800">
                    {Number(p.selling_price ?? 0).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                  </span>
                </div>
                <div className="flex justify-end gap-1 text-amber-500" title="Popularity this month">
                  {[1, 2, 3, 4].map((i) => (
                    <HiOutlineStar
                      key={i}
                      className={`w-4 h-4 ${i <= rating ? 'fill-amber-400 text-amber-500' : 'text-slate-200'}`}
                    />
                  ))}
                </div>
                <div className="flex items-center justify-end gap-1.5 mt-1">
                  <button
                    type="button"
                    onClick={() => setQty(p.id, (v) => v - 1)}
                    className="w-7 h-7 rounded-full bg-primary-600 text-white flex items-center justify-center text-sm font-bold hover:bg-primary-700 disabled:opacity-40 disabled:pointer-events-none"
                    disabled={qty <= 0}
                  >
                    −
                  </button>
                  <input
                    type="number"
                    min={0}
                    className="w-11 h-7 text-center text-sm border border-slate-300 rounded-md"
                    value={qty}
                    onChange={(e) => setQty(p.id, e.target.value)}
                  />
                  <button
                    type="button"
                    onClick={() => setQty(p.id, (v) => v + 1)}
                    className="w-7 h-7 rounded-full bg-primary-600 text-white flex items-center justify-center text-sm font-bold hover:bg-primary-700"
                  >
                    +
                  </button>
                </div>
              </div>
            );
          })}
        </div>

        {filteredProducts.length === 0 && (
          <div className="text-center py-12 text-slate-500">
            {products.length === 0 ? 'No products in catalogue.' : 'No products match your search.'}
          </div>
        )}
      </div>
    </div>
  );
}
