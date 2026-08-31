import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Loader2 } from 'lucide-react';
import {
  deskPageUrl,
  fetchCreateInit,
  fetchExchangeRate,
  submitCreateEntry,
} from '../api/revenueDesk';

const SECTIONS = [
  { id: 'rev-general', label: 'General' },
  { id: 'rev-attachment', label: 'Attachment' },
];

const FLAG_BASE = 'https://flagcdn.com/w40/';
const IMMEDIATE_PAYMENT = ['Cash', 'Bank', 'Mobile'];

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

function findCurrencyMeta(list, currency) {
  const iso = String(currency || 'TZS').toUpperCase();
  const found = (list || []).find((opt) => String(opt.iso || opt.code).toUpperCase() === iso);
  if (found) return found;
  if (iso === 'TZS') {
    return { code: 'TZS', iso: 'TZS', name: 'Tanzanian Shilling', flag: 'tz' };
  }
  return { code: currency, iso, name: currency, flag: '' };
}

function flagUrl(flagCode, currencyIso = '') {
  const code = String(flagCode || '').toLowerCase()
    || (String(currencyIso).toUpperCase() === 'TZS' ? 'tz' : 'un');
  return `${FLAG_BASE}${code}.png`;
}

function formatRateHint(data) {
  if (!data || !data.ok) {
    return data?.error ? data.error : 'Could not load BOT rate. Enter manually.';
  }
  const src = data.via_ai ? 'BOT (AI)' : (data.source || 'BOT');
  const asOf = data.as_of ? ` as of ${data.as_of}` : '';
  return `${src} mean rate: ${Number(data.rate).toFixed(4)} TZS per 1 ${data.currency} (${src}${asOf}). You may adjust before saving.`;
}

function depositBucketForPayment(mode) {
  if (mode === 'Bank') return 'bank';
  if (mode === 'Cash') return 'cash';
  if (mode === 'Mobile') return 'mobile';
  return null;
}

