import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  Check,
  ChevronRight,
  Eye,
  FileDown,
  FileText,
  Folder,
  FolderOpen,
  LoaderCircle,
  Lock,
  MoreVertical,
  Paperclip,
  Pencil,
  Save,
  ShieldCheck,
  SquarePen,
  Trash2,
  User,
  X,
} from 'lucide-react';
import { approveCover, deleteCover, fetchCoverDesk, getBootData, saveCover } from '../api.js';
import { documentNameWithoutFile } from '../utils/title.js';

function readUrlParam(key) {
  if (typeof window === 'undefined') return '';
  return new URL(window.location.href).searchParams.get(key) || '';
}

function writeUrlParams(values) {
  if (typeof window === 'undefined' || !window.history?.replaceState) return;
  const url = new URL(window.location.href);
  Object.entries(values).forEach(([key, value]) => {
    if (value) url.searchParams.set(key, String(value));
    else url.searchParams.delete(key);
  });
  const next = url.pathname + url.search + url.hash;
  if (next !== window.location.pathname + window.location.search + window.location.hash) {
    window.history.replaceState(window.history.state, '', next);
  }
}

/** Lazy-load the PDF helper; never auto-reload the page (that caused the preview flash). */
function loadCoverPdf() {
  return import('../utils/coverPdf.js').catch((err) => {
    throw new Error('The page is out of date. Refresh the page and try again.', { cause: err });
  });
}

function prefetchCoverPdf() {
  loadCoverPdf().catch(() => {});
}

const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

function blankForm() {
  const now = new Date();
  return {
    id: 0,
    document_name: '',
    file_no: '',
    file_total: '',
    doc_from: '',
    doc_to: '',
    period_month: String(now.getMonth() + 1),
    period_year: String(now.getFullYear()),
  };
}

function isApproved(cover) {
  return String(cover?.status || '').toLowerCase() === 'approved';
}

function pad2(n) {
  return String(n).padStart(2, '0');
}

