import React, { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  HiOutlineArrowPath,
  HiOutlineBarsArrowDown,
  HiOutlineCheckCircle,
  HiOutlineCloudArrowUp,
  HiOutlineDocument,
  HiOutlineEllipsisVertical,
  HiOutlineEye,
  HiOutlineInbox,
  HiOutlineLink,
  HiOutlineMagnifyingGlass,
  HiOutlineTrash,
  HiOutlineXMark,
} from 'react-icons/hi2';
import './products-desk.css';
import './uploads-desk.css';

function folderHref(folder) {
  const q = new URLSearchParams({ folder });
  return `index.php?${q.toString()}`;
}

function uploadsDeleteRel(path, folder) {
  if (!path) return path;
  if (folder === 'products' && path.indexOf('products/') === 0) return path;
  if (folder === 'categories' && path.indexOf('categories/') === 0) return path;
  if (folder === 'documents' && path.indexOf('documents/') === 0) return path;
  const prefix =
    folder === 'products'
      ? 'products/'
      : folder === 'categories'
        ? 'categories/'
        : folder === 'documents'
          ? 'documents/'
          : '';
  return prefix ? `${prefix}${path}` : path;
}

function absoluteUrl(url) {
  if (!url) return '';
  if (/^https?:\/\//i.test(url)) return url;
  return `${window.location.origin}${url.startsWith('/') ? '' : '/'}${url}`;
}

function Bone({ className = '', style }) {
  return <span className={`uploads-desk-bone ${className}`.trim()} style={style} aria-hidden="true" />;
}

function UploadsGridSkeleton({ count = 12 }) {
  return (
    <div className="uploads-desk-grid uploads-desk-skeleton-grid" role="status" aria-live="polite" aria-busy="true">
      <span className="sr-only">Loading files...</span>
      {Array.from({ length: count }, (_, i) => (
        <div key={i} className="uploads-card uploads-card--skeleton" aria-hidden="true">
          <div className="uploads-card-preview uploads-card-preview--skeleton">
            <Bone className="uploads-desk-bone--preview" />
          </div>
          <div className="uploads-card-meta">
            <Bone className="uploads-desk-bone--name" />
            <Bone className="uploads-desk-bone--size" />
          </div>
        </div>
      ))}
    </div>
  );
}

function UploadsDeskSkeleton({ isSelect = false }) {
  return (
    <div className="uploads-desk uploads-desk-skeleton" role="status" aria-live="polite" aria-busy="true">
      <span className="sr-only">Loading files...</span>
      {!isSelect ? (
        <div className="uploads-desk-top">
          <div className="uploads-desk-top-lead">
            <div className="uploads-desk-tabs uploads-desk-tabs--skeleton" aria-hidden="true">
              <Bone className="uploads-desk-bone--tab" />
              <Bone className="uploads-desk-bone--tab" style={{ width: '5.5rem' }} />
            </div>
          </div>
          <Bone className="uploads-desk-bone--upload" />
        </div>
      ) : null}

      <div className={`uploads-desk-toolbar${isSelect ? ' uploads-desk-toolbar--select' : ''}`} aria-hidden="true">
        {!isSelect ? (
          <div className="uploads-desk-storage">
            <Bone className="uploads-desk-bone--label" style={{ width: '4.5rem', height: '0.7rem' }} />
            <Bone className="uploads-desk-bone--label" style={{ width: '3.5rem', height: '0.55rem', marginTop: 6 }} />
            <Bone className="uploads-desk-bone--bar" />
          </div>
        ) : (
          <div className="uploads-desk-toolbar-spacer" />
        )}
        <div className="uploads-desk-toolbar-search">
          <Bone className="uploads-desk-bone--search" />
        </div>
        <div className="uploads-desk-toolbar-actions">
          <Bone className="uploads-desk-bone--icon-btn" />
          <Bone className="uploads-desk-bone--icon-btn" />
        </div>
      </div>

      {!isSelect ? (
        <div className="uploads-desk-select-all" aria-hidden="true">
          <Bone className="uploads-desk-bone--check" />
          <Bone className="uploads-desk-bone--label" style={{ width: '4rem' }} />
        </div>
      ) : null}

      <UploadsGridSkeleton />
    </div>
  );
}

function toast(opts) {
  if (window.Swal) {
    window.Swal.fire({
      toast: true,
      position: 'top-end',
      showConfirmButton: false,
      timer: 2400,
      timerProgressBar: true,
      ...opts,
    });
    return;
  }
  // eslint-disable-next-line no-alert
  window.alert(opts.title || opts.text || '');
}

function confirmDialog(opts) {
  if (window.Swal) return window.Swal.fire(opts);
  // eslint-disable-next-line no-alert
  return Promise.resolve({ isConfirmed: window.confirm(opts.title || 'Confirm?') });
}

function pickDefaultDupIndex(files) {
  let best = 0;
  let bestSc = -1;
  let bestMt = -1;
  files.forEach((f, fi) => {
    const sc = Number(f.usage_score) || 0;
    const mt = Number(f.mtime) || 0;
    if (sc > bestSc || (sc === bestSc && mt > bestMt)) {
      bestSc = sc;
      bestMt = mt;
      best = fi;
    }
  });
  return best;
}

function maxDeleteScore(files, keepRel) {
  let m = 0;
  files.forEach((f) => {
    if (f.rel === keepRel) return;
    const s = Number(f.usage_score) || 0;
    if (s > m) m = s;
  });
  return m;
}

function FileCardMenu({ file, showUsage, onUsage, onDelete, onClose, anchorRect }) {
  const ref = useRef(null);
  const [pos, setPos] = useState({ top: 0, left: 0 });

  useLayoutEffect(() => {
    if (!anchorRect) return;
    const menuW = 184;
    const menuH = ref.current?.offsetHeight || 180;
    const pad = 8;
    const vw = window.innerWidth;
    const vh = window.innerHeight;

    // Prefer aligning to the right edge of the button (opens leftward),
    // but flip to the right when near the sidebar / left viewport edge.
    let left = anchorRect.right - menuW;
    if (left < pad) {
      left = Math.min(anchorRect.left, vw - menuW - pad);
    }
    if (left + menuW > vw - pad) {
      left = Math.max(pad, vw - menuW - pad);
    }

    let top = anchorRect.bottom + 6;
    if (top + menuH > vh - pad) {
      top = Math.max(pad, anchorRect.top - menuH - 6);
    }

    setPos({ top, left });
  }, [anchorRect]);

  useEffect(() => {
    const onDoc = (e) => {
      if (ref.current && !ref.current.contains(e.target)) onClose();
    };
    const onKey = (e) => {
      if (e.key === 'Escape') onClose();
    };
    const onScroll = () => onClose();
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', onClose);
    return () => {
      document.removeEventListener('mousedown', onDoc);
      document.removeEventListener('keydown', onKey);
      window.removeEventListener('scroll', onScroll, true);
      window.removeEventListener('resize', onClose);
    };
  }, [onClose]);

  const copyLink = async (e) => {
    e.stopPropagation();
    try {
      await navigator.clipboard.writeText(absoluteUrl(file.url));
      toast({ icon: 'success', title: 'Link copied!' });
    } catch {
      toast({ icon: 'error', title: 'Could not copy link' });
    }
    onClose();
  };

  return createPortal(
    <div
      className="uploads-menu-dropdown uploads-menu-dropdown--portal"
      ref={ref}
      role="menu"
      style={{ top: pos.top, left: pos.left }}
      onClick={(e) => e.stopPropagation()}
    >
      <a
        href={file.url}
        target="_blank"
        rel="noopener noreferrer"
        className="uploads-menu-item"
        onClick={(e) => e.stopPropagation()}
      >
        <HiOutlineEye size={14} aria-hidden="true" /> View
      </a>
      <a
        href={file.url}
        download={file.name}
        className="uploads-menu-item"
        onClick={(e) => e.stopPropagation()}
      >
        Download
      </a>
      <button type="button" className="uploads-menu-item" onClick={copyLink}>
        <HiOutlineLink size={14} aria-hidden="true" /> Copy link
      </button>
      {showUsage && file.is_image ? (
        <button
          type="button"
          className="uploads-menu-item"
          onClick={(e) => {
            e.stopPropagation();
            onUsage(file);
            onClose();
          }}
        >
          Where used & impact
        </button>
      ) : null}
      <div className="uploads-menu-divider" />
      <button
        type="button"
        className="uploads-menu-item uploads-menu-item--danger"
        onClick={(e) => {
          e.stopPropagation();
          onDelete(file);
          onClose();
        }}
      >
        <HiOutlineTrash size={14} aria-hidden="true" /> Delete
      </button>
    </div>,
    document.body
  );
}

function UsageModal({ data, onClose }) {
  if (!data) return null;
  const impact =
    data.impact === 'high' ? 'High impact' : data.impact === 'medium' ? 'Some DB references' : 'No DB references';
  const impactClass =
    data.impact === 'high'
      ? 'uploads-impact--high'
      : data.impact === 'medium'
        ? 'uploads-impact--medium'
        : 'uploads-impact--none';
  const pi = data.refs?.product_images || [];
  const mainP = data.refs?.main_image_products || [];
  const leg = data.refs?.legacy_image_products || [];

  return (
    <div className="prod-desk-modal-backdrop" role="presentation" onClick={onClose}>
      <div
        className="prod-desk-modal uploads-modal"
        role="dialog"
        aria-modal="true"
        onClick={(e) => e.stopPropagation()}
      >
        <button type="button" className="prod-desk-modal-close" onClick={onClose} aria-label="Close">
          <HiOutlineXMark size={18} />
        </button>
        <h2 className="uploads-modal-title">Usage & delete impact</h2>
        <p className="uploads-modal-meta">
          File: <code>{data.rel}</code>
        </p>
        <span className={`uploads-impact ${impactClass}`}>{impact}</span>
        <ul className="uploads-usage-lines">
          {(data.summary_lines || []).map((line) => (
            <li key={line}>{line}</li>
          ))}
        </ul>
        {pi.length ? (
          <div className="uploads-usage-block">
            <h3>product_images</h3>
            <div className="uploads-usage-table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Product</th>
                    <th>Code</th>
                    <th>Pri</th>
                  </tr>
                </thead>
                <tbody>
                  {pi.map((row) => (
                    <tr key={`${row.product_id}-${row.image_name}`}>
                      <td>{row.product_name}</td>
                      <td>{row.product_code}</td>
                      <td>{row.is_primary ? 'Y' : ''}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        ) : null}
        {mainP.length ? (
          <div className="uploads-usage-block">
            <h3>products.main_image</h3>
            <ul>
              {mainP.map((row) => (
                <li key={row.id}>
                  #{row.id} {row.name} ({row.product_code})
                </li>
              ))}
            </ul>
          </div>
        ) : null}
        {leg.length ? (
          <div className="uploads-usage-block">
            <h3>products.image (legacy)</h3>
            <ul>
              {leg.map((row) => (
                <li key={row.id}>
                  #{row.id} {row.name}
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </div>
    </div>
  );
}

function DuplicatesModal({ scan, productsBaseUrl, onClose, onConfirm }) {
  const [keepers, setKeepers] = useState(() => {
    const next = {};
    (scan.groups || []).forEach((g, i) => {
      next[`h${i}`] = g.files[pickDefaultDupIndex(g.files)]?.rel || '';
    });
    (scan.name_groups || []).forEach((g, i) => {
      next[`n${i}`] = g.files[pickDefaultDupIndex(g.files)]?.rel || '';
    });
    return next;
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const imgSrc = (rel) => {
    if (!rel || !productsBaseUrl) return '';
    const r = String(rel).replace(/^\/+/, '');
    return (
      productsBaseUrl +
      r
        .split('/')
        .map((seg) => encodeURIComponent(seg))
        .join('/')
    );
  };

  const submit = async () => {
    setError('');
    const payload = { groups: [] };
    let validationError = '';

    (scan.groups || []).forEach((g, i) => {
      if (validationError) return;
      const keep = keepers[`h${i}`];
      if (!keep) return;
      const keepScore = Number(g.files.find((f) => f.rel === keep)?.usage_score) || 0;
      if (keepScore < maxDeleteScore(g.files, keep)) {
        validationError = `Keeper must be at least as linked as every removed copy (SHA-1 ...${String(g.hash || '').slice(0, 8)}).`;
        return;
      }
      const del = g.files.map((f) => f.rel).filter((rel) => rel !== keep);
      if (del.length) payload.groups.push({ match: 'hash', hash: g.hash, keep, delete: del });
    });

    (scan.name_groups || []).forEach((g, i) => {
      if (validationError) return;
      const keep = keepers[`n${i}`];
      if (!keep) return;
      const keepScore = Number(g.files.find((f) => f.rel === keep)?.usage_score) || 0;
      if (keepScore < maxDeleteScore(g.files, keep)) {
        validationError = `Keeper must be at least as linked as every removed copy (${g.basename}).`;
        return;
      }
      const del = g.files.map((f) => f.rel).filter((rel) => rel !== keep);
      if (del.length) payload.groups.push({ match: 'name', keep, delete: del });
    });

    if (validationError) {
      setError(validationError);
      return;
    }
    if (!payload.groups.length) {
      setError('Nothing to delete (pick different keepers or cancel).');
      return;
    }
    setBusy(true);
    try {
      await onConfirm(payload);
    } finally {
      setBusy(false);
    }
  };

  const renderGroup = (g, key, kind) => (
    <div key={key} className={`uploads-dup-group${kind === 'name' ? ' uploads-dup-group--name' : ''}`}>
      <p className="uploads-dup-group-title">
        {kind === 'hash'
          ? `${g.count} identical copies - ${((g.size || 0) / 1024).toFixed(1)} KB`
          : `${g.count} paths - ${g.basename}`}
      </p>
      {kind === 'hash' ? <p className="uploads-dup-hash">{String(g.hash || '').slice(0, 12)}...</p> : null}
      {g.files.map((f) => {
        const sc = Number(f.usage_score) || 0;
        const src = imgSrc(f.rel);
        return (
          <label key={f.rel} className="uploads-dup-row" title={(f.usage_hints || []).join(', ')}>
            <input
              type="radio"
              name={key}
              checked={keepers[key] === f.rel}
              onChange={() => setKeepers((prev) => ({ ...prev, [key]: f.rel }))}
            />
            {src ? (
              <img src={src} alt="" className="uploads-dup-thumb" loading="lazy" width={56} height={56} />
            ) : (
              <span className="uploads-dup-thumb uploads-dup-thumb--empty">?</span>
            )}
            <span className="uploads-dup-rel">
              {f.rel}
              {kind === 'name' ? (
                <span className="uploads-dup-size"> ({((f.size || 0) / 1024).toFixed(1)} KB)</span>
              ) : null}
              {sc > 0 ? <span className="uploads-dup-link">link {sc}</span> : null}
            </span>
          </label>
        );
      })}
    </div>
  );

  return (
    <div className="prod-desk-modal-backdrop" role="presentation" onClick={onClose}>
      <div
        className="prod-desk-modal uploads-modal uploads-modal--wide"
        role="dialog"
        aria-modal="true"
        onClick={(e) => e.stopPropagation()}
      >
        <button type="button" className="prod-desk-modal-close" onClick={onClose} aria-label="Close">
          <HiOutlineXMark size={18} />
        </button>
        <h2 className="uploads-modal-title">
          Duplicates: {(scan.groups || []).length} by content, {(scan.name_groups || []).length} by name
        </h2>
        {scan.capped ? (
          <p className="uploads-dup-cap">Scan capped at {scan.scanned} files ... run again after cleanup if needed.</p>
        ) : null}
        <p className="uploads-dup-lead">
          Default keeper picks the copy with the strongest catalog link. You cannot delete a copy that is more linked
          than the keeper.
        </p>
        <div className="uploads-dup-scroll">
          {(scan.groups || []).length ? (
            <section>
              <h3 className="uploads-dup-section">A) Same file content (SHA-1)</h3>
              {(scan.groups || []).map((g, i) => renderGroup(g, `h${i}`, 'hash'))}
            </section>
          ) : null}
          {(scan.name_groups || []).length ? (
            <section>
              <h3 className="uploads-dup-section">B) Same filename (different folders)</h3>
              <p className="uploads-dup-warn">
                These share the same name but may be different images. Keep the catalog-linked copy.
              </p>
              {(scan.name_groups || []).map((g, i) => renderGroup(g, `n${i}`, 'name'))}
            </section>
          ) : null}
        </div>
        {error ? <div className="uploads-dup-error">{error}</div> : null}
        <div className="prod-desk-modal-actions" style={{ marginTop: '1rem' }}>
          <button type="button" className="prod-desk-modal-btn prod-desk-modal-btn--secondary" onClick={onClose}>
            Cancel
          </button>
          <button
            type="button"
            className="prod-desk-modal-btn prod-desk-modal-btn--primary"
            onClick={submit}
            disabled={busy}
          >
            {busy ? (
              <>
                <HiOutlineArrowPath size={16} className="uploads-spin" aria-hidden="true" /> Deleting...
              </>
            ) : (
              'Delete unselected copies'
            )}
          </button>
        </div>
      </div>
    </div>
  );
}

export default function UploadsList({ data }) {
  const {
    mode = 'view',
    folder = 'uploads',
    imagesOnly = false,
    files: initialFiles = [],
    usedSpaceLabel = '0 B',
    usedPercent = 0,
    showProductsImageTools = false,
    loadDocumentsAsync = false,
    productsBaseUrl = '',
    urls = {},
  } = data;

  const isSelect = mode === 'select';
  const [files, setFiles] = useState(initialFiles);
  const [docsLoading, setDocsLoading] = useState(Boolean(loadDocumentsAsync));
  const [docsError, setDocsError] = useState('');
  const [search, setSearch] = useState('');
  const [sort, setSort] = useState('newest');
  const [sortMenuOpen, setSortMenuOpen] = useState(false);
  const [selected, setSelected] = useState(() => new Set());
  const [openMenu, setOpenMenu] = useState(null); // { key, rect } | null
  const [usageData, setUsageData] = useState(null);
  const [dupScan, setDupScan] = useState(null);
  const [scanningDup, setScanningDup] = useState(false);
  const [booting, setBooting] = useState(true);

  useEffect(() => {
    const t = window.setTimeout(() => setBooting(false), 180);
    return () => window.clearTimeout(t);
  }, []);

  useEffect(() => {
    if (!loadDocumentsAsync) return undefined;
    let cancelled = false;
    (async () => {
      setDocsLoading(true);
      setDocsError('');
      try {
        const res = await fetch(urls.documents || 'ajax_documents.php', { credentials: 'same-origin' });
        const json = await res.json();
        if (cancelled) return;
        if (!res.ok || !json.ok) {
          throw new Error(json.error || 'Failed to load documents.');
        }
        setFiles(Array.isArray(json.files) ? json.files : []);
      } catch (err) {
        if (!cancelled) {
          setDocsError(err.message || 'Failed to load documents.');
        }
      } finally {
        if (!cancelled) setDocsLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [loadDocumentsAsync, urls.documents]);

  useEffect(() => {
    if (!sortMenuOpen) return undefined;
    const onDoc = () => setSortMenuOpen(false);
    const timer = window.setTimeout(() => {
      document.addEventListener('click', onDoc);
    }, 0);
    return () => {
      window.clearTimeout(timer);
      document.removeEventListener('click', onDoc);
    };
  }, [sortMenuOpen]);

  const sorted = useMemo(() => {
    const list = [...files];
    if (sort === 'name') list.sort((a, b) => String(a.name).localeCompare(String(b.name)));
    else if (sort === 'oldest') list.sort((a, b) => (a.mtime || 0) - (b.mtime || 0));
    else list.sort((a, b) => (b.mtime || 0) - (a.mtime || 0));
    return list;
  }, [files, sort]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return sorted;
    return sorted.filter((f) => String(f.name || '').toLowerCase().includes(q));
  }, [sorted, search]);

  const allVisibleSelected =
    filtered.length > 0 && filtered.every((f) => selected.has(`${f.source}|${f.rel}`));

  const toggleSelect = (file) => {
    const key = `${file.source}|${file.rel}`;
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  };

  const toggleSelectAll = () => {
    if (allVisibleSelected) {
      setSelected((prev) => {
        const next = new Set(prev);
        filtered.forEach((f) => next.delete(`${f.source}|${f.rel}`));
        return next;
      });
      return;
    }
    setSelected((prev) => {
      const next = new Set(prev);
      filtered.forEach((f) => next.add(`${f.source}|${f.rel}`));
      return next;
    });
  };

  const selectForParent = (file) => {
    window.parent.postMessage(
      {
        type: 'fileSelected',
        name: file.name,
        rel: file.rel,
        folder,
      },
      '*'
    );
  };

  const removeFilesLocal = (rels) => {
    const set = new Set(rels);
    setFiles((prev) => prev.filter((f) => !set.has(f.rel)));
    setSelected((prev) => {
      const next = new Set();
      prev.forEach((k) => {
        const rel = k.split('|').slice(1).join('|');
        if (!set.has(rel)) next.add(k);
      });
      return next;
    });
  };

  const deleteFiles = async (items) => {
    if (!items.length) {
      toast({ icon: 'error', title: 'Please select files to delete.' });
      return;
    }
    const result = await confirmDialog({
      title: items.length === 1 ? 'Move to recycle bin?' : `Move ${items.length} files to recycle bin?`,
      text: 'Files are stored in the upload recycle bin until you restore or delete them permanently.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#d33',
      confirmButtonText: 'Yes, move to bin',
    });
    if (!result.isConfirmed) return;

    try {
      const res = await fetch(urls.delete || 'ajax_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          paths: items.map((it) => ({
            rel: uploadsDeleteRel(it.rel, folder),
            source: it.source || 'tenant',
          })),
          folder,
        }),
      });
      const json = await res.json();
      if (!json.success) {
        const err = (json.errors && json.errors[0]) || json.message || 'Could not delete file.';
        if (window.Swal) window.Swal.fire('Error', err, 'error');
        else toast({ icon: 'error', title: err });
        return;
      }
      removeFilesLocal(items.map((it) => it.rel));
      if (window.Swal) {
        window.Swal.fire(
          'Moved to recycle bin',
          `${json.deletedCount || items.length} file(s). Restore from Recycle bin if needed.`,
          'success'
        );
      } else {
        toast({ icon: 'success', title: 'Moved to recycle bin' });
      }
    } catch {
      if (window.Swal) window.Swal.fire('Error', 'Server error.', 'error');
      else toast({ icon: 'error', title: 'Server error.' });
    }
  };

  const openUsage = async (file) => {
    if (window.Swal) {
      window.Swal.fire({ title: 'Checking...', allowOutsideClick: false, didOpen: () => window.Swal.showLoading() });
    }
    try {
      const res = await fetch(urls.imageUsage || 'ajax_image_usage.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ rel: file.rel }),
      });
      const json = await res.json();
      if (window.Swal) window.Swal.close();
      if (!json.ok) {
        if (window.Swal) window.Swal.fire('Error', json.message || 'Request failed', 'error');
        return;
      }
      setUsageData(json);
    } catch {
      if (window.Swal) window.Swal.fire('Error', 'Network error.', 'error');
    }
  };

  const scanDuplicates = async () => {
    setScanningDup(true);
    if (window.Swal) {
      window.Swal.fire({
        title: 'Scanning files...',
        text: 'Hashing files and grouping by name (may take a minute).',
        allowOutsideClick: false,
        didOpen: () => window.Swal.showLoading(),
      });
    }
    try {
      const res = await fetch(urls.duplicateScan || 'ajax_duplicate_scan.php', {
        method: 'GET',
        credentials: 'same-origin',
      });
      const json = await res.json();
      if (window.Swal) window.Swal.close();
      if (!json.ok) {
        if (window.Swal) window.Swal.fire('Error', json.message || 'Scan failed', 'error');
        return;
      }
      const nHash = (json.groups || []).length;
      const nName = (json.name_groups || []).length;
      if (!nHash && !nName) {
        if (window.Swal) {
          window.Swal.fire(
            'No duplicates',
            'No identical content (SHA-1) and no extra copies of the same filename were found under product uploads.',
            'info'
          );
        }
        return;
      }
      setDupScan(json);
    } catch {
      if (window.Swal) window.Swal.fire('Error', 'Network error.', 'error');
    } finally {
      setScanningDup(false);
    }
  };

  const commitDuplicates = async (payload) => {
    const res = await fetch(urls.deleteDuplicates || 'ajax_delete_duplicates.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });
    const out = await res.json();
    setDupScan(null);
    const msg = `Moved ${out.deleted || 0} file(s) to the recycle bin.`;
    if (window.Swal) {
      await window.Swal.fire({
        title: out.ok ? 'Done' : 'Partial',
        html: out.errors?.length ? `${msg}<br><span class="text-red-600 text-xs">${out.errors.slice(0, 5).join(' | ')}</span>` : msg,
        icon: out.ok ? 'success' : 'warning',
      });
    }
    window.location.reload();
  };

  if (booting) {
    return <UploadsDeskSkeleton isSelect={isSelect} />;
  }

  return (
    <div className={`uploads-desk${isSelect ? ' uploads-desk--select' : ''}`}>
      {!isSelect ? (
        <div className="uploads-desk-top">
          <div className="uploads-desk-top-lead">
            <nav className="uploads-desk-tabs" aria-label="Upload folders">
              <a
                href={folderHref('uploads')}
                className={`uploads-desk-tab${folder !== 'documents' ? ' is-active' : ''}`}
              >
                All files
              </a>
              <a
                href={folderHref('documents')}
                className={`uploads-desk-tab${folder === 'documents' ? ' is-active' : ''}`}
              >
                Documents
              </a>
            </nav>
          </div>
          <a href={urls.upload || 'upload.php'} className="prod-desk-btn prod-desk-btn-primary uploads-desk-upload-btn">
            <HiOutlineCloudArrowUp size={16} aria-hidden="true" /> Upload
          </a>
        </div>
      ) : null}

      <div className={`uploads-desk-toolbar${isSelect ? ' uploads-desk-toolbar--select' : ''}`}>
        {!isSelect ? (
          <div className="uploads-desk-storage">
            <div>
              <div className="uploads-desk-storage-title">
                {folder === 'documents' ? 'Documents' : 'All files'}
              </div>
              <div className="uploads-desk-storage-meta">{usedSpaceLabel} used</div>
            </div>
            <div className="uploads-desk-storage-bar" aria-hidden="true">
              <span style={{ width: `${Math.min(100, usedPercent || 0)}%` }} />
            </div>
          </div>
        ) : (
          <div className="uploads-desk-toolbar-spacer" aria-hidden="true" />
        )}

        <div className="uploads-desk-toolbar-search">
          <div className="uploads-desk-search">
            <HiOutlineMagnifyingGlass size={14} aria-hidden="true" />
            <input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search files..."
              aria-label="Search files"
            />
          </div>
        </div>

        <div className="uploads-desk-toolbar-actions">
          {showProductsImageTools ? (
            <button
              type="button"
              className="uploads-desk-ghost-btn"
              onClick={scanDuplicates}
              disabled={scanningDup}
            >
              {scanningDup ? <HiOutlineArrowPath size={14} className="uploads-spin" /> : null}
              Delete duplicates
            </button>
          ) : null}

          {!isSelect ? (
            <button
              type="button"
              className="prod-desk-icon-btn prod-desk-icon-btn--del"
              title="Delete selected"
              aria-label="Delete selected"
              onClick={() => {
                const items = files.filter((f) => selected.has(`${f.source}|${f.rel}`));
                deleteFiles(items);
              }}
            >
              <HiOutlineTrash size={18} aria-hidden="true" />
            </button>
          ) : null}

          <div className="uploads-desk-sort-wrap" onClick={(e) => e.stopPropagation()}>
            <button
              type="button"
              className={`prod-desk-icon-btn${sortMenuOpen ? ' is-active' : ''}`}
              title={
                sort === 'oldest' ? 'Oldest first' : sort === 'name' ? 'By name' : 'Newest first'
              }
              aria-label="Sort files"
              aria-expanded={sortMenuOpen}
              aria-haspopup="menu"
              onClick={() => setSortMenuOpen((v) => !v)}
            >
              <HiOutlineBarsArrowDown size={18} aria-hidden="true" />
            </button>
            {sortMenuOpen ? (
              <div className="uploads-desk-sort-menu" role="menu">
                <button
                  type="button"
                  role="menuitem"
                  className={sort === 'newest' ? 'is-active' : ''}
                  onClick={() => {
                    setSort('newest');
                    setSortMenuOpen(false);
                  }}
                >
                  Newest first
                </button>
                {!isSelect ? (
                  <button
                    type="button"
                    role="menuitem"
                    className={sort === 'oldest' ? 'is-active' : ''}
                    onClick={() => {
                      setSort('oldest');
                      setSortMenuOpen(false);
                    }}
                  >
                    Oldest first
                  </button>
                ) : null}
                <button
                  type="button"
                  role="menuitem"
                  className={sort === 'name' ? 'is-active' : ''}
                  onClick={() => {
                    setSort('name');
                    setSortMenuOpen(false);
                  }}
                >
                  By name
                </button>
              </div>
            ) : null}
          </div>
        </div>
      </div>

      {!isSelect ? (
        <div className="uploads-desk-select-all">
          <label>
            <input type="checkbox" checked={allVisibleSelected} onChange={toggleSelectAll} />
            Select all
          </label>
          {selected.size > 0 ? <span className="uploads-desk-selected-count">{selected.size} selected</span> : null}
        </div>
      ) : null}

      {docsError ? <div className="uploads-dup-error">{docsError}</div> : null}

      {docsLoading ? (
        <UploadsGridSkeleton count={12} />
      ) : filtered.length === 0 ? (
        <div className="uploads-desk-empty" role="status">
          <div className="uploads-desk-empty-icon" aria-hidden="true">
            <span className="uploads-desk-empty-ring" />
            <HiOutlineInbox size={40} />
          </div>
          <p className="uploads-desk-empty-title">No files found in the current folder.</p>
          {folder === 'documents' ? (
            <p className="uploads-desk-empty-hint">
              Upload PDFs and other files here, or switch to{' '}
              <a href={folderHref('uploads')}>All files</a>.
            </p>
          ) : (
            <p className="uploads-desk-empty-hint">Upload files to get started.</p>
          )}
        </div>
      ) : (
        <div className="uploads-desk-grid">
          {filtered.map((file) => {
            const key = `${file.source}|${file.rel}`;
            const isSelected = selected.has(key);
            return (
              <article
                key={key}
                className={`uploads-card${isSelected ? ' is-selected' : ''}${isSelect ? ' is-picker' : ''}${
                  openMenu?.key === key ? ' is-menu-open' : ''
                }`}
                onClick={() => {
                  if (isSelect) selectForParent(file);
                  else toggleSelect(file);
                }}
              >
                {!isSelect ? (
                  <div className="uploads-card-check" onClick={(e) => e.stopPropagation()}>
                    <input
                      type="checkbox"
                      checked={isSelected}
                      onChange={() => toggleSelect(file)}
                      aria-label={`Select ${file.name}`}
                    />
                  </div>
                ) : null}

                {!isSelect ? (
                  <div className="uploads-card-menu" onClick={(e) => e.stopPropagation()}>
                    <button
                      type="button"
                      className="uploads-card-menu-btn"
                      aria-label="File actions"
                      aria-expanded={openMenu?.key === key}
                      onClick={(e) => {
                        e.stopPropagation();
                        if (openMenu?.key === key) {
                          setOpenMenu(null);
                          return;
                        }
                        const rect = e.currentTarget.getBoundingClientRect();
                        setOpenMenu({
                          key,
                          rect: {
                            top: rect.top,
                            left: rect.left,
                            right: rect.right,
                            bottom: rect.bottom,
                            width: rect.width,
                            height: rect.height,
                          },
                        });
                      }}
                    >
                      <HiOutlineEllipsisVertical size={16} />
                    </button>
                  </div>
                ) : null}

                <div className="uploads-card-preview">
                  {file.is_image ? (
                    <img src={file.url} alt={file.name} loading="lazy" />
                  ) : (
                    <div className="uploads-card-file">
                      <HiOutlineDocument size={28} aria-hidden="true" />
                      <span>{file.ext || 'file'}</span>
                    </div>
                  )}
                </div>
                <div className="uploads-card-meta">
                  <div className="uploads-card-name" title={file.name}>
                    {file.name}
                  </div>
                  <div className="uploads-card-size">{file.size_label}</div>
                </div>
              </article>
            );
          })}
        </div>
      )}

      {openMenu ? (() => {
        const menuFile = files.find((f) => `${f.source}|${f.rel}` === openMenu.key);
        if (!menuFile) return null;
        return (
          <FileCardMenu
            file={menuFile}
            showUsage={showProductsImageTools}
            onUsage={openUsage}
            onDelete={(f) => deleteFiles([f])}
            onClose={() => setOpenMenu(null)}
            anchorRect={openMenu.rect}
          />
        );
      })() : null}

      {usageData ? <UsageModal data={usageData} onClose={() => setUsageData(null)} /> : null}
      {dupScan ? (
        <DuplicatesModal
          scan={dupScan}
          productsBaseUrl={productsBaseUrl}
          onClose={() => setDupScan(null)}
          onConfirm={commitDuplicates}
        />
      ) : null}

      {!isSelect && selected.size > 0 ? (
        <div className="uploads-desk-float" role="status">
          <HiOutlineCheckCircle size={16} aria-hidden="true" />
          {selected.size} selected
        </div>
      ) : null}
    </div>
  );
}
