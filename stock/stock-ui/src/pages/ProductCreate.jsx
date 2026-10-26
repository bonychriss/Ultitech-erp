import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  HiOutlineArrowPath,
  HiOutlineBars3,
  HiOutlineChevronDown,
  HiOutlinePhoto,
  HiOutlinePlus,
  HiOutlineXMark,
} from 'react-icons/hi2';
import './product-create.css';

const UOM_OPTIONS = ['pcs', 'kg', 'box', 'set', 'pair'];
const TRUCK_TYPE_OPTIONS = ['Tractor Head', 'Tipper', 'Cargo Truck', 'Mixer', 'Tanker', 'Flatbed', 'Other'];
const DRIVE_CONFIG_OPTIONS = ['4 x 2', '6 x 2', '6 x 4', '8 x 4', '8 x 8'];
const TRANSMISSION_OPTIONS = ['Manual, 12F & 2R', 'Manual, 9F & 1R', 'Manual, 16F & 2R', 'Automatic', 'Other'];
const FUEL_TYPE_OPTIONS = ['Diesel', 'Petrol', 'CNG', 'Electric', 'Hybrid'];
const STOCK_LOCATION_OPTIONS = ['Main Yard', 'Warehouse', 'Showroom', 'Workshop'];
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

function BrandPicker({ id = 'prod-brand', value, options = [], onChange }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  const list = Array.isArray(options) ? options : [];
  const selected = list.find((b) => String(b.name) === String(value)) || null;
  const selectedImg = selected?.logo_url || selected?.image_url || '';

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
    <div className={`prod-create-cat${open ? ' is-open' : ''}`} ref={ref}>
      <button
        type="button"
        id={id}
        className="prod-create-cat-trigger"
        aria-haspopup="listbox"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
      >
        <span className="prod-create-cat-thumb" aria-hidden="true">
          {selectedImg ? (
            <img
              src={selectedImg}
              alt=""
              loading="lazy"
              onError={(e) => {
                e.currentTarget.style.display = 'none';
                e.currentTarget.parentElement?.classList.add('is-empty');
              }}
            />
          ) : (
            <span className="prod-create-cat-thumb-empty" />
          )}
        </span>
        <span className={`prod-create-cat-label${!selected ? ' is-placeholder' : ''}`}>
          {selected ? selected.name : 'Select brand'}
        </span>
        <HiOutlineChevronDown size={16} className="prod-create-cat-chevron" aria-hidden />
      </button>
      {open ? (
        <div className="prod-create-cat-menu" role="listbox">
          <button
            type="button"
            role="option"
            aria-selected={!value}
            className={`prod-create-cat-option${!value ? ' is-selected' : ''}`}
            onClick={() => {
              onChange('');
              setOpen(false);
            }}
          >
            <span className="prod-create-cat-thumb" aria-hidden="true">
              <span className="prod-create-cat-thumb-empty" />
            </span>
            <span className="prod-create-cat-label is-placeholder">Select brand</span>
          </button>
          {list.map((opt) => {
            const img = opt.logo_url || opt.image_url || '';
            const selectedOpt = String(opt.name) === String(value);
            return (
              <button
                key={opt.id || opt.name}
                type="button"
                role="option"
                aria-selected={selectedOpt}
                className={`prod-create-cat-option${selectedOpt ? ' is-selected' : ''}`}
                onClick={() => {
                  onChange(String(opt.name));
                  setOpen(false);
                }}
              >
                <span className="prod-create-cat-thumb" aria-hidden="true">
                  {img ? (
                    <img
                      src={img}
                      alt=""
                      loading="lazy"
                      onError={(e) => {
                        e.currentTarget.style.display = 'none';
                        e.currentTarget.parentElement?.classList.add('is-empty');
                      }}
                    />
                  ) : (
                    <span className="prod-create-cat-thumb-empty" />
                  )}
                </span>
                <span className="prod-create-cat-label">{opt.name}</span>
              </button>
            );
          })}
        </div>
      ) : null}
    </div>
  );
}

