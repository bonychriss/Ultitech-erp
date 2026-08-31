import { useEffect, useMemo, useState } from 'react';
import { ArrowLeft, ArrowRight, History, Loader2, Send } from 'lucide-react';
import { createTransfer, fetchInit } from '../api';
import type { TransferAccount, TransferFormState } from '../types';

const BUCKET_LABEL: Record<string, string> = {
  cash: 'Cash',
  bank: 'Bank',
  mobile: 'Mobile Money',
};

const sections = [
  { id: 'general-info', label: 'General' },
  { id: 'accounts', label: 'Accounts' },
  { id: 'amount', label: 'Amount' },
] as const;

function formatMoney(amount: number, currency = 'TZS'): string {
  return `${currency} ${amount.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

function resolveMethod(accounts: TransferAccount[], fromId: number, toId: number): string {
  const from = accounts.find((a) => a.id === fromId);
  const to = accounts.find((a) => a.id === toId);
  if (!from || !to) return '';
  return `${BUCKET_LABEL[from.bucket] || 'Bank'} to ${BUCKET_LABEL[to.bucket] || 'Bank'}`;
}

export default function TransferPage() {
  const [accounts, setAccounts] = useState<TransferAccount[]>([]);
  const [historyUrl, setHistoryUrl] = useState('transactions.php');
  const [transferUrl, setTransferUrl] = useState('transfer.php');
  const [form, setForm] = useState<TransferFormState>({
    transferDate: '',
    referenceNo: '',
    description: '',
    fromAccount: 0,
    toAccount: 0,
    currency: 'TZS',
    amount: '',
    exchangeRate: '1.00',
  });
  const [activeSection, setActiveSection] = useState<string>('general-info');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError('');
      try {
        const init = await fetchInit();
        if (cancelled) return;
        setAccounts(init.accounts);
        setHistoryUrl(init.historyUrl || 'transactions.php');
        setTransferUrl(init.transferUrl || 'transfer.php');
        setForm({
          transferDate: init.defaults.transferDate,
          referenceNo: init.defaults.referenceNo,
          description: '',
          fromAccount: 0,
          toAccount: 0,
          currency: init.defaults.currency || 'TZS',
          amount: '',
          exchangeRate: init.defaults.exchangeRate || '1.00',
        });
        if (init.flashSuccess) setSuccess(init.flashSuccess);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Failed to load transfer form.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const transferMethod = useMemo(
    () => resolveMethod(accounts, form.fromAccount, form.toAccount),
    [accounts, form.fromAccount, form.toAccount],
  );

  const fromAccount = accounts.find((a) => a.id === form.fromAccount) ?? null;
  const toAccount = accounts.find((a) => a.id === form.toAccount) ?? null;

  function updateField<K extends keyof TransferFormState>(key: K, value: TransferFormState[K]) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  async function handleSubmit() {
    setError('');
    setSuccess('');
    if (!form.fromAccount || !form.toAccount) {
      setError('Please select both source and destination accounts.');
      return;
    }
    if (form.fromAccount === form.toAccount) {
      setError('Source and destination accounts cannot be the same.');
      return;
    }
    if (!(Number.parseFloat(form.amount) > 0)) {
      setError('Amount must be greater than zero.');
      return;
    }
    if (!form.referenceNo.trim()) {
      setError('Reference number is required.');
      return;
    }

    setSaving(true);
    try {
      const result = await createTransfer(form);
      setSuccess(result.message);
      window.location.href = result.transferUrl || transferUrl;
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Transfer failed.');
      setSaving(false);
    }
  }

  if (loading) {
    return (
      <div className="tf-page tf-boot-loading" role="status" aria-live="polite">
        <Loader2 className="tf-boot-spinner" aria-hidden="true" />
        <span>Loading transfer form...</span>
      </div>
    );
  }

  return (
    <div className="tf-page">
      <header className="tf-topbar">
        <div>
          <h1>Internal Transfer</h1>
          <p>Transfer funds between your internal accounts</p>
        </div>
        <div className="tf-topbar-actions">
          <a href={historyUrl} className="tf-link">
            <History className="w-3.5 h-3.5" aria-hidden="true" />
            Transfer History
          </a>
          <a href={historyUrl} className="tf-link tf-link-muted">
            <ArrowLeft className="w-3.5 h-3.5" aria-hidden="true" />
            Back
          </a>
        </div>
      </header>

      {error && (
        <div className="tf-flash tf-flash-error" role="alert">
          {error}
        </div>
      )}
      {success && (
        <div className="tf-flash tf-flash-success" role="status">
          {success}
        </div>
      )}

      <div className="tf-layout">
        <aside className="tf-nav" aria-label="Form sections">
          <ul>
            {sections.map((section) => (
              <li key={section.id}>
                <a
                  href={`#${section.id}`}
                  className={activeSection === section.id ? 'is-active' : ''}
                  onClick={() => setActiveSection(section.id)}
                >
                  {section.label}
                </a>
              </li>
            ))}
          </ul>
        </aside>

        <div className="tf-main">
          <section className="tf-section" id="general-info">
            <div className="tf-section-head">
              <h2>General Information</h2>
              <p>Date, reference, and description for this transfer.</p>
            </div>

            <div className="tf-row">
              <label className="tf-label" htmlFor="transfer_date">
                Transfer Date <span>*</span>
              </label>
              <div>
                <input
                  id="transfer_date"
                  type="date"
                  className="tf-input"
                  value={form.transferDate}
                  onChange={(e) => updateField('transferDate', e.target.value)}
                  required
                />
              </div>
            </div>

            <div className="tf-row">
              <label className="tf-label" htmlFor="reference_no">
                Reference No <span>*</span>
              </label>
              <div>
                <input
                  id="reference_no"
                  type="text"
                  className="tf-input"
                  value={form.referenceNo}
                  onChange={(e) => updateField('referenceNo', e.target.value)}
                  placeholder="e.g. ITR-20260303-120000"
                  required
                />
                <p className="tf-help">Unique reference for this internal transfer.</p>
              </div>
            </div>

            <div className="tf-row">
              <label className="tf-label" htmlFor="description">
                Description
              </label>
              <div>
                <input
                  id="description"
                  type="text"
                  className="tf-input"
                  value={form.description}
                  onChange={(e) => updateField('description', e.target.value)}
                  placeholder="e.g. Transfer from Petty Cash to CRDB Bank"
                />
                <p className="tf-help">Optional note shown on account transactions.</p>
              </div>
            </div>
          </section>

          <section className="tf-section" id="accounts">
            <div className="tf-section-head">
              <h2>Accounts</h2>
              <p>Select source and destination accounts.</p>
            </div>

            <div className="tf-row">
              <label className="tf-label">
                Transfer Route <span>*</span>
              </label>
              <div className="tf-route">
                <div>
                  <select
                    id="from_account"
                    className={`tf-input${!form.fromAccount ? ' is-placeholder' : ''}`}
                    value={form.fromAccount || ''}
                    onChange={(e) => updateField('fromAccount', Number(e.target.value) || 0)}
                    required
                  >
                    <option value="">Select source account</option>
                    {accounts.map((account) => (
                      <option key={account.id} value={account.id}>
                        {account.name}
                      </option>
                    ))}
                  </select>
                  <p className="tf-help">
                    Available balance:{' '}
                    {fromAccount ? formatMoney(fromAccount.balance, fromAccount.currency) : '-'}
                  </p>
                </div>

                <div className="tf-route-arrow" aria-hidden="true">
                  <ArrowRight className="w-4 h-4" />
                </div>

                <div>
                  <select
                    id="to_account"
                    className={`tf-input${!form.toAccount ? ' is-placeholder' : ''}`}
                    value={form.toAccount || ''}
                    onChange={(e) => updateField('toAccount', Number(e.target.value) || 0)}
                    required
                  >
                    <option value="">Select destination account</option>
                    {accounts.map((account) => (
                      <option key={account.id} value={account.id}>
                        {account.name}
                      </option>
                    ))}
                  </select>
                  <p className="tf-help">
                    Available balance: {toAccount ? formatMoney(toAccount.balance, toAccount.currency) : '-'}
                  </p>
                </div>
              </div>
            </div>

            <div className="tf-row">
              <label className="tf-label" htmlFor="transfer_method_display">
                Transfer Method
              </label>
              <div>
                <input
                  id="transfer_method_display"
                  type="text"
                  className={`tf-input tf-input-readonly${transferMethod ? ' has-value' : ' is-empty'}`}
                  value={transferMethod}
                  placeholder="Select source and destination accounts"
                  readOnly
                />
                <p className="tf-help">Set automatically from account types (e.g. Bank to Cash).</p>
              </div>
            </div>
          </section>

          <section className="tf-section" id="amount">
            <div className="tf-section-head">
              <h2>Amount</h2>
              <p>Currency and transfer amount.</p>
            </div>

            <div className="tf-row">
              <label className="tf-label" htmlFor="currency">
                Currency <span>*</span>
              </label>
              <div>
                <select
                  id="currency"
                  className="tf-input"
                  value={form.currency}
                  onChange={(e) => updateField('currency', e.target.value)}
                >
                  <option value="TZS">TZS - Tanzanian Shilling</option>
                  <option value="USD">USD - US Dollar</option>
                </select>
              </div>
            </div>

            <div className="tf-row">
              <label className="tf-label" htmlFor="amount">
                Amount <span>*</span>
              </label>
              <div>
                <input
                  id="amount"
                  type="number"
                  min="0"
                  step="0.01"
                  className="tf-input"
                  value={form.amount}
                  onChange={(e) => updateField('amount', e.target.value)}
                  placeholder="0.00"
                  required
                />
              </div>
            </div>

            <div className="tf-row">
              <label className="tf-label" htmlFor="exchange_rate">
                Exchange Rate
              </label>
              <div>
                <input
                  id="exchange_rate"
                  type="number"
                  min="0"
                  step="0.0001"
                  className="tf-input"
                  value={form.exchangeRate}
                  onChange={(e) => updateField('exchangeRate', e.target.value)}
                  placeholder="1.00"
                />
                <p className="tf-help">Use 1.00 when both accounts use the same currency.</p>
              </div>
            </div>
          </section>

          <div className="tf-actions">
            <a href={historyUrl} className="tf-btn tf-btn-secondary">
              Cancel
            </a>
            <button
              type="button"
              className="tf-btn tf-btn-primary"
              onClick={() => void handleSubmit()}
              disabled={saving}
            >
              {saving ? <Loader2 className="tf-btn-spinner" aria-hidden="true" /> : <Send className="w-4 h-4" aria-hidden="true" />}
              {saving ? 'Posting...' : 'Post Transfer'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
