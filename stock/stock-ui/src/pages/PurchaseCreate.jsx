import React, { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  HiOutlineArrowLeft,
  HiOutlinePlus,
  HiOutlineTrash,
  HiOutlineCube,
  HiOutlineExclamationCircle,
  HiOutlineMagnifyingGlass,
  HiOutlineBookOpen,
  HiOutlineChevronDown,
  HiOutlineCloudArrowUp,
  HiOutlineDocument,
  HiOutlineXMark,
} from 'react-icons/hi2';
import './purchase-edit.css';

const newRowId = () => 'r-' + Date.now() + '-' + Math.random().toString(36).slice(2, 9);

const SECTIONS = [
  { id: 'pe-supplier', label: 'Supplier' },
  { id: 'pe-payment', label: 'Payment' },
  { id: 'pe-items', label: 'Items' },
  { id: 'pe-notes', label: 'Notes' },
];

const emptyItem = () => ({ id: newRowId(), product_id: '', quantity: 1, unit_price: 0, base_price: 0 });

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
  return placeholder || '';
}

function PeImageThumb({ src, placeholder = '', className = '', iconSize = 16 }) {
  const [displaySrc, setDisplaySrc] = useState(src || '');
  const [loaded, setLoaded] = useState(false);
  const imgRef = useRef(null);

  useEffect(() => {
    setDisplaySrc(src || '');
    setLoaded(false);
  }, [src]);

  useEffect(() => {
    const img = imgRef.current;
    if (!displaySrc || !img) return;
    if (img.complete && img.naturalWidth > 0) {
      setLoaded(true);
    }
  }, [displaySrc]);

  if (!displaySrc) {
    return (
      <div className={`${className} is-empty`.trim()} aria-hidden="true">
        <HiOutlineCube style={{ width: iconSize, height: iconSize }} />
      </div>
    );
  }

  return (
    <div
      className={`${className}${loaded ? ' is-loaded' : ' is-loading'}`.trim()}
      aria-busy={!loaded}
      aria-hidden="true"
    >
      {!loaded && <span className="pe-thumb-skeleton" />}
      <img
        ref={imgRef}
        key={displaySrc}
        src={displaySrc}
        alt=""
        loading="lazy"
        decoding="async"
        className={loaded ? 'is-visible' : ''}
        onLoad={() => setLoaded(true)}
        onError={() => {
          if (placeholder && displaySrc !== placeholder) {
            setDisplaySrc(placeholder);
            setLoaded(false);
          } else {
            setDisplaySrc('');
            setLoaded(false);
          }
        }}
      />
    </div>
  );
}

