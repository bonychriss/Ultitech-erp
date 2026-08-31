import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ImageOff, Loader2, Trash2, X } from 'lucide-react';
import { fetchCreateInit, fetchExchangeRate, submitCreateInvoice, submitCreateQuote } from '../api/invoicesDesk';

const FLAG_BASE = 'https://flagcdn.com/w40/';

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

function dueDateIso(days = 30) {
  return new Date(Date.now() + days * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
}

function validUntilIso(days = 7) {
  return new Date(Date.now() + days * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
}

function formatCurrency(val) {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val || 0);
}

function productImageUrl(product, stockUploadsBase = '') {
  if (product?.image_url) return product.image_url;
  const id = product?.id;
  const mainImage = product?.main_image;
  if (id && mainImage && stockUploadsBase) {
    return `${stockUploadsBase}/${id}/thumbnail/${mainImage}`;
  }
  return '';
}

function ProductThumb({ src, boxClassName, iconSize = 18, title }) {
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    setFailed(false);
  }, [src]);

  const showImage = Boolean(src) && !failed;

  return (
    <div className={boxClassName} title={title}>
      {showImage ? (
        <img src={src} alt="" onError={() => setFailed(true)} />
      ) : (
        <ImageOff size={iconSize} className="inv-line-item-thumb-icon" aria-hidden />
      )}
    </div>
  );
}

function emptyLine(defaultTax = 18) {
  return {
    id: Date.now() + Math.random(),
    product_id: '',
    quantity: 1,
    unit_price: 0,
    discount: 0,
    tax_percent: defaultTax,
    line_total: 0,
    description: '',
    image: '',
    searchQuery: '',
    showDropdown: false,
    focusIndex: -1,
  };
}

function recalcLineTotal(item) {
  const qty = parseFloat(item.quantity) || 0;
  const price = parseFloat(item.unit_price) || 0;
  const disc = parseFloat(item.discount) || 0;
  return qty * price * (1 - disc / 100);
}

function formatRateHint(data, code) {
  if (!data || !data.ok) return data?.error || 'Could not load BOT rate. Enter manually.';
  const src = data.via_ai ? 'BOT (AI)' : (data.source || 'BOT');
  const asOf = data.as_of ? ` as of ${data.as_of}` : '';
  return `${src} mean rate: ${Number(data.rate).toFixed(4)} TZS per 1 ${code} (${src}${asOf}). You may adjust before saving.`;
}

function ensureDotLottiePlayer() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  if (customElements.get('dotlottie-wc')) return;
  if (document.getElementById('inv-dotlottie-wc')) return;
  const script = document.createElement('script');
  script.id = 'inv-dotlottie-wc';
  script.type = 'module';
  script.src = 'https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.5/dist/dotlottie-wc.js';
  document.head.appendChild(script);
}

function MoneySavingOverlay({ src, label = 'Creating invoice...' }) {
  useEffect(() => {
    ensureDotLottiePlayer();
  }, []);

  return (
    <div className="exp-create-money-overlay" role="status" aria-live="polite">
      <div className="exp-create-money-overlay-card">
        <div className="exp-create-money-overlay-anim" aria-hidden="true">
          {src ? (
            <dotlottie-wc src={src} autoplay loop speed="1" style={{ width: '220px', height: '220px' }} />
          ) : null}
        </div>
        <p className="exp-create-money-overlay-copy">{label}</p>
      </div>
    </div>
  );
}

