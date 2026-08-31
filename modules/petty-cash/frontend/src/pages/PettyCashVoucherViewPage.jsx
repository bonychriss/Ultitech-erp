import { useEffect, useState } from 'react';
import { Check, Download, ExternalLink, FileText, Image as ImageIcon, Loader2, X } from 'lucide-react';
import PettyCashStatusBadge from '../components/PettyCashStatusBadge.jsx';
import PettyCashConfirmModal from '../components/PettyCashConfirmModal.jsx';
import { deskPageUrl, fetchVoucherView, postVoucherAction } from '../api/pettyCashDesk.js';
import { formatDate, formatMoney, getCfg } from '../utils/format.js';

function isPdfUrl(url) {
  return /\.pdf($|\?)/i.test(String(url || ''));
}

function receiptFileName(url, voucherNo) {
  try {
    const path = String(url || '').split('?')[0];
    const base = path.split('/').pop() || '';
    if (base) return decodeURIComponent(base);
  } catch {
    /* ignore */
  }
  return `receipt-${voucherNo || 'voucher'}${isPdfUrl(url) ? '.pdf' : ''}`;
}

function FormField({ label, value }) {
  return (
    <div className="pc-pcv-field">
      <span className="pc-pcv-field__label">{label}:</span>
      <span className="pc-pcv-field__value">{value || ''}</span>
    </div>
  );
}

