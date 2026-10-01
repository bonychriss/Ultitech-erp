import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { AlertCircle, Check, ChevronDown, Info, Trash2, Upload, X } from 'lucide-react';
import { fetchCorrections, fetchInvoicesInit, postCorrection } from '../api/invoicesListDesk';
import { fetchInvoiceViewInit } from '../api/invoiceViewDesk';
import InvoiceDocumentPane from '../components/InvoiceDocumentPane';

function todayIso() {
  const now = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount || 0);
}

const ATTACHMENT_TYPES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
const ATTACHMENT_MAX = 5 * 1024 * 1024;

function formatFileSize(bytes) {
  const size = Number(bytes) || 0;
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${Math.max(1, Math.round(size / 1024))} KB`;
  return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

function fileKind(name) {
  const ext = String(name || '').split('.').pop().toLowerCase();
  if (ext === 'pdf') return 'PDF';
  if (['jpg', 'jpeg'].includes(ext)) return 'JPG';
  if (ext === 'png') return 'PNG';
  if (ext === 'webp') return 'WEBP';
  return 'FILE';
}

function canReport(invoice) {
  const status = String(invoice?.status || '').toLowerCase();
  if (['cancelled', 'canceled'].includes(status)) return false;
  if (invoice?.correction_status === 'pending') return false;
  return true;
}

export default function WrongInvoicesPage() {
  const [init, setInit] = useState(null);
  const [reports, setReports] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [reportedOn, setReportedOn] = useState(todayIso);
  const [invoiceId, setInvoiceId] = useState('');
  const [invoiceQuery, setInvoiceQuery] = useState('');
  const [invoiceMenuOpen, setInvoiceMenuOpen] = useState(false);
  const invoicePickRef = useRef(null);
  const [reason, setReason] = useState('');
  const [attachment, setAttachment] = useState(null);
  const [attachmentDrag, setAttachmentDrag] = useState(false);
  const attachmentInputRef = useRef(null);
  const [formError, setFormError] = useState('');
  const [reasonError, setReasonError] = useState('');
  const reasonRef = useRef(null);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState('');
  const [reverseReport, setReverseReport] = useState(null);
  const [reverseError, setReverseError] = useState('');
  const [reversing, setReversing] = useState(false);
  const [aboutOpen, setAboutOpen] = useState(false);
  const aboutRef = useRef(null);
  const [isAdmin, setIsAdmin] = useState(false);
  const [preview, setPreview] = useState(null);
  const [previewLoading, setPreviewLoading] = useState(false);
  const [previewError, setPreviewError] = useState('');
  const [previewData, setPreviewData] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const data = await fetchInvoicesInit();
      setInit(data);
      const correctionsUrl = data?.urls?.corrections;
      if (!correctionsUrl) {
        throw new Error('Wrong invoices are not available.');
      }
      const queue = await fetchCorrections(correctionsUrl);
      setReports(Array.isArray(queue.reports) ? queue.reports : []);
      setIsAdmin(Boolean(queue.is_admin ?? data.is_admin));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load reports.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    if (!aboutOpen) return undefined;
    function onPointerDown(event) {
      if (!aboutRef.current?.contains(event.target)) setAboutOpen(false);
    }
    document.addEventListener('mousedown', onPointerDown);
    return () => document.removeEventListener('mousedown', onPointerDown);
  }, [aboutOpen]);

  useEffect(() => {
    if (!preview) return undefined;
    function onKeyDown(event) {
      if (event.key === 'Escape') setPreview(null);
    }
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [preview]);

  useEffect(() => {
    if (!previewData?.document_font_family || !previewData?.font_stylesheets) return undefined;
    const links = [];
    const template = document.createElement('template');
    template.innerHTML = previewData.font_stylesheets.trim();
    template.content.querySelectorAll('link[rel="stylesheet"]').forEach((node) => {
      const href = node.getAttribute('href');
      if (!href || document.querySelector(`link[data-ov-doc-font="${href}"]`)) return;
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      link.dataset.ovDocFont = href;
      document.head.appendChild(link);
      links.push(link);
    });
    return () => {
      links.forEach((node) => node.remove());
    };
  }, [previewData?.document_font_family, previewData?.font_stylesheets]);

  async function openInvoicePreview(report) {
    const id = Number(report?.invoice_id || 0);
    if (id < 1) return;
    setPreview(report);
    setPreviewData(null);
    setPreviewError('');
    setPreviewLoading(true);
    try {
      const params = new URLSearchParams();
      params.set('id', String(id));
      const moduleName = init?.module || new URLSearchParams(window.location.search).get('module') || 'sales';
      if (moduleName) params.set('module', moduleName);
      const payload = await fetchInvoiceViewInit(params);
      setPreviewData(payload);
    } catch (err) {
      setPreviewError(err instanceof Error ? err.message : 'Could not open the invoice.');
    } finally {
      setPreviewLoading(false);
    }
  }

  useEffect(() => {
    if (!invoiceMenuOpen) return undefined;
    function onPointerDown(event) {
      if (!invoicePickRef.current?.contains(event.target)) setInvoiceMenuOpen(false);
    }
    document.addEventListener('mousedown', onPointerDown);
    return () => document.removeEventListener('mousedown', onPointerDown);
  }, [invoiceMenuOpen]);

  const invoiceOptions = useMemo(() => {
    const needle = invoiceQuery.trim().toLowerCase();
    const rows = (Array.isArray(init?.invoices) ? init.invoices : []).filter(canReport);
    const filtered = needle === ''
      ? rows
      : rows.filter((invoice) => `${invoice.invoice_number || ''} ${invoice.customer_name || ''}`.toLowerCase().includes(needle));
    return filtered.slice(0, 40);
  }, [init, invoiceQuery]);

  const selectedInvoice = useMemo(() => {
    const rows = Array.isArray(init?.invoices) ? init.invoices : [];
    return rows.find((row) => String(row.id) === String(invoiceId)) || null;
  }, [init, invoiceId]);

  function invoiceLabel(invoice) {
    return `${invoice.invoice_number} - ${invoice.customer_name || 'No customer'} - ${formatCurrency(invoice.total_amount)}`;
  }

  function chooseAttachment(file) {
    if (!file) return;
    const ext = String(file.name || '').split('.').pop().toLowerCase();
    if (!ATTACHMENT_TYPES.includes(ext)) {
      setFormError('Attachment must be PDF, JPG, PNG, or WEBP.');
      return;
    }
    if (file.size > ATTACHMENT_MAX) {
      setFormError('Attachment must be 5MB or smaller.');
      return;
    }
    setFormError('');
    setAttachment(file);
  }

  function openForm() {
    setReportedOn(todayIso());
    setInvoiceId('');
    setInvoiceQuery('');
    setInvoiceMenuOpen(false);
    setReason('');
    setAttachment(null);
    setFormError('');
    setReasonError('');
    setFormOpen(true);
  }

  function closeForm() {
    if (saving) return;
    setFormOpen(false);
  }

  async function submitReport(event) {
    event.preventDefault();
    const correctionsUrl = init?.urls?.corrections;
    if (!correctionsUrl || saving) return;
    if (!invoiceId) {
      setFormError('Choose an invoice.');
      return;
    }
    if (!reportedOn) {
      setFormError('Enter the date.');
      return;
    }
    if (!reason.trim()) {
      setReasonError('Reasons are required.');
      reasonRef.current?.focus();
      return;
    }
    setReasonError('');
    setSaving(true);
    setFormError('');
    try {
      const fields = {
        action: 'report',
        invoice_id: invoiceId,
        reported_on: reportedOn,
        reason: reason.trim(),
      };
      if (attachment) fields.attachment = attachment;
      const data = await postCorrection(correctionsUrl, fields);
      setReports(Array.isArray(data.reports) ? data.reports : []);
      setInit((current) => {
        if (!current) return current;
        return {
          ...current,
          invoices: (current.invoices || []).map((row) => (
            String(row.id) === String(invoiceId) ? { ...row, correction_status: 'pending' } : row
          )),
        };
      });
      setFormOpen(false);
      setNotice(data.message || 'Reported. An admin will approve the reversal.');
    } catch (err) {
      setFormError(err instanceof Error ? err.message : 'Could not report.');
    } finally {
      setSaving(false);
    }
  }

  async function decide(report, action) {
    const correctionsUrl = init?.urls?.corrections;
    if (!correctionsUrl) return;
    if (action === 'approve') {
      setReverseError('');
      setReverseReport(report);
      return;
    }
    let note = '';
    if (action === 'reject') {
      if (typeof window.Swal !== 'undefined') {
        const result = await window.Swal.fire({
          title: 'Reject this report?',
          input: 'text',
          inputPlaceholder: 'Note for the salesperson (optional)',
          showCancelButton: true,
          confirmButtonText: 'Reject',
          confirmButtonColor: '#64748b',
          cancelButtonColor: '#94a3b8',
        });
        if (!result.isConfirmed) return;
        note = String(result.value || '');
      } else if (!window.confirm('Reject this report?')) {
        return;
      }
    }
    try {
      const data = await postCorrection(correctionsUrl, { action, id: report.id, note });
      setReports(Array.isArray(data.reports) ? data.reports : []);
      if (typeof window.Swal !== 'undefined') {
        window.Swal.fire({ icon: 'success', title: action === 'approve' ? 'Reversed' : 'Rejected', text: data.message || '' });
      }
    } catch (err) {
      if (typeof window.Swal !== 'undefined') {
        window.Swal.fire({ icon: 'error', title: 'Could not update', text: err.message || 'Network error' });
      } else {
        window.alert(err.message || 'Could not update');
      }
    }
  }

  async function confirmReverse() {
    const correctionsUrl = init?.urls?.corrections;
    if (!correctionsUrl || !reverseReport || reversing) return;
    setReversing(true);
    setReverseError('');
    try {
      const data = await postCorrection(correctionsUrl, { action: 'approve', id: reverseReport.id });
      setReports(Array.isArray(data.reports) ? data.reports : []);
      setReverseReport(null);
      setNotice(data.message || 'The invoice is cancelled.');
    } catch (err) {
      setReverseError(err instanceof Error ? err.message : 'Could not update');
    } finally {
      setReversing(false);
    }
  }

  return (
    <div className="exp-desk-page inv-wrong-page">
      {notice ? (
        <div className="exp-desk-flash-ok" role="status">
          <span>{notice}</span>
          <button type="button" className="exp-desk-flash-dismiss" aria-label="Dismiss" onClick={() => setNotice('')}>
            <X size={14} aria-hidden="true" />
          </button>
        </div>
      ) : null}
      <div className="exp-desk-page-header">
        <div className="exp-desk-page-header-actions">
          <div className="inv-wrong-about" ref={aboutRef}>
            <button
              type="button"
              className="inv-wrong-about-btn"
              aria-label="About this process"
              aria-expanded={aboutOpen}
              onClick={() => setAboutOpen((open) => !open)}
            >
              <Info size={18} aria-hidden="true" />
            </button>
            {aboutOpen ? (
              <div className="inv-wrong-about-panel" role="dialog" aria-label="Wrong invoice process">
                <strong>How a wrong invoice is handled</strong>
                <ol>
                  <li>A salesperson reports the invoice, with the date, the reason, and an optional file.</li>
                  <li>An admin approves it. The invoice is cancelled, and the sales and ledger recording is reversed. It stays on the Invoices page.</li>
                  <li>If money was already received, finance reviews that collection.</li>
                  <li>An admin can reject the report. The invoice then stays as it is.</li>
                </ol>
              </div>
            ) : null}
          </div>
          <button
            type="button"
            className="exp-desk-btn exp-desk-btn-primary exp-desk-btn-create"
            onClick={openForm}
          >
            Report wrong invoice
          </button>
        </div>
      </div>

      {reverseReport ? createPortal(
        <div className="inv-wrong-modal" role="presentation">
          <button
            type="button"
            className="inv-wrong-modal-backdrop"
            aria-label="Close"
            onClick={() => { if (!reversing) setReverseReport(null); }}
          />
          <div className="inv-wrong-modal-card inv-wrong-confirm" role="dialog" aria-modal="true" aria-labelledby="inv-wrong-reverse-title">
            <h2 id="inv-wrong-reverse-title">
              <AlertCircle size={20} aria-hidden="true" />
              Reverse this invoice?
            </h2>
            <p>
              {reverseReport.invoice_number} will be cancelled. It leaves sales and receivables, and the ledger posting is reversed. The invoice stays on the invoice list as cancelled.
            </p>
            {reverseError ? <p className="inv-wrong-form-error" role="alert">{reverseError}</p> : null}
            <div className="inv-wrong-form-actions">
              <button type="button" className="exp-desk-btn exp-desk-btn-ghost" onClick={() => setReverseReport(null)} disabled={reversing}>
                Cancel
              </button>
              <button type="button" className="exp-desk-btn exp-desk-btn-primary" onClick={confirmReverse} disabled={reversing}>
                {reversing ? 'Reversing...' : 'Approve and reverse'}
              </button>
            </div>
          </div>
        </div>,
        document.body,
      ) : null}

      {preview ? createPortal(
        <div className="inv-wrong-modal inv-wrong-preview" role="presentation">
          <button type="button" className="inv-wrong-modal-backdrop" aria-label="Close" onClick={() => setPreview(null)} />
          <div className="inv-wrong-preview-card" role="dialog" aria-modal="true" aria-labelledby="inv-wrong-preview-title">
            <div className="inv-wrong-preview-head">
              <h2 id="inv-wrong-preview-title">{preview.invoice_number || 'Invoice'}</h2>
              <button type="button" className="inv-wrong-preview-close" aria-label="Close" onClick={() => setPreview(null)}>
                <X size={18} aria-hidden="true" />
              </button>
            </div>
            <div className="inv-wrong-preview-body">
              {previewLoading ? <p className="exp-desk-empty-sub">Loading invoice...</p> : null}
              {previewError ? <p className="inv-wrong-form-error" role="alert">{previewError}</p> : null}
              {previewData?.document_html ? (
                <InvoiceDocumentPane
                  html={previewData.document_html}
                  fontFamily={previewData.document_font_family || ''}
                />
              ) : null}
              {previewData?.catalog_html ? (
                <InvoiceDocumentPane
                  html={previewData.catalog_html}
                  fontFamily={previewData.document_font_family || ''}
                  className="ov-catalog-pane"
                />
              ) : null}
            </div>
          </div>
        </div>,
        document.body,
      ) : null}

      {formOpen ? createPortal(
        <div className="inv-wrong-modal" role="presentation">
          <button type="button" className="inv-wrong-modal-backdrop" aria-label="Close" onClick={closeForm} />
          <form className="inv-wrong-modal-card" onSubmit={submitReport} role="dialog" aria-modal="true" aria-labelledby="inv-wrong-form-title">
            <h2 id="inv-wrong-form-title">Report wrong invoice</h2>
            <label>
              Date
              <input type="date" value={reportedOn} onChange={(event) => setReportedOn(event.target.value)} required />
            </label>
            <div className="inv-wrong-field" ref={invoicePickRef}>
              <span>Invoice</span>
              <button
                type="button"
                className="inv-wrong-invoice-trigger"
                aria-expanded={invoiceMenuOpen}
                aria-haspopup="listbox"
                onClick={() => {
                  setInvoiceMenuOpen((open) => !open);
                  setInvoiceQuery('');
                }}
              >
                <span>{selectedInvoice ? invoiceLabel(selectedInvoice) : 'Select invoice'}</span>
                <ChevronDown size={16} aria-hidden="true" />
              </button>
              {invoiceMenuOpen ? (
                <div className="inv-wrong-invoice-menu">
                  <input
                    type="search"
                    value={invoiceQuery}
                    autoFocus
                    placeholder="Search invoice"
                    aria-label="Search invoice"
                    onChange={(event) => setInvoiceQuery(event.target.value)}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter') event.preventDefault();
                    }}
                  />
                  <ul role="listbox">
                    {invoiceOptions.length === 0 ? (
                      <li className="inv-wrong-invoice-empty">No matching invoice</li>
                    ) : invoiceOptions.map((invoice) => (
                      <li key={invoice.id}>
                        <button
                          type="button"
                          className={String(invoice.id) === String(invoiceId) ? 'is-selected' : ''}
                          onClick={() => {
                            setInvoiceId(String(invoice.id));
                            setInvoiceMenuOpen(false);
                          }}
                        >
                          {invoiceLabel(invoice)}
                        </button>
                      </li>
                    ))}
                  </ul>
                </div>
              ) : null}
            </div>
            <label>
              Reasons <span className="inv-wrong-required">*</span>
              <textarea
                ref={reasonRef}
                value={reason}
                onChange={(event) => {
                  setReason(event.target.value);
                  if (event.target.value.trim()) setReasonError('');
                }}
                maxLength={500}
                rows={4}
                placeholder="What is wrong with this invoice?"
                required
                aria-required="true"
                aria-invalid={reasonError ? 'true' : 'false'}
              />
              {reasonError ? <span className="inv-wrong-form-error" role="alert">{reasonError}</span> : null}
            </label>
            <div className="inv-wrong-field">
              <span>Attachment</span>
              <div
                className={`inv-wrong-drop${attachmentDrag ? ' is-drag' : ''}`}
                role="button"
                tabIndex={0}
                onClick={() => attachmentInputRef.current?.click()}
                onKeyDown={(event) => {
                  if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    attachmentInputRef.current?.click();
                  }
                }}
                onDragOver={(event) => {
                  event.preventDefault();
                  setAttachmentDrag(true);
                }}
                onDragLeave={() => setAttachmentDrag(false)}
                onDrop={(event) => {
                  event.preventDefault();
                  setAttachmentDrag(false);
                  chooseAttachment(event.dataTransfer.files?.[0]);
                }}
              >
                <span className="inv-wrong-drop-icon"><Upload size={18} aria-hidden="true" /></span>
                <strong>Choose a file or drag and drop it here.</strong>
                <small>JPEG, PNG, WEBP, and PDF, up to 5 MB.</small>
                <input
                  ref={attachmentInputRef}
                  type="file"
                  accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*"
                  onChange={(event) => {
                    chooseAttachment(event.target.files?.[0]);
                    event.target.value = '';
                  }}
                />
              </div>
              {attachment ? (
                <div className="inv-wrong-file">
                  <span className={`inv-wrong-file-kind inv-wrong-file-kind--${fileKind(attachment.name).toLowerCase()}`}>
                    {fileKind(attachment.name)}
                  </span>
                  <span className="inv-wrong-file-body">
                    <strong>{attachment.name}</strong>
                    <small>
                      {saving
                        ? `${formatFileSize(attachment.size)} - Uploading...`
                        : `${formatFileSize(attachment.size)} - Ready`}
                      {saving ? null : <Check size={12} aria-hidden="true" />}
                    </small>
                    <span className={`inv-wrong-file-bar${saving ? ' is-busy' : ' is-done'}`} />
                  </span>
                  <button
                    type="button"
                    className="inv-wrong-file-remove"
                    aria-label="Remove attachment"
                    disabled={saving}
                    onClick={() => setAttachment(null)}
                  >
                    <Trash2 size={16} aria-hidden="true" />
                  </button>
                </div>
              ) : null}
            </div>
            {formError ? <p className="inv-wrong-form-error" role="alert">{formError}</p> : null}
            <div className="inv-wrong-form-actions">
              <button type="button" className="exp-desk-btn exp-desk-btn-ghost" onClick={closeForm} disabled={saving}>
                Cancel
              </button>
              <button type="submit" className="exp-desk-btn exp-desk-btn-primary" disabled={saving}>
                {saving ? 'Reporting...' : 'Report'}
              </button>
            </div>
          </form>
        </div>,
        document.body,
      ) : null}

      <section className="exp-desk-results inv-corrections" aria-label="Wrong invoices">
        {loading ? (
          <p className="exp-desk-empty-sub">Loading reports...</p>
        ) : error ? (
          <div className="exp-desk-empty">
            <AlertCircle className="exp-desk-empty-icon" aria-hidden="true" />
            <p className="exp-desk-empty-title">Could not load reports</p>
            <p className="exp-desk-empty-sub">{error}</p>
          </div>
        ) : reports.length === 0 ? (
          <div className="exp-desk-empty">
            <AlertCircle className="exp-desk-empty-icon" aria-hidden="true" />
            <p className="exp-desk-empty-title">No reports</p>
            <p className="exp-desk-empty-sub">Use Report wrong invoice to send one here.</p>
          </div>
        ) : (
          <div className="exp-desk-table-wrap">
            <table className="exp-desk-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Invoice</th>
                  <th>Customer</th>
                  <th>Reported by</th>
                  <th>Reasons</th>
                  <th>Status</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {reports.map((report) => (
                  <tr key={report.id}>
                    <td>{report.reported_on || '-'}</td>
                    <td>
                      <button type="button" className="exp-desk-ref inv-wrong-invoice-link" onClick={() => openInvoicePreview(report)}>
                        {report.invoice_number}
                      </button>
                      <div className="exp-desk-subdate">{formatCurrency(report.total_amount)}</div>
                    </td>
                    <td>{report.customer_name || '-'}</td>
                    <td>{report.reported_by_name || '-'}</td>
                    <td>
                      <div>{report.reason}</div>
                      {report.attachment ? (
                        <a href={report.attachment} target="_blank" rel="noopener noreferrer">Attachment</a>
                      ) : null}
                    </td>
                    <td><span className={`inv-report-status inv-report-status--${report.status}`}>{report.status}</span></td>
                    <td>
                      {isAdmin && report.status === 'pending' ? (
                        <span className="inv-report-actions">
                          <button type="button" className="inv-report-icon inv-report-icon--approve" title="Approve and reverse" aria-label="Approve and reverse" onClick={() => decide(report, 'approve')}>
                            <Check size={16} aria-hidden="true" />
                          </button>
                          <button type="button" className="inv-report-icon inv-report-icon--reject" title="Reject" aria-label="Reject" onClick={() => decide(report, 'reject')}>
                            <X size={16} aria-hidden="true" />
                          </button>
                        </span>
                      ) : (
                        report.review_note || ''
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
