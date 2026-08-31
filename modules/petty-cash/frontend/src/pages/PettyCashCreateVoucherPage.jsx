import { useEffect, useRef, useState } from 'react';
import { CheckCircle2, FileText, Loader2, Trash2, UploadCloud, XCircle } from 'lucide-react';
import { deskPageUrl, fetchCreateInit, submitCreateVoucher } from '../api/pettyCashDesk.js';
import { formatMoney, todayIso } from '../utils/format.js';

const RECEIPT_ACCEPT = 'image/jpeg,image/png,image/gif,image/webp,application/pdf,.jpg,.jpeg,.png,.gif,.webp,.pdf';
const RECEIPT_MAX_BYTES = 5 * 1024 * 1024;

function formatFileSize(bytes) {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function validateReceiptFile(file) {
  if (!file) return { ok: false, message: 'No file selected.' };
  const name = (file.name || '').toLowerCase();
  const type = (file.type || '').toLowerCase();
  const isImage = type.startsWith('image/') || /\.(jpe?g|png|gif|webp)$/i.test(name);
  const isPdf = type === 'application/pdf' || name.endsWith('.pdf');
  if (!isImage && !isPdf) {
    return { ok: false, message: 'Only JPG, PNG, GIF, WEBP, or PDF files are allowed.' };
  }
  if (file.size > RECEIPT_MAX_BYTES) {
    return { ok: false, message: `File is too large. Maximum size is ${formatFileSize(RECEIPT_MAX_BYTES)}.` };
  }
  return { ok: true, message: 'Receipt ready to attach.', isPdf };
}

export default function PettyCashCreateVoucherPage() {
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [receiptPreviewUrl, setReceiptPreviewUrl] = useState('');
  const [receiptStatus, setReceiptStatus] = useState({ ok: false, message: '' });
  const [dragOver, setDragOver] = useState(false);
  const receiptInputRef = useRef(null);
  const [form, setForm] = useState({
    date: todayIso(),
    petty_cash_account_id: '',
    expense_account_id: '',
    amount: '',
    description: '',
    receipt: null,
  });

  useEffect(() => {
    fetchCreateInit()
      .then((data) => {
        setInit(data);
        setForm((current) => ({
          ...current,
          petty_cash_account_id: data.default_petty_cash_account_id
            ? String(data.default_petty_cash_account_id)
            : '',
        }));
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    return () => {
      if (receiptPreviewUrl) URL.revokeObjectURL(receiptPreviewUrl);
    };
  }, [receiptPreviewUrl]);

  function clearReceipt() {
    if (receiptPreviewUrl) URL.revokeObjectURL(receiptPreviewUrl);
    setReceiptPreviewUrl('');
    setReceiptStatus({ ok: false, message: '' });
    setForm((f) => ({ ...f, receipt: null }));
    if (receiptInputRef.current) receiptInputRef.current.value = '';
  }

  function applyReceiptFile(file) {
    if (!file) {
      clearReceipt();
      return;
    }
    const result = validateReceiptFile(file);
    if (!result.ok) {
      if (receiptPreviewUrl) URL.revokeObjectURL(receiptPreviewUrl);
      setReceiptPreviewUrl('');
      setForm((f) => ({ ...f, receipt: null }));
      setReceiptStatus(result);
      if (receiptInputRef.current) receiptInputRef.current.value = '';
      return;
    }
    if (receiptPreviewUrl) URL.revokeObjectURL(receiptPreviewUrl);
    const nextUrl = result.isPdf ? '' : URL.createObjectURL(file);
    setReceiptPreviewUrl(nextUrl);
    setReceiptStatus(result);
    setForm((f) => ({ ...f, receipt: file }));
  }

  const mainAccount = init?.petty_cash_account || null;
  const categoryOptions = init?.category_accounts
    || (init?.category_accounts_by_parent?.[String(form.petty_cash_account_id)] || []);
  const hasMainAccount = Boolean(form.petty_cash_account_id);
  const createSubHref = init?.create_sub_account_url || init?.balances_url || '#';

  async function onSubmit(event) {
    event.preventDefault();
    if (receiptStatus.message && !receiptStatus.ok) {
      setError(receiptStatus.message);
      return;
    }
    setBusy(true);
    setError('');
    try {
      const fd = new FormData();
      fd.append('date', form.date);
      fd.append('petty_cash_account_id', form.petty_cash_account_id);
      fd.append('expense_account_id', form.expense_account_id);
      fd.append('amount', form.amount);
      fd.append('description', form.description);
      if (form.receipt) fd.append('receipt', form.receipt);
      const result = await submitCreateVoucher(fd);
      if (result.redirect) window.location.href = result.redirect;
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not create voucher.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <div className="exp-create-loading">
        <Loader2 size={22} className="exp-create-spinner" aria-hidden />
        <span>Loading form...</span>
      </div>
    );
  }

  return (
    <div className="exp-create-shell">
      <div className="exp-create-topbar">
        <a href={deskPageUrl('index.php')} className="exp-desk-action-link" style={{ fontSize: '0.8125rem' }}>
          Back to dashboard
        </a>
      </div>

      {error ? (
        <div className="exp-create-alert exp-create-alert--error" role="alert">
          {error}
        </div>
      ) : null}

      <form className="exp-create-layout" onSubmit={onSubmit}>
        <div className="exp-create-main">
          <section className="exp-create-section">
            <div className="exp-create-section-header">
              <h2>Voucher details</h2>
              <p>Custodian float: {formatMoney(init?.balance ?? 0)}</p>
            </div>

            <div className="exp-create-row">
              <label className="exp-create-label">Petty cash account</label>
              <div>
                <input
                  type="text"
                  className="exp-create-input exp-create-input--readonly"
                  readOnly
                  value={
                    mainAccount
                      ? `${mainAccount.label || mainAccount.name} (${formatMoney(mainAccount.balance)})`
                      : 'Petty Cash (not found in Balances)'
                  }
                />
                <div className="exp-create-help">
                  Default Balances account — same idea as Revenue. Add category sub-accounts under it.
                </div>
              </div>
            </div>

            <div className="exp-create-row">
              <label className="exp-create-label" htmlFor="pc-voucher-category">
                Category sub-account <span className="req">*</span>
              </label>
              <div>
                <select
                  id="pc-voucher-category"
                  required
                  className="exp-create-select"
                  value={form.expense_account_id}
                  onChange={(e) => setForm((f) => ({ ...f, expense_account_id: e.target.value }))}
                  disabled={!hasMainAccount || categoryOptions.length === 0}
                >
                  <option value="">
                    {!hasMainAccount
                      ? 'Petty Cash account missing'
                      : categoryOptions.length === 0
                        ? 'No sub-accounts yet'
                        : 'Select sub-account (e.g. Fuel, Transport)'}
                  </option>
                  {categoryOptions.map((acc) => (
                    <option key={acc.id} value={String(acc.id)}>
                      {acc.label || acc.name}
                    </option>
                  ))}
                </select>
                <div className="exp-create-help">
                  {categoryOptions.length === 0 ? (
                    <>
                      Create Fuel, Transport, and other categories under Petty Cash in{' '}
                      <a href={createSubHref}>Balances</a>.
                    </>
                  ) : (
                    <>
                      Or add more categories under Petty Cash in{' '}
                      <a href={createSubHref}>Balances</a>.
                    </>
                  )}
                </div>
              </div>
            </div>

            <div className="exp-create-row">
              <label className="exp-create-label" htmlFor="pc-voucher-date">
                Date <span className="req">*</span>
              </label>
              <div>
                <input
                  id="pc-voucher-date"
                  type="date"
                  required
                  className="exp-create-input"
                  value={form.date}
                  onChange={(e) => setForm((f) => ({ ...f, date: e.target.value }))}
                />
              </div>
            </div>

            <div className="exp-create-row">
              <label className="exp-create-label" htmlFor="pc-voucher-amount">
                Amount (TZS) <span className="req">*</span>
              </label>
              <div>
                <input
                  id="pc-voucher-amount"
                  type="number"
                  min="0"
                  step="0.01"
                  required
                  className="exp-create-input exp-create-input--price"
                  value={form.amount}
                  onChange={(e) => setForm((f) => ({ ...f, amount: e.target.value }))}
                />
              </div>
            </div>

            <div className="exp-create-row">
              <label className="exp-create-label" htmlFor="pc-voucher-description">
                Description <span className="req">*</span>
              </label>
              <div>
                <textarea
                  id="pc-voucher-description"
                  required
                  rows={3}
                  className="exp-create-textarea"
                  value={form.description}
                  onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
                />
              </div>
            </div>
          </section>

          <section className="exp-create-section" id="voucher-receipt">
            <div className="exp-create-section-header">
              <h2>Receipt</h2>
              <p>Optional image or PDF — max {formatFileSize(RECEIPT_MAX_BYTES)}.</p>
            </div>

            <div className="pc-receipt-upload">
              <input
                ref={receiptInputRef}
                id="pc-voucher-receipt"
                type="file"
                accept={RECEIPT_ACCEPT}
                className="pc-receipt-upload__input"
                onChange={(e) => applyReceiptFile(e.target.files?.[0] || null)}
              />

              {!form.receipt ? (
                <label
                  htmlFor="pc-voucher-receipt"
                  className={`pc-receipt-dropzone${dragOver ? ' is-dragover' : ''}${receiptStatus.message && !receiptStatus.ok ? ' is-error' : ''}`}
                  onDragEnter={(e) => {
                    e.preventDefault();
                    setDragOver(true);
                  }}
                  onDragOver={(e) => {
                    e.preventDefault();
                    setDragOver(true);
                  }}
                  onDragLeave={(e) => {
                    e.preventDefault();
                    setDragOver(false);
                  }}
                  onDrop={(e) => {
                    e.preventDefault();
                    setDragOver(false);
                    applyReceiptFile(e.dataTransfer.files?.[0] || null);
                  }}
                >
                  <span className="pc-receipt-dropzone__icon" aria-hidden>
                    <UploadCloud size={28} strokeWidth={1.75} />
                  </span>
                  <span className="pc-receipt-dropzone__title">Drop receipt here, or browse</span>
                  <span className="pc-receipt-dropzone__hint">JPG, PNG, GIF, WEBP, or PDF</span>
                </label>
              ) : (
                <div className="pc-receipt-preview" role="status">
                  <div className="pc-receipt-preview__media">
                    {receiptPreviewUrl ? (
                      <img src={receiptPreviewUrl} alt="Receipt preview" />
                    ) : (
                      <div className="pc-receipt-preview__pdf">
                        <FileText size={36} strokeWidth={1.6} aria-hidden />
                        <span>PDF</span>
                      </div>
                    )}
                  </div>
                  <div className="pc-receipt-preview__meta">
                    <div className="pc-receipt-preview__name" title={form.receipt.name}>
                      {form.receipt.name}
                    </div>
                    <div className="pc-receipt-preview__size">{formatFileSize(form.receipt.size)}</div>
                    <div className="pc-receipt-preview__status pc-receipt-preview__status--ok">
                      <CheckCircle2 size={15} aria-hidden />
                      {receiptStatus.message || 'Receipt ready to attach.'}
                    </div>
                    <div className="pc-receipt-preview__actions">
                      <button
                        type="button"
                        className="pc-receipt-btn pc-receipt-btn--ghost"
                        onClick={() => receiptInputRef.current?.click()}
                      >
                        Replace
                      </button>
                      <button
                        type="button"
                        className="pc-receipt-btn pc-receipt-btn--danger"
                        onClick={clearReceipt}
                      >
                        <Trash2 size={14} aria-hidden />
                        Remove
                      </button>
                    </div>
                  </div>
                </div>
              )}

              {receiptStatus.message && !receiptStatus.ok ? (
                <div className="pc-receipt-feedback pc-receipt-feedback--error" role="alert">
                  <XCircle size={16} aria-hidden />
                  <span>{receiptStatus.message}</span>
                </div>
              ) : null}
            </div>
          </section>

          <div className="exp-create-actions">
            <a href={deskPageUrl('index.php')} className="exp-create-btn-cancel">Cancel</a>
            <button
              type="submit"
              className="exp-create-btn-save"
              disabled={busy || !hasMainAccount || categoryOptions.length === 0}
            >
              {busy ? (
                <>
                  <Loader2 size={18} className="exp-create-spinner" aria-hidden />
                  Saving...
                </>
              ) : (
                'Submit voucher'
              )}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
}