export default function PettyCashVoucherViewPage() {
  const voucherId = parseInt(String(getCfg().voucher_id || new URLSearchParams(window.location.search).get('id') || 0), 10);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [busyAction, setBusyAction] = useState('');
  const [approveOpen, setApproveOpen] = useState(false);

  useEffect(() => {
    if (voucherId <= 0) {
      setError('Invalid voucher id.');
      setLoading(false);
      return;
    }
    fetchVoucherView(voucherId)
      .then(setData)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [voucherId]);

  async function runAction(action) {
    if (!data?.can_manage) return;
    if (action === 'approve_voucher') {
      setApproveOpen(true);
      return;
    }
    if (action === 'cancel_voucher' && !window.confirm('Cancel this voucher?')) return;

    let reason = '';
    if (action === 'reject_voucher') {
      const prompted = window.prompt('Rejection reason (optional):');
      if (prompted === null) return;
      reason = prompted;
    }

    setBusy(true);
    setBusyAction(action);
    setError('');
    try {
      if (action === 'reject_voucher') {
        await postVoucherAction(action, voucherId, { reason });
      } else {
        await postVoucherAction(action, voucherId);
      }
      const refreshed = await fetchVoucherView(voucherId);
      setData(refreshed);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Action failed.');
    } finally {
      setBusy(false);
      setBusyAction('');
    }
  }

  async function confirmApproveVoucher() {
    setBusy(true);
    setBusyAction('approve_voucher');
    setError('');
    try {
      await postVoucherAction('approve_voucher', voucherId);
      setApproveOpen(false);
      const refreshed = await fetchVoucherView(voucherId);
      setData(refreshed);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Action failed.');
    } finally {
      setBusy(false);
      setBusyAction('');
    }
  }

  if (loading) {
    return (
      <div className="exp-desk-boot-loading">
        <Loader2 className="exp-desk-boot-spinner" aria-hidden="true" />
        <span>Loading voucher...</span>
      </div>
    );
  }

  const v = data?.voucher;
  if (!v) {
    return <div className="exp-desk-flash exp-desk-flash-error">{error || 'Voucher not found.'}</div>;
  }

  const canAct = Boolean(data?.can_manage && v.status === 'pending');
  const receiptIsPdf = isPdfUrl(v.receipt_url);
  const categoryLabel = v.category_label || v.expense_account_name || v.category || '';
  const voucherNo = v.voucher_number || String(v.id);
  const isRejected = String(v.status || '').toLowerCase() === 'rejected';
  const approvedLabel = isRejected ? 'Rejected by' : 'Approved by';
  const approvedName = v.approved_by_name || '';
  const approvedSigUrl = !isRejected ? (v.approved_by_signature_url || '') : '';
  const receivedSigUrl = v.custodian_signature_url || '';

  return (
    <div className="pc-voucher-view">
      <PettyCashConfirmModal
        open={approveOpen}
        title="Approve voucher"
        message={
          v.voucher_number
            ? `Approve ${v.voucher_number}? It will deduct float and post to Balances.`
            : 'Approve this voucher? It will deduct float and post to Balances.'
        }
        confirmLabel="Approve"
        cancelLabel="Cancel"
        busy={busy && busyAction === 'approve_voucher'}
        onClose={() => {
          if (!(busy && busyAction === 'approve_voucher')) setApproveOpen(false);
        }}
        onConfirm={confirmApproveVoucher}
      />
      <div className="pc-voucher-view__topbar">
        <a href={deskPageUrl('vouchers/index.php')} className="exp-desk-action-link" style={{ fontSize: '0.8125rem' }}>
          All vouchers
        </a>
        <div className="pc-voucher-view__topbar-right">
          <PettyCashStatusBadge status={v.status} isPosted={v.is_posted} />
          <a
            href={deskPageUrl('index.php')}
            className="exp-desk-action-link"
            style={{ fontSize: '0.8125rem' }}
            onClick={(e) => {
              e.preventDefault();
              const href = deskPageUrl('index.php');
              if (window.erpNavBack && typeof window.erpNavBack.go === 'function') {
                if (window.erpNavBack.go(href)) return;
              }
              window.location.href = href;
            }}
          >
            Back
          </a>
        </div>
      </div>

      {error ? (
        <div className="exp-desk-flash exp-desk-flash-error" role="alert">
          {error}
        </div>
      ) : null}

      {canAct ? (
        <div className="pc-pcv-toolbar">
          <button
            type="button"
            className="exp-desk-btn exp-desk-btn-primary"
            disabled={busy}
            onClick={() => runAction('approve_voucher')}
          >
            {busy && busyAction === 'approve_voucher' ? (
              <Loader2 size={16} className="exp-create-spinner" aria-hidden />
            ) : (
              <Check size={16} aria-hidden />
            )}
            Approve
          </button>
          <button
            type="button"
            className="exp-desk-btn exp-desk-btn-secondary"
            disabled={busy}
            onClick={() => runAction('reject_voucher')}
          >
            {busy && busyAction === 'reject_voucher' ? (
              <Loader2 size={16} className="exp-create-spinner" aria-hidden />
            ) : (
              <X size={16} aria-hidden />
            )}
            Reject
          </button>
          <button
            type="button"
            className="exp-desk-btn exp-desk-btn-secondary"
            disabled={busy}
            onClick={() => runAction('cancel_voucher')}
          >
            {busy && busyAction === 'cancel_voucher' ? (
              <Loader2 size={16} className="exp-create-spinner" aria-hidden />
            ) : null}
            Cancel voucher
          </button>
        </div>
      ) : null}

      <article className="pc-pcv-form" aria-label="Petty cash voucher">
        <header className="pc-pcv-form__header">
          <div className="pc-pcv-form__header-col">
            <FormField label="Custodian" value={v.custodian_name} />
            <FormField label="Category" value={categoryLabel} />
            {v.petty_cash_account_name ? (
              <FormField label="Petty cash account" value={v.petty_cash_account_name} />
            ) : null}
          </div>
          <div className="pc-pcv-form__header-col pc-pcv-form__header-col--right">
            <FormField label="Voucher No" value={voucherNo} />
            <FormField label="Date" value={formatDate(v.date)} />
          </div>
        </header>

        <table className="pc-pcv-table">
          <thead>
            <tr>
              <th scope="col" className="pc-pcv-table__particulars">Particulars</th>
              <th scope="col" className="pc-pcv-table__amount">Amount</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td className="pc-pcv-table__particulars">
                <div className="pc-pcv-particulars">
                  <div className="pc-pcv-particulars__text">
                    {v.description || ''}
                  </div>
                  {v.rejection_reason ? (
                    <div className="pc-pcv-particulars__note">
                      Rejection reason: {v.rejection_reason}
                    </div>
                  ) : null}
                </div>
              </td>
              <td className="pc-pcv-table__amount">
                <div className="pc-pcv-amount">{formatMoney(v.amount)}</div>
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <td className="pc-pcv-sign pc-pcv-sign--approved">
                <div className="pc-pcv-sign__label">{approvedLabel}:</div>
                <div className="pc-pcv-sign__pad">
                  {approvedSigUrl ? (
                    <img
                      src={approvedSigUrl}
                      alt=""
                      className="pc-pcv-sign__image"
                    />
                  ) : (
                    <div className="pc-pcv-sign__placeholder" />
                  )}
                </div>
                <div className="pc-pcv-sign__value">{approvedName || '—'}</div>
                {v.approved_at && v.approved_by_name ? (
                  <div className="pc-pcv-sign__meta">{formatDate(v.approved_at)}</div>
                ) : null}
              </td>
              <td className="pc-pcv-sign pc-pcv-sign--received">
                <div className="pc-pcv-sign__label">Received by:</div>
                <div className="pc-pcv-sign__pad">
                  {receivedSigUrl ? (
                    <img
                      src={receivedSigUrl}
                      alt=""
                      className="pc-pcv-sign__image"
                    />
                  ) : (
                    <div className="pc-pcv-sign__placeholder" />
                  )}
                </div>
                <div className="pc-pcv-sign__value">{v.custodian_name || '—'}</div>
                {v.created_by_name && v.created_by_name !== v.custodian_name ? (
                  <div className="pc-pcv-sign__meta">Prepared by {v.created_by_name}</div>
                ) : null}
              </td>
            </tr>
          </tfoot>
        </table>
      </article>

      <section className="pc-pcv-attachment" aria-label="Receipt">
        {v.receipt_url ? (
          <div className="pc-receipt-chip">
            <div className="pc-receipt-chip__thumb" aria-hidden>
              {receiptIsPdf ? (
                <FileText size={22} strokeWidth={1.7} />
              ) : (
                <img src={v.receipt_url} alt="" />
              )}
            </div>
            <div className="pc-receipt-chip__meta">
              <div className="pc-receipt-chip__title">Receipt</div>
              <div className="pc-receipt-chip__name" title={receiptFileName(v.receipt_url, voucherNo)}>
                {receiptIsPdf ? (
                  <>
                    <FileText size={12} aria-hidden />
                    PDF file
                  </>
                ) : (
                  <>
                    <ImageIcon size={12} aria-hidden />
                    Image file
                  </>
                )}
              </div>
            </div>
            <div className="pc-receipt-chip__actions">
              <a
                href={v.receipt_url}
                className="pc-receipt-chip__btn"
                target="_blank"
                rel="noreferrer"
              >
                <ExternalLink size={14} aria-hidden />
                View
              </a>
              <a
                href={v.receipt_url}
                className="pc-receipt-chip__btn pc-receipt-chip__btn--primary"
                download={receiptFileName(v.receipt_url, voucherNo)}
              >
                <Download size={14} aria-hidden />
                Download
              </a>
            </div>
          </div>
        ) : (
          <div className="pc-receipt-chip pc-receipt-chip--empty">
            <div className="pc-receipt-chip__thumb" aria-hidden>
              <FileText size={20} strokeWidth={1.6} />
            </div>
            <div className="pc-receipt-chip__meta">
              <div className="pc-receipt-chip__title">Receipt</div>
              <div className="pc-receipt-chip__name">No receipt attached</div>
            </div>
          </div>
        )}
      </section>
    </div>
  );
}