export default function InvoiceCreatePage() {
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState([]);
  const [currencyMenuOpen, setCurrencyMenuOpen] = useState(false);
  const [rateHint, setRateHint] = useState('Select one or more currencies. BOT rates load automatically for non-TZS codes.');
  const [rateLoadingCodes, setRateLoadingCodes] = useState([]);
  const rateFetchToken = useRef(0);
  const currencyRef = useRef(null);
  const productSearchRefs = useRef(new Map());

  const [customerId, setCustomerId] = useState('');
  const [invoiceDate, setInvoiceDate] = useState(todayIso());
  const [dueDate, setDueDate] = useState(dueDateIso());
  const [validUntil, setValidUntil] = useState(validUntilIso());
  const [leadTime, setLeadTime] = useState('');
  const [orderType, setOrderType] = useState('spare');
  const [discountAmount, setDiscountAmount] = useState(0);
  const [taxPercentage, setTaxPercentage] = useState(18);
  const [shippingCharges, setShippingCharges] = useState(0);
  const [currencies, setCurrencies] = useState([]);
  const [primaryCurrency, setPrimaryCurrencyState] = useState('');
  const [exchangeRates, setExchangeRates] = useState({ TZS: '1.0000' });
  const [items, setItems] = useState([emptyLine()]);

  const closeAllProductDropdowns = useCallback(() => {
    setItems((prev) => prev.map((item) => (
      item.showDropdown ? { ...item, showDropdown: false, focusIndex: -1 } : item
    )));
  }, []);

  const hasOpenProductDropdown = useMemo(
    () => items.some((item) => item.showDropdown),
    [items],
  );

  const isRoadmaster = !!init?.is_roadmaster;
  const isUltimate = !!init?.is_ultimate;
  const supportsTruckInvoices = !!init?.supports_truck_invoices;
  const documentType = init?.document_type || 'invoice';
  const isQuote = documentType === 'quote';
  const isTruckDocument = supportsTruckInvoices && orderType === 'truck';
  const taxMode = init?.tax_mode || 'exclusive';
  const indexUrl = init?.index_url || init?.invoices_index_url || '../index.php';
  const submitLabel = init?.submit_label || (isQuote ? 'Create Quotation' : 'Create Invoice');
  const moneyAnimSrc = init?.money_animation_url || '/assets/animations/Money.lottie';
  const isMarketCustomer = useMemo(() => {
    const list = init?.customers || [];
    const selected = list.find((c) => String(c.id) === String(customerId));
    return Boolean(selected?.from_market);
  }, [init, customerId]);
  const showMoneySavingOverlay = saving && !isQuote && isMarketCustomer;

  const loadInit = useCallback(async () => {
    setLoading(true);
    try {
      const data = await fetchCreateInit();
      setInit(data);
      const supportsTruck = !!data.supports_truck_invoices;
      setOrderType(supportsTruck ? (data.predefined_type || 'spare') : 'spare');
      setExchangeRates({ TZS: '1.0000', ...(data.initial_exchange_rates || {}) });
      const isTruckMode = supportsTruck && data.predefined_type === 'truck';
      if (isTruckMode) {
        setCurrencies([]);
        setPrimaryCurrencyState('');
      } else {
        const code = String(data.default_currency || 'TZS').toUpperCase();
        setCurrencies([code]);
        setPrimaryCurrencyState(code);
      }

      const picked = new URLSearchParams(window.location.search).get('customer_id')
        || localStorage.getItem('selected_customer_id');
      if (picked) {
        setCustomerId(String(picked));
        localStorage.removeItem('selected_customer_id');
      }

      const productsList = data.products || [];
      const mapCatalogueItems = (catItems) => catItems.map((ci) => {
        const prod = productsList.find((p) => String(p.id) === String(ci.product_id));
        if (!prod) return null;
        const qty = parseFloat(ci.quantity) || 1;
        const price = parseFloat(prod.selling_price) || 0;
        const line = emptyLine();
        line.product_id = prod.id;
        line.searchQuery = prod.name;
        line.unit_price = price;
        line.description = prod.description || '';
        line.image = prod.image_url || '';
        line.quantity = qty;
        line.line_total = recalcLineTotal(line);
        return line;
      }).filter(Boolean);

      let restored = false;
      try {
        const raw = localStorage.getItem('sales_catalogue_items');
        if (raw) {
          const catItems = JSON.parse(raw);
          if (Array.isArray(catItems) && catItems.length > 0) {
            const mapped = mapCatalogueItems(catItems);
            if (mapped.length) {
              setItems(mapped);
              restored = true;
            }
          }
          localStorage.removeItem('sales_catalogue_items');
        }
      } catch {
        // ignore catalogue restore errors
      }

      if (!restored) {
        const idsParam = new URLSearchParams(window.location.search).get('catalogue_product_ids');
        if (idsParam) {
          const ids = idsParam.split(',').map((s) => Number(s.trim())).filter((id) => id > 0);
          if (ids.length > 0) {
            const mapped = mapCatalogueItems(ids.map((id) => ({ product_id: id, quantity: 1 })));
            if (mapped.length) setItems(mapped);
          }
          try {
            const url = new URL(window.location.href);
            url.searchParams.delete('catalogue_product_ids');
            window.history.replaceState({}, '', url.toString());
          } catch {
            // ignore URL cleanup errors
          }
        }
      }
    } catch (err) {
      setErrors([err instanceof Error ? err.message : 'Failed to load form.']);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { loadInit(); }, [loadInit]);

  useEffect(() => {
    if (!currencyMenuOpen) return undefined;
    function onDocClick(e) {
      if (!currencyRef.current?.contains(e.target)) setCurrencyMenuOpen(false);
    }
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, [currencyMenuOpen]);

  useEffect(() => {
    if (!hasOpenProductDropdown) return undefined;
    function onDocClick(e) {
      let inside = false;
      productSearchRefs.current.forEach((el) => {
        if (el?.contains(e.target)) inside = true;
      });
      if (!inside) closeAllProductDropdowns();
    }
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, [hasOpenProductDropdown, closeAllProductDropdowns]);

  const fetchBotRate = useCallback(async (code) => {
    const currencyCode = String(code || 'TZS').toUpperCase();
    if (currencyCode === 'TZS') {
      setExchangeRates((prev) => ({ ...prev, TZS: '1.0000' }));
      return;
    }
    const token = ++rateFetchToken.current;
    setRateLoadingCodes((prev) => [...new Set([...prev, currencyCode])]);
    try {
      const data = await fetchExchangeRate(currencyCode, init?.exchange_rate_api_url);
      if (token !== rateFetchToken.current) return;
      if (data?.ok && data.rate) {
        setExchangeRates((prev) => ({ ...prev, [currencyCode]: Number(data.rate).toFixed(4) }));
        setRateHint(formatRateHint(data, currencyCode));
      } else if (init?.initial_exchange_rates?.[currencyCode]) {
        setExchangeRates((prev) => ({ ...prev, [currencyCode]: init.initial_exchange_rates[currencyCode] }));
      } else {
        setRateHint(formatRateHint(data, currencyCode));
      }
    } catch {
      if (token === rateFetchToken.current) setRateHint(`Could not fetch BOT rate for ${currencyCode}. Enter manually.`);
    } finally {
      if (token === rateFetchToken.current) {
        setRateLoadingCodes((prev) => prev.filter((c) => c !== currencyCode));
      }
    }
  }, [init]);

  const displayCurrencies = useMemo(() => {
    if (!currencies.length) return [];
    const primary = primaryCurrency || currencies[0];
    const ordered = [primary];
    currencies.forEach((code) => {
      if (code !== primary && !ordered.includes(code)) ordered.push(code);
    });
    return ordered;
  }, [currencies, primaryCurrency]);

  const hasSelectedCurrencies = displayCurrencies.length > 0;

  const convertAmount = useCallback((amount, fromCode, toCode) => {
    const from = String(fromCode || 'TZS').toUpperCase();
    const to = String(toCode || 'TZS').toUpperCase();
    if (from === to) return amount;
    const toTzs = (value, code) => {
      if (code === 'TZS') return value;
      const rate = parseFloat(exchangeRates[code]) || 0;
      return rate > 0 ? value * rate : value;
    };
    const fromTzs = (tzsValue, code) => {
      if (code === 'TZS') return tzsValue;
      const rate = parseFloat(exchangeRates[code]) || 0;
      return rate > 0 ? tzsValue / rate : tzsValue;
    };
    return fromTzs(toTzs(amount, from), to);
  }, [exchangeRates]);

  const moneyLabel = useCallback((amount) => {
    if (!hasSelectedCurrencies) return 'Select currency';
    const primary = primaryCurrency || displayCurrencies[0];
    return displayCurrencies.map((code) => `${code} ${formatCurrency(convertAmount(amount, primary, code))}`).join(' | ');
  }, [hasSelectedCurrencies, displayCurrencies, primaryCurrency, convertAmount]);

  const totals = useMemo(() => {
    const grossSubtotal = items.reduce((sum, item) => sum + (parseFloat(item.line_total) || 0), 0);
    const discountAmt = parseFloat(discountAmount) || 0;
    const afterDisc = Math.max(0, grossSubtotal - discountAmt);
    const shipping = parseFloat(shippingCharges) || 0;
    if (taxMode === 'inclusive') {
      const subtotal2 = items.reduce((sum, item) => {
        const gross = parseFloat(item.line_total) || 0;
        const pct = Number.isFinite(parseFloat(item.tax_percent)) ? parseFloat(item.tax_percent) : (parseFloat(taxPercentage) || 18);
        if (pct <= 0) return sum + gross;
        return sum + gross / (1 + pct / 100);
      }, 0);
      const afterDiscSubtotal = Math.max(0, subtotal2 - discountAmt);
      let taxAmt2 = Math.max(0, grossSubtotal - subtotal2);
      if (subtotal2 > 0 && afterDiscSubtotal < subtotal2) taxAmt2 *= afterDiscSubtotal / subtotal2;
      return { subtotal: subtotal2, taxAmt: taxAmt2, grandTotal: afterDiscSubtotal + taxAmt2 + shipping };
    }
    const taxAmt = items.reduce((sum, item) => {
      const base = parseFloat(item.line_total) || 0;
      const pct = Number.isFinite(parseFloat(item.tax_percent)) ? parseFloat(item.tax_percent) : (parseFloat(taxPercentage) || 18);
      return sum + base * (pct / 100);
    }, 0);
    return { subtotal: grossSubtotal, taxAmt, grandTotal: afterDisc + taxAmt + shipping };
  }, [items, discountAmount, taxPercentage, shippingCharges, taxMode]);

  function toggleCurrency(code) {
    const currencyCode = String(code || 'TZS').toUpperCase();
    setCurrencies((prev) => {
      const selected = [...prev];
      const idx = selected.indexOf(currencyCode);
      if (idx >= 0) {
        selected.splice(idx, 1);
        setPrimaryCurrencyState((p) => (p === currencyCode ? (selected[0] || '') : p));
        return selected;
      }
      selected.push(currencyCode);
      setPrimaryCurrencyState((p) => p || currencyCode);
      if (currencyCode !== 'TZS') fetchBotRate(currencyCode);
      return selected;
    });
  }

  function setPrimaryCurrency(code) {
    const currencyCode = String(code || 'TZS').toUpperCase();
    setCurrencies((prev) => (prev.includes(currencyCode) ? prev : [...prev, currencyCode]));
    setPrimaryCurrencyState(currencyCode);
    if (currencyCode !== 'TZS' && !exchangeRates[currencyCode]) fetchBotRate(currencyCode);
  }

  function updateItem(index, patch) {
    setItems((prev) => {
      const next = [...prev];
      const item = { ...next[index], ...patch };
      item.line_total = recalcLineTotal(item);
      next[index] = item;
      return next;
    });
  }

  function selectProduct(index, product) {
    if (!product) return;
    updateItem(index, {
      product_id: product.id,
      searchQuery: product.name || '',
      unit_price: parseFloat(product.selling_price) || 0,
      description: product.description || '',
      image: productImageUrl(product, init?.stock_uploads_base) || '',
      showDropdown: false,
      focusIndex: -1,
    });
    if (supportsTruckInvoices && product.item_type === 'vehicle' && orderType !== 'truck') {
      setOrderType('truck');
      setCurrencies([]);
      setPrimaryCurrencyState('');
    }
  }

  function buildFormData() {
    const formData = new FormData();
    formData.append('customer_id', customerId);
    if (isQuote) {
      formData.append('quote_date', invoiceDate);
      formData.append('valid_until', validUntil);
      formData.append('status', 'quotation');
      formData.append('created_by', String(init?.current_user_id || ''));
    } else {
      formData.append('invoice_date', invoiceDate);
      formData.append('due_date', dueDate);
    }
    formData.append('lead_time', leadTime);
    formData.append('order_type', supportsTruckInvoices ? orderType : 'spare');
    formData.append('subtotal', totals.subtotal.toFixed(2));
    formData.append('discount_amount', String(discountAmount));
    formData.append('tax_amount', totals.taxAmt.toFixed(2));
    formData.append('shipping_charges', String(shippingCharges));
    formData.append('total_amount', totals.grandTotal.toFixed(2));
    formData.append('tax_percentage', String(taxPercentage));
    formData.append('currency', primaryCurrency || displayCurrencies[0] || 'TZS');
    formData.append('display_currencies', JSON.stringify(displayCurrencies));
    formData.append('currency_rates', JSON.stringify(exchangeRates));
    items.forEach((item, index) => {
      if (!item.product_id) return;
      formData.append(`items[${index}][product_id]`, String(item.product_id));
      formData.append(`items[${index}][quantity]`, String(item.quantity));
      formData.append(`items[${index}][unit_price]`, String(item.unit_price));
      formData.append(`items[${index}][discount]`, String(item.discount));
      formData.append(`items[${index}][line_total]`, String(item.line_total));
      formData.append(`items[${index}][description]`, item.description || '');
    });
    return formData;
  }

  async function handleSubmit(event) {
    event.preventDefault();
    if (isTruckDocument && !hasSelectedCurrencies) {
      setErrors([`Please select at least one currency before creating the truck ${isQuote ? 'quotation' : 'invoice'}.`]);
      return;
    }
    if (!customerId) {
      setErrors(['Please select a customer.']);
      return;
    }
    setSaving(true);
    setErrors([]);
    try {
      const result = isQuote
        ? await submitCreateQuote(buildFormData())
        : await submitCreateInvoice(buildFormData());
      window.location.href = result.redirect || indexUrl;
    } catch (err) {
      setErrors([err instanceof Error ? err.message : `Failed to create ${isQuote ? 'quotation' : 'invoice'}.`]);
      setSaving(false);
    }
  }

  if (loading) {
    return (
      <div className="exp-create-loading">
        <Loader2 size={22} className="exp-create-spinner" aria-hidden />
        Loading form...
      </div>
    );
  }

  if (!init) {
    return (
      <div className="exp-create-shell">
        <div className="exp-create-alert exp-create-alert--error">{errors[0] || 'Could not load invoice form.'}</div>
      </div>
    );
  }

  const currencyOptions = init.currency_options || {};
  const nonTzsCurrencies = displayCurrencies.filter((code) => code !== 'TZS');

  return (
    <div className="exp-create-shell">
      {showMoneySavingOverlay ? (
        <MoneySavingOverlay src={moneyAnimSrc} label="Creating invoice..." />
      ) : null}
      {errors.length > 0 && (
        <div className="exp-create-alert exp-create-alert--error" role="alert">
          {errors.map((msg) => <div key={msg}>{msg}</div>)}
        </div>
      )}

      <form onSubmit={handleSubmit}>
        <div className="exp-create-main">
          <section className="exp-create-section" id="invoice-general">

            {!isQuote && (
            <div className="exp-create-row">
              <label className="exp-create-label">Invoice Number</label>
              <div>
                <input type="text" readOnly className="exp-create-input exp-create-input--readonly" value={init.next_invoice_number || '-'} />
                <div className="exp-create-help">Generated automatically when the invoice is saved.</div>
              </div>
            </div>
            )}

            <div className="exp-create-row">
              <label className="exp-create-label">Customer<span className="req">*</span></label>
              <div>
                <select className="exp-create-select" value={customerId} onChange={(e) => setCustomerId(e.target.value)} required>
                  <option value="">Select Customer</option>
                  {(init.customers || []).map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.company_name}{c.contact_person ? ` (${c.contact_person})` : ''}
                    </option>
                  ))}
                </select>
                <div className="exp-create-help">
                  <a href={init.customer_catalogue_url}>Customer catalogue</a>
                  {' | '}
                  <a href={init.customers_index_url}>Manage customers</a>
                </div>
              </div>
            </div>

            <div className="exp-create-row exp-create-row--dates">
              <label className="exp-create-label">{isQuote ? 'Quote date' : 'Invoice date'}<span className="req">*</span></label>
              <div className="exp-create-dates-inline">
                <input type="date" className="exp-create-input exp-create-input--date" value={invoiceDate} onChange={(e) => setInvoiceDate(e.target.value)} required />
                <div className="exp-create-date-pair">
                  <label className="exp-create-date-label">{isQuote ? 'Valid until' : 'Due date'}<span className="req">*</span></label>
                  <input
                    type="date"
                    className="exp-create-input exp-create-input--date"
                    value={isQuote ? validUntil : dueDate}
                    onChange={(e) => (isQuote ? setValidUntil(e.target.value) : setDueDate(e.target.value))}
                    required
                  />
                </div>
              </div>
            </div>

            <div className="exp-create-row exp-create-row--lead-currency">
              <label className="exp-create-label">Lead time <span className="exp-create-label-hint">(days)</span></label>
              <div className="exp-create-lead-currency-inline">
                <input type="number" min="0" className="exp-create-input exp-create-input--lead" value={leadTime} onChange={(e) => setLeadTime(e.target.value)} placeholder="e.g. 10" />
                <div className="exp-create-currency-pair">
                  <label className="exp-create-inline-label">Currencies<span className="req">*</span></label>
                  <div ref={currencyRef} className="exp-create-currency-field">
                    <div className="inv-currency-chips">
                      {displayCurrencies.map((code) => {
                        const meta = currencyOptions[code] || { name: code, flag: 'un' };
                        const isPrimary = primaryCurrency === code;
                        return (
                          <span key={code} className={`inv-currency-chip${isPrimary ? ' is-primary' : ''}`}>
                            <img src={`${FLAG_BASE}${meta.flag}.png`} alt="" className="inv-currency-flag" />
                            <strong>{code}</strong>
                            <button type="button" className={isPrimary ? 'is-active' : ''} onClick={() => setPrimaryCurrency(code)}>
                              {isPrimary ? 'Billing' : 'Set billing'}
                            </button>
                            <button
                              type="button"
                              className="inv-currency-chip-remove"
                              onClick={() => toggleCurrency(code)}
                              aria-label={`Remove ${code}`}
                              title="Remove currency"
                            >
                              <X size={12} aria-hidden />
                            </button>
                          </span>
                        );
                      })}
                    </div>
                    <div className={`inv-currency-picker${currencyMenuOpen ? ' is-open' : ''}`}>
                      <button type="button" className="inv-currency-trigger" onClick={() => setCurrencyMenuOpen((o) => !o)}>
                        <span className="inv-currency-trigger-label">Select currencies</span>
                        <span>{displayCurrencies.length} selected</span>
                      </button>
                      {currencyMenuOpen && (
                        <div className="inv-currency-menu" role="listbox">
                          {Object.entries(currencyOptions).map(([code, meta]) => {
                            const isChecked = displayCurrencies.includes(code);
                            return (
                              <button key={code} type="button" className={`inv-currency-option${isChecked ? ' is-checked' : ''}`} onClick={() => toggleCurrency(code)}>
                                <span className="inv-currency-check">{isChecked ? '\u2713' : ''}</span>
                                <img src={`${FLAG_BASE}${meta.flag}.png`} alt="" className="inv-currency-flag" />
                                <span className="code">{code}</span>
                                <span className="name">{meta.name}</span>
                              </button>
                            );
                          })}
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            </div>

            {nonTzsCurrencies.length > 0 && (
              <div className="exp-create-row">
                <label className="exp-create-label">Exchange rates</label>
                <div>
                  <div className="inv-rate-grid">
                    {nonTzsCurrencies.map((code) => (
                      <div key={code}>
                        <label className="exp-create-help" htmlFor={`rate-${code}`}>{code}</label>
                        <input
                          id={`rate-${code}`}
                          type="number"
                          step="0.0001"
                          min="0"
                          className="exp-create-input"
                          value={exchangeRates[code] || ''}
                          onChange={(e) => setExchangeRates((prev) => ({ ...prev, [code]: e.target.value }))}
                          placeholder="TZS per 1 unit"
                        />
                      </div>
                    ))}
                  </div>
                  <div className="exp-create-help">
                    {rateLoadingCodes.length ? `Loading BOT rate for ${rateLoadingCodes.join(', ')}�` : rateHint}
                  </div>
                </div>
              </div>
            )}
          </section>

          <section className="exp-create-section" id="invoice-lines">
            <div className="exp-create-section-header">
              <h2>Line Items</h2>
              <div className="exp-create-help">
                <a href={init.catalogue_url}>Product catalogue</a>
              </div>
            </div>

            <div className="inv-line-table-wrap">
              <table className="inv-line-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th className="inv-line-col-image">Image</th>
                    <th>Item</th>
                    <th className="inv-line-col-qty">Qty</th>
                    <th>Unit price</th>
                    <th>Disc %</th>
                    <th>Tax %</th>
                    <th>Total</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((item, index) => {
                    const matching = item.showDropdown
                      ? (init.products || []).filter((p) => {
                          if (!item.searchQuery) return true;
                          const q = item.searchQuery.toLowerCase();
                          return p.name?.toLowerCase().includes(q) || p.product_code?.toLowerCase().includes(q);
                        })
                      : [];
                    return (
                      <tr key={item.id}>
                        <td>{index + 1}</td>
                        <td className="inv-line-col-image">
                          <ProductThumb
                            src={item.image}
                            boxClassName="inv-line-item-thumb"
                            title={item.searchQuery || 'Product'}
                          />
                        </td>
                        <td className="inv-line-item-cell">
                          <div
                            className="inv-product-search"
                            ref={(el) => {
                              if (el) productSearchRefs.current.set(item.id, el);
                              else productSearchRefs.current.delete(item.id);
                            }}
                          >
                          <input
                            type="text"
                            value={item.searchQuery}
                            placeholder="Search product..."
                            onChange={(e) => updateItem(index, { searchQuery: e.target.value, showDropdown: true })}
                            onFocus={() => updateItem(index, { showDropdown: true })}
                          />
                          {item.showDropdown && (
                            <div className="inv-product-dropdown">
                              <div className="inv-product-dropdown-header">
                                <span>Select product</span>
                                <button
                                  type="button"
                                  className="inv-product-dropdown-close"
                                  onClick={() => updateItem(index, { showDropdown: false, focusIndex: -1 })}
                                  aria-label="Close product search"
                                >
                                  <X size={14} aria-hidden />
                                </button>
                              </div>
                              {matching.length > 0 ? (
                                matching.slice(0, 20).map((p, mi) => {
                                const thumbUrl = productImageUrl(p, init.stock_uploads_base);
                                return (
                                <div
                                  key={p.id}
                                  className={`inv-product-option${item.focusIndex === mi ? ' is-focused' : ''}`}
                                  onMouseDown={(e) => e.preventDefault()}
                                  onClick={() => selectProduct(index, p)}
                                >
                                  <ProductThumb
                                    src={thumbUrl}
                                    boxClassName="inv-product-option-thumb"
                                    iconSize={16}
                                  />
                                  <div>
                                    <div><strong>{p.name}</strong></div>
                                    <div className="exp-create-help">{p.product_code} | {formatCurrency(p.selling_price)}</div>
                                  </div>
                                </div>
                                );
                              })
                              ) : (
                                <div className="inv-product-dropdown-empty">No matching products</div>
                              )}
                            </div>
                          )}
                          </div>
                        </td>
                        <td className="inv-line-col-qty">
                          <input
                            type="text"
                            inputMode="numeric"
                            pattern="[0-9]*"
                            className="inv-line-qty-input"
                            value={item.quantity}
                            onChange={(e) => {
                              const raw = e.target.value.replace(/[^\d]/g, '');
                              updateItem(index, { quantity: Math.max(1, parseInt(raw, 10) || 1) });
                            }}
                          />
                        </td>
                        <td>
                          <input type="number" step="0.01" min="0" value={item.unit_price} onChange={(e) => updateItem(index, { unit_price: Math.max(0, parseFloat(e.target.value) || 0) })} />
                        </td>
                        <td>
                          <input type="number" min="0" max="100" value={item.discount} onChange={(e) => updateItem(index, { discount: Math.max(0, Math.min(100, parseFloat(e.target.value) || 0)) })} />
                        </td>
                        <td>
                          <select value={item.tax_percent} onChange={(e) => updateItem(index, { tax_percent: parseFloat(e.target.value) || 0 })}>
                            {[0, 10, 18, 20].map((t) => <option key={t} value={t}>{t}%</option>)}
                          </select>
                        </td>
                        <td>{formatCurrency(item.line_total)}</td>
                        <td>
                          <button
                            type="button"
                            className="inv-line-btn inv-line-btn--icon"
                            onClick={() => setItems((prev) => (prev.length > 1 ? prev.filter((_, i) => i !== index) : [emptyLine()]))}
                            aria-label="Remove row"
                            title="Remove row"
                          >
                            <Trash2 size={16} aria-hidden />
                          </button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            <div className="inv-line-toolbar inv-line-toolbar--below">
              <button type="button" className="inv-line-btn inv-line-btn--primary inv-line-btn--rounded" onClick={() => setItems((prev) => [...prev, emptyLine(taxPercentage || 18)])}>
                Add row
              </button>
            </div>

            <div className="inv-summary-box">
              <div className="inv-summary-row">
                <span>{taxMode === 'inclusive' ? 'Subtotal (excl. tax)' : 'Subtotal'}</span>
                <strong>{moneyLabel(totals.subtotal)}</strong>
              </div>
              <div className="inv-summary-row">
                <span>Discount (-)</span>
                <input type="number" step="0.01" className="inv-summary-input" value={discountAmount} onChange={(e) => setDiscountAmount(Math.max(0, parseFloat(e.target.value) || 0))} />
              </div>
              <div className="inv-summary-row">
                <span>Tax (%)</span>
                <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                  <input type="number" step="0.01" className="inv-summary-input" value={taxPercentage} onChange={(e) => setTaxPercentage(Math.max(0, Math.min(100, parseFloat(e.target.value) || 0)))} />
                  <span>{moneyLabel(totals.taxAmt)}</span>
                </div>
              </div>
              <div className="inv-summary-row">
                <span>Shipping (+)</span>
                <input type="number" step="0.01" className="inv-summary-input" value={shippingCharges} onChange={(e) => setShippingCharges(Math.max(0, parseFloat(e.target.value) || 0))} />
              </div>
              <div className="inv-summary-row">
                <span>Grand Total</span>
                <span className="inv-summary-total">{moneyLabel(totals.grandTotal)}</span>
              </div>
            </div>
          </section>

          <div className="exp-create-actions">
            <button type="button" className="exp-create-btn-cancel" onClick={() => { window.location.href = indexUrl; }} disabled={saving}>
              Cancel
            </button>
            <button type="submit" className="exp-create-btn-save" disabled={saving}>
              {saving && <Loader2 size={18} className="exp-create-spinner" aria-hidden />}
              {submitLabel}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
}
