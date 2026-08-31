import React, { useState } from 'react';
import {
  HiOutlineArrowLeft,
  HiOutlinePencilSquare,
  HiOutlineDocumentDuplicate,
  HiOutlineTrash,
  HiOutlinePhoto,
  HiOutlineArrowDownTray,
  HiOutlineArrowUpTray,
  HiOutlineArrowsRightLeft,
  HiOutlineCheck,
  HiOutlineQrCode,
  HiOutlineHashtag,
  HiOutlineArchiveBox,
  HiOutlineMapPin,
  HiOutlineBellAlert,
  HiOutlineCurrencyDollar,
  HiOutlineShoppingCart,
  HiOutlineTag,
} from 'react-icons/hi2';
import './product-view.css';

function formatMoney(n) {
  return Number(n || 0).toLocaleString('en', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

function formatDate(value) {
  if (!value) return '—';
  const d = new Date(value.includes('T') ? value : value.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleString();
}

function MovementType({ type }) {
  if (type === 'in') {
    return (
      <span className="pv-type-in">
        <HiOutlineArrowDownTray size={14} style={{ display: 'inline', verticalAlign: '-2px', marginRight: 4 }} />
        IN
      </span>
    );
  }
  if (type === 'out') {
    return (
      <span className="pv-type-out">
        <HiOutlineArrowUpTray size={14} style={{ display: 'inline', verticalAlign: '-2px', marginRight: 4 }} />
        OUT
      </span>
    );
  }
  return (
    <span className="pv-type-adj">
      <HiOutlineArrowsRightLeft size={14} style={{ display: 'inline', verticalAlign: '-2px', marginRight: 4 }} />
      ADJ
    </span>
  );
}

function downloadImage(url, filename) {
  if (!url) return Promise.reject(new Error('no url'));

  const trigger = (href, revoke) => {
    const a = document.createElement('a');
    a.href = href;
    a.download = filename;
    a.target = '_blank';
    a.rel = 'noopener';
    document.body.appendChild(a);
    a.click();
    a.remove();
    if (revoke) URL.revokeObjectURL(href);
  };

  return fetch(url, { credentials: 'same-origin' })
    .then((res) => {
      if (!res.ok) throw new Error('fetch failed');
      return res.blob();
    })
    .then((blob) => {
      trigger(URL.createObjectURL(blob), true);
    })
    .catch(() => {
      trigger(url, false);
    });
}

function guessExt(name, url) {
  const fromName = (name || '').match(/\.(jpe?g|png|gif|webp|bmp)$/i);
  if (fromName) return fromName[0].toLowerCase();
  const fromUrl = (url || '').match(/\.(jpe?g|png|gif|webp|bmp)(?:\?|$)/i);
  if (fromUrl) return `.${fromUrl[1].toLowerCase()}`;
  return '.jpg';
}

function MainImage({ src }) {
  const [loaded, setLoaded] = useState(false);
  const [failed, setFailed] = useState(false);

  if (!src || failed) {
    return (
      <div className="pv-empty-media">
        <HiOutlinePhoto size={36} style={{ margin: '0 auto 0.5rem', display: 'block' }} />
        No image
      </div>
    );
  }

  return (
    <div className={`pv-main-image-wrap${loaded ? '' : ' is-loading'}`} aria-busy={!loaded}>
      {!loaded && <span className="pv-img-skeleton" aria-hidden="true" />}
      <img
        src={src}
        alt=""
        className={loaded ? 'is-visible' : ''}
        onLoad={() => setLoaded(true)}
        onError={() => setFailed(true)}
      />
    </div>
  );
}

function InfoRow({ tone, icon: Icon, label, value, badge, mono }) {
  if (value == null || value === '') return null;
  return (
    <div className="pv-info">
      <span className={`pv-info-icon pv-info-icon--${tone}`} aria-hidden="true">
        <Icon size={18} />
      </span>
      <div className="pv-info-body">
        <span className="pv-info-label">{label}</span>
        <span className={`pv-info-value${mono ? ' is-mono' : ''}`}>
          {value}
          {badge ? <span className="pv-info-badge">{badge}</span> : null}
        </span>
      </div>
    </div>
  );
}

export default function ProductView({ data }) {
  const {
    product,
    images = [],
    movements = [],
    showCost = false,
    listUrl = 'index.php',
    editUrl = '',
    duplicateUrl = '',
  } = data;

  const [activeImageIndex, setActiveImageIndex] = useState(0);
  const [downloadState, setDownloadState] = useState('idle'); // idle | loading | done

  const activeImage = images[activeImageIndex] || images[0] || null;
  const mainSrc = activeImage?.large_url || activeImage?.medium_url || activeImage?.thumbnail_url || '';

  const handleDownloadImage = () => {
    if (!mainSrc || downloadState === 'loading') return;
    const code = (product?.product_code || `product-${product?.id || 'image'}`)
      .replace(/[^\w.-]+/g, '_');
    const baseName = (activeImage?.image_name || '').replace(/\.[^.]+$/, '') || code;
    const ext = guessExt(activeImage?.image_name, mainSrc);
    setDownloadState('loading');
    downloadImage(mainSrc, `${code}_${baseName}${ext}`).finally(() => {
      setDownloadState('done');
      window.setTimeout(() => setDownloadState('idle'), 1400);
    });
  };

  if (!product) {
    return (
      <div className="pv-page">
        <p className="pv-muted">Product not found.</p>
        <a href={listUrl} className="pv-btn pv-btn-secondary">
          <HiOutlineArrowLeft size={16} /> Back to products
        </a>
      </div>
    );
  }

  const currency = product.currency || 'USD';
  const stockTone = product.is_out_of_stock || product.is_low_stock ? 'rose' : 'emerald';
  const stockBadge = product.is_out_of_stock ? 'Out' : product.is_low_stock ? 'Low' : null;

  const confirmDelete = () => {
    const url = `delete.php?id=${product.id}`;
    if (window.StockAlert) {
      window.StockAlert.confirm(
        'Delete this product? This action cannot be undone.',
        'Delete Product',
        () => {
          window.location.href = url;
        }
      );
    } else if (window.confirm('Delete this product?')) {
      window.location.href = url;
    }
  };

  return (
    <div className="pv-page">
      <div className="pv-toolbar">
        <a href={listUrl} className="pv-btn pv-btn-secondary">
          <HiOutlineArrowLeft size={16} /> Back
        </a>
        <div className="pv-toolbar-actions">
          <a href={editUrl || `edit.php?id=${product.id}`} className="pv-btn pv-btn-primary">
            <HiOutlinePencilSquare size={16} /> Edit
          </a>
          <a href={duplicateUrl || `duplicate.php?id=${product.id}`} className="pv-btn pv-btn-secondary">
            <HiOutlineDocumentDuplicate size={16} /> Duplicate
          </a>
          <button type="button" className="pv-btn pv-btn-danger" onClick={confirmDelete}>
            <HiOutlineTrash size={16} /> Delete
          </button>
        </div>
      </div>

      <section className="pv-hero">
        <div className="pv-hero-media">
          {images.length === 0 ? (
            <div className="pv-empty-media">
              <HiOutlinePhoto size={36} style={{ margin: '0 auto 0.5rem', display: 'block' }} />
              No image
            </div>
          ) : (
            <>
              <div className={`pv-media-stage${downloadState !== 'idle' ? ` is-${downloadState}` : ''}`}>
                <MainImage key={mainSrc} src={mainSrc} />
                {downloadState === 'loading' ? (
                  <div className="pv-download-overlay" aria-hidden="true">
                    <span className="pv-download-ring" />
                  </div>
                ) : null}
                <button
                  type="button"
                  className={`pv-download-btn${downloadState !== 'idle' ? ` is-${downloadState}` : ''}`}
                  onClick={handleDownloadImage}
                  disabled={downloadState === 'loading'}
                  title="Download image"
                  aria-busy={downloadState === 'loading'}
                >
                  {downloadState === 'loading' ? (
                    <>
                      <span className="pv-download-spinner" aria-hidden="true" />
                      Saving…
                    </>
                  ) : downloadState === 'done' ? (
                    <>
                      <HiOutlineCheck size={16} className="pv-download-check" />
                      Saved
                    </>
                  ) : (
                    <>
                      <HiOutlineArrowDownTray size={16} className="pv-download-arrow" />
                      Download
                    </>
                  )}
                </button>
              </div>
              {images.length > 1 && (
                <div className="pv-thumbs">
                  {images.map((img, i) => (
                    <button
                      key={img.id || `${img.image_name}-${i}`}
                      type="button"
                      className={`pv-thumb-btn${i === activeImageIndex ? ' is-active' : ''}`}
                      onClick={() => setActiveImageIndex(i)}
                      title={`Image ${i + 1}`}
                    >
                      <img src={img.thumbnail_url || img.medium_url || ''} alt="" loading="lazy" />
                    </button>
                  ))}
                </div>
              )}
            </>
          )}
        </div>

        <div className="pv-hero-body">
          <h1 className="pv-title">{product.name}</h1>
          {product.description ? <p className="pv-desc">{product.description}</p> : null}

          <div className="pv-info-grid">
            <InfoRow
              tone="indigo"
              icon={HiOutlineQrCode}
              label="Code"
              value={product.product_code || '—'}
              mono
            />
            <InfoRow
              tone="violet"
              icon={HiOutlineHashtag}
              label="Part no"
              value={product.oem_number}
              mono
            />
            <InfoRow
              tone={stockTone}
              icon={HiOutlineArchiveBox}
              label="In stock"
              value={product.quantity ?? 0}
              badge={stockBadge}
            />
            <InfoRow
              tone="amber"
              icon={HiOutlineMapPin}
              label="Location"
              value={product.location || 'Not set'}
            />
            <InfoRow
              tone="orange"
              icon={HiOutlineBellAlert}
              label="Reorder"
              value={product.reorder_level ?? 0}
            />
            <InfoRow
              tone="emerald"
              icon={HiOutlineCurrencyDollar}
              label="Selling"
              value={`${formatMoney(product.unit_price)} ${currency}`}
            />
            {showCost ? (
              <InfoRow
                tone="blue"
                icon={HiOutlineShoppingCart}
                label="Buying"
                value={`${formatMoney(product.buying_price)} ${currency}`}
              />
            ) : null}
            {Number(product.wholesale_price || 0) > 0 ? (
              <InfoRow
                tone="teal"
                icon={HiOutlineTag}
                label="Wholesale"
                value={`${formatMoney(product.wholesale_price)} ${currency}`}
              />
            ) : null}
          </div>
        </div>
      </section>

      <section className="pv-card">
        <div className="pv-card-head">Recent movements</div>
        <div className="pv-table-wrap">
          <table className="pv-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Qty</th>
                <th>Ref</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody>
              {movements.length === 0 ? (
                <tr>
                  <td colSpan={5} className="pv-muted" style={{ textAlign: 'center', padding: '1.5rem' }}>
                    No movements recorded
                  </td>
                </tr>
              ) : (
                movements.map((mov) => (
                  <tr key={mov.id}>
                    <td>{formatDate(mov.created_at)}</td>
                    <td>
                      <MovementType type={mov.movement_type} />
                    </td>
                    <td style={{ fontWeight: 700 }}>{mov.quantity}</td>
                    <td>
                      {mov.reference_type || '—'}
                      {mov.reference_id != null && mov.reference_id !== '' ? ` #${mov.reference_id}` : ''}
                    </td>
                    <td className="pv-muted">{mov.notes || '—'}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}