function CategoryPicker({ id = 'prod-category', value, options = [], onChange, required = false }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  const list = Array.isArray(options) ? options : [];
  const selected = list.find((c) => String(c.id) === String(value)) || null;
  const selectedImg = selected?.image_url || '';

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
    <div className={`prod-create-cat${open ? ' is-open' : ''}`} ref={ref}>
      <button
        type="button"
        id={id}
        className="prod-create-cat-trigger"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-required={required || undefined}
        onClick={() => setOpen((v) => !v)}
      >
        <span className="prod-create-cat-thumb" aria-hidden="true">
          {selectedImg ? (
            <img
              src={selectedImg}
              alt=""
              loading="lazy"
              onError={(e) => {
                e.currentTarget.style.display = 'none';
                e.currentTarget.parentElement?.classList.add('is-empty');
              }}
            />
          ) : (
            <span className="prod-create-cat-thumb-empty" />
          )}
        </span>
        <span className={`prod-create-cat-label${!selected ? ' is-placeholder' : ''}`}>
          {selected ? selected.name : 'Select category'}
        </span>
        <HiOutlineChevronDown size={16} className="prod-create-cat-chevron" aria-hidden />
      </button>
      {open ? (
        <div className="prod-create-cat-menu" role="listbox">
          <button
            type="button"
            role="option"
            aria-selected={!value}
            className={`prod-create-cat-option${!value ? ' is-selected' : ''}`}
            onClick={() => {
              onChange('');
              setOpen(false);
            }}
          >
            <span className="prod-create-cat-thumb" aria-hidden="true">
              <span className="prod-create-cat-thumb-empty" />
            </span>
            <span className="prod-create-cat-label is-placeholder">Select category</span>
          </button>
          {list.map((opt) => {
            const img = opt.image_url || '';
            const selectedOpt = String(opt.id) === String(value);
            return (
              <button
                key={opt.id}
                type="button"
                role="option"
                aria-selected={selectedOpt}
                className={`prod-create-cat-option${selectedOpt ? ' is-selected' : ''}`}
                onClick={() => {
                  onChange(String(opt.id));
                  setOpen(false);
                }}
              >
                <span className="prod-create-cat-thumb" aria-hidden="true">
                  {img ? (
                    <img
                      src={img}
                      alt=""
                      loading="lazy"
                      onError={(e) => {
                        e.currentTarget.style.display = 'none';
                        e.currentTarget.parentElement?.classList.add('is-empty');
                      }}
                    />
                  ) : (
                    <span className="prod-create-cat-thumb-empty" />
                  )}
                </span>
                <span className="prod-create-cat-label">{opt.name}</span>
              </button>
            );
          })}
        </div>
      ) : null}
      {required ? (
        <input
          tabIndex={-1}
          aria-hidden="true"
          className="prod-create-cat-required"
          value={value || ''}
          onChange={() => {}}
          required
        />
      ) : null}
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
  const [driveConfiguration, setDriveConfiguration] = useState(product?.drive_configuration || '');
  const [cabDetails, setCabDetails] = useState(product?.cab_details || '');
  const [fuelType, setFuelType] = useState(product?.fuel_type || 'Diesel');
  const [tireModel, setTireModel] = useState(product?.tire_model || '');
  const [curbWeight, setCurbWeight] = useState(
    product?.curb_weight_kg != null ? String(product.curb_weight_kg) : ''
  );
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
  const [moreOpen, setMoreOpen] = useState(false);

  const isTruck = !isUltimate && registerType === 'truck';
  const isSpare = !isUltimate && registerType === 'spare_part';
  const currencyCode = currencyMeta(currency).code || currency || 'TSh';

  const codePreview = isTruck ? previewTruckCode : previewCode;

  useEffect(() => {
    if (!successMsg) return undefined;
    const t = window.setTimeout(() => setSuccessMsg(''), 4000);
    return () => window.clearTimeout(t);
  }, [successMsg]);

  const brandOptions = useMemo(() => {
    if (useBrandFreeText || !brands.length) return [];
    return brands;
  }, [brands, useBrandFreeText]);

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
    if (isTruck) {
      if (!modelNumber.trim()) nextErrors.push('Model No. is required.');
      if (!brand.trim()) nextErrors.push('Make is required.');
      if (!truckType.trim()) nextErrors.push('Truck type is required.');
      if (!cabDetails.trim()) nextErrors.push('Cabin is required.');
      if (!engineModel.trim()) nextErrors.push('Engine is required.');
      if (!transmission.trim()) nextErrors.push('Transmission is required.');
      if (!fuelType.trim()) nextErrors.push('Fuel type is required.');
      if (!isEdit && (currentStock === '' || Number.isNaN(Number(currentStock)))) {
        nextErrors.push('Opening stock is required.');
      }
    } else {
      if (!name.trim()) nextErrors.push('Product name is required.');
      if (!categoryId) nextErrors.push('Category is required.');
      if (!isUltimate && !oemNumber.trim()) nextErrors.push('OEM / Part no is required.');
    }
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
    const submitName = isTruck
      ? (name.trim() || [brand, modelNumber].filter(Boolean).join(' ').trim() || 'Truck')
      : name.trim();
    let submitCategoryId = categoryId;
    if (isTruck && !submitCategoryId) {
      const brandMatch = categories.find(
        (c) => String(c.name || '').toLowerCase() === String(brand || '').toLowerCase()
      );
      submitCategoryId = brandMatch
        ? String(brandMatch.id)
        : categories[0]
          ? String(categories[0].id)
          : '';
    }
    fd.append('register_type', isUltimate ? 'general' : registerType);
    fd.append('name', submitName);
    fd.append('category_id', submitCategoryId);
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
    fd.append('drive_configuration', driveConfiguration);
    fd.append('cab_details', cabDetails);
    fd.append('fuel_type', fuelType);
    fd.append('tire_model', tireModel);
    fd.append('curb_weight_kg', curbWeight);
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
      <div className="prod-create-topbar">
        <div>
          <h1>{isEdit ? 'Edit Product' : 'Add Product'}</h1>
          <p>{isEdit ? 'Update product details and photos.' : 'Register a new product for stock.'}</p>
        </div>
      </div>

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

          <section className="prod-create-section" id="product-details">
            <div className="prod-create-card">
              {!isUltimate ? (
                <FieldRow label="Type" htmlFor="prod-type" required>
                  <div className="prod-create-type-toggle" id="prod-type">
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
                </FieldRow>
              ) : null}

              {isTruck ? (
                <>
                  <div className="prod-create-pair">
                    <FieldRow label="Model No." htmlFor="prod-model-no" required>
                      <input
                        id="prod-model-no"
                        className="prod-create-input"
                        value={modelNumber}
                        onChange={(e) => setModelNumber(e.target.value)}
                        placeholder="e.g. CA4181P2K15T1YA80"
                        required
                      />
                    </FieldRow>
                    <FieldRow label="Make" htmlFor="prod-brand" required>
                      {useBrandFreeText || brandOptions.length === 0 ? (
                        <input
                          id="prod-brand"
                          className="prod-create-input"
                          value={brand}
                          onChange={(e) => setBrand(e.target.value)}
                          placeholder="Select make"
                          required
                        />
                      ) : (
                        <BrandPicker
                          id="prod-brand"
                          value={brand}
                          options={brandOptions}
                          onChange={setBrand}
                        />
                      )}
                    </FieldRow>
                  </div>

                  <div className="prod-create-pair">
                    <FieldRow label="Truck type" htmlFor="prod-truck-type" required>
                      <select
                        id="prod-truck-type"
                        className="prod-create-select"
                        value={truckType}
                        onChange={(e) => setTruckType(e.target.value)}
                        required
                      >
                        <option value="">Select truck type</option>
                        {TRUCK_TYPE_OPTIONS.map((opt) => (
                          <option key={opt} value={opt}>{opt}</option>
                        ))}
                        {truckType && !TRUCK_TYPE_OPTIONS.includes(truckType) ? (
                          <option value={truckType}>{truckType}</option>
                        ) : null}
                      </select>
                    </FieldRow>
                    <FieldRow label="Drive configuration" htmlFor="prod-drive">
                      <select
                        id="prod-drive"
                        className="prod-create-select"
                        value={driveConfiguration}
                        onChange={(e) => setDriveConfiguration(e.target.value)}
                      >
                        <option value="">Select drive</option>
                        {DRIVE_CONFIG_OPTIONS.map((opt) => (
                          <option key={opt} value={opt}>{opt}</option>
                        ))}
                        {driveConfiguration && !DRIVE_CONFIG_OPTIONS.includes(driveConfiguration) ? (
                          <option value={driveConfiguration}>{driveConfiguration}</option>
                        ) : null}
                      </select>
                    </FieldRow>
                  </div>

                  <div className="prod-create-pair">
                    <FieldRow label="Cabin" htmlFor="prod-cabin" required>
                      <input
                        id="prod-cabin"
                        className="prod-create-input"
                        value={cabDetails}
                        onChange={(e) => setCabDetails(e.target.value)}
                        placeholder="e.g. Flat roof, Single Sleeper"
                        required
                      />
                    </FieldRow>
                    <FieldRow label="Engine" htmlFor="prod-engine-model" required>
                      <input
                        id="prod-engine-model"
                        className="prod-create-input"
                        value={engineModel}
                        onChange={(e) => setEngineModel(e.target.value)}
                        placeholder="e.g. Weichai, 380HP, Euro II"
                        required
                      />
                    </FieldRow>
                  </div>

                  <div className="prod-create-pair">
                    <FieldRow label="Transmission" htmlFor="prod-trans" required>
                      <select
                        id="prod-trans"
                        className="prod-create-select"
                        value={transmission}
                        onChange={(e) => setTransmission(e.target.value)}
                        required
                      >
                        <option value="">Select transmission</option>
                        {TRANSMISSION_OPTIONS.map((opt) => (
                          <option key={opt} value={opt}>{opt}</option>
                        ))}
                        {transmission && !TRANSMISSION_OPTIONS.includes(transmission) ? (
                          <option value={transmission}>{transmission}</option>
                        ) : null}
                      </select>
                    </FieldRow>
                    <FieldRow label="Fuel type" htmlFor="prod-fuel" required>
                      <select
                        id="prod-fuel"
                        className="prod-create-select"
                        value={fuelType}
                        onChange={(e) => setFuelType(e.target.value)}
                        required
                      >
                        <option value="">Select fuel type</option>
                        {FUEL_TYPE_OPTIONS.map((opt) => (
                          <option key={opt} value={opt}>{opt}</option>
                        ))}
                      </select>
                    </FieldRow>
                  </div>
                </>
              ) : (
                <>
                  <FieldRow label="Product name" htmlFor="prod-name" required>
                    <input
                      id="prod-name"
                      className="prod-create-input"
                      value={name}
                      onChange={(e) => setName(e.target.value)}
                      placeholder="Enter product name"
                      required
                    />
                  </FieldRow>

                  <div className="prod-create-pair">
                    <FieldRow label="Category" htmlFor="prod-category" required>
                      <CategoryPicker
                        id="prod-category"
                        value={categoryId}
                        options={categories}
                        onChange={setCategoryId}
                        required
                      />
                    </FieldRow>

                    <FieldRow label="Brand" htmlFor="prod-brand">
                      {useBrandFreeText || brandOptions.length === 0 ? (
                        <input
                          id="prod-brand"
                          className="prod-create-input"
                          value={brand}
                          onChange={(e) => setBrand(e.target.value)}
                          placeholder="Select brand"
                        />
                      ) : (
                        <BrandPicker
                          id="prod-brand"
                          value={brand}
                          options={brandOptions}
                          onChange={setBrand}
                        />
                      )}
                    </FieldRow>
                  </div>

                  <div className="prod-create-pair">
                    {isEdit ? (
                      <FieldRow label="In stock" htmlFor="prod-qty-spare" help="Quantity is changed via stock movements.">
                        <input
                          id="prod-qty-spare"
                          className="prod-create-input prod-create-input--readonly"
                          value={currentStock}
                          readOnly
                        />
                      </FieldRow>
                    ) : (
                      <FieldRow label="Opening stock" htmlFor="prod-qty-spare">
                        <input
                          id="prod-qty-spare"
                          type="number"
                          min="0"
                          className="prod-create-input"
                          value={currentStock}
                          onChange={(e) => setCurrentStock(e.target.value)}
                          placeholder="Enter quantity"
                        />
                      </FieldRow>
                    )}

                    {!isUltimate ? (
                      <FieldRow label="OEM / Part no" htmlFor="prod-oem" required>
                        <input
                          id="prod-oem"
                          className="prod-create-input"
                          value={oemNumber}
                          onChange={(e) => setOemNumber(e.target.value)}
                          placeholder="e.g. 12345-67890"
                          required
                        />
                      </FieldRow>
                    ) : (
                      <div aria-hidden="true" />
                    )}
                  </div>
                </>
              )}

              <div className="prod-create-pair">
                <FieldRow label={`Selling price (${currencyCode})`} htmlFor="prod-sell" required>
                  <input
                    id="prod-sell"
                    type="number"
                    step="0.01"
                    min="0"
                    className="prod-create-input prod-create-input--price"
                    value={unitPrice}
                    onChange={(e) => setUnitPrice(e.target.value)}
                    placeholder="Enter selling price"
                    required
                  />
                </FieldRow>

                <FieldRow label={`Buying price (${currencyCode})`} htmlFor="prod-buy">
                  <input
                    id="prod-buy"
                    type="number"
                    step="0.01"
                    min="0"
                    className="prod-create-input prod-create-input--price"
                    value={buyingPrice}
                    onChange={(e) => setBuyingPrice(e.target.value)}
                    placeholder="Enter buying price"
                  />
                </FieldRow>
              </div>

              {isTruck ? (
                <>
                  <div className="prod-create-pair">
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
                      <FieldRow label="Opening stock" htmlFor="prod-qty" required>
                        <input
                          id="prod-qty"
                          type="number"
                          min="0"
                          className="prod-create-input"
                          value={currentStock}
                          onChange={(e) => setCurrentStock(e.target.value)}
                          placeholder="Enter quantity"
                          required
                        />
                      </FieldRow>
                    )}
                    <FieldRow label="Stock location" htmlFor="prod-location">
                      <select
                        id="prod-location"
                        className="prod-create-select"
                        value={location}
                        onChange={(e) => setLocation(e.target.value)}
                      >
                        <option value="">Select location</option>
                        {STOCK_LOCATION_OPTIONS.map((opt) => (
                          <option key={opt} value={opt}>{opt}</option>
                        ))}
                        {location && !STOCK_LOCATION_OPTIONS.includes(location) ? (
                          <option value={location}>{location}</option>
                        ) : null}
                      </select>
                    </FieldRow>
                  </div>
                  <div className="prod-create-pair">
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
                    <FieldRow label="Condition" htmlFor="prod-condition">
                      <select
                        id="prod-condition"
                        className="prod-create-select"
                        value={partCondition}
                        onChange={(e) => setPartCondition(e.target.value)}
                      >
                        <option value="new">New</option>
                        <option value="used">Used</option>
                        <option value="refurbished">Refurbished</option>
                      </select>
                    </FieldRow>
                  </div>
                </>
              ) : null}
            </div>
          </section>

          <section className="prod-create-section" id="product-images">
            <div className="prod-create-section-header">
              <h2>
                Images
                {requireProductImage && !isEdit ? <span className="req"> *</span> : null}
              </h2>
              <p>Upload product photos. The first image is primary.</p>
            </div>
            <div className="prod-create-card">
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
            </div>
          </section>

          <section className="prod-create-section" id="product-more">
            <button
              type="button"
              className={`prod-create-more-toggle${moreOpen ? ' is-open' : ''}`}
              onClick={() => setMoreOpen((v) => !v)}
              aria-expanded={moreOpen}
            >
              <span className="prod-create-more-toggle-left">
                <HiOutlineBars3 size={18} aria-hidden />
                More details (optional)
              </span>
              <HiOutlineChevronDown size={18} className="prod-create-more-chevron" aria-hidden />
            </button>

            {moreOpen ? (
              <div className="prod-create-card prod-create-card--more">
                {isEdit ? (
                  <FieldRow label="Product code" htmlFor="prod-code" required>
                    <input
                      id="prod-code"
                      className="prod-create-input"
                      value={productCode}
                      onChange={(e) => setProductCode(e.target.value)}
                      required
                    />
                  </FieldRow>
                ) : null}

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

                <div className="prod-create-pair">
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

                  <FieldRow label="Currency" htmlFor="prod-currency">
                    <CurrencyPicker value={currency} options={currencies} onChange={setCurrency} />
                  </FieldRow>
                </div>

                {(isUltimate || isSpare || registerType === 'general') && (
                  <div className="prod-create-pair">
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
                  </div>
                )}

                {isSpare ? (
                  <FieldRow label="Compatible model" htmlFor="prod-compat">
                    <input
                      id="prod-compat"
                      className="prod-create-input"
                      value={compatibility}
                      onChange={(e) => setCompatibility(e.target.value)}
                      placeholder="e.g. Volvo FH16"
                    />
                  </FieldRow>
                ) : null}

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

                <div className="prod-create-pair">
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
                </div>

                {isTruck ? (
                  <>
                    <FieldRow label="VIN" htmlFor="prod-vin">
                      <input id="prod-vin" className="prod-create-input" value={vin} onChange={(e) => setVin(e.target.value)} />
                    </FieldRow>
                    <FieldRow label="Chassis #" htmlFor="prod-chassis">
                      <input id="prod-chassis" className="prod-create-input" value={chassisNumber} onChange={(e) => setChassisNumber(e.target.value)} />
                    </FieldRow>
                    <FieldRow label="Engine #" htmlFor="prod-engine">
                      <input id="prod-engine" className="prod-create-input" value={engineNumber} onChange={(e) => setEngineNumber(e.target.value)} />
                    </FieldRow>
                    <div className="prod-create-pair">
                      <FieldRow label="Model year" htmlFor="prod-year">
                        <input id="prod-year" type="number" className="prod-create-input" value={modelYear} onChange={(e) => setModelYear(e.target.value)} />
                      </FieldRow>
                      <FieldRow label="Mileage" htmlFor="prod-mileage">
                        <input id="prod-mileage" type="number" className="prod-create-input" value={mileage} onChange={(e) => setMileage(e.target.value)} />
                      </FieldRow>
                    </div>
                    <div className="prod-create-pair">
                      <FieldRow label="Color" htmlFor="prod-color">
                        <input id="prod-color" className="prod-create-input" value={color} onChange={(e) => setColor(e.target.value)} />
                      </FieldRow>
                      <FieldRow label="Truck type" htmlFor="prod-truck-type">
                        <input id="prod-truck-type" className="prod-create-input" value={truckType} onChange={(e) => setTruckType(e.target.value)} />
                      </FieldRow>
                    </div>
                    <div className="prod-create-pair">
                      <FieldRow label="Model number" htmlFor="prod-model-no">
                        <input id="prod-model-no" className="prod-create-input" value={modelNumber} onChange={(e) => setModelNumber(e.target.value)} />
                      </FieldRow>
                      <FieldRow label="Engine model" htmlFor="prod-engine-model">
                        <input id="prod-engine-model" className="prod-create-input" value={engineModel} onChange={(e) => setEngineModel(e.target.value)} />
                      </FieldRow>
                    </div>
                    <FieldRow label="Transmission" htmlFor="prod-trans">
                      <input id="prod-trans" className="prod-create-input" value={transmission} onChange={(e) => setTransmission(e.target.value)} />
                    </FieldRow>
                  </>
                ) : null}
              </div>
            ) : null}
          </section>

          <div className="prod-create-actions">
            {isEdit && viewUrl ? (
              <a href={viewUrl} className="prod-create-btn-cancel">
                View
              </a>
            ) : null}
            <a href={listUrl} className="prod-create-btn-cancel">
              Cancel
            </a>
            <button
              type="submit"
              className="prod-create-btn-save"
              disabled={saving}
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
