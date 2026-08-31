import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  HiOutlineFolder,
  HiOutlineCheckCircle,
  HiOutlineStar,
  HiOutlineInbox,
  HiOutlineMagnifyingGlass,
  HiOutlinePlus,
  HiOutlinePencilSquare,
  HiOutlineTrash,
  HiOutlineXMark,
  HiOutlineTruck,
  HiOutlineCog6Tooth,
  HiOutlinePhoto,
  HiStar,
  HiOutlineArrowPath,
} from 'react-icons/hi2';
import ProductThumb from './ProductThumb';
import './products-desk.css';
import './categories-desk.css';

const IMAGE_ACCEPT = 'image/jpeg,image/png,image/webp,image/gif';

function typeLabel(type) {
  if (type === 'vehicle') return 'Truck';
  if (type === 'spare_part') return 'Spare Part';
  return 'General';
}

function typeClass(type) {
  if (type === 'vehicle') return 'prod-desk-type--truck';
  if (type === 'spare_part') return 'prod-desk-type--spare';
  return 'prod-desk-type--general';
}

function normalizeSearchQuery(q) {
  return String(q || '')
    .trim()
    .replace(/\s+/g, ' ');
}

function categoryMatchesQuery(q, cat) {
  const tokens = normalizeSearchQuery(q)
    .toLowerCase()
    .split(' ')
    .filter(Boolean);
  if (tokens.length === 0) return true;
  const hay = [
    cat.name,
    cat.description,
    cat.parent_name,
    cat.item_type,
    typeLabel(cat.item_type),
    cat.status,
  ]
    .map((v) => String(v || '').toLowerCase())
    .join(' ');
  return tokens.every((t) => hay.includes(t));
}

function CategoryImageField({
  id,
  name,
  label,
  hint,
  existingUrl = '',
  existingFile = '',
  oldFieldName,
  variant = 'cover',
}) {
  const inputRef = useRef(null);
  const [file, setFile] = useState(null);
  const [dragging, setDragging] = useState(false);
  const [previewUrl, setPreviewUrl] = useState('');
  const [imgFailed, setImgFailed] = useState(false);

  // Create blob URL in an effect so Strict Mode revoke does not leave a dead cached URL.
  useEffect(() => {
    if (!file) {
      setPreviewUrl('');
      return undefined;
    }
    const url = URL.createObjectURL(file);
    setPreviewUrl(url);
    return () => {
      URL.revokeObjectURL(url);
    };
  }, [file]);

  useEffect(() => {
    setImgFailed(false);
  }, [previewUrl, existingUrl]);

  const displayUrl = previewUrl || existingUrl;
  const showPreview = Boolean(displayUrl) && !imgFailed;

  const pick = (incoming) => {
    const next = Array.from(incoming || []).find((f) => f && String(f.type || '').startsWith('image/'));
    if (next) {
      setImgFailed(false);
      setFile(next);
    }
  };

  const assignInputFiles = (list) => {
    if (!inputRef.current || !list?.[0]) return;
    try {
      const dt = new DataTransfer();
      dt.items.add(list[0]);
      inputRef.current.files = dt.files;
    } catch {
      // Some browsers block programmatic FileList assignment; form still gets files from change.
    }
  };

  return (
    <div className="cat-desk-form-row cat-desk-form-row--images">
      <span className="cat-desk-form-label">{label}</span>
      <div className="cat-desk-image-field">
        {oldFieldName ? <input type="hidden" name={oldFieldName} value={existingFile || ''} /> : null}
        <div
          className={`cat-desk-dropzone cat-desk-dropzone--${variant}${dragging ? ' is-dragging' : ''}${
            showPreview ? ' has-preview' : ''
          }`}
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
          onDrop={(e) => {
            e.preventDefault();
            setDragging(false);
            pick(e.dataTransfer.files);
            assignInputFiles(e.dataTransfer.files);
          }}
        >
          <input
            ref={inputRef}
            id={id}
            type="file"
            name={name}
            accept={IMAGE_ACCEPT}
            className="cat-desk-dropzone-input"
            onChange={(e) => pick(e.target.files)}
          />
          {showPreview ? (
            <div className="cat-desk-dropzone-frame">
              <img
                src={displayUrl}
                alt=""
                className="cat-desk-dropzone-preview"
                onLoad={() => setImgFailed(false)}
                onError={() => setImgFailed(true)}
              />
            </div>
          ) : (
            <button
              type="button"
              className="cat-desk-dropzone-empty"
              onClick={() => inputRef.current?.click()}
            >
              <HiOutlinePhoto size={22} aria-hidden="true" />
              <span className="cat-desk-dropzone-title">
                {imgFailed ? 'Could not load image — click to choose another' : 'Drop image or click to browse'}
              </span>
              <span className="cat-desk-dropzone-hint">{hint || 'JPG, PNG, or WebP'}</span>
            </button>
          )}
        </div>
        {showPreview || file ? (
          <div className="cat-desk-image-actions">
            <button
              type="button"
              className="prod-desk-btn prod-desk-btn-secondary cat-desk-btn"
              onClick={() => inputRef.current?.click()}
            >
              Replace
            </button>
            {file ? (
              <button
                type="button"
                className="prod-desk-btn prod-desk-btn-secondary cat-desk-btn"
                onClick={() => {
                  setFile(null);
                  setImgFailed(false);
                  if (inputRef.current) inputRef.current.value = '';
                }}
              >
                Clear new
              </button>
            ) : null}
          </div>
        ) : null}
      </div>
    </div>
  );
}

