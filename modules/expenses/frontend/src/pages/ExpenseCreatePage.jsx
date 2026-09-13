import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Loader2, Trash2, X, ChevronDown } from 'lucide-react';
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

function buildDraftFormDataFromSnapshot(snap) {
  const formData = new FormData();
  formData.append('csrf_token', snap.init.csrf_token);
  formData.append('save_mode', 'draft');
  formData.append('date', snap.date || todayIso());
  formData.append('payment_method', snap.paymentMethod || 'cash');
  formData.append('currency', snap.currency || 'TZS');
  formData.append('exchange_rate', snap.exchangeRate || '1.0000');
  formData.append('amount', snap.amount || '0');
  formData.append('description', snap.description || '');
  if (snap.mainAccountId) formData.append('main_account_id', snap.mainAccountId);
  if (snap.accountId) formData.append('account_id', snap.accountId);
  if (snap.mainPaymentAccountId) formData.append('main_payment_account_id', snap.mainPaymentAccountId);
  if (snap.sourceAccountId) formData.append('source_account_id', snap.sourceAccountId);
  if (snap.attachment) formData.append('attachment', snap.attachment);
  if (snap.editId) formData.append('expense_id', String(snap.editId));
  return formData;
}

function persistDraftBeaconFromSnapshot(snap) {
  if (!snap?.init || snap.saving) return false;
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
    return false;
  }
  const formData = buildDraftFormDataFromSnapshot(snap);
  if (snap.editId) {
    return submitUpdateExpenseDraftOnLeave(formData);
  }
  return submitCreateExpenseDraftOnLeave(formData);
}