export default function RevenueCreatePage() {
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState([]);
  const [activeSection, setActiveSection] = useState('rev-general');
  const [currencyOpen, setCurrencyOpen] = useState(false);
  const currencyRef = useRef(null);
  const rateFetchToken = useRef(0);

  const [entryDate, setEntryDate] = useState(todayIso());
  const [customerName, setCustomerName] = useState('');
  const [subAccountId, setSubAccountId] = useState('');
  const [currency, setCurrency] = useState('TZS');
  const [exchangeRate, setExchangeRate] = useState('1.0000');
  const [exchangeRateHint, setExchangeRateHint] = useState('TZS is the base currency (rate 1.00).');
  const [amountExclusive, setAmountExclusive] = useState('');
  const [vatRate, setVatRate] = useState('18');
  const [paymentMode, setPaymentMode] = useState('Cash');
  const [depositAccountId, setDepositAccountId] = useState('');
  const [description, setDescription] = useState('');
  const [attachment, setAttachment] = useState(null);

  const loadInit = useCallback(async () => {
    setLoading(true);
    try {
      const data = await fetchCreateInit();
      setInit(data);
      setCurrency(data.default_currency || 'TZS');
      setSubAccountId(data.default_sub_account_id ? String(data.default_sub_account_id) : '');
      setExchangeRate(Number(data.default_exchange_rate || 1).toFixed(4));
    } catch (err) {
      setErrors([err instanceof Error ? err.message : 'Failed to load form.']);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadInit();
  }, [loadInit]);

  useEffect(() => {
    if (!init?.sub_accounts?.length) {
      setSubAccountId('');
      return;
    }
    const stillValid = init.sub_accounts.some((acc) => String(acc.id) === String(subAccountId));
    if (!stillValid) {
      setSubAccountId(String(init.sub_accounts[0].id));
    }
  }, [init, subAccountId]);

  const requiresDeposit = IMMEDIATE_PAYMENT.includes(paymentMode);
  const depositBucket = depositBucketForPayment(paymentMode);

  const filteredDepositAccounts = useMemo(() => {
    if (!init?.financial_accounts || !depositBucket) return [];
    const accounts = init.financial_accounts.filter((acc) => acc.bucket === depositBucket);
    const currencyMatches = accounts.filter(
      (acc) => String(acc.currency || 'TZS').toUpperCase() === String(currency).toUpperCase(),
    );
    const useCurrency = currencyMatches.length > 0;
    return accounts.filter((acc) => (
      !useCurrency || String(acc.currency || 'TZS').toUpperCase() === String(currency).toUpperCase()
    ));
  }, [init, depositBucket, currency]);

  useEffect(() => {
    if (!requiresDeposit) {
      setDepositAccountId('');
      return;
    }
    if (depositAccountId && !filteredDepositAccounts.some((a) => String(a.id) === String(depositAccountId))) {
      setDepositAccountId('');
    }
  }, [requiresDeposit, filteredDepositAccounts, depositAccountId]);

  const selectedCurrencyMeta = useMemo(
    () => findCurrencyMeta(init?.currencies, currency),
    [init, currency],
  );

  const refreshExchangeRate = useCallback(async (code) => {
    const token = ++rateFetchToken.current;
    if (code === 'TZS') {
      setExchangeRate('1.0000');
      setExchangeRateHint('TZS is the base currency (rate 1.00).');
      return;
    }
    setExchangeRateHint('Loading Bank of Tanzania exchange rate...');
    try {
      const data = await fetchExchangeRate(code);
      if (token !== rateFetchToken.current) return;
      if (data.ok && data.rate) {
        setExchangeRate(Number(data.rate).toFixed(4));
      }
      setExchangeRateHint(formatRateHint(data));
    } catch {
      if (token !== rateFetchToken.current) return;
      setExchangeRateHint('Could not fetch BOT rate. Enter manually.');
    }
  }, []);

  useEffect(() => {
    if (!init || !currency) return;
    refreshExchangeRate(currency);
  }, [currency, init, refreshExchangeRate]);

  useEffect(() => {
    if (!currencyOpen) return undefined;
    function handlePointerDown(event) {
      if (!currencyRef.current?.contains(event.target)) {
        setCurrencyOpen(false);
      }
    }
    document.addEventListener('mousedown', handlePointerDown);
    return () => document.removeEventListener('mousedown', handlePointerDown);
  }, [currencyOpen]);

  useEffect(() => {
    const sections = SECTIONS.map((s) => document.getElementById(s.id)).filter(Boolean);
    if (!sections.length) return undefined;
    const observer = new IntersectionObserver(
      (entries) => {
        const visible = entries
          .filter((e) => e.isIntersecting)
          .sort((a, b) => b.intersectionRatio - a.intersectionRatio);
        if (visible[0]?.target?.id) {
          setActiveSection(visible[0].target.id);
        }
      },
      { rootMargin: '-120px 0px -55% 0px', threshold: [0.1, 0.35, 0.6] },
    );
    sections.forEach((el) => observer.observe(el));
    return () => observer.disconnect();
  }, [loading]);

  function scrollToSection(id) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    setActiveSection(id);
  }

  function buildFormData() {
    const formData = new FormData();
    formData.append('csrf_token', init.csrf_token);
    formData.append('entry_date', entryDate);
    formData.append('customer_name', customerName);
    formData.append('description', description);
    formData.append('narration', description);
    formData.append('payment_mode', paymentMode);
    formData.append('amount_exclusive', amountExclusive);
    formData.append('tax_treatment', 'Exclusive');
    formData.append('vat_rate', vatRate);
    formData.append('currency', currency);
    formData.append('exchange_rate', exchangeRate);
    formData.append('revenue_sub_account_id', subAccountId);
    if (depositAccountId) formData.append('account_id', depositAccountId);
    if (attachment) formData.append('attachment', attachment);
    return formData;
  }

  async function handleSubmit(event) {
    event.preventDefault();
    if (!init) return;
    setSaving(true);
    setErrors([]);
    try {
      const result = await submitCreateEntry(buildFormData());
      if (!result.ok) {
        setErrors(result.errors || [result.error || 'Failed to save entry.']);
        setSaving(false);
        return;
      }
      window.location.href = result.redirect || init.list_url || deskPageUrl('revenue_entries.php');
    } catch (err) {
      setErrors([err instanceof Error ? err.message : 'Failed to save entry.']);
      setSaving(false);
    }
  }

  function handleCancel() {
    window.location.href = init?.list_url || deskPageUrl('revenue_entries.php');
  }

  const depositHelp = paymentMode === 'Bank'
    ? 'Select a bank account for this transfer.'
    : paymentMode === 'Cash'
      ? 'Select a cash account for this payment.'
      : paymentMode === 'Mobile'
        ? 'Select a mobile money account for this payment.'
        : 'Required when payment is Cash, Bank Transfer, or Mobile Payment.';

  if (loading) {
    return (
      <div className="rev-create-loading">
        <Loader2 size={22} className="rev-create-spinner" aria-hidden="true" />
        <span>Loading form...</span>
      </div>
    );
  }

  if (!init) {
    return (
      <div className="rev-create-shell">
        <div className="rev-create-alert rev-create-alert--error">
          {errors[0] || 'Could not load the revenue form.'}
        </div>
      </div>
    );
  }

  return (
    <div className="rev-create-shell">
      <div className="rev-create-topbar">
        <h1>create Revenue</h1>
        <a href={init.list_url || deskPageUrl('revenue_entries.php')} className="rev-create-back">
          Back to Revenues
        </a>
      </div>

      {errors.length > 0 && (
        <div className="rev-create-alert rev-create-alert--error" role="alert">
          {errors.map((msg) => (
            <div key={msg}>{msg}</div>
          ))}
        </div>
      )}

      <form onSubmit={handleSubmit}>
        <div className="rev-create-layout">
          <nav className="rev-create-nav" aria-label="Form sections">
            <ul>
              {SECTIONS.map((section) => (
                <li key={section.id}>
                  <button
                    type="button"
                    className={activeSection === section.id ? 'is-active' : ''}
                    onClick={() => scrollToSection(section.id)}
                  >
                    {section.label}
                  </button>
                </li>
              ))}
            </ul>
          </nav>

          <div className="rev-create-main">
            <section className="rev-create-section" id="rev-general">
              <div className="rev-create-section-header">
                <h2>General Information</h2>
                <p>Core revenue details and payment setup.</p>
              </div>

              <div className="rev-create-row">
                <label className="rev-create-label" htmlFor="rev-entry-date">
                  Revenue Date<span className="req">*</span>
                </label>
                <input
                  id="rev-entry-date"
                  type="date"
                  className="rev-create-input"
                  value={entryDate}
                  onChange={(e) => setEntryDate(e.target.value)}
                  required
                />
              </div>

              <div className="rev-create-row">
                <label className="rev-create-label" htmlFor="rev-customer">
                  Customer<span className="req">*</span>
                </label>
                <div>
                  <select
                    id="rev-customer"
                    className="rev-create-select"
                    value={customerName}
                    onChange={(e) => setCustomerName(e.target.value)}
                    required
                  >
                    <option value="">-- Select Customer --</option>
                    {(init.customers || []).map((name) => (
                      <option key={name} value={name}>{name}</option>
                    ))}
                  </select>
                  <div className="rev-create-help">
                    <a href={init.create_customer_url}>+ Create New Customer</a>
                  </div>
                </div>
              </div>

              <div className="rev-create-row">
                <label className="rev-create-label" htmlFor="rev-sub-account">
                  Revenue Sub Account<span className="req">*</span>
                </label>
                <div>
                  <select
                    id="rev-sub-account"
                    className="rev-create-select"
                    value={subAccountId}
                    onChange={(e) => setSubAccountId(e.target.value)}
                    required
                    disabled={!init?.sub_accounts?.length}
                  >
                    <option value="">-- Select Sub Account --</option>
                    {(init?.sub_accounts || []).map((acc) => (
                      <option key={acc.id} value={String(acc.id)}>{acc.label || acc.name}</option>
                    ))}
                  </select>
                  <div className="rev-create-help">
                    {init?.revenue_parent?.name
                      ? `Sub-accounts under ${init.revenue_parent.name} in Balances. `
                      : 'Sub-accounts from Balances chart of accounts. '}
                    {init?.balances_accounts_url ? (
                      <a href={init.balances_accounts_url}>Manage in Chart of Accounts</a>
                    ) : null}
                  </div>
                </div>
              </div>

              <div className="rev-create-row">
                <label className="rev-create-label">Currency<span className="req">*</span></label>
                <div className={`rev-create-currency${currencyOpen ? ' is-open' : ''}`} ref={currencyRef}>
                  <button
                    type="button"
                    className="rev-create-currency-trigger"
                    onClick={() => setCurrencyOpen((o) => !o)}
                    aria-expanded={currencyOpen}
                  >
                    <img
                      src={flagUrl(selectedCurrencyMeta.flag, selectedCurrencyMeta.iso)}
                      alt=""
                      className="rev-create-currency-flag"
                      width={28}
                      height={20}
                    />
                    <span className="rev-create-currency-label">
                      <span className="code">{selectedCurrencyMeta.iso || currency}</span>
                      <span className="name">{selectedCurrencyMeta.name}</span>
                    </span>
                  </button>
                  {currencyOpen && (
                    <div className="rev-create-currency-menu" role="listbox">
                      {(init.currencies || []).map((opt) => (
                        <button
                          key={opt.iso || opt.code}
                          type="button"
                          role="option"
                          className={`rev-create-currency-option${currency === (opt.iso || opt.code) ? ' is-selected' : ''}`}
                          onClick={() => {
                            setCurrency(opt.iso || opt.code);
                            setCurrencyOpen(false);
                          }}
                        >
                          <img
                            src={flagUrl(opt.flag, opt.iso || opt.code)}
                            alt=""
                            className="rev-create-currency-flag"
                            width={28}
                            height={20}
                          />
                          <span className="code">{opt.iso || opt.code}</span>
                          <span className="name">{opt.name}</span>
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              </div>

              <div className="rev-create-row">
                <label className="rev-create-label" htmlFor="rev-exchange-rate">Exchange Rate</label>
                <div>
                  <input
                    id="rev-exchange-rate"
                    type="number"
                    step="0.0001"
                    min="0.0001"
                    className="rev-create-input"
                    value={exchangeRate}
                    onChange={(e) => setExchangeRate(e.target.value)}
                    disabled={currency === 'TZS'}
                  />
                  <div className="rev-create-help">{exchangeRateHint}</div>
                </div>
              </div>

              <div className="rev-create-row">
                <label className="rev-create-label" htmlFor="rev-amount">
                  Amount ({currency})<span className="req">*</span>
                </label>
                <input
                  id="rev-amount"
                  type="number"
                  step="0.01"
                  min="0"
                  className="rev-create-input rev-create-input--price"
                  value={amountExclusive}
                  onChange={(e) => setAmountExclusive(e.target.value)}
                  placeholder="0.00"
                  required
                />
              </div>

              <div className="rev-create-row">
                <label className="rev-create-label" htmlFor="rev-vat">VAT Rate</label>
                <select
                  id="rev-vat"
                  className="rev-create-select"
                  value={vatRate}
                  onChange={(e) => setVatRate(e.target.value)}
                >
                  {(init.vat_rates || []).map((opt) => (
                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                  ))}
                </select>
              </div>

              <div className="rev-create-row">
                <label className="rev-create-label" htmlFor="rev-payment">
                  Payment Method<span className="req">*</span>
                </label>
                <select
                  id="rev-payment"
                  className="rev-create-select"
                  value={paymentMode}
                  onChange={(e) => setPaymentMode(e.target.value)}
                  required
                >
                  {(init.payment_modes || []).map((opt) => (
                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                  ))}
                </select>
              </div>

              {requiresDeposit && (
                <div className="rev-create-row">
                  <label className="rev-create-label" htmlFor="rev-deposit">
                    Deposit To<span className="req">*</span>
                  </label>
                  <div>
                    <select
                      id="rev-deposit"
                      className="rev-create-select"
                      value={depositAccountId}
                      onChange={(e) => setDepositAccountId(e.target.value)}
                      required
                    >
                      <option value="">-- Select Account --</option>
                      {filteredDepositAccounts.map((acc) => (
                        <option key={acc.id} value={String(acc.id)}>
                          {acc.name} ({acc.currency})
                        </option>
                      ))}
                    </select>
                    <div className="rev-create-help">{depositHelp}</div>
                  </div>
                </div>
              )}

              <div className="rev-create-row">
                <label className="rev-create-label" htmlFor="rev-description">Description</label>
                <textarea
                  id="rev-description"
                  className="rev-create-textarea"
                  rows={3}
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  placeholder="What is this revenue for?"
                />
              </div>
            </section>

            <section className="rev-create-section" id="rev-attachment">
              <div className="rev-create-section-header">
                <h2>Additional Details</h2>
                <p>Supporting attachment for this entry.</p>
              </div>

              <div className="rev-create-row">
                <label className="rev-create-label" htmlFor="rev-attachment">
                  Attachment<span className="req">*</span>
                </label>
                <div>
                  <input
                    id="rev-attachment"
                    type="file"
                    className="rev-create-input"
                    accept=".pdf,.jpg,.jpeg,.png"
                    onChange={(e) => setAttachment(e.target.files?.[0] || null)}
                    required
                  />
                  <div className="rev-create-help">PDF, JPG, PNG � max 5MB (required for submission).</div>
                </div>
              </div>
            </section>

            <div className="rev-create-actions">
              <button type="button" className="rev-create-btn rev-create-btn--ghost" onClick={handleCancel} disabled={saving}>
                Cancel
              </button>
              <button type="submit" className="rev-create-btn rev-create-btn--primary" disabled={saving}>
                {saving ? 'Saving...' : 'Save Entry'}
              </button>
            </div>
          </div>
        </div>
      </form>
    </div>
  );
}
