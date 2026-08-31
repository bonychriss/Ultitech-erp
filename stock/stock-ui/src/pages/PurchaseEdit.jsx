import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  HiOutlineArrowLeft,
  HiOutlinePlus,
  HiOutlineTrash,
  HiOutlineCube,
  HiOutlineExclamationCircle,
  HiOutlineCheckCircle,
} from 'react-icons/hi2';
import './purchase-edit.css';

const newRowId = () => 'r-' + Date.now() + '-' + Math.random().toString(36).slice(2, 9);

const SECTIONS = [
  { id: 'pe-supplier', label: 'Supplier' },
  { id: 'pe-items', label: 'Order Items' },
  { id: 'pe-notes', label: 'Notes & Terms' },
];

function productUnitBase(product) {
  if (!product) return 0;
  return Number(product.buying_price ?? product.unit_price) || 0;
}

function productImageSrc(product, rowProductId, baseUrl, placeholder) {
  if (product?.image_url) return product.image_url;
  if (product?.main_image) {
    const imageProductId = product.image_product_id || product.linked_product_id || rowProductId;
    return `${baseUrl}uploads/products/${imageProductId}/medium/${product.main_image}`;
  }
  return placeholder || null;
}

function currencyFlagUrl(meta) {
  const flagUrl = String(meta?.flag_url || '').trim();
  if (flagUrl) return flagUrl;

  const country = String(meta?.flag || '').trim().toLowerCase();
  if (country) return `https://flagcdn.com/w40/${country}.png`;

  return (
    'data:image/svg+xml,' +
    encodeURIComponent(
      '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">'
        + '<circle cx="12" cy="12" r="10" fill="#e2e8f0"/>'
        + '<path d="M8 12h8M12 8v8" stroke="#64748b" stroke-width="1.5" stroke-linecap="round"/>'
        + '</svg>',
    )
  );
}

