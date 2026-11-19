import { useEffect, useMemo, useRef, useState } from 'react';
import { ChevronDown, Loader2, Upload, Wallet, X } from 'lucide-react';
import { fetchRevenuePaymentInit, submitRevenuePayment } from '../api/invoiceViewDesk.js';

function depositBucketForPayment(method) {
  const m = String(method || '').toLowerCase();
  if (m.includes('cash')) return 'cash';
  if (m.includes('mobile')) return 'mobile';
  return 'bank';
}

function money(value) {
  const n = Number(value || 0);
  if (!Number.isFinite(n)) return '0.00';
  return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function accountInitial(name) {
  const cleaned = String(name || '').replace(/^\s*\d+\s*-\s*/, '').trim();
  return (cleaned.charAt(0) || '?').toUpperCase();
}

function AccountLogo({ account, size = 22 }) {
  const logo = String(account?.logo_url || '').trim();
  const [broken, setBroken] = useState(false);
  if (!logo || broken) {
    return (
      <span className="rev-pay-acc-logo rev-pay-acc-logo--initial" aria-hidden="true" style={{ width: size, height: size }}>
        {accountInitial(account?.name)}
      </span>
    );
  }
  return (
    <span className="rev-pay-acc-logo" aria-hidden="true" style={{ width: size, height: size }}>
      <img src={logo} alt="" loading="lazy" onError={() => setBroken(true)} />
    </span>
  );
}

function PaymentAccountPicker({
  accounts,
  value,
  onChange,
  disabled,
  placeholder,
}) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);
  const selected = accounts.find((acc) => String(acc.id) === String(value)) || null;

  useEffect(() => {
    if (!open) return undefined;
    function onDoc(event) {
      if (rootRef.current && !rootRef.current.contains(event.target)) {
        setOpen(false);
      }
    }
    function onKey(event) {
      if (event.key !== 'Escape') return;
      event.stopPropagation();
      setOpen(false);
    }
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDoc);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  useEffect(() => {
    if (disabled) setOpen(false);
  }, [disabled]);

  return (
    <div className={`rev-pay-acc-picker${open ? ' is-open' : ''}${disabled ? ' is-disabled' : ''}`} ref={rootRef}>
      <button
        type="button"
        className="rev-pay-acc-trigger"
        disabled={disabled}
        aria-haspopup="listbox"
        aria-expanded={open}
        onClick={() => {
          if (!disabled) setOpen((prev) => !prev);
        }}
      >
        {selected ? (
          <span className="rev-pay-acc-option-main">
            <AccountLogo account={selected} />
            <span className="rev-pay-acc-option-text">
              <span className="rev-pay-acc-option-name">{selected.name}</span>
              {selected.currency ? <span className="rev-pay-acc-option-meta">{selected.currency}</span> : null}
            </span>
          </span>
        ) : (
          <span className="rev-pay-acc-placeholder">{placeholder}</span>
        )}
        <ChevronDown size={16} className="rev-pay-acc-caret" aria-hidden="true" />
      </button>

      {open ? (
        <div className="rev-pay-acc-menu" role="listbox">
          {accounts.length ? accounts.map((acc) => {
            const active = String(acc.id) === String(value);
            return (
              <button
                key={acc.id}
                type="button"
                role="option"
                aria-selected={active}
                className={`rev-pay-acc-option${active ? ' is-active' : ''}`}
                onClick={() => {
                  onChange(String(acc.id));
                  setOpen(false);
                }}
              >
                <AccountLogo account={acc} />
                <span className="rev-pay-acc-option-text">
                  <span className="rev-pay-acc-option-name">{acc.name}</span>
                  {acc.currency ? <span className="rev-pay-acc-option-meta">{acc.currency}</span> : null}
                </span>
              </button>
            );
          }) : (
            <div className="rev-pay-acc-empty">No accounts for this method</div>
          )}
        </div>
      ) : null}
    </div>
  );
}

