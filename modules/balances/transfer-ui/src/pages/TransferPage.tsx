import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { ArrowLeft, ArrowRight, Loader2, Send } from 'lucide-react';
import { createTransfer, fetchInit } from '../api';
import type { TransferAccount, TransferFormState } from '../types';

const BUCKET_LABEL: Record<string, string> = {
  cash: 'Cash',
  bank: 'Bank',
  mobile: 'Mobile Money',
};

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

function FieldRow({
  label,
  htmlFor,
  required,
  children,
  help,
}: {
  label: string;
  htmlFor?: string;
  required?: boolean;
  children: ReactNode;
  help?: string;
}) {
  return (
    <div className="tf-row">
      <label className="tf-label" htmlFor={htmlFor}>
        {label}
        {required ? <span className="tf-req">*</span> : null}
      </label>
      <div className="tf-field">
        {children}
        {help ? <p className="tf-help">{help}</p> : null}
      </div>
    </div>
  );
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

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
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
      <div className="tf-shell tf-boot-loading" role="status" aria-live="polite">
        <Loader2 className="tf-boot-spinner" aria-hidden="true" />
        <span>Loading transfer form...</span>
      </div>
    );
  }

  return (
    <div className="tf-shell">
      <div className="tf-topbar">
        <div>
          <h1>Internal Transfer</h1>
        </div>
        <a href={historyUrl} className="tf-btn-back">
          <ArrowLeft size={16} aria-hidden="true" />
          Back to Transactions
        </a>
      </div>

      <form className="tf-layout" onSubmit={(e) => void handleSubmit(e)} noValidate>
        <div className="tf-main">
          {success ? (
            <div className="tf-alert tf-alert--success" role="status">
              {success}
            </div>
          ) : null}
          {error ? (
            <div className="tf-alert tf-alert--error" role="alert">
              {error}
            </div>
          ) : null}

          <section className="tf-section" id="general-info">
            <div className="tf-card">
              <FieldRow label="Transfer Date" htmlFor="transfer_date" required>
                <input
                  id="transfer_date"
                  type="date"
                  className="tf-input"
                  value={form.transferDate}
                  onChange={(e) => updateField('transferDate', e.target.value)}
                  required
                />
              </FieldRow>

              <FieldRow
                label="Reference No"
                htmlFor="reference_no"
                required
                help="Unique reference for this internal transfer."
              >
                <input
                  id="reference_no"
                  type="text"
                  className="tf-input"
                  value={form.referenceNo}
                  onChange={(e) => updateField('referenceNo', e.target.value)}
                  placeholder="e.g. ITR-20260303-120000"
                  required
                />
              </FieldRow>

              <FieldRow
                label="Description"
                htmlFor="description"
                help="Optional note shown on account transactions."
              >
                <input
                  id="description"
                  type="text"
                  className="tf-input"
                  value={form.description}
                  onChange={(e) => updateField('description', e.target.value)}
                  placeholder="e.g. Transfer from Petty Cash to CRDB Bank"
                />
              </FieldRow>
            </div>
          </section>

          <section className="tf-section" id="accounts">
            <div className="tf-section-header">
              <h2>Accounts</h2>
            </div>
            <div className="tf-card">
              <FieldRow label="Transfer Route" required>
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
                      {fromAccount ? formatMoney(fromAccount.balance, fromAccount.currency) : '—'}
                    </p>
                  </div>

                  <div className="tf-route-arrow" aria-hidden="true">
                    <ArrowRight size={16} />
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
                      Available balance:{' '}
                      {toAccount ? formatMoney(toAccount.balance, toAccount.currency) : '—'}
                    </p>
                  </div>
                </div>
              </FieldRow>

              <FieldRow
                label="Transfer Method"
                htmlFor="transfer_method_display"
                help="Set automatically from account types (e.g. Bank to Cash)."
              >
                <input
                  id="transfer_method_display"
                  type="text"
                  className={`tf-input tf-input--readonly${transferMethod ? '' : ' is-empty'}`}
                  value={transferMethod}
                  placeholder="Select source and destination accounts"
                  readOnly
                />
              </FieldRow>
            </div>
          </section>

          <section className="tf-section" id="amount">
            <div className="tf-section-header">
              <h2>Amount</h2>
            </div>
            <div className="tf-card">
              <FieldRow label="Currency" htmlFor="currency" required>
                <select
                  id="currency"
                  className="tf-input"
                  value={form.currency}
                  onChange={(e) => updateField('currency', e.target.value)}
                >
                  <option value="TZS">TZS - Tanzanian Shilling</option>
                  <option value="USD">USD - US Dollar</option>
                </select>
              </FieldRow>

              <FieldRow label="Amount" htmlFor="amount" required>
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
              </FieldRow>

              <FieldRow
                label="Exchange Rate"
                htmlFor="exchange_rate"
                help="Use 1.00 when both accounts use the same currency."
              >
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
              </FieldRow>
            </div>
          </section>

          <div className="tf-actions">
            <a href={historyUrl} className="tf-btn-cancel">
              Cancel
            </a>
            <button type="submit" className="tf-btn-save" disabled={saving}>
              {saving ? (
                <Loader2 className="tf-btn-spinner" aria-hidden="true" />
              ) : (
                <Send size={16} aria-hidden="true" />
              )}
              {saving ? 'Posting...' : 'Post Transfer'}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
}
