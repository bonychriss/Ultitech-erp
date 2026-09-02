import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  HiOutlineArrowPath,
  HiOutlinePhoto,
  HiOutlinePlus,
  HiOutlineXMark,
} from 'react-icons/hi2';
import './product-create.css';

const UOM_OPTIONS = ['pcs', 'kg', 'box', 'set', 'pair'];
const IMAGE_ACCEPT = 'image/jpeg,image/png,image/webp,image/gif';
const FLAG_BASE = 'https://flagcdn.com/w40/';

const CURRENCY_META = {
  TZS: { iso: 'TZS', code: 'TSh', name: 'Tanzanian Shilling', flag: 'tz' },
  USD: { iso: 'USD', code: 'USD', name: 'US Dollar', flag: 'us' },
  EUR: { iso: 'EUR', code: 'EUR', name: 'Euro', flag: 'eu' },
};

function flagUrl(flagCode) {
  const code = String(flagCode || 'un').toLowerCase();
  return `${FLAG_BASE}${code}.png`;
}

function currencyMeta(iso) {
  const key = String(iso || 'TZS').toUpperCase();
  return CURRENCY_META[key] || { iso: key, code: key, name: key, flag: 'un' };
}

function FieldRow({ label, htmlFor, required, children, help }) {
  return (
    <div className="prod-create-row">
      <label className="prod-create-label" htmlFor={htmlFor}>
        {label}
        {required ? <span className="req">*</span> : null}
      </label>
      {children}
      {help ? <div className="prod-create-help">{help}</div> : null}
    </div>
  );
}

function CurrencyPicker({ value, options, onChange }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  const selected = currencyMeta(value);
  const list = (options || ['TZS', 'USD', 'EUR']).map((c) =>
    typeof c === 'string' ? currencyMeta(c) : currencyMeta(c.iso || c.code)
  );

  useEffect(() => {
    if (!open) return undefined;
    const onDoc = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDoc);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div className={`prod-create-currency${open ? ' is-open' : ''}`} ref={ref}>
      <button
        type="button"
        id="prod-currency"
        className="prod-create-currency-trigger"
        aria-haspopup="listbox"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
      >
        <img
          src={flagUrl(selected.flag)}
          alt=""
          className="prod-create-currency-flag"
          width={28}
          height={20}
        />
        <span className="prod-create-currency-label">
          <span className="code">{selected.code}</span>
          <span className="name">{selected.name}</span>
        </span>
      </button>
      {open ? (
        <div className="prod-create-currency-menu" role="listbox">
          {list.map((opt) => (
            <button
              key={opt.iso}
              type="button"
              role="option"
              aria-selected={opt.iso === selected.iso}
              className={`prod-create-currency-option${opt.iso === selected.iso ? ' is-selected' : ''}`}
              onClick={() => {
                onChange(opt.iso);
                setOpen(false);
              }}
            >
              <img
                src={flagUrl(opt.flag)}
                alt=""
                className="prod-create-currency-flag"
                width={28}
                height={20}
              />
              <span className="code">{opt.code}</span>
              <span className="name">{opt.name}</span>
            </button>
          ))}
        </div>
      ) : null}
    </div>
  );
}