function formatDate(value) {
  if (!value) return '';
  const d = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return String(value);
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function Field({ label, hint, error, children, wide }) {
  return (
    <label className={`cp-field${wide ? ' cp-field--wide' : ''}${error ? ' has-error' : ''}`}>
      <span className="cp-field-label">{label}</span>
      {children}
      {error ? <span className="cp-field-error">{error}</span> : hint ? <span className="cp-field-hint">{hint}</span> : null}
    </label>
  );
}

function ConfirmDelete({ cover, busy, onCancel, onConfirm }) {
  if (!cover) return null;
  return (
    <div
      className="cp-modal-backdrop"
      role="presentation"
      onMouseDown={busy ? undefined : ((e) => { if (e.target === e.currentTarget) onCancel(); })}
    >
      <div className="cp-modal" role="dialog" aria-modal="true" aria-labelledby="cp-del-title" onMouseDown={(e) => e.stopPropagation()}>
        <h2 id="cp-del-title">Delete cover page?</h2>
        <p>
          <strong>{documentNameWithoutFile(cover.document_name)}</strong> (File {pad2(cover.file_no)} / {pad2(cover.file_total)}) will be removed. This cannot be undone.
        </p>
        <div className="cp-modal-actions">
          <button type="button" className="cp-btn cp-btn--ghost" onClick={onCancel} disabled={busy}>Cancel</button>
          <button type="button" className="cp-btn cp-btn--danger" onClick={onConfirm} disabled={busy}>
            {busy ? 'Deleting…' : 'Delete'}
          </button>
        </div>
      </div>
    </div>
  );
}

function RowMenu({ disabled, canAttach, canEdit, canDelete, onAttach, onEdit, onDelete }) {
  const [pos, setPos] = useState(null);
  const btnRef = useRef(null);
  const menuRef = useRef(null);
  const showAttach = canAttach !== false;
  const showEdit = canEdit !== false;
  const showDelete = canDelete !== false;
  if (!showAttach && !showEdit && !showDelete) return null;

  useEffect(() => {
    if (!pos) return undefined;
    const close = () => setPos(null);
    const onDown = (e) => {
      if (menuRef.current?.contains(e.target) || btnRef.current?.contains(e.target)) return;
      close();
    };
    const onKey = (e) => {
      if (e.key === 'Escape') close();
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    window.addEventListener('resize', close);
    window.addEventListener('scroll', close, true);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
      window.removeEventListener('resize', close);
      window.removeEventListener('scroll', close, true);
    };
  }, [pos]);

  const toggle = () => {
    if (pos) {
      setPos(null);
      return;
    }
    const r = btnRef.current.getBoundingClientRect();
    const menuHeight = 160;
    const openUp = r.bottom + menuHeight + 8 > window.innerHeight;
    setPos({
      right: Math.max(8, window.innerWidth - r.right),
      ...(openUp ? { bottom: window.innerHeight - r.top + 6 } : { top: r.bottom + 6 }),
    });
  };

  const pick = (action) => () => {
    setPos(null);
    action();
  };

  return (
    <>
      <button
        ref={btnRef}
        type="button"
        className={`cp-icon-btn${pos ? ' is-open' : ''}`}
        title="More actions"
        aria-label="More actions"
        aria-haspopup="menu"
        aria-expanded={Boolean(pos)}
        onClick={toggle}
      >
        <MoreVertical size={16} aria-hidden="true" />
      </button>
      {pos ? (
        <div ref={menuRef} className="cp-row-menu" role="menu" style={pos}>
          {showAttach ? (
            <button
              type="button"
              role="menuitem"
              className="cp-row-menu-item"
              title="Choose a PDF; the cover page is added as its first page"
              onClick={pick(onAttach)}
              disabled={disabled}
            >
              <Paperclip size={15} aria-hidden="true" />
              Attach to a PDF
            </button>
          ) : null}
          {showEdit ? (
            <button type="button" role="menuitem" className="cp-row-menu-item" onClick={pick(onEdit)}>
              <Pencil size={15} aria-hidden="true" />
              Edit
            </button>
          ) : null}
          {showDelete ? (
            <button type="button" role="menuitem" className="cp-row-menu-item cp-row-menu-item--danger" onClick={pick(onDelete)}>
              <Trash2 size={15} aria-hidden="true" />
              Delete
            </button>
          ) : null}
        </div>
      ) : null}
    </>
  );
}

function CoverPreview({ preview, busy, onClose, onDownload, ignoreCloseUntilRef }) {
  useEffect(() => {
    if (!preview) return undefined;
    const onKey = (e) => {
      if (e.key !== 'Escape') return;
      if (Date.now() < (ignoreCloseUntilRef?.current || 0)) return;
      onClose();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [preview, onClose, ignoreCloseUntilRef]);

  if (!preview || typeof document === 'undefined') return null;
  const { cover, url, error } = preview;
  const allowed = cover.can_download !== false && isApproved(cover);
  const requestClose = (e) => {
    if (e && e.target !== e.currentTarget) return;
    if (Date.now() < (ignoreCloseUntilRef?.current || 0)) return;
    onClose();
  };

  return createPortal(
    <div className="cp-modal-backdrop" role="presentation" onMouseDown={requestClose}>
      <div
        className="cp-preview"
        role="dialog"
        aria-modal="true"
        aria-labelledby="cp-preview-title"
        onMouseDown={(e) => e.stopPropagation()}
      >
        <header className="cp-preview-head">
          <h2 id="cp-preview-title">
            {documentNameWithoutFile(cover.document_name)} — File {pad2(cover.file_no)} / {pad2(cover.file_total)}
          </h2>
          <button type="button" className="cp-icon-btn" title="Close" aria-label="Close" onClick={onClose}>
            <X size={16} aria-hidden="true" />
          </button>
        </header>
        <div className="cp-preview-body">
          {url ? (
            <iframe className="cp-preview-frame" src={`${url}#toolbar=0&navpanes=0&view=FitH`} title="Cover page preview" />
          ) : error ? (
            <div className="cp-preview-loading">
              <span>{error}</span>
            </div>
          ) : (
            <div className="cp-preview-loading">
              <LoaderCircle size={28} className="cp-spin" aria-hidden="true" />
              <span>Preparing preview…</span>
            </div>
          )}
        </div>
        <div className="cp-modal-actions cp-preview-actions">
          <button type="button" className="cp-btn cp-btn--ghost" onClick={onClose}>Close</button>
          <button
            type="button"
            className="cp-btn cp-btn--primary"
            onClick={onDownload}
            disabled={busy || !url || !allowed}
            title={allowed ? 'Download cover' : 'Waiting for another user to approve before download'}
          >
            {busy ? <LoaderCircle size={15} className="cp-spin" aria-hidden="true" /> : <FileDown size={15} aria-hidden="true" />}
            {allowed ? 'Download cover' : 'Awaiting approval'}
          </button>
        </div>
      </div>
    </div>,
    document.body,
  );
}

export default function CoverPageDesk() {
  const boot = useMemo(() => getBootData(), []);
  const user = boot.user || {};
  const documentNames = Array.isArray(boot.documentNames) ? boot.documentNames : [];
  const [tab, setTab] = useState(() => (readUrlParam('tab') === 'saved' ? 'saved' : 'new'));
  const [form, setForm] = useState(blankForm);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [covers, setCovers] = useState(Array.isArray(boot.covers) ? boot.covers : []);
  const [toast, setToast] = useState(null);
  const [pendingDelete, setPendingDelete] = useState(null);
  const [deleting, setDeleting] = useState(false);
  const [busyId, setBusyId] = useState(0);
  const [preview, setPreview] = useState(null);
  const previewTokenRef = useRef(0);
  const ignorePreviewCloseUntilRef = useRef(0);
  const attachInputRef = useRef(null);
  const attachTargetRef = useRef(null);
  const [nameMenuOpen, setNameMenuOpen] = useState(false);
  const nameMenuRef = useRef(null);

  useEffect(() => {
    prefetchCoverPdf();
  }, []);

  useEffect(() => {
    if (!nameMenuOpen) return undefined;
    const close = (e) => {
      if (nameMenuRef.current && !nameMenuRef.current.contains(e.target)) setNameMenuOpen(false);
    };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, [nameMenuOpen]);

  useEffect(() => {
    if (!toast) return undefined;
    const t = window.setTimeout(() => setToast(null), 3200);
    return () => window.clearTimeout(t);
  }, [toast]);

  useEffect(() => {
    writeUrlParams({ tab: tab === 'saved' ? 'saved' : '' });
  }, [tab]);

  // Boot payload can be empty/stale; always refresh the saved list from the API on open.
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const data = await fetchCoverDesk();
        if (cancelled || !Array.isArray(data.covers)) return;
        setCovers(data.covers);
      } catch {
        /* keep boot covers */
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const editing = form.id > 0;
  const editingCover = editing ? covers.find((c) => c.id === form.id) : null;
  const preparedName = editingCover ? editingCover.prepared_by_name : user.name;
  const preparedDesignation = editingCover ? editingCover.prepared_by_designation : user.designation;
  const preparedDate = editingCover ? editingCover.prepared_date : boot.today;

  const set = (key) => (e) => {
    const value = e.target.value;
    setForm((f) => ({ ...f, [key]: value }));
    if (errors[key]) setErrors((er) => ({ ...er, [key]: undefined }));
  };
  const chooseName = (name) => {
    setForm((f) => ({ ...f, document_name: name }));
    if (errors.document_name) setErrors((er) => ({ ...er, document_name: undefined }));
  };
  const setNumber = (key) => (e) => {
    const value = e.target.value.replace(/\D/g, '').slice(0, 6);
    setForm((f) => ({ ...f, [key]: value }));
    if (errors[key]) setErrors((er) => ({ ...er, [key]: undefined }));
  };

  const resetForm = () => {
    setForm(blankForm());
    setErrors({});
  };

  const submit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    try {
      const res = await saveCover(form);
      setCovers(Array.isArray(res.covers) ? res.covers : []);
      setToast({ kind: 'success', text: res.message || 'Saved.' });
      resetForm();
      setTab('saved');
    } catch (err) {
      setErrors(err.fields || {});
      setToast({ kind: 'error', text: err.message || 'Could not save.' });
    } finally {
      setSaving(false);
    }
  };

  const startEdit = (cover) => {
    if (cover.can_edit === false) {
      setToast({ kind: 'error', text: 'You can only edit cover pages you created.' });
      return;
    }
    setForm({
      id: cover.id,
      document_name: documentNameWithoutFile(cover.document_name),
      file_no: String(cover.file_no),
      file_total: String(cover.file_total),
      doc_from: String(cover.doc_from),
      doc_to: String(cover.doc_to),
      period_month: String(cover.period_month),
      period_year: String(cover.period_year),
    });
    setErrors({});
    setTab('new');
  };

  const runDownload = async (cover, task, successText) => {
    if (cover.can_download === false || !isApproved(cover)) {
      setToast({ kind: 'error', text: 'Another user must approve this cover page before download or print.' });
      return;
    }
    setBusyId(cover.id);
    try {
      await task();
      setToast({ kind: 'success', text: successText });
    } catch (err) {
      setToast({ kind: 'error', text: err.message || 'Could not create the PDF.' });
    } finally {
      setBusyId(0);
    }
  };

  const downloadCover = (cover) => runDownload(
    cover,
    async () => (await loadCoverPdf()).downloadCoverPdf(cover),
    'Cover page downloaded.',
  );

  const approve = async (cover) => {
    if (!cover?.id) return;
    setBusyId(cover.id);
    try {
      const res = await approveCover(cover.id);
      setCovers(Array.isArray(res.covers) ? res.covers : []);
      setToast({ kind: 'success', text: res.message || 'Approved.' });
      if (preview?.cover?.id === cover.id) {
        const updated = (Array.isArray(res.covers) ? res.covers : []).find((c) => c.id === cover.id);
        if (updated) setPreview((p) => (p ? { ...p, cover: updated } : p));
      }
    } catch (err) {
      setToast({ kind: 'error', text: err.message || 'Could not approve.' });
    } finally {
      setBusyId(0);
    }
  };

  const previewUrl = preview?.url;
  useEffect(() => () => {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
  }, [previewUrl]);

  const closePreview = useCallback(() => {
    previewTokenRef.current += 1;
    setPreview(null);
  }, []);

  const openPreview = useCallback(async (cover) => {
    if (!cover?.id) return;
    const token = ++previewTokenRef.current;
    // Block backdrop/Escape from the same gesture that opened the modal.
    ignorePreviewCloseUntilRef.current = Date.now() + 700;
    setPreview({ cover, url: null, error: null });
    try {
      const mod = await loadCoverPdf();
      const url = await mod.coverPreviewUrl(cover);
      if (token !== previewTokenRef.current) {
        URL.revokeObjectURL(url);
        return;
      }
      setPreview({ cover, url, error: null });
    } catch (err) {
      if (token !== previewTokenRef.current) return;
      // Keep the dialog open with the error — closing looked like a flash.
      setPreview({
        cover,
        url: null,
        error: err.message || 'Could not open the preview.',
      });
      setToast({ kind: 'error', text: err.message || 'Could not open the preview.' });
    }
  }, []);

  const pendingViewIdRef = useRef(Number(readUrlParam('view')) || 0);
  useEffect(() => {
    const id = pendingViewIdRef.current;
    if (!id || covers.length === 0) return;
    const cover = covers.find((c) => c.id === id);
    if (!cover) return;
    pendingViewIdRef.current = 0;
    writeUrlParams({ view: '' });
    openPreview(cover);
  }, [covers, openPreview]);

  const pickDocument = (cover) => {
    attachTargetRef.current = cover;
    if (attachInputRef.current) {
      attachInputRef.current.value = '';
      attachInputRef.current.click();
    }
  };

  const onDocumentPicked = (e) => {
    const file = e.target.files && e.target.files[0];
    const cover = attachTargetRef.current;
    if (!file || !cover) return;
    if (!/\.pdf$/i.test(file.name) && file.type !== 'application/pdf') {
      setToast({ kind: 'error', text: 'Choose a PDF file.' });
      return;
    }
    runDownload(
      cover,
      async () => (await loadCoverPdf()).downloadCoverWithDocument(cover, file),
      'Document with cover page downloaded.',
    );
  };

  const confirmDelete = async () => {
    if (!pendingDelete) return;
    setDeleting(true);
    try {
      const res = await deleteCover(pendingDelete.id);
      setCovers(Array.isArray(res.covers) ? res.covers : []);
      if (form.id === pendingDelete.id) resetForm();
      setToast({ kind: 'success', text: res.message || 'Deleted.' });
      setPendingDelete(null);
    } catch (err) {
      setToast({ kind: 'error', text: err.message || 'Could not delete.' });
    } finally {
      setDeleting(false);
    }
  };

  return (
    <div className="cp-desk">
      <div className="cp-tabs" role="tablist">
        <button
          type="button"
          role="tab"
          aria-selected={tab === 'new'}
          className={`cp-tab${tab === 'new' ? ' is-active' : ''}`}
          onClick={() => setTab('new')}
        >
          <SquarePen size={16} aria-hidden="true" />
          {editing ? 'Edit cover details' : 'New cover page'}
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={tab === 'saved'}
          className={`cp-tab${tab === 'saved' ? ' is-active' : ''}`}
          onClick={() => setTab('saved')}
        >
          <FolderOpen size={16} aria-hidden="true" />
          Saved cover pages
          <span className="cp-tab-count">{covers.length}</span>
        </button>
      </div>

      {tab === 'new' ? (
        <form className="cp-form" onSubmit={submit} noValidate>
          <section className="cp-card">
            <header className="cp-card-head">
              <span className="cp-card-icon cp-card-icon--gold"><FileText size={17} aria-hidden="true" /></span>
              <div>
                <h2>Document</h2>
                <p>The title printed in large letters on the cover.</p>
              </div>
            </header>
            <div className="cp-grid">
              <div className={`cp-field cp-field--wide${errors.document_name ? ' has-error' : ''}`}>
                <span className="cp-field-label" id="cp-docname-label">Name of the document</span>
                <div className="cp-input-group cp-select" ref={nameMenuRef}>
                  <button
                    type="button"
                    className={`cp-select-trigger${nameMenuOpen ? ' is-open' : ''}`}
                    aria-haspopup="listbox"
                    aria-expanded={nameMenuOpen}
                    aria-labelledby="cp-docname-label"
                    onClick={() => setNameMenuOpen((o) => !o)}
                    onKeyDown={(e) => {
                      if (e.key === 'Escape') setNameMenuOpen(false);
                      if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
                        e.preventDefault();
                        setNameMenuOpen(true);
                      }
                    }}
                  >
                    <span className={form.document_name ? 'cp-select-value' : 'cp-select-placeholder'}>
                      {form.document_name || 'Select the type of file'}
                    </span>
                    <ChevronRight size={18} className="cp-select-chevron" aria-hidden="true" />
                  </button>
                  <span className="cp-input-suffix" title="Fixed on every cover page">
                    <Lock size={13} strokeWidth={2.5} aria-hidden="true" />
                    FILE
                  </span>
                  {nameMenuOpen ? (
                    <ul className="cp-select-menu" role="listbox" aria-labelledby="cp-docname-label">
                      {documentNames.map((name) => {
                        const selected = form.document_name === name;
                        return (
                          <li key={name} role="option" aria-selected={selected}>
                            <button
                              type="button"
                              className={`cp-select-option${selected ? ' is-selected' : ''}`}
                              onClick={() => {
                                chooseName(name);
                                setNameMenuOpen(false);
                              }}
                              onKeyDown={(e) => {
                                if (e.key === 'Escape') setNameMenuOpen(false);
                              }}
                            >
                              <span>{name}</span>
                              {selected ? <Check size={16} strokeWidth={2.5} aria-hidden="true" /> : null}
                            </button>
                          </li>
                        );
                      })}
                    </ul>
                  ) : null}
                </div>
                {errors.document_name ? (
                  <span className="cp-field-error">{errors.document_name}</span>
                ) : (
                  <span className="cp-field-hint">The word FILE is always printed under the name and cannot be changed.</span>
                )}
              </div>
            </div>
          </section>

          <section className="cp-card">
            <header className="cp-card-head">
              <span className="cp-card-icon cp-card-icon--blue"><Folder size={17} aria-hidden="true" /></span>
              <div>
                <h2>File details</h2>
                <p>File number, document range and the period the file covers.</p>
              </div>
            </header>
            <div className="cp-grid">
              <div className="cp-pair">
                <Field label="File No." error={errors.file_no}>
                  <input type="text" inputMode="numeric" value={form.file_no} onChange={setNumber('file_no')} placeholder="01" />
                </Field>
                <span className="cp-pair-sep">out of</span>
                <Field label="Total files" error={errors.file_total}>
                  <input type="text" inputMode="numeric" value={form.file_total} onChange={setNumber('file_total')} placeholder="10" />
                </Field>
              </div>
              <div className="cp-pair">
                <Field label="Document No." error={errors.doc_from}>
                  <input type="text" inputMode="numeric" value={form.doc_from} onChange={setNumber('doc_from')} placeholder="01" />
                </Field>
                <span className="cp-pair-sep">up to</span>
                <Field label="Last document No." error={errors.doc_to}>
                  <input type="text" inputMode="numeric" value={form.doc_to} onChange={setNumber('doc_to')} placeholder="103" />
                </Field>
              </div>
              <div className="cp-pair">
                <Field label="File period — month" error={errors.period_month}>
                  <select value={form.period_month} onChange={set('period_month')}>
                    {MONTHS.map((m, i) => (
                      <option key={m} value={String(i + 1)}>{m}</option>
                    ))}
                  </select>
                </Field>
                <span className="cp-pair-sep" aria-hidden="true" />
                <Field label="Year" error={errors.period_year}>
                  <input type="text" inputMode="numeric" value={form.period_year} onChange={setNumber('period_year')} placeholder="2026" maxLength={4} />
                </Field>
              </div>
            </div>
          </section>

          <div className="cp-two-col">
            <section className="cp-card">
              <header className="cp-card-head">
                <span className="cp-card-icon cp-card-icon--green"><User size={17} aria-hidden="true" /></span>
                <div>
                  <h2>Prepared by</h2>
                  <p>Filled in automatically from your account.</p>
                </div>
              </header>
              <dl className="cp-readonly">
                <div><dt>Name</dt><dd>{preparedName || '—'}</dd></div>
                <div>
                  <dt>Signature</dt>
                  <dd>
                    {editingCover?.prepared_signature_url || user.signature_url
                      ? 'Your account signature is used on the cover'
                      : (preparedDesignation || 'Add a signature in your profile to stamp the cover')}
                  </dd>
                </div>
                <div><dt>Date</dt><dd>{formatDate(preparedDate) || '—'}</dd></div>
              </dl>
            </section>

            <section className="cp-card">
              <header className="cp-card-head">
                <span className="cp-card-icon cp-card-icon--purple"><ShieldCheck size={17} aria-hidden="true" /></span>
                <div>
                  <h2>Approval</h2>
                  <p>After you save, another user must log in and approve before download or print.</p>
                </div>
              </header>
              <dl className="cp-readonly">
                <div>
                  <dt>Status</dt>
                  <dd>
                    {editingCover && isApproved(editingCover) ? (
                      <span className="cp-status cp-status--approved">Approved</span>
                    ) : (
                      <span className="cp-status cp-status--pending">Pending approval</span>
                    )}
                  </dd>
                </div>
                <div>
                  <dt>Reviewed &amp; approved by</dt>
                  <dd>
                    {editingCover && isApproved(editingCover)
                      ? (editingCover.reviewed_by_name || '—')
                      : 'Filled automatically when another user approves'}
                  </dd>
                </div>
                <div>
                  <dt>Signature</dt>
                  <dd>
                    {editingCover && isApproved(editingCover)
                      ? (editingCover.reviewed_signature_url
                        ? 'Approver signature stamped on the cover'
                        : (editingCover.reviewed_by_designation || 'No signature on file — designation used'))
                      : 'Approver’s account signature is stamped when they approve'}
                  </dd>
                </div>
              </dl>
              {editing && isApproved(editingCover) ? (
                <p className="cp-field-hint cp-approval-note">
                  Saving changes will clear the current approval and require a new approval.
                </p>
              ) : null}
            </section>
          </div>

          <div className="cp-actions">
            {editing ? (
              <button type="button" className="cp-btn cp-btn--ghost" onClick={resetForm} disabled={saving}>
                Cancel edit
              </button>
            ) : (
              <button type="button" className="cp-btn cp-btn--ghost" onClick={resetForm} disabled={saving}>
                Clear
              </button>
            )}
            <button type="submit" className="cp-btn cp-btn--primary" disabled={saving}>
              <Save size={16} aria-hidden="true" />
              {saving ? 'Saving…' : editing ? 'Update details' : 'Save details'}
            </button>
          </div>
        </form>
      ) : (
        <section className="cp-card cp-card--table">
          {covers.length === 0 ? (
            <div className="cp-empty">
              <FolderOpen size={36} strokeWidth={1.5} aria-hidden="true" />
              <p>No cover pages yet.</p>
              <button type="button" className="cp-btn cp-btn--primary" onClick={() => setTab('new')}>
                Create the first one
              </button>
            </div>
          ) : (
            <div className="cp-table-wrap">
              <table className="cp-table">
                <thead>
                  <tr>
                    <th className="cp-col-no">No.</th>
                    <th>Document</th>
                    <th>File</th>
                    <th>Documents</th>
                    <th>File period</th>
                    <th>Prepared by</th>
                    <th>Status</th>
                    <th>Saved on</th>
                    <th className="cp-col-actions" aria-label="Actions" />
                  </tr>
                </thead>
                <tbody>
                  {covers.map((c, i) => {
                    const approved = isApproved(c);
                    const canDownload = c.can_download !== false && approved;
                    const canApprove = Boolean(c.can_approve);
                    const rowBusy = busyId === c.id;
                    return (
                    <tr key={c.id}>
                      <td className="cp-col-no">{i + 1}</td>
                      <td className="cp-col-doc">{documentNameWithoutFile(c.document_name)}</td>
                      <td>{pad2(c.file_no)} / {pad2(c.file_total)}</td>
                      <td>{pad2(c.doc_from)} – {pad2(c.doc_to)}</td>
                      <td>{MONTHS[c.period_month - 1] || ''} {c.period_year}</td>
                      <td>{c.prepared_by_name}</td>
                      <td>
                        {approved ? (
                          <span className="cp-status cp-status--approved" title={c.reviewed_by_name ? `Approved by ${c.reviewed_by_name}` : 'Approved'}>
                            Approved
                          </span>
                        ) : (
                          <span className="cp-status cp-status--pending">Pending</span>
                        )}
                      </td>
                      <td>{formatDate(c.created_at)}</td>
                      <td className="cp-col-actions">
                        <button
                          type="button"
                          className="cp-icon-btn cp-icon-btn--view"
                          title="View cover page"
                          aria-label="View cover page"
                          onMouseDown={(e) => e.stopPropagation()}
                          onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            openPreview(c);
                          }}
                        >
                          <Eye size={15} aria-hidden="true" />
                        </button>
                        {canApprove ? (
                          <button
                            type="button"
                            className="cp-btn cp-btn--small cp-btn--approve"
                            onClick={() => approve(c)}
                            disabled={busyId !== 0}
                            title="Approve so this cover can be downloaded and printed"
                          >
                            {rowBusy ? <LoaderCircle size={15} className="cp-spin" aria-hidden="true" /> : <ShieldCheck size={15} aria-hidden="true" />}
                            Approve
                          </button>
                        ) : null}
                        <button
                          type="button"
                          className="cp-btn cp-btn--small cp-btn--primary"
                          onClick={() => downloadCover(c)}
                          disabled={busyId !== 0 || !canDownload}
                          title={canDownload ? 'Download cover' : 'Waiting for another user to approve'}
                        >
                          {rowBusy && canDownload ? <LoaderCircle size={15} className="cp-spin" aria-hidden="true" /> : canDownload ? <FileDown size={15} aria-hidden="true" /> : <Lock size={15} aria-hidden="true" />}
                          {canDownload ? 'Download cover' : 'Locked'}
                        </button>
                        <RowMenu
                          disabled={busyId !== 0}
                          canAttach={canDownload}
                          canEdit={c.can_edit !== false}
                          canDelete={c.can_delete !== false}
                          onAttach={() => pickDocument(c)}
                          onEdit={() => startEdit(c)}
                          onDelete={() => setPendingDelete(c)}
                        />
                      </td>
                    </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </section>
      )}

      <input
        ref={attachInputRef}
        type="file"
        accept="application/pdf,.pdf"
        className="cp-hidden-input"
        onChange={onDocumentPicked}
        tabIndex={-1}
        aria-hidden="true"
      />

      <CoverPreview
        preview={preview}
        busy={preview ? busyId === preview.cover.id : false}
        onClose={closePreview}
        onDownload={() => preview && downloadCover(preview.cover)}
        ignoreCloseUntilRef={ignorePreviewCloseUntilRef}
      />

      <ConfirmDelete cover={pendingDelete} busy={deleting} onCancel={() => setPendingDelete(null)} onConfirm={confirmDelete} />

      {toast ? (
        <div className={`cp-toast cp-toast--${toast.kind}`} role="status">{toast.text}</div>
      ) : null}
    </div>
  );
}
