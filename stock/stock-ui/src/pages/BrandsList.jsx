import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  HiOutlineInbox,
  HiOutlineMagnifyingGlass,
  HiOutlinePlus,
  HiOutlinePencilSquare,
  HiOutlineTrash,
  HiOutlineXMark,
  HiOutlinePhoto,
  HiOutlineArrowPath,
} from 'react-icons/hi2';
import ProductThumb from './ProductThumb';
import './products-desk.css';
import './brands-desk.css';

const IMAGE_ACCEPT = 'image/jpeg,image/png,image/webp,image/gif';

function typeLabel(type) {
  if (type === 'truck' || type === 'vehicle') return 'Truck';
  if (type === 'spare_part') return 'Spare Part';
  return 'General';
}

function typeClass(type) {
  if (type === 'truck' || type === 'vehicle') return 'brand-desk-type--truck';
  if (type === 'spare_part') return 'brand-desk-type--spare';
  return 'brand-desk-type--general';
}

function normalizeSearchQuery(q) {
  return String(q || '')
    .trim()
    .replace(/\s+/g, ' ');
}

function brandMatchesQuery(q, brand) {
  const tokens = normalizeSearchQuery(q)
    .toLowerCase()
    .split(' ')
    .filter(Boolean);
  if (tokens.length === 0) return true;
  const hay = [brand.name, brand.brand_type, typeLabel(brand.brand_type), brand.meta_title]
    .map((v) => String(v || '').toLowerCase())
    .join(' ');
  return tokens.every((t) => hay.includes(t));
}