function ImageUpload({ images, onChange }) {
  const inputRef = useRef(null);
  const [dragging, setDragging] = useState(false);
  const previews = useMemo(
    () =>
      images.map((file) => ({
        file,
        url: URL.createObjectURL(file),
        key: `${file.name}-${file.size}-${file.lastModified}`,
      })),
    [images]
  );

  useEffect(() => {
    return () => {
      previews.forEach((p) => URL.revokeObjectURL(p.url));
    };
  }, [previews]);

  const mergeFiles = (incoming) => {
    const next = Array.from(incoming || []).filter((f) => f.type.startsWith('image/'));
    if (!next.length) return;
    const seen = new Set(images.map((f) => `${f.name}-${f.size}-${f.lastModified}`));
    const merged = [...images];
    next.forEach((f) => {
      const key = `${f.name}-${f.size}-${f.lastModified}`;
      if (!seen.has(key)) {
        seen.add(key);
        merged.push(f);
      }
    });
    onChange(merged);
  };

  const removeAt = (index) => {
    onChange(images.filter((_, i) => i !== index));
  };

  const onDrop = (e) => {
    e.preventDefault();
    setDragging(false);
    mergeFiles(e.dataTransfer.files);
  };

  return (
    <div className="prod-create-images">
      <div
        className={`prod-create-dropzone${dragging ? ' is-dragging' : ''}${images.length ? ' has-files' : ''}`}
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
          setDragging(false);
        }}
        onDrop={onDrop}
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
          id="prod-images"
          type="file"
          className="prod-create-dropzone-input"
          accept={IMAGE_ACCEPT}
          multiple
          onChange={(e) => {
            mergeFiles(e.target.files);
            e.target.value = '';
          }}
        />
        <span className="prod-create-dropzone-icon" aria-hidden="true">
          <HiOutlinePhoto size={28} />
        </span>
        <span className="prod-create-dropzone-title">
          {images.length ? 'Add more photos' : 'Drop photos here, or click to browse'}
        </span>
        <span className="prod-create-dropzone-hint">JPG, PNG, or WebP - first image is primary</span>
      </div>

      {previews.length > 0 ? (
        <ul className="prod-create-preview-grid">
          {previews.map((item, index) => (
            <li key={item.key} className={`prod-create-preview${index === 0 ? ' is-primary' : ''}`}>
              <img src={item.url} alt="" />
              {index === 0 ? <span className="prod-create-preview-badge">Primary</span> : null}
              <button
                type="button"
                className="prod-create-preview-remove"
                onClick={(e) => {
                  e.stopPropagation();
                  removeAt(index);
                }}
                title="Remove"
                aria-label={`Remove ${item.file.name}`}
              >
                <HiOutlineXMark size={14} />
              </button>
              <span className="prod-create-preview-name">{item.file.name}</span>
            </li>
          ))}
          <li className="prod-create-preview prod-create-preview--add">
            <button
              type="button"
              className="prod-create-preview-add-btn"
              onClick={() => inputRef.current?.click()}
            >
              <HiOutlinePlus size={22} />
              <span>Add</span>
            </button>
          </li>
        </ul>
      ) : null}
    </div>
  );
}