function LineProductThumb({ src, placeholder }) {
  return (
    <PeImageThumb
      src={src}
      placeholder={placeholder}
      className="pe-items-col pe-items-col--img pe-item-thumb"
      iconSize={16}
    />
  );
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

function voucherLabel(v) {
  if (v?.label) return String(v.label);
  const no = v?.voucher_no || v?.pv_number || ('#' + (v?.id || ''));
  const payee = v?.payee_name || 'Unknown payee';
  const amount = v?.total_amount != null
    ? Number(v.total_amount).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    : '';
  const cur = v?.currency || '';
  return [no, payee, amount ? (cur + ' ' + amount).trim() : ''].filter(Boolean).join(' - ');
}

function itemsHaveProducts(rows) {
  return (rows || []).some((r) => String(r.product_id || '').trim() !== '');
}

function mapLinesToItems(lines, rateNum) {
  const rate = Number(rateNum) > 0 ? Number(rateNum) : 1;
  const merged = {};
  (lines || []).forEach((line) => {
    const pid = String(line.product_id || '');
    if (!pid) return;
    const qty = Number(line.quantity) || 0;
    const base = Number(line.unit_price) || 0;
    if (!merged[pid]) merged[pid] = { product_id: pid, quantity: 0, base_price: base };
    merged[pid].quantity += qty > 0 ? qty : 1;
    if (!merged[pid].base_price && base > 0) merged[pid].base_price = base;
  });
  const rows = Object.values(merged);
  if (!rows.length) return null;
  return rows.map((it) => ({
    id: newRowId(),
    product_id: String(it.product_id),
    quantity: Number(it.quantity) || 1,
    base_price: Number(it.base_price) || 0,
    unit_price: Number(((Number(it.base_price) || 0) * rate).toFixed(2)),
  }));
}

function makeItemFromBase(productId, quantity, basePrice, rateNum) {
  const rate = Number(rateNum) > 0 ? Number(rateNum) : 1;
  const base = Number(basePrice) || 0;
  return {
    id: newRowId(),
    product_id: String(productId || ''),
    quantity: Number(quantity) || 1,
    base_price: base,
    unit_price: Number((base * rate).toFixed(2)),
  };
}

function productOptionLabel(product) {
  if (!product) return '';
  const name = String(product.name || '').trim();
  const code = String(product.product_code || product.sku || '').trim();
  if (name && code) return `${name} (${code})`;
  return name || code || `Product #${product.id}`;
}

function ProductSearchSelect({
  products,
  value,
  onChange,
  name = 'product_id[]',
  required = false,
  placeholder = 'Search product by name or code...',
  ariaLabel = 'Product',
  baseUrl = '',
  imagePlaceholder = '',
}) {
  const selected = useMemo(
    () => products.find((p) => String(p.id) === String(value)) || null,
    [products, value],
  );
  const [query, setQuery] = useState(() => (selected ? productOptionLabel(selected) : ''));
  const [open, setOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(-1);
  const [menuStyle, setMenuStyle] = useState(null);
  const wrapRef = useRef(null);
  const inputRef = useRef(null);
  const listId = useMemo(() => `pe-prod-list-${Math.random().toString(36).slice(2, 9)}`, []);

  useEffect(() => {
    if (!open) {
      setQuery(selected ? productOptionLabel(selected) : '');
    }
  }, [selected, open]);

  const matches = useMemo(() => {
    const q = query.trim().toLowerCase();
    const list = Array.isArray(products) ? products : [];
    if (!q) return list.slice(0, 40);
    const scored = [];
    for (const p of list) {
      const nameStr = String(p.name || '').toLowerCase();
      const code = String(p.product_code || p.sku || '').toLowerCase();
      if (!nameStr.includes(q) && !code.includes(q) && String(p.id) !== q) continue;
      const rank = nameStr.startsWith(q) || code.startsWith(q) ? 0 : 1;
      scored.push({ p, rank });
    }
    scored.sort((a, b) => a.rank - b.rank || String(a.p.name || '').localeCompare(String(b.p.name || '')));
    return scored.slice(0, 40).map((row) => row.p);
  }, [products, query]);

  const updateMenuPosition = () => {
    const el = inputRef.current;
    if (!el) return;

    const vv = window.visualViewport;
    const viewTop = vv?.offsetTop ?? 0;
    const viewLeft = vv?.offsetLeft ?? 0;
    const viewWidth = vv?.width ?? window.innerWidth;
    const viewHeight = vv?.height ?? window.innerHeight;
    const rect = el.getBoundingClientRect();
    const margin = 8;
    const isMobile = viewWidth < 768;

    // Never wider than the visible viewport (keyboard-safe).
    const width = Math.max(
      160,
      Math.min(isMobile ? viewWidth - margin * 2 : Math.max(rect.width, 280), viewWidth - margin * 2),
    );
    let left = rect.left;
    if (isMobile) {
      left = viewLeft + margin;
    } else {
      left = Math.min(Math.max(rect.left, viewLeft + margin), viewLeft + viewWidth - width - margin);
    }

    const spaceBelow = viewTop + viewHeight - rect.bottom - margin;
    const spaceAbove = rect.top - viewTop - margin;
    // Prefer above the field when the keyboard leaves little room below.
    const preferBelow = spaceBelow >= 120 && spaceBelow >= spaceAbove;
    const available = Math.max(preferBelow ? spaceBelow : spaceAbove, 72);
    const maxHeight = Math.min(isMobile ? 200 : 280, available);

    const style = {
      position: 'fixed',
      left,
      width,
      maxHeight,
      height: 'auto',
      overflowY: 'auto',
      WebkitOverflowScrolling: 'touch',
      zIndex: 4000,
      top: 'auto',
      bottom: 'auto',
    };

    if (preferBelow) {
      style.top = Math.min(rect.bottom + 4, viewTop + viewHeight - margin - 48);
    } else {
      // Anchor to the top of available space above the input (works with visualViewport).
      style.top = Math.max(viewTop + margin, rect.top - maxHeight - 4);
    }

    setMenuStyle(style);
  };

  useEffect(() => {
    if (!open) return undefined;
    updateMenuPosition();
    // Keep the field visible above the soft keyboard on phones.
    try {
      inputRef.current?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    } catch (_) {
      /* ignore */
    }
    const repositionTimers = [50, 200, 450].map((ms) => window.setTimeout(updateMenuPosition, ms));
    const onDown = (e) => {
      if (!wrapRef.current?.contains(e.target) && !e.target.closest?.('.pe-product-search-menu')) {
        setOpen(false);
      }
    };
    const onKey = (e) => {
      if (e.key === 'Escape') setOpen(false);
    };
    const onReposition = () => updateMenuPosition();
    document.addEventListener('mousedown', onDown);
    document.addEventListener('touchstart', onDown, { passive: true });
    document.addEventListener('keydown', onKey);
    window.addEventListener('resize', onReposition);
    window.addEventListener('scroll', onReposition, true);
    const vv = window.visualViewport;
    vv?.addEventListener('resize', onReposition);
    vv?.addEventListener('scroll', onReposition);
    return () => {
      repositionTimers.forEach((id) => window.clearTimeout(id));
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('touchstart', onDown);
      document.removeEventListener('keydown', onKey);
      window.removeEventListener('resize', onReposition);
      window.removeEventListener('scroll', onReposition, true);
      vv?.removeEventListener('resize', onReposition);
      vv?.removeEventListener('scroll', onReposition);
    };
  }, [open]);

  useEffect(() => {
    if (!open) return undefined;
    updateMenuPosition();
    return undefined;
  }, [open, matches.length, query]);

  const pick = (product) => {
    onChange(String(product?.id || ''));
    setQuery(product ? productOptionLabel(product) : '');
    setOpen(false);
    setActiveIndex(-1);
  };

  const onKeyDown = (e) => {
    if (!open && (e.key === 'ArrowDown' || e.key === 'Enter')) {
      setOpen(true);
      return;
    }
    if (!open) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActiveIndex((i) => (i + 1) % Math.max(matches.length, 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActiveIndex((i) => (i <= 0 ? Math.max(matches.length - 1, 0) : i - 1));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (activeIndex >= 0 && matches[activeIndex]) pick(matches[activeIndex]);
    }
  };

  const menu = open ? (
    <div id={listId} className="pe-product-search-menu" role="listbox" style={menuStyle || undefined}>
      {!matches.length ? (
        <div className="pe-product-search-empty">No products match</div>
      ) : (
        matches.map((p, index) => {
          const isActive = index === activeIndex || String(p.id) === String(value);
          const thumbSrc = productImageSrc(p, p.id, baseUrl, imagePlaceholder);
          return (
            <button
              key={p.id}
              type="button"
              role="option"
              aria-selected={String(p.id) === String(value)}
              className={`pe-product-search-option${isActive ? ' is-active' : ''}`}
              onMouseDown={(e) => e.preventDefault()}
              onClick={() => pick(p)}
            >
              <PeImageThumb
                src={thumbSrc}
                placeholder={imagePlaceholder}
                className="pe-product-search-thumb"
                iconSize={16}
              />
              <span className="pe-product-search-meta">
                <span className="pe-product-search-name">{p.name || 'Unnamed'}</span>
                <span className="pe-product-search-code">{p.product_code || p.sku || ''}</span>
              </span>
            </button>
          );
        })
      )}
    </div>
  ) : null;

  return (
    <div className={`pe-product-search${open ? ' is-open' : ''}`} ref={wrapRef}>
      <input type="hidden" name={name} value={value || ''} required={required} />
      <input
        ref={inputRef}
        type="search"
        className="pe-input pe-product-search-input"
        value={query}
        placeholder={placeholder}
        aria-label={ariaLabel}
        aria-autocomplete="list"
        aria-expanded={open}
        aria-controls={listId}
        autoComplete="off"
        onFocus={() => setOpen(true)}
        onChange={(e) => {
          setQuery(e.target.value);
          setOpen(true);
          setActiveIndex(-1);
        }}
        onKeyDown={onKeyDown}
      />
      {typeof document !== 'undefined' && menu ? createPortal(menu, document.body) : null}
    </div>
  );
}

const ATTACH_ACCEPT = '.pdf,.jpg,.jpeg,.png,.docx,.xlsx,.xls,.csv,.txt,image/*';

function formatAttachSize(bytes) {
  const n = Number(bytes) || 0;
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

function attachFileKey(file) {
  return `${file.name}|${file.size}|${file.lastModified}`;
}

function AttachmentsPicker() {
  const inputRef = useRef(null);
  const [files, setFiles] = useState([]);
  const [dragging, setDragging] = useState(false);

  const syncInput = (next) => {
    const unique = [];
    const seen = new Set();
    next.forEach((file) => {
      const key = attachFileKey(file);
      if (seen.has(key)) return;
      seen.add(key);
      unique.push(file);
    });
    setFiles(unique);
    const dt = new DataTransfer();
    unique.forEach((file) => dt.items.add(file));
    if (inputRef.current) inputRef.current.files = dt.files;
  };

  const addFiles = (list) => {
    if (!list?.length) return;
    syncInput([...files, ...Array.from(list)]);
  };

  const removeAt = (index) => {
    syncInput(files.filter((_, i) => i !== index));
  };

  return (
    <div className="pe-attach">
      <div className="pe-attach-head">
        <span className="pe-field-label">Attachments</span>
        {files.length > 0 ? (
          <span className="pe-attach-count">{files.length} file{files.length === 1 ? '' : 's'}</span>
        ) : null}
      </div>

      <div
        className={`pe-attach-dropzone${dragging ? ' is-dragging' : ''}${files.length ? ' has-files' : ''}`}
        onDragEnter={(e) => {
          e.preventDefault();
          setDragging(true);
        }}
        onDragOver={(e) => {
          e.preventDefault();
          setDragging(true);
        }}
        onDragLeave={(e) => {
          e.preventDefault();
          if (!e.currentTarget.contains(e.relatedTarget)) setDragging(false);
        }}
        onDrop={(e) => {
          e.preventDefault();
          setDragging(false);
          addFiles(e.dataTransfer.files);
        }}
        onClick={() => inputRef.current?.click()}
        role="button"
        tabIndex={0}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            inputRef.current?.click();
          }
        }}
      >
        <input
          ref={inputRef}
          type="file"
          name="attachments[]"
          className="pe-attach-input"
          multiple
          accept={ATTACH_ACCEPT}
          onChange={(e) => {
            const picked = e.target.files;
            e.target.value = '';
            addFiles(picked);
          }}
        />
        <span className="pe-attach-icon" aria-hidden="true">
          <HiOutlineCloudArrowUp style={{ width: 28, height: 28 }} />
        </span>
        <span className="pe-attach-title">
          {files.length ? 'Drop more files, or click to browse' : 'Drop files here, or click to browse'}
        </span>
        <span className="pe-attach-hint">PDF, images, Word, Excel, CSV, or TXT</span>
      </div>

      {files.length > 0 ? (
        <ul className="pe-attach-list">
          {files.map((file, index) => (
            <li key={attachFileKey(file)} className="pe-attach-item">
              <span className="pe-attach-item-icon" aria-hidden="true">
                <HiOutlineDocument style={{ width: 18, height: 18 }} />
              </span>
              <span className="pe-attach-item-meta">
                <span className="pe-attach-item-name" title={file.name}>{file.name}</span>
                <span className="pe-attach-item-size">{formatAttachSize(file.size)}</span>
              </span>
              <button
                type="button"
                className="pe-attach-item-remove"
                title="Remove"
                aria-label={`Remove ${file.name}`}
                onClick={(e) => {
                  e.stopPropagation();
                  removeAt(index);
                }}
              >
                <HiOutlineXMark style={{ width: 16, height: 16 }} />
              </button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}

export default function PurchaseCreate({ data }) {
  const {
    formAction = 'domestic_create.php',
    indexUrl = 'index.php',
    catalogueUrl = '',
    baseUrl = '/staff/stock/',
    exchangeRateApiUrl = 'exchange_rate.php',
    productImagePlaceholder = '',
    suppliers = [],
    products = [],
    poCurrencyOptions = {},
    displayBotRate = null,
    selectedCurrencyCode: initialCurrencyCode = 'TZS',
    currencySymbol = '',
    stockPurchaseVouchers = [],
    voucherSalesOrderItemsMap = {},
    voucherPickerHint = '',
    cloned_po = null,
    cloned_items = [],
    classificationPrefillItems = null,
    isClassificationEdit = false,
    classificationPoId = null,
    classificationPoNumber = '',
    selectedVoucherIds: initialSelectedVoucherIds = [],
    purchaseTypeDefault = 'domestic',
    error: initialError = '',
    prefillProductId = null,
    prefillQty = null,
    supplierSetupError = '',
  } = data || {};

  const initialDisplayRate = (() => {
    if (displayBotRate != null && Number(displayBotRate) > 0) return Number(displayBotRate);
    return String(initialCurrencyCode || 'TZS').toUpperCase() === 'TZS' ? 1 : 1;
  })();

  const [purchaseType, setPurchaseType] = useState(
    purchaseTypeDefault === 'import' || cloned_po?.purchase_type === 'import' ? 'import' : 'domestic',
  );
  const [supplierId, setSupplierId] = useState(cloned_po?.supplier_id != null ? String(cloned_po.supplier_id) : '');
  const [supplierInvoiceNo, setSupplierInvoiceNo] = useState(cloned_po?.supplier_invoice_no || '');
  const [purchaseOrderDate, setPurchaseOrderDate] = useState(() => {
    if (cloned_po?.purchase_order_date) return String(cloned_po.purchase_order_date).slice(0, 10);
    if (cloned_po?.created_at) return String(cloned_po.created_at).slice(0, 10);
    return new Date().toISOString().slice(0, 10);
  });
  const [notes, setNotes] = useState(cloned_po?.notes ?? '');
  const [termsConditions, setTermsConditions] = useState(cloned_po?.terms_conditions ?? cloned_po?.terms ?? '');
  const [taxPercentage, setTaxPercentage] = useState(cloned_po ? Number(cloned_po.tax_percentage) || 0 : 0);
  const [discountPercentage, setDiscountPercentage] = useState(
    cloned_po ? Number(cloned_po.discount_percentage) || 0 : 0,
  );
  const [currencyCode, setCurrencyCode] = useState(
    String(cloned_po?.currency || initialCurrencyCode || 'TZS').toUpperCase(),
  );
  const [exchangeRate, setExchangeRate] = useState(initialDisplayRate);
  const [items, setItems] = useState([emptyItem()]);
  const [selectedVoucherIds, setSelectedVoucherIds] = useState(() =>
    (initialSelectedVoucherIds || []).map((id) => Number(id)).filter((id) => id > 0),
  );
  const [voucherSearch, setVoucherSearch] = useState('');
  const [voucherPickerOpen, setVoucherPickerOpen] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error] = useState(initialError);
  const [activeSection, setActiveSection] = useState(SECTIONS[0].id);
  const [currencyMenuOpen, setCurrencyMenuOpen] = useState(false);
  const [initialized, setInitialized] = useState(false);
  const currencyPickerRef = useRef(null);

  const currencyCodes = useMemo(() => Object.keys(poCurrencyOptions || {}), [poCurrencyOptions]);
  const selectedCurrencyMeta = poCurrencyOptions[currencyCode] || {
    name: currencyCode,
    symbol: currencySymbol || currencyCode,
  };
  const selectedCurrencyFlagUrl = currencyFlagUrl(selectedCurrencyMeta);
  const paymentVoucherIdsCsv = selectedVoucherIds.join(',');
  const paymentVoucherIdFirst = selectedVoucherIds.length ? String(selectedVoucherIds[0]) : '';
  const selectedVoucherRows = useMemo(
    () =>
      selectedVoucherIds
        .map((id) => (stockPurchaseVouchers || []).find((v) => Number(v.id) === Number(id)))
        .filter(Boolean),
    [selectedVoucherIds, stockPurchaseVouchers],
  );
  const noSuppliers = !suppliers || suppliers.length === 0;

  const filteredVouchers = useMemo(() => {
    const q = voucherSearch.trim().toLowerCase();
    if (!q) return stockPurchaseVouchers || [];
    return (stockPurchaseVouchers || []).filter((v) =>
      [voucherLabel(v), v.voucher_no, v.pv_number, v.payee_name, v.prepared_by, v.currency, String(v.id || '')]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
        .includes(q),
    );
  }, [stockPurchaseVouchers, voucherSearch]);

  useEffect(() => {
    if (initialized) return;
    setInitialized(true);
    if (typeof window === 'undefined') return;

    const rateNum = Number(exchangeRate) || 1;
    let applied = false;

    try {
      const raw = localStorage.getItem('purchase_catalogue_items');
      if (raw) {
        const picked = JSON.parse(raw);
        if (Array.isArray(picked) && picked.length > 0) {
          localStorage.removeItem('purchase_catalogue_items');
          const firstProd = products.find((p) => String(p.id) === String(picked[0].product_id));
          if (firstProd?.supplier_id) setSupplierId(String(firstProd.supplier_id));
          setItems(
            picked.map((it) => {
              const p = products.find((x) => String(x.id) === String(it.product_id));
              return makeItemFromBase(it.product_id, it.quantity, productUnitBase(p), rateNum);
            }),
          );
          applied = true;
        }
      }
    } catch (_) { /* ignore */ }

    if (!applied) {
      const params = new URLSearchParams(window.location.search);
      const preProdId = prefillProductId || params.get('product_id');
      const preQty = prefillQty != null ? prefillQty : params.get('qty');
      if (preProdId) {
        const p = products.find((x) => String(x.id) === String(preProdId));
        if (p?.supplier_id) setSupplierId(String(p.supplier_id));
        setItems([makeItemFromBase(preProdId, preQty, productUnitBase(p), rateNum)]);
        applied = true;
      }
    }

    if (!applied && cloned_items?.length) {
      if (cloned_po?.supplier_id) setSupplierId(String(cloned_po.supplier_id));
      setItems(
        cloned_items.map((it) => {
          const raw = Number(it.unit_price) || 0;
          if (it.unit_price_is_display) {
            return {
              id: newRowId(),
              product_id: String(it.product_id || ''),
              quantity: Number(it.quantity) || 1,
              base_price: rateNum > 0 ? raw / rateNum : raw,
              unit_price: Number(raw.toFixed(2)),
            };
          }
          return makeItemFromBase(it.product_id, it.quantity, raw, rateNum);
        }),
      );
      applied = true;
    }

    if (!applied && isClassificationEdit && classificationPrefillItems?.length) {
      setItems(
        classificationPrefillItems.map((it) => {
          const display = Number(it.unit_display ?? it.unit_price) || 0;
          return {
            id: newRowId(),
            product_id: String(it.product_id || ''),
            quantity: Number(it.quantity) || 1,
            base_price: rateNum > 0 ? display / rateNum : display,
            unit_price: Number(display.toFixed(2)),
          };
        }),
      );
      applied = true;
    }

    const seedIds = (initialSelectedVoucherIds || []).map((id) => Number(id)).filter((id) => id > 0);
    if (seedIds.length) {
      setSelectedVoucherIds(seedIds);
      const firstV = (stockPurchaseVouchers || []).find((v) => Number(v.id) === seedIds[0]);
      if (firstV?.supplier_id) setSupplierId((prev) => prev || String(firstV.supplier_id));
      if (!applied) {
        const lines = [];
        seedIds.forEach((vid) => {
          const mapped = voucherSalesOrderItemsMap?.[String(vid)] || voucherSalesOrderItemsMap?.[vid];
          if (Array.isArray(mapped)) lines.push(...mapped);
        });
        const mappedItems = mapLinesToItems(lines, rateNum);
        if (mappedItems) setItems(mappedItems);
      }
    }
  }, [initialized]); // eslint-disable-line react-hooks/exhaustive-deps -- one-shot init

  useEffect(() => {
    if (!currencyMenuOpen) return undefined;
    const onPointer = (e) => {
      if (!currencyPickerRef.current?.contains(e.target)) setCurrencyMenuOpen(false);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') setCurrencyMenuOpen(false);
    };
    document.addEventListener('mousedown', onPointer);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onPointer);
      document.removeEventListener('keydown', onKey);
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
    setItems((prev) =>
      prev.map((it) => ({ ...it, unit_price: Number(((Number(it.base_price) || 0) * rateNum).toFixed(2)) })),
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

  const applyVoucherLines = (voucherIdList) => {
    const rateNum = Number(exchangeRate) || 1;
    const lines = [];
    voucherIdList.forEach((vid) => {
      const mapped = voucherSalesOrderItemsMap?.[String(vid)] || voucherSalesOrderItemsMap?.[vid];
      if (Array.isArray(mapped)) lines.push(...mapped);
    });
    const mappedItems = mapLinesToItems(lines, rateNum);
    if (!mappedItems) return;
    if (itemsHaveProducts(items)) {
      const ok = window.confirm(
        'Selected voucher has quotation lines. Replace current order items with those lines?',
      );
      if (!ok) return;
    }
    setItems(mappedItems);
  };

  const toggleVoucher = (voucher) => {
    const vid = Number(voucher.id);
    if (!vid) return;
    if (selectedVoucherIds.includes(vid)) {
      setSelectedVoucherIds((prev) => prev.filter((id) => id !== vid));
      return;
    }
    const nextIds = [...selectedVoucherIds, vid];
    setSelectedVoucherIds(nextIds);
    if (voucher.supplier_id) setSupplierId(String(voucher.supplier_id));
    const lines = voucherSalesOrderItemsMap?.[String(vid)] || voucherSalesOrderItemsMap?.[vid];
    if (Array.isArray(lines) && lines.length > 0) applyVoucherLines(nextIds);
  };

  const addRow = () => setItems((prev) => [...prev, emptyItem()]);
  const removeRow = (id) => setItems((prev) => (prev.length > 1 ? prev.filter((r) => r.id !== id) : prev));

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
    if (p?.supplier_id && !supplierId) setSupplierId(String(p.supplier_id));
  };

  const subtotal = useMemo(
    () => items.reduce((sum, r) => sum + (Number(r.quantity) || 0) * (Number(r.unit_price) || 0), 0),
    [items],
  );
  const discountAmt = subtotal * ((Number(discountPercentage) || 0) / 100);
  const taxable = Math.max(0, subtotal - discountAmt);
  const taxAmount = taxable * ((Number(taxPercentage) || 0) / 100);
  const grandTotal = taxable + taxAmount;

  const formatMoney = (n) =>
    currencyCode +
    ' ' +
    (Number(n) || 0).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  const scrollToSection = (id) => {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  const submitLabel = isClassificationEdit
    ? (isSubmitting ? 'Saving...' : 'Save classification')
    : (isSubmitting ? 'Creating...' : 'Create Purchase Order');

  return (
    <div className="pe-shell pe-shell--compact">
      <div className="pe-topbar pe-topbar--actions-only">
        <div className="pe-topbar-actions">
          {catalogueUrl ? (
            <a
              href={catalogueUrl}
              className="pe-icon-link"
              target="_blank"
              rel="noopener noreferrer"
              title="Catalogue"
              aria-label="Open catalogue"
            >
              <HiOutlineBookOpen style={{ width: 20, height: 20 }} />
            </a>
          ) : null}
          <a href={indexUrl} className="pe-icon-link" title="Back to list" aria-label="Back to purchase orders">
            <HiOutlineArrowLeft style={{ width: 20, height: 20 }} />
          </a>
        </div>
      </div>

      {error ? (
        <div className="pe-alert pe-alert--error">
          <HiOutlineExclamationCircle style={{ width: 18, height: 18, flexShrink: 0 }} />
          <div>{error}</div>
        </div>
      ) : null}

      {noSuppliers || supplierSetupError ? (
        <div className="pe-alert pe-alert--warn">
          <HiOutlineExclamationCircle style={{ width: 18, height: 18, flexShrink: 0 }} />
          <div>
            {supplierSetupError
              || 'No suppliers found yet. Add at least one supplier before creating a purchase order.'}
          </div>
        </div>
      ) : null}

      {isClassificationEdit ? (
        <div className="pe-alert pe-alert--warn">
          <HiOutlineExclamationCircle style={{ width: 18, height: 18, flexShrink: 0 }} />
          <div>
            Classification edit for <strong>{classificationPoNumber || ('PO #' + classificationPoId)}</strong>.
            Adjust type and vouchers, then save.
          </div>
        </div>
      ) : null}

      <form
        method="POST"
        action={formAction}
        encType="multipart/form-data"
        onSubmit={() => setIsSubmitting(true)}
        className="pe-layout"
      >
        <input type="hidden" name="procurement_journey" value="standard" />
        <input type="hidden" name="payment_voucher_ids" value={paymentVoucherIdsCsv} />
        <input type="hidden" name="payment_voucher_id" value={paymentVoucherIdFirst} />
        {isClassificationEdit ? (
          <>
            <input type="hidden" name="save_classification" value="1" />
            <input type="hidden" name="po_id" value={classificationPoId || ''} />
          </>
        ) : null}

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
            <div className="pe-row">
              <label className="pe-label">Purchase type</label>
              <div className="pe-field">
                <div className="pe-type-toggle" role="radiogroup" aria-label="Purchase type">
                  <label className={`pe-type-option${purchaseType === 'domestic' ? ' is-active' : ''}`}>
                    <input
                      type="radio"
                      name="purchase_type"
                      value="domestic"
                      checked={purchaseType === 'domestic'}
                      onChange={() => setPurchaseType('domestic')}
                    />
                    Internal
                  </label>
                  <label className={`pe-type-option${purchaseType === 'import' ? ' is-active' : ''}`}>
                    <input
                      type="radio"
                      name="purchase_type"
                      value="import"
                      checked={purchaseType === 'import'}
                      onChange={() => setPurchaseType('import')}
                    />
                    Abroad
                  </label>
                </div>
              </div>
            </div>

            <div className="pe-row">
              <label className="pe-label">
                Supplier <span className="pe-req">*</span>
              </label>
              <div className="pe-field">
                <select
                  className="pe-select"
                  name="supplier_id"
                  required={!selectedVoucherIds.length}
                  value={supplierId}
                  onChange={(e) => setSupplierId(e.target.value)}
                >
                  <option value="">Select supplier</option>
                  {suppliers.map((s) => (
                    <option key={s.id} value={s.id}>{s.name}</option>
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
                <input type="hidden" name="currency" value={currencyCode} />
                <div className={`pe-currency-picker${currencyMenuOpen ? ' is-open' : ''}`} ref={currencyPickerRef}>
                  <button
                    type="button"
                    className="pe-currency-picker-trigger"
                    aria-haspopup="listbox"
                    aria-expanded={currencyMenuOpen}
                    onClick={() => setCurrencyMenuOpen((o) => !o)}
                  >
                    <img src={selectedCurrencyFlagUrl} alt="" className="pe-currency-flag" width={28} height={20} />
                    <span className="pe-currency-picker-label">
                      <span className="code">{currencyCode}</span>
                      <span className="name">{selectedCurrencyMeta.name || currencyCode}</span>
                    </span>
                  </button>
                  {currencyMenuOpen ? (
                    <div className="pe-currency-picker-menu" role="listbox" aria-label="Currency options">
                      {currencyCodes.map((code) => {
                        const meta = poCurrencyOptions[code] || { name: code };
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
                              src={currencyFlagUrl(meta)}
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
                  ) : null}
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

          <section id="pe-payment" className="pe-section">
            <div className="pe-row pe-row--top">
              <label className="pe-label">Payment vouchers</label>
              <div className="pe-field">
                <button
                  type="button"
                  className={`pe-voucher-trigger${voucherPickerOpen ? ' is-open' : ''}`}
                  aria-expanded={voucherPickerOpen}
                  onClick={() => setVoucherPickerOpen((open) => !open)}
                >
                  <span className="pe-voucher-trigger-text">
                    {selectedVoucherIds.length
                      ? `${selectedVoucherIds.length} voucher${selectedVoucherIds.length === 1 ? '' : 's'} linked`
                      : 'Link payment vouchers'}
                  </span>
                  <HiOutlineChevronDown style={{ width: 16, height: 16 }} />
                </button>

                {selectedVoucherRows.length && !voucherPickerOpen ? (
                  <div className="pe-voucher-selected">
                    {selectedVoucherRows.map((v) => (
                      <span key={v.id} className="pe-voucher-chip" title={voucherLabel(v)}>
                        {v.voucher_no || v.pv_number || `PV-${v.id}`}
                      </span>
                    ))}
                  </div>
                ) : null}

                {voucherPickerOpen ? (
                  <div className="pe-voucher-panel">
                    {voucherPickerHint ? <p className="pe-voucher-hint">{voucherPickerHint}</p> : null}

                    <div className="pe-voucher-search">
                      <HiOutlineMagnifyingGlass style={{ width: 16, height: 16 }} />
                      <input
                        type="search"
                        className="pe-input"
                        value={voucherSearch}
                        onChange={(e) => setVoucherSearch(e.target.value)}
                        placeholder="Search vouchers by number, payee, amount..."
                        aria-label="Search payment vouchers"
                        autoFocus
                      />
                    </div>

                    <div className="pe-voucher-list" role="group" aria-label="Payment vouchers">
                      {!filteredVouchers.length ? (
                        <div className="pe-voucher-empty">No vouchers match this search.</div>
                      ) : (
                        filteredVouchers.map((v) => {
                          const vid = Number(v.id);
                          const checked = selectedVoucherIds.includes(vid);
                          const hasLines = !!(
                            voucherSalesOrderItemsMap?.[String(vid)] || voucherSalesOrderItemsMap?.[vid]
                          )?.length;
                          return (
                            <label key={vid} className={`pe-voucher-item${checked ? ' is-selected' : ''}`}>
                              <input type="checkbox" checked={checked} onChange={() => toggleVoucher(v)} />
                              <span className="pe-voucher-item-body">
                                <span className="pe-voucher-item-title">{voucherLabel(v)}</span>
                                <span className="pe-voucher-item-meta">
                                  {v.date_created ? String(v.date_created).slice(0, 10) : ''}
                                  {v.is_paid ? ' | Paid' : ' | Unpaid'}
                                  {hasLines ? ' | Has quotation lines' : ''}
                                </span>
                              </span>
                            </label>
                          );
                        })
                      )}
                    </div>
                  </div>
                ) : null}
              </div>
            </div>
          </section>

          <section id="pe-items" className="pe-section">
            <div className="pe-items">
              <div className="pe-items-head" aria-hidden="true">
                <span className="pe-items-col pe-items-col--idx" />
                <span className="pe-items-col pe-items-col--img" />
                <span className="pe-items-col pe-items-col--product">Product</span>
                <span className="pe-items-col pe-items-col--qty">Qty</span>
                <span className="pe-items-col pe-items-col--unit">Unit ({currencyCode})</span>
                <span className="pe-items-col pe-items-col--total">Line total</span>
                <span className="pe-items-col pe-items-col--action" />
              </div>
              {items.map((row, index) => {
                const prod = products.find((p) => String(p.id) === String(row.product_id));
                const total = (Number(row.quantity) || 0) * (Number(row.unit_price) || 0);
                const imgSrc = productImageSrc(prod, row.product_id, baseUrl, productImagePlaceholder);
                return (
                  <div className="pe-item pe-item--row" key={row.id}>
                    <span className="pe-items-col pe-items-col--idx">{index + 1}</span>
                    <LineProductThumb src={imgSrc} placeholder={productImagePlaceholder} />
                    <div className="pe-items-col pe-items-col--product">
                      <ProductSearchSelect
                        products={products}
                        value={row.product_id}
                        onChange={(productId) => onProductChange(row.id, productId)}
                        required
                        ariaLabel={`Product for item ${index + 1}`}
                        baseUrl={baseUrl}
                        imagePlaceholder={productImagePlaceholder}
                      />
                    </div>
                    <div className="pe-items-col pe-items-col--qty" data-mobile-label="Qty">
                      <input
                        type="number"
                        name="quantity[]"
                        className="pe-input"
                        min="0.01"
                        step="0.01"
                        inputMode="decimal"
                        value={row.quantity}
                        onChange={(e) => updateRow(row.id, { quantity: e.target.value })}
                        aria-label={`Quantity for item ${index + 1}`}
                      />
                    </div>
                    <div className="pe-items-col pe-items-col--unit" data-mobile-label={`Unit (${currencyCode})`}>
                      <input
                        type="number"
                        step="0.01"
                        name="unit_price[]"
                        className="pe-input"
                        inputMode="decimal"
                        value={row.unit_price || ''}
                        onChange={(e) => updateRow(row.id, { unit_price: e.target.value })}
                        aria-label={`Unit price for item ${index + 1}`}
                      />
                    </div>
                    <div className="pe-items-col pe-items-col--total" data-mobile-label="Line total">
                      <input
                        type="text"
                        className="pe-input pe-input--ro"
                        value={formatMoney(total)}
                        readOnly
                        aria-label={`Line total for item ${index + 1}`}
                      />
                    </div>
                    <div className="pe-items-col pe-items-col--action">
                      <button
                        type="button"
                        className="pe-icon-btn"
                        onClick={() => removeRow(row.id)}
                        title="Remove item"
                        aria-label="Remove item"
                      >
                        <HiOutlineTrash style={{ width: 16, height: 16 }} />
                      </button>
                    </div>
                  </div>
                );
              })}
            </div>

            <div style={{ marginTop: 10 }}>
              <button type="button" className="pe-btn pe-btn--ghost" onClick={addRow}>
                <HiOutlinePlus style={{ width: 16, height: 16 }} /> Add item
              </button>
            </div>

            <div className="pe-row" style={{ marginTop: 24 }}>
              <label className="pe-label">Discount (%)</label>
              <div className="pe-field pe-field--narrow">
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  max="100"
                  name="discount_percentage"
                  className="pe-input"
                  value={discountPercentage}
                  onChange={(e) => setDiscountPercentage(e.target.value)}
                />
              </div>
            </div>
            <div className="pe-row">
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
              <label className="pe-label">Discount</label>
              <div className="pe-field pe-field--narrow">
                <input type="text" className="pe-input pe-input--ro" value={formatMoney(discountAmt)} readOnly />
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
            <div className="pe-notes-split">
              <div className="pe-notes-col">
                <label className="pe-field-label" htmlFor="pe-notes-input">Notes</label>
                <textarea
                  id="pe-notes-input"
                  name="notes"
                  rows={5}
                  className="pe-textarea"
                  placeholder="Internal notes..."
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                />
              </div>
              <div className="pe-notes-col">
                <label className="pe-field-label" htmlFor="pe-terms-input">Terms &amp; Conditions</label>
                <textarea
                  id="pe-terms-input"
                  name="terms_conditions"
                  rows={5}
                  className="pe-textarea"
                  placeholder="Terms for the PO..."
                  value={termsConditions}
                  onChange={(e) => setTermsConditions(e.target.value)}
                />
              </div>
            </div>

            <AttachmentsPicker />

            <div className="pe-actions">
              <a href={indexUrl} className="pe-btn pe-btn--ghost">Cancel</a>
              <button type="submit" className="pe-btn pe-btn--primary" disabled={isSubmitting || noSuppliers}>
                {submitLabel}
              </button>
            </div>
          </section>
        </div>
      </form>
    </div>
  );
}