function BrandLogoField({ id, existingUrl = '', existingFile = '' }) {
  const inputRef = useRef(null);
  const [file, setFile] = useState(null);
  const [dragging, setDragging] = useState(false);
  const [previewUrl, setPreviewUrl] = useState('');
  const [imgFailed, setImgFailed] = useState(false);

  useEffect(() => {
    if (!file) {
      setPreviewUrl('');
      return undefined;
    }
    const url = URL.createObjectURL(file);
    setPreviewUrl(url);
    return () => URL.revokeObjectURL(url);
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
      // ignore
    }
  };

  return (
    <div className="brand-desk-form-row">
      <span className="brand-desk-form-label">Logo</span>
      <div>
        <input type="hidden" name="old_logo" value={existingFile || ''} />
        <div
          className={`brand-desk-dropzone${dragging ? ' is-dragging' : ''}${showPreview ? ' has-preview' : ''}`}
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
            name="logo"
            accept={IMAGE_ACCEPT}
            className="brand-desk-dropzone-input"
            onChange={(e) => pick(e.target.files)}
          />
          {showPreview ? (
            <div className="brand-desk-dropzone-frame">
              <img
                src={displayUrl}
                alt=""
                className="brand-desk-dropzone-preview"
                onLoad={() => setImgFailed(false)}
                onError={() => setImgFailed(true)}
              />
            </div>
          ) : (
            <button type="button" className="brand-desk-dropzone-empty" onClick={() => inputRef.current?.click()}>
              <HiOutlinePhoto size={22} aria-hidden="true" />
              <span className="brand-desk-dropzone-title">
                {imgFailed ? 'Could not load logo � choose another' : 'Drop logo or click to browse'}
              </span>
              <span className="brand-desk-dropzone-hint">PNG or WebP recommended</span>
            </button>
          )}
        </div>
        {showPreview || file ? (
          <div className="brand-desk-image-actions">
            <button
              type="button"
              className="prod-desk-btn prod-desk-btn-secondary brand-desk-btn"
              onClick={() => inputRef.current?.click()}
            >
              Replace
            </button>
            {file ? (
              <button
                type="button"
                className="prod-desk-btn prod-desk-btn-secondary brand-desk-btn"
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

function BrandFormFields({ mode, item, data, formAction }) {
  const { hasBrandType = true, hasLogo = true, hasMeta = true, isUltimate = false } = data;
  const defaults = item || {};
  const [saving, setSaving] = useState(false);
  const showType = hasBrandType && !isUltimate;

  return (
    <form
      method="POST"
      action={formAction}
      encType="multipart/form-data"
      className="brand-desk-form"
      onSubmit={() => setSaving(true)}
    >
      <input type="hidden" name="action" value={mode} />
      {mode === 'edit' ? <input type="hidden" name="id" value={defaults.id} /> : null}
      {hasBrandType && isUltimate ? <input type="hidden" name="brand_type" value="general" /> : null}

      <div className="brand-desk-form-row">
        <label className="brand-desk-form-label" htmlFor={`brand-name-${mode}`}>
          Name <span className="req">*</span>
        </label>
        <input
          id={`brand-name-${mode}`}
          type="text"
          name="name"
          required
          className="brand-desk-input"
          defaultValue={defaults.name || ''}
          placeholder="Brand name"
          autoFocus
          disabled={saving}
        />
      </div>

      {showType ? (
        <div className="brand-desk-form-row">
          <label className="brand-desk-form-label" htmlFor={`brand-type-${mode}`}>
            Type
          </label>
          <select
            id={`brand-type-${mode}`}
            name="brand_type"
            className="brand-desk-select"
            defaultValue={
              defaults.brand_type === 'vehicle' ? 'truck' : defaults.brand_type || 'spare_part'
            }
            disabled={saving}
          >
            <option value="spare_part">Spare Part</option>
            <option value="truck">Truck</option>
            <option value="general">General</option>
          </select>
        </div>
      ) : null}

      {hasLogo ? (
        <BrandLogoField
          id={`brand-logo-${mode}`}
          existingUrl={defaults.logo_url || ''}
          existingFile={defaults.logo || ''}
        />
      ) : null}

      {hasMeta ? (
        <>
          <div className="brand-desk-form-row">
            <label className="brand-desk-form-label" htmlFor={`brand-meta-title-${mode}`}>
              Meta title
            </label>
            <input
              id={`brand-meta-title-${mode}`}
              type="text"
              name="meta_title"
              className="brand-desk-input"
              defaultValue={defaults.meta_title || ''}
              placeholder="Optional"
              disabled={saving}
            />
          </div>
          <div className="brand-desk-form-row">
            <label className="brand-desk-form-label" htmlFor={`brand-meta-desc-${mode}`}>
              Meta description
            </label>
            <textarea
              id={`brand-meta-desc-${mode}`}
              name="meta_description"
              className="brand-desk-textarea"
              rows={3}
              defaultValue={defaults.meta_description || ''}
              placeholder="Optional"
              disabled={saving}
            />
          </div>
        </>
      ) : null}

      <div className="prod-desk-modal-actions" style={{ marginTop: '1.15rem' }}>
        <button
          type="submit"
          className="prod-desk-modal-btn prod-desk-modal-btn--primary brand-desk-btn"
          disabled={saving}
          aria-busy={saving}
        >
          {saving ? (
            <>
              <HiOutlineArrowPath size={16} className="brand-desk-spinner" aria-hidden="true" />
              Saving...
            </>
          ) : mode === 'edit' ? (
            'Save changes'
          ) : (
            'Save brand'
          )}
        </button>
      </div>
    </form>
  );
}

export default function BrandsList({ data }) {
  const {
    brands: initialBrands = [],
    hasBrandType = true,
    isUltimate = false,
    formAction = 'index.php',
    deleteUrl = 'delete.php',
    toast = '',
  } = data;
  const showTypeColumn = hasBrandType && !isUltimate;

  const [brands] = useState(initialBrands);
  const [search, setSearch] = useState('');
  const [booting, setBooting] = useState(true);
  const [showAdd, setShowAdd] = useState(false);
  const [editItem, setEditItem] = useState(null);

  useEffect(() => {
    const timer = window.setTimeout(() => setBooting(false), 220);
    return () => window.clearTimeout(timer);
  }, []);

  useEffect(() => {
    if (!toast || !window.Swal) return;
    const map = {
      deleted: { title: 'Deleted', text: 'The brand has been removed.' },
      updated: { title: 'Updated', text: 'Brand information saved.' },
    };
    const msg = map[toast];
    if (!msg) return;
    window.Swal.fire({
      toast: true,
      position: 'top-end',
      icon: 'success',
      title: msg.title,
      text: msg.text,
      showConfirmButton: false,
      timer: 3200,
      timerProgressBar: true,
    });
  }, [toast]);

  useEffect(() => {
    if (!showAdd && !editItem) return undefined;
    const onKey = (e) => {
      if (e.key === 'Escape') {
        setShowAdd(false);
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
  }, [showAdd, editItem]);

  const filtered = useMemo(() => brands.filter((b) => brandMatchesQuery(search, b)), [brands, search]);

  const confirmDelete = (id, name) => {
    const go = () => {
      window.location.href = `${deleteUrl}?id=${id}`;
    };
    if (window.Swal) {
      window.Swal.fire({
        title: 'Delete brand?',
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
    if (window.confirm(`Delete �${name}�?`)) go();
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
              placeholder="Search brands..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              aria-label="Search brands"
            />
          </div>
        </div>
        <div className="prod-desk-page-header-actions">
          <button
            type="button"
            className="prod-desk-btn prod-desk-btn-primary brand-desk-btn"
            onClick={() => setShowAdd(true)}
          >
            <HiOutlinePlus size={16} aria-hidden="true" />
            <span className="prod-desk-btn-label-desktop">Add brand</span>
            <span className="prod-desk-btn-label-mobile">New</span>
          </button>
        </div>
      </div>

      <section className="prod-desk-results">
        <div className="prod-desk-results-head">
          <span className="prod-desk-results-count">
            {filtered.length} {filtered.length === 1 ? 'brand' : 'brands'}
          </span>
        </div>

        {filtered.length === 0 ? (
          <div className="prod-desk-empty">
            <HiOutlineInbox size={28} style={{ color: '#94a3b8' }} aria-hidden="true" />
            <p className="prod-desk-empty-title">No brands found</p>
            <p className="prod-desk-empty-sub">
              {brands.length === 0 ? 'Create your first brand to get started.' : 'Try a different search.'}
            </p>
            {brands.length === 0 ? (
              <button
                type="button"
                className="prod-desk-btn prod-desk-btn-primary brand-desk-btn"
                style={{ marginTop: '0.75rem' }}
                onClick={() => setShowAdd(true)}
              >
                <HiOutlinePlus size={16} aria-hidden="true" /> Add brand
              </button>
            ) : null}
          </div>
        ) : (
          <div className="prod-desk-table-wrap">
            <table className="prod-desk-table">
              <thead>
                <tr>
                  <th>Brand</th>
                  {showTypeColumn ? <th>Type</th> : null}
                  <th>Logo</th>
                  <th style={{ textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((brand) => (
                  <tr key={brand.id}>
                    <td>
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
                        onClick={() => setEditItem(brand)}
                      >
                        {brand.name}
                      </button>
                      {brand.meta_title ? (
                        <div className="prod-desk-code">{brand.meta_title}</div>
                      ) : null}
                    </td>
                    {showTypeColumn ? (
                      <td>
                        <span className={`brand-desk-type ${typeClass(brand.brand_type)}`}>
                          {typeLabel(brand.brand_type)}
                        </span>
                      </td>
                    ) : null}
                    <td>
                      <ProductThumb src={brand.logo_url || ''} className="brand-desk-logo" size={14} />
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      <div className="prod-desk-actions">
                        <button
                          type="button"
                          className="prod-desk-icon-btn prod-desk-icon-btn--edit"
                          title="Edit"
                          onClick={() => setEditItem(brand)}
                        >
                          <HiOutlinePencilSquare size={16} aria-hidden="true" />
                        </button>
                        <button
                          type="button"
                          className="prod-desk-icon-btn prod-desk-icon-btn--del"
                          title="Delete"
                          onClick={() => confirmDelete(brand.id, brand.name)}
                        >
                          <HiOutlineTrash size={16} aria-hidden="true" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {showAdd ? (
        <div className="prod-desk-modal-backdrop" role="presentation" onClick={() => setShowAdd(false)}>
          <div
            className="prod-desk-modal brand-desk-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="brand-add-title"
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
            <h2 id="brand-add-title" className="brand-desk-modal-title">
              Add brand
            </h2>
            <BrandFormFields mode="add" item={null} data={data} formAction={formAction} />
          </div>
        </div>
      ) : null}

      {editItem ? (
        <div className="prod-desk-modal-backdrop" role="presentation" onClick={() => setEditItem(null)}>
          <div
            className="prod-desk-modal brand-desk-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="brand-edit-title"
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
            <h2 id="brand-edit-title" className="brand-desk-modal-title">
              Edit brand
            </h2>
            <BrandFormFields mode="edit" item={editItem} data={data} formAction={formAction} />
          </div>
        </div>
      ) : null}
    </div>
  );
}