export default function ProductCreate({ data = {} }) {
  const {
    mode = 'create',
    isUltimate = true,
    product = null,
    existingImages = [],
    categories = [],
    suppliers = [],
    brands = [],
    useBrandFreeText = true,
    previewCode = '',
    previewTruckCode = '',
    currencies = ['TZS', 'USD', 'EUR'],
    defaultCurrency = 'TZS',
    listUrl = 'index.php',
    viewUrl = '',
    createApiUrl = 'api/create-product.php',
    updateApiUrl = 'api/update-product.php',
    updated = false,
    requireProductImage = false,
  } = data;

  const isEdit = mode === 'edit' && product && product.id;

  const initialRegister = isUltimate
    ? 'general'
    : product?.register_type === 'truck' || product?.item_type === 'vehicle'
      ? 'truck'
      : product?.register_type === 'general' || product?.item_type === 'general'
        ? 'general'
        : 'spare_part';

  const [registerType, setRegisterType] = useState(initialRegister);
  const [productCode, setProductCode] = useState(product?.product_code || '');
  const [name, setName] = useState(product?.name || '');
  const [categoryId, setCategoryId] = useState(
    product?.category_id != null ? String(product.category_id) : ''
  );
  const [description, setDescription] = useState(product?.description || '');
  const [brand, setBrand] = useState(product?.brand || '');
  const [supplierId, setSupplierId] = useState(
    product?.supplier_id != null ? String(product.supplier_id) : ''
  );
  const [uom, setUom] = useState(product?.unit_of_measure || 'pcs');
  const [partCondition, setPartCondition] = useState(product?.part_condition || 'new');
  const [currency, setCurrency] = useState(product?.currency || defaultCurrency || 'TZS');
  const [unitPrice, setUnitPrice] = useState(
    product?.unit_price != null ? String(product.unit_price) : ''
  );
  const [buyingPrice, setBuyingPrice] = useState(
    product?.buying_price != null ? String(product.buying_price) : ''
  );
  const [wholesalePrice, setWholesalePrice] = useState(
    product?.wholesale_price != null && Number(product.wholesale_price) > 0
      ? String(product.wholesale_price)
      : ''
  );
  const [currentStock, setCurrentStock] = useState(
    product?.quantity != null ? String(product.quantity) : '0'
  );
  const [reorderLevel, setReorderLevel] = useState(
    product?.reorder_level != null ? String(product.reorder_level) : '10'
  );
  const [location, setLocation] = useState(product?.location || '');
  const [compatibility, setCompatibility] = useState(product?.compatibility || '');
  const [oemNumber, setOemNumber] = useState(product?.oem_number || '');
  const [vin, setVin] = useState(product?.vin || '');
  const [chassisNumber, setChassisNumber] = useState(product?.chassis_number || '');
  const [engineNumber, setEngineNumber] = useState(product?.engine_number || '');
  const [modelYear, setModelYear] = useState(
    product?.model_year != null ? String(product.model_year) : ''
  );
  const [mileage, setMileage] = useState(product?.mileage != null ? String(product.mileage) : '');
  const [color, setColor] = useState(product?.color || '');
  const [truckType, setTruckType] = useState(product?.truck_type || '');
  const [modelNumber, setModelNumber] = useState(product?.model_number || '');
  const [engineModel, setEngineModel] = useState(product?.engine_model || '');
  const [transmission, setTransmission] = useState(product?.transmission_model || '');
  const [images, setImages] = useState([]);
  const [savedImages, setSavedImages] = useState(() =>
    Array.isArray(existingImages) ? existingImages.filter((img) => img && img.id) : []
  );
  const [deleteImageIds, setDeleteImageIds] = useState([]);
  const [primaryImageId, setPrimaryImageId] = useState(() => {
    const primary = (existingImages || []).find((img) => img.is_primary);
    return primary?.id || existingImages?.[0]?.id || 0;
  });
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState([]);
  const [successMsg, setSuccessMsg] = useState(updated ? 'Product updated successfully.' : '');

  const isTruck = !isUltimate && registerType === 'truck';
  const isSpare = !isUltimate && registerType === 'spare_part';

  const codePreview = isTruck ? previewTruckCode : previewCode;

  useEffect(() => {
    if (!successMsg) return undefined;
    const t = window.setTimeout(() => setSuccessMsg(''), 4000);
    return () => window.clearTimeout(t);
  }, [successMsg]);

  const brandOptions = useMemo(() => {
    if (useBrandFreeText || !brands.length) return [];
    if (isUltimate) return brands;
    if (isTruck) {
      return brands.filter((b) => !b.brand_type || b.brand_type === 'truck' || b.brand_type === 'vehicle');
    }
    return brands.filter((b) => !b.brand_type || b.brand_type === 'spare_part' || b.brand_type === 'general');
  }, [brands, useBrandFreeText, isUltimate, isTruck]);

  const visibleSavedImages = useMemo(
    () => savedImages.filter((img) => !deleteImageIds.includes(img.id)),
    [savedImages, deleteImageIds]
  );

  const onFiles = (list) => {
    setImages(Array.isArray(list) ? list : []);
  };

  const markDeleteImage = (imgId) => {
    setDeleteImageIds((prev) => (prev.includes(imgId) ? prev : [...prev, imgId]));
    if (primaryImageId === imgId) {
      const next = savedImages.find((img) => img.id !== imgId && !deleteImageIds.includes(img.id));
      setPrimaryImageId(next?.id || 0);
    }
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    const nextErrors = [];
    if (isEdit && !productCode.trim()) nextErrors.push('Product code is required.');
    if (!name.trim()) nextErrors.push('Product name is required.');
    if (!categoryId) nextErrors.push('Category is required.');
    if (unitPrice === '' || Number.isNaN(Number(unitPrice))) nextErrors.push('Selling price is required.');
    if (requireProductImage && !isEdit && images.length === 0) {
      nextErrors.push('Please add at least one product image before saving.');
    }
    if (nextErrors.length) {
      if (requireProductImage && !isEdit && images.length === 0) {
        window.alert('Please add at least one product image before you can save this product.');
        document.getElementById('product-images')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      setErrors(nextErrors);
      return;
    }

    setSaving(true);
    setErrors([]);
    setSuccessMsg('');

    const fd = new FormData();
    if (isEdit) {
      fd.append('id', String(product.id));
      fd.append('product_code', productCode.trim());
    }
    fd.append('register_type', isUltimate ? 'general' : registerType);
    fd.append('name', name.trim());
    fd.append('category_id', categoryId);
    fd.append('description', description);
    fd.append('brand', brand);
    fd.append('supplier_id', supplierId);
    fd.append('unit_of_measure', uom);
    fd.append('part_condition', partCondition);
    fd.append('currency', currency);
    fd.append('unit_price', String(unitPrice || 0));
    fd.append('buying_price', String(buyingPrice || 0));
    fd.append('wholesale_price', String(wholesalePrice || 0));
    fd.append('current_stock', String(currentStock || 0));
    fd.append('reorder_level', String(reorderLevel || 0));
    fd.append('location', location);
    fd.append('compatible_truck_model', compatibility);
    fd.append('oem_number', oemNumber);
    fd.append('vin', vin);
    fd.append('chassis_number', chassisNumber);
    fd.append('engine_number', engineNumber);
    fd.append('model_year', modelYear);
    fd.append('mileage', mileage);
    fd.append('color', color);
    fd.append('truck_type', truckType);
    fd.append('model_number', modelNumber);
    fd.append('engine_model', engineModel);
    fd.append('transmission_model', transmission);
    images.forEach((file) => fd.append('product_images[]', file));
    if (isEdit) {
      if (deleteImageIds.length) {
        fd.append('delete_image_ids', JSON.stringify(deleteImageIds));
      }
      if (primaryImageId) {
        fd.append('set_primary_id', String(primaryImageId));
      }
    }

    const apiUrl = isEdit ? updateApiUrl : createApiUrl;

    try {
      const res = await fetch(apiUrl, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.ok) {
        setErrors([json.error || (isEdit ? 'Could not update product.' : 'Could not create product.')]);
        setSaving(false);
        return;
      }
      if (isEdit) {
        window.location.href = json.redirect || `edit.php?id=${product.id}&updated=1`;
        return;
      }
      window.location.href = json.redirect || `${listUrl}?created=1&created_id=${json.id}`;
    } catch (err) {
      setErrors([err.message || 'Network error while saving.']);
      setSaving(false);
    }
  };

  return (
    <div className="prod-create-shell">
      <style>{`
        .prod-create-row {
          display: grid !important;
          grid-template-columns: 210px 1fr !important;
          grid-auto-rows: min-content !important;
          align-items: start !important;
          align-content: start !important;
          margin-bottom: 24px !important;
          height: auto !important;
          min-height: 0 !important;
        }
        .prod-create-help { grid-column: 2; margin-top: 6px !important; }
        @media (max-width: 900px) {
          body.dashboard .prod-create-row {
            display: block !important;
            margin-top: 0 !important;
            margin-bottom: 16px !important;
            height: auto !important;
            min-height: 0 !important;
          }
          body.dashboard .prod-create-label {
            display: block !important;
            padding: 0 0 4px !important;
            margin: 0 0 4px 0 !important;
          }
          body.dashboard .prod-create-help {
            grid-column: auto;
            display: block !important;
            margin: 4px 0 0 0 !important;
          }
          body.dashboard .prod-create-input,
          body.dashboard .prod-create-select,
          body.dashboard .prod-create-textarea,
          body.dashboard .prod-create-currency {
            width: 100% !important;
            max-width: none !important;
            margin-top: 0 !important;
            margin-bottom: 0 !important;
          }
        }
      `}</style>
      <form className="prod-create-layout" onSubmit={handleSubmit} noValidate>
        <div className="prod-create-main">
          {successMsg ? (
            <div className="prod-create-alert prod-create-alert--success" role="status">
              {successMsg}
            </div>
          ) : null}

          {errors.length > 0 && (
            <div className="prod-create-alert prod-create-alert--error" role="alert">
              {errors.map((err) => (
                <div key={err}>{err}</div>
              ))}
            </div>
          )}

          {!isUltimate && (
            <section className="prod-create-section" id="product-type">
              <div className="prod-create-section-header">
                <h2>Product type</h2>
                <p>Choose spare part or truck registration.</p>
              </div>
              <div className="prod-create-row">
                <span className="prod-create-label">Type<span className="req">*</span></span>
                <div className="prod-create-type-toggle">
                  <label className={registerType === 'spare_part' ? 'is-active' : ''}>
                    <input
                      type="radio"
                      name="register_type"
                      value="spare_part"
                      checked={registerType === 'spare_part'}
                      onChange={() => setRegisterType('spare_part')}
                    />
                    Spare part
                  </label>
                  <label className={registerType === 'truck' ? 'is-active' : ''}>
                    <input
                      type="radio"
                      name="register_type"
                      value="truck"
                      checked={registerType === 'truck'}
                      onChange={() => setRegisterType('truck')}
                    />
                    Truck
                  </label>
                </div>
              </div>
            </section>
          )}

          <section className="prod-create-section" id="product-basics">
            <div className="prod-create-section-header">
              <h2>Basics</h2>
              <p>Name, category, and identifying details.</p>
            </div>

            <FieldRow
              label="Product code"
              htmlFor="prod-code"
              required={isEdit}
              help={isEdit ? null : 'Assigned automatically when you save.'}
            >
              {isEdit ? (
                <input
                  id="prod-code"
                  className="prod-create-input"
                  value={productCode}
                  onChange={(e) => setProductCode(e.target.value)}
                  required
                />
              ) : (
                <input
                  id="prod-code"
                  className="prod-create-input prod-create-input--readonly"
                  value={codePreview || '-'}
                  readOnly
                />
              )}
            </FieldRow>

            <FieldRow label="Name" htmlFor="prod-name" required>
              <input
                id="prod-name"
                className="prod-create-input"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Enter product name"
                required
              />
            </FieldRow>

            <FieldRow label="Category" htmlFor="prod-category" required>
              <select
                id="prod-category"
                className="prod-create-select"
                value={categoryId}
                onChange={(e) => setCategoryId(e.target.value)}
                required
              >
                <option value="">Select category</option>
                {categories.map((c) => (
                  <option key={c.id} value={String(c.id)}>
                    {c.name}
                  </option>
                ))}
              </select>
            </FieldRow>

            <FieldRow label="Description" htmlFor="prod-desc">
              <textarea
                id="prod-desc"
                className="prod-create-textarea"
                rows={3}
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                placeholder="Short description"
              />
            </FieldRow>

            <FieldRow label="Brand" htmlFor="prod-brand">
              {useBrandFreeText || brandOptions.length === 0 ? (
                <input
                  id="prod-brand"
                  className="prod-create-input"
                  value={brand}
                  onChange={(e) => setBrand(e.target.value)}
                  placeholder="Brand"
                />
              ) : (
                <select
                  id="prod-brand"
                  className="prod-create-select"
                  value={brand}
                  onChange={(e) => setBrand(e.target.value)}
                >
                  <option value="">Select brand</option>
                  {brandOptions.map((b) => (
                    <option key={b.id || b.name} value={b.name}>
                      {b.name}
                    </option>
                  ))}
                </select>
              )}
            </FieldRow>

            <FieldRow label="Supplier" htmlFor="prod-supplier">
              <select
                id="prod-supplier"
                className="prod-create-select"
                value={supplierId}
                onChange={(e) => setSupplierId(e.target.value)}
              >
                <option value="">None</option>
                {suppliers.map((s) => (
                  <option key={s.id} value={String(s.id)}>
                    {s.name}
                  </option>
                ))}
              </select>
            </FieldRow>

            {(isUltimate || isSpare || registerType === 'general') && (
              <>
                <FieldRow label="UOM" htmlFor="prod-uom">
                  <select
                    id="prod-uom"
                    className="prod-create-select"
                    value={uom}
                    onChange={(e) => setUom(e.target.value)}
                  >
                    {UOM_OPTIONS.map((u) => (
                      <option key={u} value={u}>
                        {u}
                      </option>
                    ))}
                  </select>
                </FieldRow>
                <FieldRow label="Condition" htmlFor="prod-condition">
                  <select
                    id="prod-condition"
                    className="prod-create-select"
                    value={partCondition}
                    onChange={(e) => setPartCondition(e.target.value)}
                  >
                    <option value="new">New</option>
                    <option value="used">Used</option>
                  </select>
                </FieldRow>
              </>
            )}

            {isSpare && (
              <>
                <FieldRow label="Compatible model" htmlFor="prod-compat">
                  <input
                    id="prod-compat"
                    className="prod-create-input"
                    value={compatibility}
                    onChange={(e) => setCompatibility(e.target.value)}
                    placeholder="e.g. Volvo FH16"
                  />
                </FieldRow>
                {!isUltimate && (
                  <FieldRow label="OEM / Part no" htmlFor="prod-oem">
                    <input
                      id="prod-oem"
                      className="prod-create-input"
                      value={oemNumber}
                      onChange={(e) => setOemNumber(e.target.value)}
                    />
                  </FieldRow>
                )}
              </>
            )}
          </section>

          {isTruck && (
            <section className="prod-create-section" id="product-truck">
              <div className="prod-create-section-header">
                <h2>Truck details</h2>
                <p>Identification and specifications.</p>
              </div>
              <FieldRow label="VIN" htmlFor="prod-vin">
                <input id="prod-vin" className="prod-create-input" value={vin} onChange={(e) => setVin(e.target.value)} />
              </FieldRow>
              <FieldRow label="Chassis #" htmlFor="prod-chassis">
                <input id="prod-chassis" className="prod-create-input" value={chassisNumber} onChange={(e) => setChassisNumber(e.target.value)} />
              </FieldRow>
              <FieldRow label="Engine #" htmlFor="prod-engine">
                <input id="prod-engine" className="prod-create-input" value={engineNumber} onChange={(e) => setEngineNumber(e.target.value)} />
              </FieldRow>
              <FieldRow label="Model year" htmlFor="prod-year">
                <input id="prod-year" type="number" className="prod-create-input" value={modelYear} onChange={(e) => setModelYear(e.target.value)} />
              </FieldRow>
              <FieldRow label="Mileage" htmlFor="prod-mileage">
                <input id="prod-mileage" type="number" className="prod-create-input" value={mileage} onChange={(e) => setMileage(e.target.value)} />
              </FieldRow>
              <FieldRow label="Color" htmlFor="prod-color">
                <input id="prod-color" className="prod-create-input" value={color} onChange={(e) => setColor(e.target.value)} />
              </FieldRow>
              <FieldRow label="Truck type" htmlFor="prod-truck-type">
                <input id="prod-truck-type" className="prod-create-input" value={truckType} onChange={(e) => setTruckType(e.target.value)} />
              </FieldRow>
              <FieldRow label="Model number" htmlFor="prod-model-no">
                <input id="prod-model-no" className="prod-create-input" value={modelNumber} onChange={(e) => setModelNumber(e.target.value)} />
              </FieldRow>
              <FieldRow label="Engine model" htmlFor="prod-engine-model">
                <input id="prod-engine-model" className="prod-create-input" value={engineModel} onChange={(e) => setEngineModel(e.target.value)} />
              </FieldRow>
              <FieldRow label="Transmission" htmlFor="prod-trans">
                <input id="prod-trans" className="prod-create-input" value={transmission} onChange={(e) => setTransmission(e.target.value)} />
              </FieldRow>
            </section>
          )}

          <section className="prod-create-section" id="product-pricing">
            <div className="prod-create-section-header">
              <h2>Pricing</h2>
            </div>

            <FieldRow label="Currency" htmlFor="prod-currency" required>
              <CurrencyPicker value={currency} options={currencies} onChange={setCurrency} />
            </FieldRow>

            <FieldRow label="Buying price" htmlFor="prod-buy">
              <input
                id="prod-buy"
                type="number"
                step="0.01"
                min="0"
                className="prod-create-input prod-create-input--price"
                value={buyingPrice}
                onChange={(e) => setBuyingPrice(e.target.value)}
                placeholder="0.00"
              />
            </FieldRow>

            <FieldRow label="Selling price" htmlFor="prod-sell" required>
              <input
                id="prod-sell"
                type="number"
                step="0.01"
                min="0"
                className="prod-create-input prod-create-input--price"
                value={unitPrice}
                onChange={(e) => setUnitPrice(e.target.value)}
                placeholder="0.00"
                required
              />
            </FieldRow>

            {!isTruck ? (
              <FieldRow label="Wholesale" htmlFor="prod-wholesale">
                <input
                  id="prod-wholesale"
                  type="number"
                  step="0.01"
                  min="0"
                  className="prod-create-input prod-create-input--price"
                  value={wholesalePrice}
                  onChange={(e) => setWholesalePrice(e.target.value)}
                  placeholder="0.00"
                />
              </FieldRow>
            ) : null}
          </section>

          <section className="prod-create-section" id="product-stock">
            <div className="prod-create-section-header">
              <h2>Stock</h2>
              <p>{isEdit ? 'Reorder level and storage location.' : 'Opening quantity and reorder settings.'}</p>
            </div>

            {isEdit ? (
              <FieldRow label="In stock" htmlFor="prod-qty" help="Quantity is changed via stock movements.">
                <input
                  id="prod-qty"
                  className="prod-create-input prod-create-input--readonly"
                  value={currentStock}
                  readOnly
                />
              </FieldRow>
            ) : (
              <FieldRow label="Opening qty" htmlFor="prod-qty">
                <input
                  id="prod-qty"
                  type="number"
                  min="0"
                  className="prod-create-input"
                  value={currentStock}
                  onChange={(e) => setCurrentStock(e.target.value)}
                />
              </FieldRow>
            )}

            <FieldRow label="Reorder level" htmlFor="prod-reorder">
              <input
                id="prod-reorder"
                type="number"
                min="0"
                className="prod-create-input"
                value={reorderLevel}
                onChange={(e) => setReorderLevel(e.target.value)}
              />
            </FieldRow>

            <FieldRow label="Location" htmlFor="prod-location">
              <input
                id="prod-location"
                className="prod-create-input"
                value={location}
                onChange={(e) => setLocation(e.target.value)}
                placeholder="Warehouse / shelf"
              />
            </FieldRow>
          </section>

          <section className="prod-create-section" id="product-images">
            <div className="prod-create-section-header">
              <h2>
                Images
                {requireProductImage && !isEdit ? <span className="req"> *</span> : null}
              </h2>
              <p>
                {isEdit
                  ? 'Manage existing photos or upload new ones. Mark one as primary.'
                  : requireProductImage
                    ? 'Add at least one product photo before saving. The first image is used as the primary photo.'
                    : 'Upload one or more product photos. The first image is used as the primary photo.'}
              </p>
            </div>

            {isEdit && visibleSavedImages.length > 0 ? (
              <ul className="prod-create-preview-grid prod-create-existing-grid">
                {visibleSavedImages.map((img) => (
                  <li
                    key={img.id}
                    className={`prod-create-preview${primaryImageId === img.id ? ' is-primary' : ''}`}
                  >
                    <img src={img.medium_url || img.thumbnail_url || ''} alt="" />
                    {primaryImageId === img.id ? (
                      <span className="prod-create-preview-badge">Primary</span>
                    ) : (
                      <button
                        type="button"
                        className="prod-create-preview-primary"
                        onClick={() => setPrimaryImageId(img.id)}
                      >
                        Set primary
                      </button>
                    )}
                    <button
                      type="button"
                      className="prod-create-preview-remove"
                      onClick={() => markDeleteImage(img.id)}
                      title="Remove"
                      aria-label="Remove image"
                    >
                      <HiOutlineXMark size={14} />
                    </button>
                  </li>
                ))}
              </ul>
            ) : null}

            <ImageUpload images={images} onChange={onFiles} />
          </section>

          <div className="prod-create-actions">
            {isEdit && viewUrl ? (
              <a href={viewUrl} className="prod-create-btn-cancel" style={{ borderRadius: 9999 }}>
                View
              </a>
            ) : null}
            <a href={listUrl} className="prod-create-btn-cancel" style={{ borderRadius: 9999 }}>
              Cancel
            </a>
            <button
              type="submit"
              className="prod-create-btn-save"
              disabled={saving}
              style={{ borderRadius: 9999 }}
            >
              {saving ? (
                <>
                  <HiOutlineArrowPath size={18} className="prod-create-spinner" aria-hidden />
                  Saving...
                </>
              ) : isEdit ? (
                'Save changes'
              ) : (
                'Save product'
              )}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
}
