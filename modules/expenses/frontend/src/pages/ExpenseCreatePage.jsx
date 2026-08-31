import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Loader2, Trash2 } from 'lucide-react';
import {
  deleteDraftExpense,
  deskPageUrl,
  fetchCreateInit,
  fetchEditInit,
  fetchExchangeRate,
  submitCreateExpense,
  submitCreateExpenseDraftOnLeave,
  submitUpdateExpense,
  submitUpdateExpenseDraftOnLeave,
} from '../api/expensesDesk';

const FLAG_BASE = 'https://flagcdn.com/w40/';

function normalizeCurrencyIso(code) {
  const value = String(code || '').trim().toUpperCase();
  if (value === 'TSH') return 'TZS';
  return value;
}

function findCurrencyMeta(list, currency) {
  const iso = normalizeCurrencyIso(currency);
  const found = (list || []).find((opt) => normalizeCurrencyIso(opt.iso || opt.code) === iso);
  if (found) return found;
  if (iso === 'TZS') {
    return { code: 'TSh', iso: 'TZS', name: 'Tanzanian Shilling', flag: 'tz' };
  }
  return { code: currency, iso, name: currency, flag: '' };
}

function currencyMatchesOption(opt, currency) {
  return normalizeCurrencyIso(opt.iso || opt.code) === normalizeCurrencyIso(currency);
}