export default function ExpenseCreatePage({
  asModal = false,
  editId: editIdProp = null,
  onClose = null,
  onSaved = null,
} = {}) {
  const editId = useMemo(() => {
    const fromProp = editIdProp != null ? parseInt(String(editIdProp), 10) : 0;
    if (fromProp > 0) return fromProp;
    return resolveEditId();
  }, [editIdProp]);
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

  const leaveToDesk = useCallback(() => {
    if (typeof onClose === 'function') {
      onClose();
      return;
    }
    window.location.href = deskPageUrl('index.php');
  }, [onClose]);

  const finishSaved = useCallback((redirectUrl) => {
    if (typeof onSaved === 'function') {
      onSaved(redirectUrl);
      return;
    }
    if (typeof onClose === 'function') {
      onClose({ saved: true, redirect: redirectUrl });
      return;
    }
    window.location.href = redirectUrl || deskPageUrl('index.php');
  }, [onClose, onSaved]);

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
  const [advancedOpen, setAdvancedOpen] = useState(false);

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
    if (draft.currency && normalizeCurrencyIso(draft.currency) !== 'TZS') {
      setAdvancedOpen(true);
    }
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
    if (init.require_receipt) formData.append('require_receipt', '1');
    if (mainAccountId) formData.append('main_account_id', mainAccountId);
    if (accountId) formData.append('account_id', accountId);
    if (mainPaymentAccountId) formData.append('main_payment_account_id', mainPaymentAccountId);
    if (sourceAccountId) formData.append('source_account_id', sourceAccountId);
    if (attachment) formData.append('attachment', attachment);
    if (isEditing) formData.append('expense_id', String(editId));
    return formData;
  }

  function handleWalletChange(value) {
    setSourceAccountId(value);
    const wallet = (init?.simple_wallets || []).find((w) => String(w.id) === String(value));
    if (!wallet) return;
    setPaymentMethod(wallet.kind === 'cash' ? 'cash' : 'bank_transfer');
    setMainPaymentAccountId(wallet.parent_id ? String(wallet.parent_id) : '');
  }

  function handleCategoryChange(value) {
    setAccountId(value);
    const cat = (init?.simple_categories || []).find((c) => String(c.id) === String(value));
    setMainAccountId(cat?.parent_id ? String(cat.parent_id) : '');
  }

  async function handleSaveDraft() {
    if (!init) return;
    setSaving(true);
    setErrors([]);
    try {
      exitHandledRef.current = true;
      const result = await saveExpense('draft');
      finishSaved(result.redirect || deskPageUrl('index.php'));
    } catch (err) {
      exitHandledRef.current = false;
      setErrors([err instanceof Error ? err.message : 'Failed to save draft.']);
      setSaving(false);
    }
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
      if (persistDraftBeaconFromSnapshot(formSnapshotRef.current)) {
        exitHandledRef.current = true;
      }
    }

    window.addEventListener('pagehide', handlePageHide);
    return () => {
      window.removeEventListener('pagehide', handlePageHide);
      if (!exitHandledRef.current) {
        persistDraftBeaconFromSnapshot(formSnapshotRef.current);
      }
    };
  }, []);

  async function handleSubmit(event) {
    event.preventDefault();
    if (!init) return;

    setSaving(true);
    setErrors([]);

    try {
      exitHandledRef.current = true;
      const result = await saveExpense('post');
      finishSaved(result.redirect || deskPageUrl('index.php'));
    } catch (err) {
      exitHandledRef.current = false;
      setErrors([err instanceof Error ? err.message : 'Failed to save expense.']);
      setSaving(false);
    }
  }

  async function handleCancel() {
    let savedDraft = false;
    if (shouldAutoSaveDraft()) {
      setSaving(true);
      exitHandledRef.current = true;
      try {
        await saveExpense('draft');
        savedDraft = true;
      } catch {
        // Leave anyway; unmount / pagehide may still queue a draft.
        persistDraftBeaconFromSnapshot(formSnapshotRef.current);
      } finally {
        setSaving(false);
      }
    } else {
      exitHandledRef.current = true;
    }

    if (savedDraft) {
      finishSaved(deskPageUrl('index.php'));
    } else {
      leaveToDesk();
    }
  }

  const handleCancelRef = useRef(handleCancel);
  handleCancelRef.current = handleCancel;

  async function handleDeleteDraft() {
    if (!isEditing || !editId || !init?.csrf_token) return;
    const label = init.preview_expense_number || `draft #${editId}`;
    if (!window.confirm(`Delete ${label}? This cannot be undone.`)) return;

    setDeleting(true);
    setErrors([]);
    try {
      await deleteDraftExpense(editId, init.csrf_token);
      exitHandledRef.current = true;
      finishSaved(deskPageUrl('index.php'));
    } catch (err) {
      setErrors([err instanceof Error ? err.message : 'Failed to delete draft.']);
    } finally {
      setDeleting(false);
    }
  }

  useEffect(() => {
    if (!asModal) return undefined;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function handleKeyDown(event) {
      if (event.key === 'Escape' && !saving && !deleting) {
        void handleCancelRef.current();
      }
    }

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [asModal, saving, deleting]);

  if (loading) {
    const loadingBody = (
      <div className="exp-create-loading">
        <Loader2 size={22} className="exp-create-spinner" aria-hidden />
        Loading form...
      </div>
    );
    if (!asModal) return loadingBody;
    return createPortal(
      <div className="exp-create-modal-backdrop" role="presentation">
        <div className="exp-create-modal" role="dialog" aria-modal="true" aria-label="Record expense">
          <div className="exp-create-modal-head">
            <h2>Record expense</h2>
          </div>
          <div className="exp-create-modal-body">{loadingBody}</div>
        </div>
      </div>,
      document.body,
    );
  }

  if (!init) {
    const errorBody = (
      <div className="exp-create-shell">
        <div className="exp-create-alert exp-create-alert--error">
          {errors[0] || 'Could not load the expense form.'}
        </div>
        {asModal ? (
          <div className="exp-create-actions">
            <button type="button" className="exp-create-btn-cancel" onClick={leaveToDesk}>
              Close
            </button>
          </div>
        ) : null}
      </div>
    );
    if (!asModal) return errorBody;
    return createPortal(
      <div className="exp-create-modal-backdrop" onClick={leaveToDesk} role="presentation">
        <div
          className="exp-create-modal"
          role="dialog"
          aria-modal="true"
          aria-label="Record expense"
          onClick={(event) => event.stopPropagation()}
        >
          <div className="exp-create-modal-head">
            <h2>Record expense</h2>
            <button type="button" className="exp-create-modal-close" onClick={leaveToDesk} aria-label="Close">
              <X size={18} />
            </button>
          </div>
          <div className="exp-create-modal-body">{errorBody}</div>
        </div>
      </div>,
      document.body,
    );
  }

  const simpleWallets = init.simple_wallets || [];
  const simpleCategories = init.simple_categories || [];
  const hasSimpleWallets = simpleWallets.length > 0;
  const hasSimpleCategories = simpleCategories.length > 0;
  const requireReceipt = Boolean(init.require_receipt);
  const isTzs = normalizeCurrencyIso(currency) === 'TZS';
  const modalTitle = isEditing ? 'Edit expense' : 'Record expense';
  const amountCode = selectedCurrencyMeta.code || 'TSh';

  const formBody = (
    <div className={`exp-create-shell${asModal ? ' exp-create-shell--modal' : ''}`}>
      {errors.length > 0 && (
        <div className="exp-create-alert exp-create-alert--error" role="alert">
          {errors.map((msg) => (
            <div key={msg}>{msg}</div>
          ))}
        </div>
      )}

      <form onSubmit={handleSubmit}>
        <div className="exp-create-main">
          <section className="exp-create-section" id="expense-simple">
            <div className="exp-create-row">
              <label className="exp-create-label" htmlFor="exp-amount">
                Amount ({amountCode})<span className="req">*</span>
              </label>
              <div>
                <input
                  id="exp-amount"
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

            <div className="exp-create-row">
              <label className="exp-create-label" htmlFor="exp-wallet">
                Paid from<span className="req">*</span>
              </label>
              <div>
                <select
                  id="exp-wallet"
                  className="exp-create-select"
                  value={sourceAccountId}
                  onChange={(e) => handleWalletChange(e.target.value)}
                  required
                  disabled={!hasSimpleWallets}
                >
                  <option value="">
                    {hasSimpleWallets ? 'Select cash, bank, or mobile' : 'No payment wallets found'}
                  </option>
                  {simpleWallets.map((w) => (
                    <option key={w.id} value={String(w.id)}>
                      {w.label || w.name}
                    </option>
                  ))}
                </select>
                <div className="exp-create-help">
                  Cash, bank, and mobile wallets only - not Assets / Liabilities headers.
                </div>
              </div>
            </div>

            <div className="exp-create-row">
              <label className="exp-create-label" htmlFor="exp-category">
                Category<span className="req">*</span>
              </label>
              <div>
                <select
                  id="exp-category"
                  className="exp-create-select"
                  value={accountId}
                  onChange={(e) => handleCategoryChange(e.target.value)}
                  required
                  disabled={!hasSimpleCategories}
                >
                  <option value="">
                    {hasSimpleCategories ? 'Select category' : 'No expense categories found'}
                  </option>
                  {simpleCategories.map((c) => (
                    <option key={c.id} value={String(c.id)}>
                      {c.label || c.name}
                    </option>
                  ))}
                </select>
                <div className="exp-create-help">What the money was spent on.</div>
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
                  rows={2}
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  placeholder="e.g. Office supplies"
                  required
                />
              </div>
            </div>

            <div className="exp-create-row">
              <label className="exp-create-label" htmlFor="receipt_upload">
                Receipt{requireReceipt ? <span className="req">*</span> : null}
              </label>
              <div>
                <input
                  id="receipt_upload"
                  type="file"
                  className="exp-create-input"
                  accept=".jpg,.jpeg,.png,.pdf"
                  onChange={(e) => setAttachment(e.target.files?.[0] || null)}
                  required={requireReceipt && !isEditing && !existingAttachment}
                />
                <div className="exp-create-help">
                  {requireReceipt ? 'Required. ' : 'Optional. '}JPG, PNG, or PDF.
                  {existingAttachment ? ` Current: ${existingAttachment}` : ''}
                </div>
              </div>
            </div>
          </section>

          <div className="exp-create-advanced">
            <button
              type="button"
              className={`exp-create-advanced-toggle${advancedOpen ? ' is-open' : ''}`}
              onClick={() => setAdvancedOpen((o) => !o)}
              aria-expanded={advancedOpen}
            >
              <span>More details</span>
              <ChevronDown size={16} aria-hidden="true" />
            </button>

            {advancedOpen ? (
              <div className="exp-create-advanced-body">
                <div className="exp-create-row">
                  <label className="exp-create-label">Expense number</label>
                  <div>
                    <input
                      type="text"
                      readOnly
                      className="exp-create-input exp-create-input--readonly"
                      value={init.preview_expense_number}
                    />
                    <div className="exp-create-help">Generated automatically when saved.</div>
                  </div>
                </div>

                <div className="exp-create-row">
                  <label className="exp-create-label">Currency</label>
                  <div>
                    <div className={`exp-create-currency${currencyOpen ? ' is-open' : ''}`} ref={currencyRef}>
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
                  </div>
                </div>

                <div className="exp-create-row">
                  <label className="exp-create-label" htmlFor="exchange_rate">Exchange rate</label>
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

                <p className="exp-create-help" style={{ margin: '0.25rem 0 0.75rem' }}>
                  For multi-currency or accounting review. Category and Paid from already post to the ledger.
                </p>
              </div>
            ) : null}
          </div>

          <div className="exp-create-actions">
            {isEditing && (
              <button
                type="button"
                className="exp-create-btn-delete"
                onClick={handleDeleteDraft}
                disabled={saving || deleting}
              >
                {deleting ? (
                  <Loader2 size={14} className="exp-create-spinner" aria-hidden />
                ) : (
                  <Trash2 size={14} aria-hidden="true" />
                )}
                {deleting ? 'Deleting...' : 'Delete draft'}
              </button>
            )}
            <button
              type="button"
              className="exp-create-btn-draft"
              onClick={() => { void handleSaveDraft(); }}
              disabled={saving || deleting}
            >
              Save draft
            </button>
            <button
              type="button"
              className="exp-create-btn-cancel"
              onClick={handleCancel}
              disabled={saving || deleting}
            >
              Cancel
            </button>
            <button type="submit" className="exp-create-btn-save" disabled={saving || deleting}>
              {saving && <Loader2 size={14} className="exp-create-spinner" aria-hidden />}
              Record expense
            </button>
          </div>
          <p className="exp-create-actions-hint">
            If you close or cancel after starting this form, it is saved as a draft automatically.
          </p>
        </div>
      </form>
    </div>
  );

  if (!asModal) {
    return formBody;
  }

  return createPortal(
    <div
      className="exp-create-modal-backdrop"
      onClick={saving || deleting ? undefined : () => { void handleCancel(); }}
      role="presentation"
    >
      <div
        className="exp-create-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="exp-create-modal-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="exp-create-modal-head">
          <h2 id="exp-create-modal-title">{modalTitle}</h2>
          <button
            type="button"
            className="exp-create-modal-close"
            onClick={() => { void handleCancel(); }}
            disabled={saving || deleting}
            aria-label="Close"
          >
            <X size={18} />
          </button>
        </div>
        <div className="exp-create-modal-body">{formBody}</div>
      </div>
    </div>,
    document.body,
  );
}
