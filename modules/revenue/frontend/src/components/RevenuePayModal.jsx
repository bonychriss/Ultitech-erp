import { useEffect, useMemo, useRef, useState } from 'react';
import { Loader2, Upload, Wallet, X } from 'lucide-react';
import { fetchPaymentInit, submitPayment } from '../api/revenueDesk';

function depositBucketForPayment(method) {
  const m = String(method || '').toLowerCase();
  if (m.includes('cash')) return 'cash';
  if (m.includes('mobile')) return 'mobile';
  return 'bank';
}

export default function RevenuePayModal({ entryId, onClose, onSuccess }) {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [init, setInit] = useState(null);
  const [collectionDate, setCollectionDate] = useState('');
  const [paymentMethod, setPaymentMethod] = useState('');
  const [payerName, setPayerName] = useState('');
  const [referenceNumber, setReferenceNumber] = useState('');
  const [accountId, setAccountId] = useState('');
  const [currency, setCurrency] = useState('TZS');
  const [amount, setAmount] = useState('');
  const [paymentNotes, setPaymentNotes] = useState('');
  const [attachment, setAttachment] = useState(null);
  const fileInputRef = useRef(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError('');
      try {
        const data = await fetchPaymentInit(entryId);
        if (cancelled) return;
        setInit(data);
        const entry = data.entry || {};
        setCollectionDate(entry.collection_date || new Date().toISOString().slice(0, 10));
        setPaymentMethod('');
        setReferenceNumber(entry.default_reference || '');
        setPayerName(entry.customer_name || '');
        setAmount(String(entry.default_amount ?? entry.amount_due ?? ''));
        setPaymentNotes('');
        setCurrency((entry.currencies || [{ code: 'TZS' }])[0]?.code || 'TZS');
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Could not load payment form.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [entryId]);

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function onKeyDown(event) {
      if (event.key === 'Escape') onClose();
    }
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [onClose]);

  const entry = init?.entry || {};
  const maxAmount = Number(entry.amount_due) || 0;
  const depositBucket = depositBucketForPayment(paymentMethod);

  const filteredAccounts = useMemo(() => {
    if (!paymentMethod) return [];
    const accounts = init?.accounts || [];
    const byBucket = accounts.filter((acc) => acc.bucket === depositBucket);
    const list = byBucket.length ? byBucket : accounts;
    if (!currency) return list;
    const byCurrency = list.filter((acc) => String(acc.currency || 'TZS').toUpperCase() === currency);
    return byCurrency.length ? byCurrency : list;
  }, [init, depositBucket, currency, paymentMethod]);

  useEffect(() => {
    if (!paymentMethod) {
      setAccountId('');
      return;
    }
    if (!filteredAccounts.length) {
      setAccountId('');
      return;
    }
    const stillValid = filteredAccounts.some((acc) => String(acc.id) === String(accountId));
    if (!stillValid) {
      setAccountId('');
    }
  }, [filteredAccounts, accountId, paymentMethod]);

  const enteredAmount = Number(amount);
  const amountMeetsRequired = Number.isFinite(enteredAmount) && enteredAmount >= maxAmount - 0.009;
  const amountColor = amountMeetsRequired ? '#16a34a' : '#dc2626';

  async function handleSubmit(event) {
    event.preventDefault();
    setSaving(true);
    setError('');

    const payAmount = Number(amount);
    if (!paymentMethod || !accountId || !payAmount || payAmount <= 0) {
      setError('Please complete all required payment fields.');
      setSaving(false);
      return;
    }
    if (payAmount > maxAmount + 0.009) {
      setError('Amount cannot exceed the balance due.');
      setSaving(false);
      return;
    }
    if (!attachment) {
      setError('Upload SWIFT or bank payment proof before confirming.');
      setSaving(false);
      return;
    }

    try {
      const formData = new FormData();
      formData.set('entry_id', String(entryId));
      formData.set('collection_date', collectionDate);
      formData.set('payment_method', paymentMethod);
      formData.set('reference_number', referenceNumber);
      formData.set('payer_name', payerName || entry.customer_name || 'Customer');
      formData.set('account_id', accountId);
      formData.set('currency', currency);
      formData.set('amount_collected', amount);
      formData.set('payment_notes', paymentNotes);
      formData.set('internal_note', '');
      formData.set('payment_attachment', attachment);

      const result = await submitPayment(formData);
      onSuccess(result);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Payment failed.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="rev-pay-overlay" role="presentation" onClick={onClose}>
      <div
        className="rev-pay-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="rev-pay-title"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="rev-pay-head">
          <div>
            <h2 id="rev-pay-title">Record payment</h2>
            <p className="rev-pay-sub">
              {entry.voucher_number || `#${entryId}`}
              {entry.customer_name ? ` · ${entry.customer_name}` : ''}
            </p>
          </div>
          <button type="button" className="rev-pay-close" onClick={onClose} aria-label="Close">
            <X size={18} />
          </button>
        </div>

        {loading ? (
          <div className="rev-pay-loading">
            <Loader2 size={22} className="rev-pay-spin" aria-hidden="true" />
            <span>Loading…</span>
          </div>
        ) : (
          <form className="rev-pay-form" onSubmit={handleSubmit}>
            <div className="rev-pay-body">
              <section className="rev-pay-section">
                <h3 className="rev-pay-section-title">Payment details</h3>
                <div className="rev-pay-form-grid">
                  <div className="rev-pay-field">
                    <label htmlFor="rev-pay-date">Payment date *</label>
                    <input
                      id="rev-pay-date"
                      type="date"
                      value={collectionDate}
                      onChange={(e) => setCollectionDate(e.target.value)}
                      required
                    />
                  </div>

                  <div className="rev-pay-field">
                    <label htmlFor="rev-pay-method">Payment method *</label>
                    <select
                      id="rev-pay-method"
                      value={paymentMethod}
                      onChange={(e) => {
                        setPaymentMethod(e.target.value);
                        setAccountId('');
                      }}
                      required
                    >
                      <option value="">Select method</option>
                      {(init?.payment_methods || []).map((method) => (
                        <option key={method} value={method}>{method}</option>
                      ))}
                    </select>
                  </div>

                  <div className="rev-pay-field">
                    <label htmlFor="rev-pay-account">Payment account *</label>
                    <select
                      id="rev-pay-account"
                      value={accountId}
                      onChange={(e) => setAccountId(e.target.value)}
                      required
                      disabled={!paymentMethod}
                    >
                      <option value="">
                        {paymentMethod ? 'Select account' : 'Select payment method first'}
                      </option>
                      {filteredAccounts.map((acc) => (
                        <option key={acc.id} value={acc.id}>
                          {acc.name}{acc.currency ? ` (${acc.currency})` : ''}
                        </option>
                      ))}
                    </select>
                  </div>

                  <div className="rev-pay-field">
                    <label htmlFor="rev-pay-amount">amount *</label>
                    <input
                      id="rev-pay-amount"
                      type="number"
                      min="0.01"
                      step="0.01"
                      max={maxAmount > 0 ? maxAmount : undefined}
                      value={amount}
                      onChange={(e) => setAmount(e.target.value)}
                      className={amountMeetsRequired ? 'rev-pay-input--met' : 'rev-pay-input--under'}
                      style={{ color: amountColor, WebkitTextFillColor: amountColor, fontWeight: 600 }}
                      required
                    />
                  </div>

                  <div className="rev-pay-field">
                    <label htmlFor="rev-pay-notes">Notes</label>
                    <input
                      id="rev-pay-notes"
                      type="text"
                      value={paymentNotes}
                      onChange={(e) => setPaymentNotes(e.target.value)}
                      placeholder="Optional"
                    />
                  </div>
                </div>
              </section>

              <section className="rev-pay-section">
                <h3 className="rev-pay-section-title">Payment proof</h3>
                <div className="rev-pay-field rev-pay-field--full">
                  <label htmlFor="rev-pay-proof">SWIFT / bank slip *</label>
                  <button
                    type="button"
                    className={`rev-pay-upload${attachment ? ' has-file' : ''}`}
                    onClick={() => fileInputRef.current?.click()}
                  >
                    <Upload className="rev-pay-upload-icon" size={18} aria-hidden="true" />
                    <span className="rev-pay-upload-text">
                      {attachment ? attachment.name : 'Click to choose PDF, image, or document'}
                    </span>
                    <span className="rev-pay-upload-action">
                      {attachment ? 'Change file' : 'Browse'}
                    </span>
                  </button>
                  <input
                    ref={fileInputRef}
                    id="rev-pay-proof"
                    type="file"
                    className="rev-pay-upload-input"
                    accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,image/*,application/pdf"
                    onChange={(e) => setAttachment(e.target.files?.[0] || null)}
                    required
                  />
                </div>
              </section>

              {error ? (
                <div className="rev-pay-alert" role="alert">{error}</div>
              ) : null}
            </div>

            <div className="rev-pay-actions">
              <button type="button" className="rev-pay-btn rev-pay-btn--ghost" onClick={onClose} disabled={saving}>
                Cancel
              </button>
              <button type="submit" className="rev-pay-btn rev-pay-btn--primary" disabled={saving}>
                {saving ? (
                  <Loader2 size={16} className="rev-pay-spin" aria-hidden="true" />
                ) : (
                  <Wallet size={16} aria-hidden="true" />
                )}
                {saving ? 'Posting…' : 'Confirm payment'}
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
}