function flagUrl(flagCode, currencyIso = '') {
  let code = String(flagCode || '').toLowerCase();
  if (!code) {
    code = normalizeCurrencyIso(currencyIso) === 'TZS' ? 'tz' : 'un';
  }
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

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

function resolveEditId() {
  if (typeof window !== 'undefined' && window.__EXPENSES_EDIT_ID__) {
    const id = parseInt(String(window.__EXPENSES_EDIT_ID__), 10);
    return id > 0 ? id : null;
  }
  return null;
}

function isExpenseFormFilled(fields) {
  const amountNum = parseFloat(fields.amount) || 0;
  if (amountNum > 0) return true;
  if (String(fields.description || '').trim() !== '') return true;
  if (fields.accountId || fields.sourceAccountId) return true;
  if (fields.mainAccountId || fields.mainPaymentAccountId) return true;
  if (fields.attachment || fields.existingAttachment) return true;
  if (fields.currency && fields.defaultCurrency && fields.currency !== fields.defaultCurrency) {
    return true;
  }
  return false;
}

export default function ExpenseCreatePage() {
  const editId = useMemo(() => resolveEditId(), []);
  const isEditing = editId != null;
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [errors, setErrors] = useState([]);
  const [currencyOpen, setCurrencyOpen] = useState(false);
  const currencyRef = useRef(null);
  const rateFetchToken = useRef(0);
  const exitHandledRef = useRef(false);
  const formSnapshotRef = useRef({});

  const [paymentMethod, setPaymentMethod] = useState('cash');
  const [date, setDate] = useState(todayIso());
  const [mainAccountId, setMainAccountId] = useState('');
  const [accountId, setAccountId] = useState('');
  const [mainPaymentAccountId, setMainPaymentAccountId] = useState('');
  const [sourceAccountId, setSourceAccountId] = useState('');
  const [currency, setCurrency] = useState('TZS');
  const [exchangeRate, setExchangeRate] = useState('1.0000');
  const [exchangeRateHint, setExchangeRateHint] = useState('TZS is the base currency (rate 1.00).');
  const [amount, setAmount] = useState('');
  const [description, setDescription] = useState('');
  const [attachment, setAttachment] = useState(null);
  const [existingAttachment, setExistingAttachment] = useState('');

  function applyDraftToForm(draft) {
    if (!draft) return;
    setPaymentMethod(draft.payment_method || 'cash');
    setDate(draft.date || todayIso());
    setMainAccountId(draft.main_account_id ? String(draft.main_account_id) : '');
    setAccountId(draft.account_id ? String(draft.account_id) : '');
    setMainPaymentAccountId(draft.main_payment_account_id ? String(draft.main_payment_account_id) : '');
    setSourceAccountId(draft.source_account_id ? String(draft.source_account_id) : '');
    setCurrency(draft.currency || 'TZS');
    setAmount(draft.amount != null && draft.amount !== '' ? String(draft.amount) : '');
    setDescription(draft.description || '');
    setExistingAttachment(draft.attachment_name || '');
  }

  const loadInit = useCallback(async () => {
    setLoading(true);
    try {
      const data = isEditing ? await fetchEditInit(editId) : await fetchCreateInit();
      setInit(data);
      if (data.draft) {
        applyDraftToForm(data.draft);
      } else {
        setCurrency(data.default_currency || 'TZS');
        if (data.default_currency === 'TZS') {
          setExchangeRate('1.0000');
          setExchangeRateHint('TZS is the base currency (rate 1.00).');
        }
      }
    } catch (err) {
      setErrors([err instanceof Error ? err.message : 'Failed to load form.']);
    } finally {
      setLoading(false);
    }
  }, [editId, isEditing]);

  useEffect(() => {
    loadInit();
  }, [loadInit]);

  const expenseSubs = useMemo(() => {
    if (!init?.expense?.hierarchical || !mainAccountId) return [];
    return init.expense.childrenByParent[String(mainAccountId)] || [];
  }, [init, mainAccountId]);

  const paymentSubs = useMemo(() => {
    if (!init?.payment?.hierarchical || !mainPaymentAccountId) return [];
    const rows = init.payment.childrenByParent[String(mainPaymentAccountId)] || [];
    const wantCash = paymentMethod === 'cash';
    return rows.filter((row) => {
      const kind = String(row.kind || 'bank').toLowerCase();
      return wantCash ? kind === 'cash' : kind !== 'cash';
    });
  }, [init, mainPaymentAccountId, paymentMethod]);

  const flatPaymentAccounts = useMemo(() => {
    if (!init?.payment || init.payment.hierarchical) return [];
    const wantCash = paymentMethod === 'cash';
    return (init.payment.flat || []).filter((row) => {
      const kind = String(row.kind || 'bank').toLowerCase();
      return wantCash ? kind === 'cash' : kind !== 'cash';
    });
  }, [init, paymentMethod]);

  const selectedCurrencyMeta = useMemo(() => {
    return findCurrencyMeta(init?.currencies, currency);
  }, [init, currency]);

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
    if (!init || currency === '') return;
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

  function handleMainAccountChange(value) {
    setMainAccountId(value);
    setAccountId('');
  }

  function handleMainPaymentChange(value) {
    setMainPaymentAccountId(value);
    setSourceAccountId('');
  }

  function buildFormData(saveMode) {
    const formData = new FormData();
    formData.append('csrf_token', init.csrf_token);
    formData.append('save_mode', saveMode);
    formData.append('date', date);
    formData.append('payment_method', paymentMethod);
    formData.append('currency', currency);
    formData.append('exchange_rate', exchangeRate);
    formData.append('amount', amount);
    formData.append('description', description);
    if (mainAccountId) formData.append('main_account_id', mainAccountId);
    if (accountId) formData.append('account_id', accountId);
    if (mainPaymentAccountId) formData.append('main_payment_account_id', mainPaymentAccountId);
    if (sourceAccountId) formData.append('source_account_id', sourceAccountId);
    if (attachment) formData.append('attachment', attachment);
    if (isEditing) formData.append('expense_id', String(editId));
    return formData;
  }

  async function saveExpense(saveMode) {
    const formData = buildFormData(saveMode);
    if (isEditing) {
      return submitUpdateExpense(formData);
    }
    return submitCreateExpense(formData);
  }

  const shouldAutoSaveDraft = useCallback(() => {
    if (!init || exitHandledRef.current || saving) return false;
    return isExpenseFormFilled({
      amount,
      description,
      accountId,
      sourceAccountId,
      mainAccountId,
      mainPaymentAccountId,
      attachment,
      existingAttachment,
      currency,
      defaultCurrency: init.default_currency || 'TZS',
    });
  }, [
    init,
    saving,
    amount,
    description,
    accountId,
    sourceAccountId,
    mainAccountId,
    mainPaymentAccountId,
    attachment,
    existingAttachment,
    currency,
  ]);

  useEffect(() => {
    formSnapshotRef.current = {
      init,
      editId,
      amount,
      description,
      accountId,
      sourceAccountId,
      mainAccountId,
      mainPaymentAccountId,
      attachment,
      existingAttachment,
      currency,
      date,
      paymentMethod,
      exchangeRate,
      saving,
    };
  }, [
    init,
    editId,
    amount,
    description,
    accountId,
    sourceAccountId,
    mainAccountId,
    mainPaymentAccountId,
    attachment,
    existingAttachment,
    currency,
    date,
    paymentMethod,
    exchangeRate,
    saving,
  ]);

  useEffect(() => {
    function handlePageHide() {
      if (exitHandledRef.current) return;
      const snap = formSnapshotRef.current;
      if (!snap.init || snap.saving) return;
      if (!isExpenseFormFilled({
        amount: snap.amount,
        description: snap.description,
        accountId: snap.accountId,
        sourceAccountId: snap.sourceAccountId,
        mainAccountId: snap.mainAccountId,
        mainPaymentAccountId: snap.mainPaymentAccountId,
        attachment: snap.attachment,
        existingAttachment: snap.existingAttachment,
        currency: snap.currency,
        defaultCurrency: snap.init.default_currency || 'TZS',
      })) {
        return;
      }
      exitHandledRef.current = true;
      const formData = new FormData();
      formData.append('csrf_token', snap.init.csrf_token);
      formData.append('save_mode', 'draft');
      formData.append('date', snap.date);
      formData.append('payment_method', snap.paymentMethod);
      formData.append('currency', snap.currency);
      formData.append('exchange_rate', snap.exchangeRate);
      formData.append('amount', snap.amount);
      formData.append('description', snap.description);
      if (snap.mainAccountId) formData.append('main_account_id', snap.mainAccountId);
      if (snap.accountId) formData.append('account_id', snap.accountId);
      if (snap.mainPaymentAccountId) formData.append('main_payment_account_id', snap.mainPaymentAccountId);
      if (snap.sourceAccountId) formData.append('source_account_id', snap.sourceAccountId);
      if (snap.attachment) formData.append('attachment', snap.attachment);
      if (snap.editId) {
        formData.append('expense_id', String(snap.editId));
        submitUpdateExpenseDraftOnLeave(formData);
      } else {
        submitCreateExpenseDraftOnLeave(formData);
      }
    }

    window.addEventListener('pagehide', handlePageHide);
    return () => window.removeEventListener('pagehide', handlePageHide);
  }, []);

  async function handleSubmit(event) {
    event.preventDefault();
    if (!init) return;

    setSaving(true);
    setErrors([]);

    try {
      exitHandledRef.current = true;
      const result = await saveExpense('post');
      window.location.href = result.redirect || deskPageUrl('index.php');
    } catch (err) {
      exitHandledRef.current = false;
      setErrors([err instanceof Error ? err.message : 'Failed to save expense.']);
      setSaving(false);
    }
  }

  async function handleCancel() {
    if (shouldAutoSaveDraft()) {
      setSaving(true);
      exitHandledRef.current = true;
      try {
        await saveExpense('draft');
      } catch {
        // Leave anyway; pagehide may have already queued a draft save.
      }
    } else {
      exitHandledRef.current = true;
    }
    window.location.href = deskPageUrl('index.php');
  }

  async function handleDeleteDraft() {
    if (!isEditing || !editId || !init?.csrf_token) return;
    const label = init.preview_expense_number || `draft #${editId}`;
    if (!window.confirm(`Delete ${label}? This cannot be undone.`)) return;

    setDeleting(true);
    setErrors([]);
    try {
      await deleteDraftExpense(editId, init.csrf_token);
      exitHandledRef.current = true;
      window.location.href = deskPageUrl('index.php');
    } catch (err) {
      setErrors([err instanceof Error ? err.message : 'Failed to delete draft.']);
    } finally {
      setDeleting(false);
    }
  }

  if (loading) {
    return (
      <div className="exp-create-loading">
        <Loader2 size={22} className="exp-create-spinner" aria-hidden />
        Loading form...
      </div>
    );
  }

  if (!init) {
    return (
      <div className="exp-create-shell">
        <div className="exp-create-alert exp-create-alert--error">
          {errors[0] || 'Could not load the expense form.'}
        </div>
      </div>
    );
  }

  const expenseHierarchical = init.expense?.hierarchical;
  const paymentHierarchical = init.payment?.hierarchical;
  const hasExpenseAccounts = expenseHierarchical
    ? (init.expense?.mains?.length > 0 && (init.expense?.flat?.length > 0 || Object.keys(init.expense?.childrenByParent || {}).length > 0))
    : (init.expense?.flat?.length > 0);
  const hasPaymentAccounts = paymentHierarchical
    ? (init.payment?.mains?.length > 0)
    : (init.payment?.flat?.length > 0);

  const sourceLabel = paymentMethod === 'cash' ? 'Cash Account' : 'Bank Account';
  const isTzs = normalizeCurrencyIso(currency) === 'TZS';

  return (
    <div className="exp-create-shell">
      {errors.length > 0 && (
        <div className="exp-create-alert exp-create-alert--error" role="alert">
          {errors.map((msg) => (
            <div key={msg}>{msg}</div>
          ))}
        </div>
      )}

      <form onSubmit={handleSubmit}>
        <div className="exp-create-main">
            <section className="exp-create-section" id="expense-payment">
              <div className="exp-create-row">
                <label className="exp-create-label">Expense Number</label>
                <div>
                  <input
                    type="text"
                    readOnly
                    className="exp-create-input exp-create-input--readonly"
                    value={init.preview_expense_number}
                  />
                  <div className="exp-create-help">
                    This number is generated automatically when the expense is saved.
                  </div>
                </div>
              </div>

              <div className="exp-create-row">
                <label className="exp-create-label">
                  How was it paid?<span className="req">*</span>
                </label>
                <div className="exp-create-pay-method">
                  <label>
                    <input
                      type="radio"
                      name="payment_method"
                      value="bank_transfer"
                      checked={paymentMethod === 'bank_transfer'}
                      onChange={() => {
                        setPaymentMethod('bank_transfer');
                        setSourceAccountId('');
                      }}
                    />
                    Bank Transfer
                  </label>
                  <label>
                    <input
                      type="radio"
                      name="payment_method"
                      value="cash"
                      checked={paymentMethod === 'cash'}
                      onChange={() => {
                        setPaymentMethod('cash');
                        setSourceAccountId('');
                      }}
                    />
                    Cash
                  </label>
                </div>
              </div>

              <div className="exp-create-row">
                <label className="exp-create-label">
                  {sourceLabel}<span className="req">*</span>
                </label>
                <div>
                  {paymentHierarchical ? (
                    <>
                      <select
                        className="exp-create-select"
                        value={mainPaymentAccountId}
                        onChange={(e) => handleMainPaymentChange(e.target.value)}
                        required
                        disabled={!hasPaymentAccounts}
                      >
                        <option value="">
                          {hasPaymentAccounts
                            ? 'Select account group (e.g. Assets)'
                            : 'No asset groups in Chart of Accounts'}
                        </option>
                        {(init.payment?.mains || []).map((main) => (
                          <option key={main.id} value={String(main.id)}>
                            {main.label || main.name}
                          </option>
                        ))}
                      </select>
                      <select
                        className="exp-create-select"
                        value={sourceAccountId}
                        onChange={(e) => setSourceAccountId(e.target.value)}
                        required
                        disabled={!mainPaymentAccountId}
                      >
                        <option value="">
                          {!mainPaymentAccountId
                            ? 'Select account group first'
                            : paymentSubs.length === 0
                              ? 'No matching accounts under this group'
                              : 'Select bank or cash account'}
                        </option>
                        {paymentSubs.map((sub) => (
                          <option key={sub.id} value={String(sub.id)}>
                            {sub.label || sub.name}
                          </option>
                        ))}
                      </select>
                    </>
                  ) : (
                    <select
                      className="exp-create-select"
                      value={sourceAccountId}
                      onChange={(e) => setSourceAccountId(e.target.value)}
                      required
                      disabled={!hasPaymentAccounts}
                    >
                      <option value="">
                        {hasPaymentAccounts
                          ? 'Select bank or cash account'
                          : 'No payment accounts - add under Assets in Chart of Accounts'}
                      </option>
                      {flatPaymentAccounts.map((fa) => (
                        <option key={fa.id} value={String(fa.id)}>
                          {fa.label || fa.name}
                        </option>
                      ))}
                    </select>
                  )}
                  {!hasPaymentAccounts && (
                    <div className="exp-create-help">
                      No bank/cash accounts found. Add sub-accounts under{' '}
                      <strong>1000 - Assets</strong> in{' '}
                      <a href={init.balances_url}>Chart of Accounts</a> (e.g. CRDB, Cash on Hand).
                    </div>
                  )}
                </div>
              </div>

              <div className="exp-create-row">
                <label className="exp-create-label" htmlFor="exp-date">
                  Date<span className="req">*</span>
                </label>
                <div>
                  <input
                    id="exp-date"
                    type="date"
                    className="exp-create-input"
                    value={date}
                    onChange={(e) => setDate(e.target.value)}
                    required
                  />
                </div>
              </div>

              {expenseHierarchical ? (
                <>
                  <div className="exp-create-row">
                    <label className="exp-create-label">
                      Account<span className="req">*</span>
                    </label>
                    <div>
                      <select
                        className="exp-create-select"
                        value={mainAccountId}
                        onChange={(e) => handleMainAccountChange(e.target.value)}
                        required
                        disabled={!hasExpenseAccounts}
                      >
                        <option value="">
                          {hasExpenseAccounts
                            ? 'Select account (e.g. Expenses, Cost of Goods Sold)'
                            : 'No expense categories - add under Chart of Accounts'}
                        </option>
                        {(init.expense?.mains || []).map((main) => (
                          <option key={main.id} value={String(main.id)}>
                            {main.label || main.name}
                          </option>
                        ))}
                      </select>
                      <div className="exp-create-help">
                        Main expense category (e.g. <strong>5000 - Expenses</strong>).
                      </div>
                    </div>
                  </div>

                  <div className="exp-create-row">
                    <label className="exp-create-label">
                      Sub-account<span className="req">*</span>
                    </label>
                    <div>
                      <select
                        className="exp-create-select"
                        value={accountId}
                        onChange={(e) => setAccountId(e.target.value)}
                        required
                        disabled={!mainAccountId}
                      >
                        <option value="">
                          {!mainAccountId
                            ? 'Select account first'
                            : expenseSubs.length === 0
                              ? 'No sub-accounts under this category'
                              : 'Select expense sub-account'}
                        </option>
                        {expenseSubs.map((sub) => (
                          <option key={sub.id} value={String(sub.id)}>
                            {sub.label || sub.name}
                          </option>
                        ))}
                      </select>
                      <div className="exp-create-help">
                        {!mainAccountId
                          ? 'Sub-account options appear after you select an account.'
                          : 'Choose the specific expense line to post against.'}
                      </div>
                    </div>
                  </div>
                </>
              ) : (
                <div className="exp-create-row">
                  <label className="exp-create-label">
                    Expense Account<span className="req">*</span>
                  </label>
                  <div>
                    <select
                      className="exp-create-select"
                      value={accountId}
                      onChange={(e) => setAccountId(e.target.value)}
                      required
                      disabled={!hasExpenseAccounts}
                    >
                      <option value="">
                        {hasExpenseAccounts
                          ? 'Select expense account'
                          : 'No expense accounts - add under Expenses in Chart of Accounts'}
                      </option>
                      {(init.expense?.flat || []).map((acc) => (
                        <option key={acc.id} value={String(acc.id)}>
                          {acc.label || acc.name}
                        </option>
                      ))}
                    </select>
                    <div className="exp-create-help">
                      Expense account from{' '}
                      <a href={init.balances_url}>Chart of Accounts</a>.
                    </div>
                  </div>
                </div>
              )}
            </section>

            <section className="exp-create-section" id="expense-amount">
              <div className="exp-create-section-header">
                <h2>Amount &amp; Description</h2>
                <p>Currency, amount, and purpose of this expense.</p>
              </div>

              <div className="exp-create-row">
                <label className="exp-create-label">
                  Currency<span className="req">*</span>
                </label>
                <div>
                  <div
                    className={`exp-create-currency${currencyOpen ? ' is-open' : ''}`}
                    ref={currencyRef}
                  >
                    <button
                      type="button"
                      className="exp-create-currency-trigger"
                      aria-haspopup="listbox"
                      aria-expanded={currencyOpen}
                      onClick={() => setCurrencyOpen((open) => !open)}
                    >
                      <img
                        src={flagUrl(selectedCurrencyMeta.flag, selectedCurrencyMeta.iso || currency)}
                        alt=""
                        className="exp-create-currency-flag"
                        width={28}
                        height={20}
                      />
                      <span className="exp-create-currency-label">
                        <span className="code">{selectedCurrencyMeta.code}</span>
                        <span className="name">{selectedCurrencyMeta.name}</span>
                      </span>
                    </button>
                    {currencyOpen && (
                      <div className="exp-create-currency-menu" role="listbox">
                        {(init.currencies || []).map((opt) => (
                          <button
                            key={opt.iso || opt.code}
                            type="button"
                            role="option"
                            aria-selected={currencyMatchesOption(opt, currency)}
                            className={`exp-create-currency-option${currencyMatchesOption(opt, currency) ? ' is-selected' : ''}`}
                            onClick={() => {
                              setCurrency(opt.iso || opt.code);
                              setCurrencyOpen(false);
                            }}
                          >
                            <img
                              src={flagUrl(opt.flag, opt.iso || opt.code)}
                              alt=""
                              className="exp-create-currency-flag"
                              width={28}
                              height={20}
                            />
                            <span className="code">{opt.code}</span>
                            <span className="name">{opt.name}</span>
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                  <div className="exp-create-help">Currency for this expense and amounts below.</div>
                </div>
              </div>

              <div className="exp-create-row">
                <label className="exp-create-label" htmlFor="exchange_rate">
                  Exchange Rate
                </label>
                <div>
                  <input
                    id="exchange_rate"
                    type="number"
                    step="0.0001"
                    min="0.0001"
                    className={`exp-create-input${isTzs ? ' exp-create-input--readonly' : ''}`}
                    value={exchangeRate}
                    onChange={(e) => setExchangeRate(e.target.value)}
                    readOnly={isTzs}
                    required={!isTzs}
                  />
                  <div className="exp-create-help">{exchangeRateHint}</div>
                </div>
              </div>

              <div className="exp-create-row">
                <label className="exp-create-label" htmlFor="amount_val">
                  Amount ({selectedCurrencyMeta.code})<span className="req">*</span>
                </label>
                <div>
                  <input
                    id="amount_val"
                    type="number"
                    step="0.01"
                    min="0"
                    className="exp-create-input exp-create-input--price"
                    value={amount}
                    onChange={(e) => setAmount(e.target.value)}
                    placeholder="0.00"
                    required
                  />
                </div>
              </div>

              <div className="exp-create-row">
                <label className="exp-create-label" htmlFor="description">
                  Description<span className="req">*</span>
                </label>
                <div>
                  <textarea
                    id="description"
                    className="exp-create-textarea"
                    rows={3}
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    placeholder="Enter description"
                    required
                  />
                </div>
              </div>
            </section>

            <section className="exp-create-section" id="expense-receipt">
              <div className="exp-create-section-header">
                <h2>Receipt</h2>
                <p>Upload the supporting document for this expense.</p>
              </div>

              <div className="exp-create-row">
                <label className="exp-create-label" htmlFor="receipt_upload">
                  Upload Document<span className="req">*</span>
                </label>
                <div>
                  <input
                    id="receipt_upload"
                    type="file"
                    className="exp-create-input"
                    accept=".jpg,.jpeg,.png,.pdf"
                    onChange={(e) => setAttachment(e.target.files?.[0] || null)}
                    required={!isEditing && !existingAttachment}
                  />
                  <div className="exp-create-help">
                    Accepted formats: JPG, PNG, PDF.
                    {existingAttachment && (
                      <span> Current file: {existingAttachment}. Upload a new file to replace it.</span>
                    )}
                  </div>
                </div>
              </div>
            </section>

            <div className="exp-create-actions">
              {isEditing && (
                <button
                  type="button"
                  className="exp-create-btn-delete"
                  onClick={handleDeleteDraft}
                  disabled={saving || deleting}
                >
                  {deleting ? (
                    <Loader2 size={18} className="exp-create-spinner" aria-hidden />
                  ) : (
                    <Trash2 size={18} aria-hidden="true" />
                  )}
                  {deleting ? 'Deleting...' : 'Delete draft'}
                </button>
              )}
              <button
                type="button"
                className="exp-create-btn-cancel"
                onClick={handleCancel}
                disabled={saving || deleting}
              >
                Cancel
              </button>
              <button type="submit" className="exp-create-btn-save" disabled={saving || deleting}>
                {saving && <Loader2 size={18} className="exp-create-spinner" aria-hidden />}
                Record expense
              </button>
            </div>
          </div>
      </form>
    </div>
  );
}