function CategoryFormFields({
  mode,
  item,
  data,
  selectedType,
  formAction,
}) {
  const {
    hasItemType,
    hasParent,
    hasOrder,
    hasLevel,
    hasStatus,
    hasCover = true,
    categories = [],
    isUltimate = false,
  } = data;

  const showType = hasItemType && !isUltimate;
  const defaults = item || {};
  const showImages = hasCover;
  const [saving, setSaving] = useState(false);

  return (
    <form
      method="POST"
      action={formAction}
      encType="multipart/form-data"
      className="cat-desk-form"
      onSubmit={() => setSaving(true)}
    >
      <input type="hidden" name="action" value={mode} />
      {mode === 'edit' && <input type="hidden" name="id" value={defaults.id} />}
      {hasItemType && isUltimate && <input type="hidden" name="item_type" value="general" />}
      {hasItemType && !isUltimate && mode === 'add' && (
        <input type="hidden" name="item_type" value={selectedType || 'general'} />
      )}

      <div className="cat-desk-form-row">
        <label className="cat-desk-form-label" htmlFor={`cat-name-${mode}`}>
          Name <span className="req">*</span>
        </label>
        <input
          id={`cat-name-${mode}`}
          type="text"
          name="name"
          required
          className="cat-desk-input"
          defaultValue={defaults.name || ''}
          placeholder="Category name"
          autoFocus
        />
      </div>

      <div className="cat-desk-form-row">
        <label className="cat-desk-form-label" htmlFor={`cat-desc-${mode}`}>
          Description
        </label>
        <textarea
          id={`cat-desc-${mode}`}
          name="description"
          className="cat-desk-textarea"
          rows={2}
          defaultValue={defaults.description || ''}
          placeholder="Optional"
        />
      </div>

      {hasParent && (
        <div className="cat-desk-form-row">
          <label className="cat-desk-form-label" htmlFor={`cat-parent-${mode}`}>
            Parent
          </label>
          <select
            id={`cat-parent-${mode}`}
            name="parent_id"
            className="cat-desk-select"
            defaultValue={defaults.parent_id != null ? String(defaults.parent_id) : ''}
          >
            <option value="">None</option>
            {categories
              .filter((c) => mode !== 'edit' || c.id !== defaults.id)
              .map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
          </select>
        </div>
      )}

      {(hasOrder || hasLevel) && (
        <div className="cat-desk-form-row">
          <span className="cat-desk-form-label">Order</span>
          <div style={{ display: 'grid', gridTemplateColumns: hasOrder && hasLevel ? '1fr 1fr' : '1fr', gap: '0.5rem' }}>
            {hasOrder && (
              <input
                type="number"
                name="order_level"
                className="cat-desk-input"
                defaultValue={defaults.order_level ?? 0}
                placeholder="Order"
                aria-label="Order level"
              />
            )}
            {hasLevel && (
              <input
                type="number"
                name="level"
                className="cat-desk-input"
                defaultValue={defaults.level ?? 0}
                placeholder="Level"
                aria-label="Level"
              />
            )}
          </div>
        </div>
      )}

      {showType && mode === 'edit' && (
        <div className="cat-desk-form-row">
          <label className="cat-desk-form-label" htmlFor={`cat-type-${mode}`}>
            Type
          </label>
          <select
            id={`cat-type-${mode}`}
            name="item_type"
            className="cat-desk-select"
            defaultValue={defaults.item_type || 'general'}
          >
            <option value="general">General</option>
            <option value="vehicle">Truck Category</option>
            <option value="spare_part">Spare Part Category</option>
          </select>
        </div>
      )}

      {showType && mode === 'add' && (
        <div className="cat-desk-form-row">
          <span className="cat-desk-form-label">Type</span>
          <div className="prod-desk-muted" style={{ paddingTop: '0.65rem', fontSize: '0.875rem' }}>
            {typeLabel(selectedType)}
          </div>
        </div>
      )}

      {hasStatus && (
        <div className="cat-desk-form-row">
          <label className="cat-desk-form-label" htmlFor={`cat-status-${mode}`}>
            Status
          </label>
          <select
            id={`cat-status-${mode}`}
            name="status"
            className="cat-desk-select"
            defaultValue={defaults.status || 'active'}
          >
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      )}

      {showImages ? (
        <div className="cat-desk-images-block">
          <div className="cat-desk-images-heading">Image</div>
          <CategoryImageField
            id={`cat-cover-${mode}`}
            name="cover_image"
            oldFieldName="old_cover"
            label="Cover"
            hint="JPG, PNG, or WebP"
            variant="cover"
            existingUrl={defaults.cover_url || ''}
            existingFile={defaults.cover_image || ''}
          />
        </div>
      ) : null}

      <div className="prod-desk-modal-actions" style={{ marginTop: '1.15rem' }}>
        <button
          type="submit"
          className="prod-desk-modal-btn prod-desk-modal-btn--primary cat-desk-btn"
          disabled={saving}
          aria-busy={saving}
        >
          {saving ? (
            <>
              <HiOutlineArrowPath size={16} className="cat-desk-spinner" aria-hidden="true" />
              Saving...
            </>
          ) : mode === 'edit' ? (
            'Save changes'
          ) : (
            'Save category'
          )}
        </button>
      </div>
    </form>
  );
}

export default function CategoriesList({ data }) {
  const {
    categories: initialCategories = [],
    hasItemType = false,
    hasFeatured = false,
    hasStatus = true,
    isUltimate = false,
    formAction = 'categories.php',
  } = data;

  const [categories, setCategories] = useState(initialCategories);
  const [search, setSearch] = useState('');
  const [booting, setBooting] = useState(true);
  const [showAdd, setShowAdd] = useState(false);
  const [showTypeChoice, setShowTypeChoice] = useState(false);
  const [selectedType, setSelectedType] = useState('general');
  const [editItem, setEditItem] = useState(null);
  const [featuredBusy, setFeaturedBusy] = useState(null);

  useEffect(() => {
    const timer = window.setTimeout(() => setBooting(false), 220);
    return () => window.clearTimeout(timer);
  }, []);

  useEffect(() => {
    if (!showAdd && !showTypeChoice && !editItem) return undefined;
    const onKey = (e) => {
      if (e.key === 'Escape') {
        setShowAdd(false);
        setShowTypeChoice(false);
        setEditItem(null);
      }
    };
    document.addEventListener('keydown', onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prev;
    };
  }, [showAdd, showTypeChoice, editItem]);

  const filtered = useMemo(
    () => categories.filter((c) => categoryMatchesQuery(search, c)),
    [categories, search]
  );

  const stats = useMemo(() => {
    const total = categories.length;
    const active = categories.filter((c) => (c.status || 'active') === 'active').length;
    const featured = categories.filter((c) => c.is_featured).length;
    const withParent = categories.filter((c) => c.parent_id != null).length;
    return { total, active, featured, withParent };
  }, [categories]);

  const openAdd = () => {
    if (hasItemType && !isUltimate) {
      setShowTypeChoice(true);
      return;
    }
    setSelectedType('general');
    setShowAdd(true);
  };

  const confirmDelete = (id, name) => {
    const go = () => {
      window.location.href = `${formAction}?delete=${id}`;
    };
    if (window.StockAlert?.confirm) {
      window.StockAlert.confirm(`Delete “${name}”?`, 'Delete category', go);
      return;
    }
    if (window.Swal) {
      window.Swal.fire({
        title: 'Delete category?',
        text: name || '',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
      }).then((r) => {
        if (r.isConfirmed) go();
      });
      return;
    }
    if (window.confirm(`Delete “${name}”?`)) go();
  };

  const toggleFeatured = async (cat) => {
    if (!hasFeatured || featuredBusy === cat.id) return;
    const next = !cat.is_featured;
    setFeaturedBusy(cat.id);
    setCategories((prev) =>
      prev.map((c) => (c.id === cat.id ? { ...c, is_featured: next } : c))
    );
    try {
      const fd = new FormData();
      fd.append('action', 'toggle_featured');
      fd.append('id', String(cat.id));
      fd.append('featured', next ? '1' : '0');
      const res = await fetch(formAction, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
      });
      const json = await res.json().catch(() => ({ success: false }));
      if (!json.success) {
        setCategories((prev) =>
          prev.map((c) => (c.id === cat.id ? { ...c, is_featured: !next } : c))
        );
      }
    } catch {
      setCategories((prev) =>
        prev.map((c) => (c.id === cat.id ? { ...c, is_featured: !next } : c))
      );
    } finally {
      setFeaturedBusy(null);
    }
  };

  if (booting) {
    return (
      <div className="prod-desk-page" aria-busy="true">
        <div className="prod-desk-page-header">
          <div className="prod-desk-page-header-search">
            <div className="prod-desk-search-field">
              <span className="prod-desk-bone prod-desk-bone--name" style={{ height: 36, borderRadius: 9999 }} />
            </div>
          </div>
        </div>
        <section className="prod-desk-kpi-grid">
          {[0, 1, 2, 3].map((i) => (
            <div key={i} className="prod-desk-kpi-card">
              <span className="prod-desk-bone prod-desk-bone--thumb" />
              <span className="prod-desk-skeleton-kpi-text" style={{ flex: 1 }}>
                <span className="prod-desk-bone prod-desk-bone--code" />
                <span className="prod-desk-bone prod-desk-bone--name" />
              </span>
            </div>
          ))}
        </section>
      </div>
    );
  }

  return (
    <div className="prod-desk-page">
      <div className="prod-desk-page-header">
        <div className="prod-desk-page-header-search">
          <div className="prod-desk-search-field">
            <HiOutlineMagnifyingGlass className="prod-desk-search-icon" aria-hidden="true" />
            <input
              type="search"
              className="prod-desk-search-input"
              placeholder="Search categories…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              aria-label="Search categories"
            />
          </div>
        </div>
        <div className="prod-desk-page-header-actions">
          <button type="button" className="prod-desk-btn prod-desk-btn-primary cat-desk-btn" onClick={openAdd}>
            <HiOutlinePlus size={16} aria-hidden="true" />
            <span className="prod-desk-btn-label-desktop">Add category</span>
            <span className="prod-desk-btn-label-mobile">New</span>
          </button>
        </div>
      </div>

      <section className="prod-desk-kpi-grid" aria-label="Summary">
        <div className="prod-desk-kpi-card">
          <div className="prod-desk-kpi-icon prod-desk-kpi-icon--violet">
            <HiOutlineFolder aria-hidden="true" />
          </div>
          <div>
            <div className="prod-desk-kpi-label">total</div>
            <div className="prod-desk-kpi-value">{stats.total}</div>
          </div>
        </div>
        <div className="prod-desk-kpi-card">
          <div className="prod-desk-kpi-icon prod-desk-kpi-icon--teal">
            <HiOutlineCheckCircle aria-hidden="true" />
          </div>
          <div>
            <div className="prod-desk-kpi-label">active</div>
            <div className="prod-desk-kpi-value">{stats.active}</div>
          </div>
        </div>
        {hasFeatured ? (
          <div className="prod-desk-kpi-card">
            <div className="prod-desk-kpi-icon prod-desk-kpi-icon--amber">
              <HiOutlineStar aria-hidden="true" />
            </div>
            <div>
              <div className="prod-desk-kpi-label">featured</div>
              <div className="prod-desk-kpi-value">{stats.featured}</div>
            </div>
          </div>
        ) : (
          <div className="prod-desk-kpi-card">
            <div className="prod-desk-kpi-icon prod-desk-kpi-icon--amber">
              <HiOutlineFolder aria-hidden="true" />
            </div>
            <div>
              <div className="prod-desk-kpi-label">with parent</div>
              <div className="prod-desk-kpi-value">{stats.withParent}</div>
            </div>
          </div>
        )}
        <div className="prod-desk-kpi-card">
          <div className="prod-desk-kpi-icon prod-desk-kpi-icon--rose">
            <HiOutlineInbox aria-hidden="true" />
          </div>
          <div>
            <div className="prod-desk-kpi-label">showing</div>
            <div className="prod-desk-kpi-value">{filtered.length}</div>
            <div className="prod-desk-kpi-helper">matching search</div>
          </div>
        </div>
      </section>

      <section className="prod-desk-results">
        <div className="prod-desk-results-head">
          <span className="prod-desk-results-count">
            {filtered.length} {filtered.length === 1 ? 'category' : 'categories'}
          </span>
        </div>

        {filtered.length === 0 ? (
          <div className="prod-desk-empty">
            <HiOutlineInbox size={28} style={{ color: '#94a3b8' }} aria-hidden="true" />
            <p className="prod-desk-empty-title">No categories found</p>
            <p className="prod-desk-empty-sub">
              {categories.length === 0 ? 'Create your first category to get started.' : 'Try a different search.'}
            </p>
            {categories.length === 0 && (
              <button
                type="button"
                className="prod-desk-btn prod-desk-btn-primary cat-desk-btn"
                style={{ marginTop: '0.75rem' }}
                onClick={openAdd}
              >
                <HiOutlinePlus size={16} aria-hidden="true" /> Add category
              </button>
            )}
          </div>
        ) : (
          <div className="prod-desk-table-wrap">
            <table className="prod-desk-table">
              <thead>
                <tr>
                  <th>Category</th>
                  {hasItemType && !isUltimate && <th>Type</th>}
                  <th>Parent</th>
                  {hasStatus && <th>Status</th>}
                  {hasFeatured && <th style={{ textAlign: 'center' }}>Featured</th>}
                  <th style={{ textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((cat) => {
                  const isActive = (cat.status || 'active') === 'active';
                  const thumb = cat.cover_url || cat.icon_url || cat.banner_url || '';
                  return (
                    <tr key={cat.id}>
                      <td>
                        <div className="prod-desk-product">
                          <ProductThumb src={thumb} className="cat-desk-thumb" size={16} />
                          <div style={{ minWidth: 0 }}>
                            <button
                              type="button"
                              className="prod-desk-name"
                              style={{
                                background: 'none',
                                border: 'none',
                                padding: 0,
                                cursor: 'pointer',
                                textAlign: 'left',
                                font: 'inherit',
                              }}
                              onClick={() => setEditItem(cat)}
                            >
                              {cat.name}
                            </button>
                            <div className="prod-desk-code">
                              {cat.description
                                ? cat.description.length > 72
                                  ? `${cat.description.slice(0, 72)}…`
                                  : cat.description
                                : 'No description'}
                            </div>
                          </div>
                        </div>
                      </td>
                      {hasItemType && !isUltimate && (
                        <td>
                          <span className={`prod-desk-type ${typeClass(cat.item_type)}`}>
                            {typeLabel(cat.item_type)}
                          </span>
                        </td>
                      )}
                      <td>
                        <span className="prod-desk-muted">{cat.parent_name || '—'}</span>
                      </td>
                      {hasStatus && (
                        <td>
                          <span
                            className={`cat-desk-status ${
                              isActive ? 'cat-desk-status--active' : 'cat-desk-status--inactive'
                            }`}
                          >
                            {isActive ? 'Active' : 'Inactive'}
                          </span>
                        </td>
                      )}
                      {hasFeatured && (
                        <td style={{ textAlign: 'center' }}>
                          <button
                            type="button"
                            className={`cat-desk-featured${cat.is_featured ? ' is-on' : ''}`}
                            title={cat.is_featured ? 'Unfeature' : 'Feature'}
                            disabled={featuredBusy === cat.id}
                            onClick={() => toggleFeatured(cat)}
                          >
                            {cat.is_featured ? <HiStar size={18} /> : <HiOutlineStar size={18} />}
                          </button>
                        </td>
                      )}
                      <td style={{ textAlign: 'right' }}>
                        <div className="prod-desk-actions">
                          <button
                            type="button"
                            className="prod-desk-icon-btn prod-desk-icon-btn--edit"
                            title="Edit"
                            onClick={() => setEditItem(cat)}
                          >
                            <HiOutlinePencilSquare size={16} aria-hidden="true" />
                          </button>
                          <button
                            type="button"
                            className="prod-desk-icon-btn prod-desk-icon-btn--del"
                            title="Delete"
                            onClick={() => confirmDelete(cat.id, cat.name)}
                          >
                            <HiOutlineTrash size={16} aria-hidden="true" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {showTypeChoice && (
        <div
          className="prod-desk-modal-backdrop"
          role="presentation"
          onClick={() => setShowTypeChoice(false)}
        >
          <div
            className="prod-desk-modal cat-desk-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="cat-type-title"
            onClick={(e) => e.stopPropagation()}
          >
            <button
              type="button"
              className="prod-desk-modal-close"
              onClick={() => setShowTypeChoice(false)}
              aria-label="Close"
            >
              <HiOutlineXMark size={18} />
            </button>
            <h2 id="cat-type-title" className="cat-desk-modal-title">
              What type of category?
            </h2>
            <div className="cat-desk-type-grid">
              <button
                type="button"
                className="cat-desk-type-card"
                onClick={() => {
                  setSelectedType('vehicle');
                  setShowTypeChoice(false);
                  setShowAdd(true);
                }}
              >
                <span className="cat-desk-type-card-icon cat-desk-type-card-icon--truck">
                  <HiOutlineTruck size={22} aria-hidden="true" />
                </span>
                <span className="cat-desk-type-card-title">Truck</span>
                <span className="cat-desk-type-card-sub">Heavy vehicles and trucks</span>
              </button>
              <button
                type="button"
                className="cat-desk-type-card"
                onClick={() => {
                  setSelectedType('spare_part');
                  setShowTypeChoice(false);
                  setShowAdd(true);
                }}
              >
                <span className="cat-desk-type-card-icon cat-desk-type-card-icon--spare">
                  <HiOutlineCog6Tooth size={22} aria-hidden="true" />
                </span>
                <span className="cat-desk-type-card-title">Spare part</span>
                <span className="cat-desk-type-card-sub">Parts and components</span>
              </button>
            </div>
            <div className="prod-desk-modal-actions" style={{ marginTop: '1rem' }}>
              <button
                type="button"
                className="prod-desk-modal-btn prod-desk-modal-btn--secondary cat-desk-btn"
                onClick={() => {
                  setSelectedType('general');
                  setShowTypeChoice(false);
                  setShowAdd(true);
                }}
              >
                General instead
              </button>
            </div>
          </div>
        </div>
      )}

      {showAdd && (
        <div
          className="prod-desk-modal-backdrop"
          role="presentation"
          onClick={() => setShowAdd(false)}
        >
          <div
            className="prod-desk-modal cat-desk-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="cat-add-title"
            onClick={(e) => e.stopPropagation()}
          >
            <button
              type="button"
              className="prod-desk-modal-close"
              onClick={() => setShowAdd(false)}
              aria-label="Close"
            >
              <HiOutlineXMark size={18} />
            </button>
            <h2 id="cat-add-title" className="cat-desk-modal-title">
              Add category
            </h2>
            <CategoryFormFields
              mode="add"
              item={null}
              data={data}
              selectedType={selectedType}
              formAction={formAction}
            />
          </div>
        </div>
      )}

      {editItem && (
        <div
          className="prod-desk-modal-backdrop"
          role="presentation"
          onClick={() => setEditItem(null)}
        >
          <div
            className="prod-desk-modal cat-desk-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="cat-edit-title"
            onClick={(e) => e.stopPropagation()}
          >
            <button
              type="button"
              className="prod-desk-modal-close"
              onClick={() => setEditItem(null)}
              aria-label="Close"
            >
              <HiOutlineXMark size={18} />
            </button>
            <h2 id="cat-edit-title" className="cat-desk-modal-title">
              Edit category
            </h2>
            <CategoryFormFields
              mode="edit"
              item={editItem}
              data={data}
              selectedType={editItem.item_type || 'general'}
              formAction={formAction}
            />
          </div>
        </div>
      )}
    </div>
  );
}