export default function InvoiceRegisterPaymentModal({ entryId, onClose, onSuccess }) {
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
        const data = await fetchRevenuePaymentInit(entryId);
        if (cancelled) return;
        setInit(data);
        const entry = data.entry || {};
        setCollectionDate(data.collection_date || entry.collection_date || new Date().toISOString().slice(0, 10));
        setPaymentMethod('');
        setReferenceNumber(data.default_reference || entry.default_reference || '');
        setPayerName(data.customer_name || entry.customer_name || '');
        setAmount(String(data.default_amount ?? entry.default_amount ?? data.amount_due ?? entry.amount_due ?? ''));
        setPaymentNotes('');
        const currencies = data.currencies || entry.currencies || [{ code: 'TZS' }];
        setCurrency(currencies[0]?.code || 'TZS');
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
  const customerName = init?.customer_name || entry.customer_name || '';
  const voucherNumber = init?.voucher_number || entry.voucher_number || `#${entryId}`;
  const maxAmount = Number(init?.amount_due ?? entry.amount_due ?? 0) || 0;
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
      formData.set('payer_name', payerName || customerName || 'Customer');
      formData.set('account_id', accountId);
      formData.set('currency', currency);
      formData.set('amount_collected', amount);
      formData.set('payment_notes', paymentNotes);
      formData.set('internal_note', '');
      formData.set('payment_attachment', attachment);

      const result = await submitRevenuePayment(formData);
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
        aria-labelledby="inv-pay-title"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="rev-pay-head">
          <div>
            <h2 id="inv-pay-title">Record payment</h2>
            <p className="rev-pay-sub">
              {voucherNumber}
              {customerName ? ` · ${customerName}` : ''}
            </p>
            {!loading ? (
              <p className="rev-pay-balance">
                Balance due
                <strong>{currency} {money(maxAmount)}</strong>
              </p>
            ) : null}
          </div>
          <button type="button" className="rev-pay-close" onClick={onClose} aria-label="Close">
            <X size={18} />
          </button>
        </div>

        {loading ? (
          <div className="rev-pay-loading">
            <Loader2 size={22} className="rev-pay-spin" aria-hidden="true" />
            <span>Loading...</span>
          </div>
        ) : (
          <form className="rev-pay-form" onSubmit={handleSubmit}>
            <div className="rev-pay-body">
              <div className="rev-pay-form-grid">
                <div className="rev-pay-field">
                  <label htmlFor="inv-pay-date">Payment date *</label>
                  <input
                    id="inv-pay-date"
                    type="date"
                    value={collectionDate}
                    onChange={(e) => setCollectionDate(e.target.value)}
                    required
                  />
                </div>

                <div className="rev-pay-field">
                  <label htmlFor="inv-pay-method">Payment method *</label>
                  <select
                    id="inv-pay-method"
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
                  <label id="inv-pay-account-label">Payment account *</label>
                  <PaymentAccountPicker
                    accounts={filteredAccounts}
                    value={accountId}
                    onChange={setAccountId}
                    disabled={!paymentMethod}
                    placeholder={paymentMethod ? 'Select account' : 'Select payment method first'}
                  />
                  <input
                    type="text"
                    value={accountId}
                    required
                    tabIndex={-1}
                    aria-labelledby="inv-pay-account-label"
                    className="rev-pay-acc-hidden-required"
                    onChange={() => {}}
                  />
                </div>

                <div className="rev-pay-field">
                  <label htmlFor="inv-pay-amount">Amount *</label>
                  <div className="rev-pay-amount-wrap">
                    <span className="rev-pay-amount-currency">{currency}</span>
                    <input
                      id="inv-pay-amount"
                      type="number"
                      min="0.01"
                      step="0.01"
                      max={maxAmount > 0 ? maxAmount : undefined}
                      value={amount}
                      onChange={(e) => setAmount(e.target.value)}
                      className={amountMeetsRequired ? 'rev-pay-input--met' : 'rev-pay-input--under'}
                      style={{ color: amountColor, WebkitTextFillColor: amountColor }}
                      required
                    />
                  </div>
                </div>

                <div className="rev-pay-field rev-pay-field--full">
                  <label htmlFor="inv-pay-notes">Notes</label>
                  <input
                    id="inv-pay-notes"
                    type="text"
                    value={paymentNotes}
                    onChange={(e) => setPaymentNotes(e.target.value)}
                    placeholder="Optional"
                  />
                </div>

                <div className="rev-pay-field rev-pay-field--full">
                  <label htmlFor="inv-pay-proof">SWIFT / bank slip *</label>
                  <button
                    type="button"
                    className={`rev-pay-upload${attachment ? ' has-file' : ''}`}
                    onClick={() => fileInputRef.current?.click()}
                  >
                    <Upload className="rev-pay-upload-icon" size={18} aria-hidden="true" />
                    <span className="rev-pay-upload-text">
                      {attachment ? attachment.name : 'Choose PDF, image, or document'}
                    </span>
                    <span className="rev-pay-upload-action">
                      {attachment ? 'Change' : 'Browse'}
                    </span>
                  </button>
                  <input
                    ref={fileInputRef}
                    id="inv-pay-proof"
                    type="file"
                    className="rev-pay-upload-input"
                    accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,image/*,application/pdf"
                    onChange={(e) => setAttachment(e.target.files?.[0] || null)}
                    required
                  />
                </div>
              </div>

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
                {saving ? 'Posting...' : 'Confirm payment'}
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
}