export default function PurchaseEdit({ data }) {
  const {
    po = null,
    existing_items = [],
    suppliers = [],
    products = [],
    poCurrencyOptions = {},
    pricesLocked = false,
    displayBotRate = null,
    baseUrl = '/staff/stock/',
    apiUrl = 'edit.php',
    indexUrl = 'index.php',
    exchangeRateApiUrl = 'exchange_rate.php',
    productImagePlaceholder = '',
    error: initialError = '',
    success: initialSuccess = '',
  } = data;

  const initialDisplayRate = (() => {
    if (displayBotRate != null && Number(displayBotRate) > 0) {
      return Number(displayBotRate);
    }
    const code = po ? (po.currency || 'TZS') : 'TZS';
    if (code === 'TZS') return 1;
    return po ? Number(po.exchange_rate) || 1 : 1;
  })();

  const [supplierId, setSupplierId] = useState(po ? String(po.supplier_id) : '');
  const [supplierInvoiceNo, setSupplierInvoiceNo] = useState(po ? (po.supplier_invoice_no || '') : '');
  const [purchaseOrderDate, setPurchaseOrderDate] = useState(() => {
    if (po?.purchase_order_date) return String(po.purchase_order_date).slice(0, 10);
    if (po?.created_at) return String(po.created_at).slice(0, 10);
    return new Date().toISOString().slice(0, 10);
  });
  const [notes, setNotes] = useState(po?.notes ?? '');
  const [termsConditions, setTermsConditions] = useState(po?.terms_conditions ?? po?.terms ?? '');
  const [taxPercentage, setTaxPercentage] = useState(po ? Number(po.tax_percentage) || 0 : 0);
  const [currencyCode, setCurrencyCode] = useState(po ? (po.currency || 'TZS') : 'TZS');
  const [exchangeRate, setExchangeRate] = useState(initialDisplayRate);
  const [items, setItems] = useState(() => {
    if (existing_items && existing_items.length > 0) {
      return existing_items.map((it) => {
        const basePrice = Number(it.unit_price) || 0;
        return {
          id: newRowId(),
          line_id: it.line_id || it.id || '',
          product_id: String(it.product_id || ''),
          quantity: Number(it.quantity) || 1,
          unit_price: Number((basePrice * initialDisplayRate).toFixed(2)),
          base_price: basePrice,
          qty_received: Number(it.qty_received) || 0,
        };
      });
    }
    return [{ id: newRowId(), line_id: '', product_id: '', quantity: 1, unit_price: 0, base_price: 0, qty_received: 0 }];
  });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error] = useState(initialError);
  const [success] = useState(initialSuccess);
  const [activeSection, setActiveSection] = useState(SECTIONS[0].id);
  const [currencyMenuOpen, setCurrencyMenuOpen] = useState(false);
  const currencyPickerRef = useRef(null);

  const currencyCodes = useMemo(() => Object.keys(poCurrencyOptions), [poCurrencyOptions]);
  const selectedCurrencyMeta = poCurrencyOptions[currencyCode] || { name: currencyCode };
  const selectedCurrencyFlagUrl = currencyFlagUrl(selectedCurrencyMeta);

  useEffect(() => {
    if (!currencyMenuOpen) return undefined;

    function handlePointerDown(event) {
      if (!currencyPickerRef.current?.contains(event.target)) {
        setCurrencyMenuOpen(false);
      }
    }

    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        setCurrencyMenuOpen(false);
      }
    }

    document.addEventListener('mousedown', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('mousedown', handlePointerDown);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [currencyMenuOpen]);

  useEffect(() => {
    const observer = new IntersectionObserver(
      (entries) => {
        const visible = entries
          .filter((e) => e.isIntersecting)
          .sort((a, b) => b.intersectionRatio - a.intersectionRatio);
        if (visible[0]) setActiveSection(visible[0].target.id);
      },
      { rootMargin: '-40% 0px -50% 0px', threshold: [0, 0.25, 0.5, 1] },
    );
    SECTIONS.forEach((s) => {
      const el = document.getElementById(s.id);
      if (el) observer.observe(el);
    });
    return () => observer.disconnect();
  }, []);

  const updateItemsCurrencyPricing = (rateNum) => {
    setItems((prevItems) =>
      prevItems.map((it) => {
        const basePrice = Number(it.base_price) || 0;
        return {
          ...it,
          unit_price: Number((basePrice * rateNum).toFixed(2)),
        };
      }),
    );
  };

  const handleCurrencyChange = async (code) => {
    setCurrencyCode(code);
    setCurrencyMenuOpen(false);
    if (code === 'TZS') {
      setExchangeRate(1.0);
      updateItemsCurrencyPricing(1.0);
      return;
    }
    try {
      const res = await fetch(`${exchangeRateApiUrl}?currency=${encodeURIComponent(code)}`, {
        credentials: 'same-origin',
      });
      const result = await res.json();
      if (result.ok && result.rate) {
        const newRate = parseFloat(result.rate) || 1.0;
        setExchangeRate(newRate);
        updateItemsCurrencyPricing(newRate);
      }
    } catch (err) {
      console.error('Failed to fetch exchange rate', err);
    }
  };

  const handleExchangeRateChange = (rateVal) => {
    setExchangeRate(rateVal);
    const rateNum = parseFloat(rateVal) || 0;
    if (rateNum > 0) updateItemsCurrencyPricing(rateNum);
  };

  const addRow = () => {
    setItems((prev) => [
      ...prev,
      { id: newRowId(), line_id: '', product_id: '', quantity: 1, unit_price: 0, base_price: 0, qty_received: 0 },
    ]);
  };

  const removeRow = (id) => {
    setItems((prev) => (prev.length > 1 ? prev.filter((r) => r.id !== id) : prev));
  };

  const updateRow = (id, updates) => {
    setItems((prev) =>
      prev.map((r) => {
        if (r.id !== id) return r;
        const next = { ...r, ...updates };
        if (updates.unit_price !== undefined) {
          const nextPrice = parseFloat(updates.unit_price) || 0;
          const rateNum = Number(exchangeRate) || 1;
          next.base_price = rateNum > 0 ? nextPrice / rateNum : nextPrice;
        }
        return next;
      }),
    );
  };

  const onProductChange = (id, productId) => {
    const p = products.find((x) => String(x.id) === String(productId));
    const rateNum = Number(exchangeRate) || 1;
    const baseVal = productUnitBase(p);
    updateRow(id, {
      product_id: String(productId || ''),
      unit_price: Number((baseVal * rateNum).toFixed(2)),
      base_price: baseVal,
    });
  };

  const subtotal = useMemo(
    () => items.reduce((sum, r) => sum + (Number(r.quantity) || 0) * (Number(r.unit_price) || 0), 0),
    [items],
  );
  const taxAmount = (subtotal * (Number(taxPercentage) || 0)) / 100;
  const grandTotal = subtotal + taxAmount;

  const formatMoney = (n) =>
    currencyCode +
    ' ' +
    (Number(n) || 0).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  const scrollToSection = (id) => {
    const el = document.getElementById(id);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  return (
    <div className="pe-shell">
      <div className="pe-topbar">
        <div>
          <h1>
            Edit Purchase Order: <span>{po?.po_number}</span>
          </h1>
          <p>
            Status: <strong style={{ color: '#1e293b' }}>{po?.status || '—'}</strong>
            {pricesLocked ? ' · Unit prices are locked for this status.' : ' · Update supplier, lines, currency, and terms.'}
          </p>
        </div>
        <a href={indexUrl} className="pe-btn pe-btn--ghost">
          <HiOutlineArrowLeft style={{ width: 16, height: 16 }} /> Back to list
        </a>
      </div>

      {success && (
        <div className="pe-alert pe-alert--success">
          <HiOutlineCheckCircle style={{ width: 18, height: 18, flexShrink: 0 }} />
          <div>{success}</div>
        </div>
      )}
      {error && (
        <div className="pe-alert pe-alert--error">
          <HiOutlineExclamationCircle style={{ width: 18, height: 18, flexShrink: 0 }} />
          <div>{error}</div>
        </div>
      )}
      {pricesLocked && (
        <div className="pe-alert pe-alert--warn">
          <HiOutlineExclamationCircle style={{ width: 18, height: 18, flexShrink: 0 }} />
          <div>
            This order is <strong>{po?.status}</strong>. You can update supplier, quantities, and references, but unit
            prices stay locked.
          </div>
        </div>
      )}

      <form method="POST" action={apiUrl} onSubmit={() => setIsSubmitting(true)} className="pe-layout">
        <nav className="pe-nav" aria-label="Form sections">
          {SECTIONS.map((s) => (
            <button
              key={s.id}
              type="button"
              className={`pe-nav-item${activeSection === s.id ? ' is-active' : ''}`}
              onClick={() => scrollToSection(s.id)}
            >
              {s.label}
            </button>
          ))}
        </nav>

        <div className="pe-main">
          <section id="pe-supplier" className="pe-section">
            <header className="pe-section-head">
              <h2>Supplier &amp; Reference</h2>
              <p>Who you are buying from, currency, and PO date.</p>
            </header>

            <div className="pe-row">
              <label className="pe-label">
                Supplier <span className="pe-req">*</span>
              </label>
              <div className="pe-field">
                <select
                  className="pe-select"
                  name="supplier_id"
                  required
                  value={supplierId}
                  onChange={(e) => setSupplierId(e.target.value)}
                >
                  <option value="">Select supplier</option>
                  {suppliers.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div className="pe-row">
              <label className="pe-label">
                PO Date <span className="pe-req">*</span>
              </label>
              <div className="pe-field pe-field--narrow">
                <input
                  type="date"
                  name="purchase_order_date"
                  required
                  className="pe-input"
                  value={purchaseOrderDate}
                  onChange={(e) => setPurchaseOrderDate(e.target.value)}
                />
              </div>
            </div>

            <div className="pe-row">
              <label className="pe-label">Currency</label>
              <div className="pe-field pe-field--narrow">
                <input type="hidden" name="currency_code" value={currencyCode} />
                <div
                  className={`pe-currency-picker${currencyMenuOpen ? ' is-open' : ''}`}
                  ref={currencyPickerRef}
                >
                  <button
                    type="button"
                    className="pe-currency-picker-trigger"
                    aria-haspopup="listbox"
                    aria-expanded={currencyMenuOpen}
                    onClick={() => setCurrencyMenuOpen((open) => !open)}
                  >
                    <img
                      src={selectedCurrencyFlagUrl}
                      alt=""
                      className="pe-currency-flag"
                      width={28}
                      height={20}
                    />
                    <span className="pe-currency-picker-label">
                      <span className="code">{currencyCode}</span>
                      <span className="name">{selectedCurrencyMeta.name || currencyCode}</span>
                    </span>
                  </button>
                  {currencyMenuOpen && (
                    <div className="pe-currency-picker-menu" role="listbox" aria-label="Currency options">
                      {currencyCodes.map((code) => {
                        const meta = poCurrencyOptions[code] || { name: code };
                        const flagUrl = currencyFlagUrl(meta);
                        const isSelected = code === currencyCode;

                        return (
                          <button
                            key={code}
                            type="button"
                            className={`pe-currency-picker-option${isSelected ? ' is-selected' : ''}`}
                            role="option"
                            aria-selected={isSelected}
                            onClick={() => handleCurrencyChange(code)}
                          >
                            <img
                              src={flagUrl}
                              alt=""
                              className="pe-currency-flag"
                              width={28}
                              height={20}
                              loading="lazy"
                            />
                            <span className="code">{code}</span>
                            <span className="name">{meta.name || code}</span>
                          </button>
                        );
                      })}
                    </div>
                  )}
                </div>
              </div>
            </div>

            <div className="pe-row">
              <label className="pe-label">Exchange Rate</label>
              <div className="pe-field pe-field--narrow">
                <input
                  type="number"
                  step="0.0001"
                  min="0.0001"
                  name="exchange_rate"
                  className={`pe-input${currencyCode === 'TZS' ? ' pe-input--ro' : ''}`}
                  value={exchangeRate}
                  onChange={(e) => handleExchangeRateChange(e.target.value)}
                  readOnly={currencyCode === 'TZS'}
                />
              </div>
            </div>

            <div className="pe-row">
              <label className="pe-label">Supplier Invoice #</label>
              <div className="pe-field pe-field--narrow">
                <input
                  type="text"
                  name="supplier_invoice_no"
                  className="pe-input"
                  value={supplierInvoiceNo}
                  onChange={(e) => setSupplierInvoiceNo(e.target.value)}
                  placeholder="Optional"
                />
              </div>
            </div>

          </section>

          <section id="pe-items" className="pe-section">
            <header className="pe-section-head">
              <h2>Order Items</h2>
              <p>Products, quantities, and unit prices in {currencyCode}.</p>
            </header>

            <div className="pe-items">
              {items.map((row, index) => {
                const prod = products.find((p) => String(p.id) === String(row.product_id));
                const total = (Number(row.quantity) || 0) * (Number(row.unit_price) || 0);
                const imgSrc = productImageSrc(prod, row.product_id, baseUrl, productImagePlaceholder);
                const isRowLocked = row.qty_received > 0;

                return (
                  <div className="pe-item" key={row.id}>
                    <div className="pe-item-top">
                      <span className="pe-item-title">Item {index + 1}</span>
                      {!isRowLocked && (
                        <button
                          type="button"
                          className="pe-icon-btn"
                          onClick={() => removeRow(row.id)}
                          title="Remove item"
                          aria-label="Remove item"
                        >
                          <HiOutlineTrash style={{ width: 16, height: 16 }} />
                        </button>
                      )}
                    </div>

                    <div className="pe-item-grid">
                      <div className="pe-item-thumb">
                        {imgSrc ? (
                          <img
                            src={imgSrc}
                            alt=""
                            onError={(e) => {
                              if (productImagePlaceholder && e.currentTarget.src !== productImagePlaceholder) {
                                e.currentTarget.src = productImagePlaceholder;
                              } else {
                                e.currentTarget.style.display = 'none';
                              }
                            }}
                          />
                        ) : (
                          <HiOutlineCube style={{ width: 22, height: 22 }} />
                        )}
                      </div>

                      <div>
                        <input type="hidden" name="line_id[]" value={row.line_id || ''} />
                        <div className="pe-item-fields">
                          <div style={{ gridColumn: '1 / -1' }}>
                            <span className="pe-field-label">Product</span>
                            <select
                              className="pe-select"
                              name={isRowLocked ? undefined : 'product_id[]'}
                              required={!isRowLocked}
                              value={row.product_id}
                              onChange={(e) => onProductChange(row.id, e.target.value)}
                              disabled={isRowLocked}
                            >
                              <option value="">Select product</option>
                              {products.map((p) => (
                                <option key={p.id} value={p.id}>
                                  {p.name} ({p.product_code})
                                </option>
                              ))}
                            </select>
                            {isRowLocked && <input type="hidden" name="product_id[]" value={row.product_id} />}
                            {isRowLocked && (
                              <div className="pe-item-meta">
                                {row.qty_received} already received — product locked
                              </div>
                            )}
                          </div>

                          <div>
                            <span className="pe-field-label">Qty</span>
                            <input
                              type="number"
                              name="quantity[]"
                              className="pe-input"
                              min={isRowLocked ? row.qty_received : 0.01}
                              step="0.01"
                              value={row.quantity}
                              onChange={(e) => updateRow(row.id, { quantity: e.target.value })}
                            />
                          </div>

                          <div>
                            <span className="pe-field-label">Unit ({currencyCode})</span>
                            <input
                              type="number"
                              step="0.01"
                              name="unit_price[]"
                              className={`pe-input${pricesLocked ? ' pe-input--ro' : ''}`}
                              value={row.unit_price || ''}
                              onChange={(e) => updateRow(row.id, { unit_price: e.target.value })}
                              readOnly={pricesLocked}
                            />
                          </div>

                          <div>
                            <span className="pe-field-label">Line total</span>
                            <input type="text" className="pe-input pe-input--ro" value={formatMoney(total)} readOnly />
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>

            <div style={{ marginTop: 14 }}>
              <button type="button" className="pe-btn pe-btn--ghost" onClick={addRow}>
                <HiOutlinePlus style={{ width: 16, height: 16 }} /> Add item
              </button>
            </div>

            <div className="pe-row" style={{ marginTop: 24 }}>
              <label className="pe-label">Tax rate (%)</label>
              <div className="pe-field pe-field--narrow">
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  name="tax_percentage"
                  className="pe-input"
                  value={taxPercentage}
                  onChange={(e) => setTaxPercentage(e.target.value)}
                />
              </div>
            </div>

            <div className="pe-row">
              <label className="pe-label">Subtotal</label>
              <div className="pe-field pe-field--narrow">
                <input type="text" className="pe-input pe-input--ro" value={formatMoney(subtotal)} readOnly />
              </div>
            </div>

            <div className="pe-row">
              <label className="pe-label">Tax amount</label>
              <div className="pe-field pe-field--narrow">
                <input type="text" className="pe-input pe-input--ro" value={formatMoney(taxAmount)} readOnly />
              </div>
            </div>

            <div className="pe-row">
              <label className="pe-label">Grand total</label>
              <div className="pe-field pe-field--narrow">
                <input type="text" className="pe-input pe-input--ro" value={formatMoney(grandTotal)} readOnly />
              </div>
            </div>
          </section>

          <section id="pe-notes" className="pe-section">
            <header className="pe-section-head">
              <h2>Notes &amp; Terms</h2>
              <p>Internal notes and terms shown on the purchase order.</p>
            </header>

            <div className="pe-row pe-row--top">
              <label className="pe-label">Notes</label>
              <div className="pe-field">
                <textarea
                  name="notes"
                  rows={3}
                  className="pe-textarea"
                  placeholder="Internal notes…"
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                />
              </div>
            </div>

            <div className="pe-row pe-row--top">
              <label className="pe-label">Terms &amp; Conditions</label>
              <div className="pe-field">
                <textarea
                  name="terms_conditions"
                  rows={5}
                  className="pe-textarea"
                  placeholder="Terms for the PO…"
                  value={termsConditions}
                  onChange={(e) => setTermsConditions(e.target.value)}
                />
              </div>
            </div>

            <div className="pe-actions">
              <a href={indexUrl} className="pe-btn pe-btn--ghost">
                Cancel
              </a>
              <button type="submit" className="pe-btn pe-btn--primary" disabled={isSubmitting}>
                {isSubmitting ? 'Updating…' : 'Update Purchase Order'}
              </button>
            </div>
          </section>
        </div>
      </form>
    </div>
  );
}
