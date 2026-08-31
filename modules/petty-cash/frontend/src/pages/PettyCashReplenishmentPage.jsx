import { useEffect, useState } from 'react';
import { Loader2 } from 'lucide-react';
import { deskPageUrl, fetchReplenishmentInit, submitReplenishmentRequest } from '../api/pettyCashDesk.js';
import { formatMoney } from '../utils/format.js';

export default function PettyCashReplenishmentPage() {
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [form, setForm] = useState({
    petty_cash_account_id: '',
    source_account_id: '',
    amount: '',
    description: '',
  });

  useEffect(() => {
    fetchReplenishmentInit()
      .then((data) => {
        setInit(data);
        if (data.default_petty_cash_account_id) {
          setForm((current) => ({
            ...current,
            petty_cash_account_id: String(data.default_petty_cash_account_id),
          }));
        }
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);

  const sourceAccounts = init?.source_accounts || [];

  useEffect(() => {
    if (!form.source_account_id) return;
    const selected = sourceAccounts.find((a) => String(a.id) === String(form.source_account_id));
    if (selected && !(Number(selected.balance) > 0)) {
      setForm((current) => ({ ...current, source_account_id: '' }));
    }
  }, [form.source_account_id, sourceAccounts]);

  async function onSubmit(event) {
    event.preventDefault();
    if (!init?.can_manage) {
      setError('Only Finance or Admin can create top-up requests.');
      return;
    }
    const selectedSource = sourceAccounts.find((a) => String(a.id) === String(form.source_account_id));
    if (!selectedSource || !(Number(selectedSource.balance) > 0)) {
      setError('Select a source account with available balance.');
      return;
    }
    const amountValue = parseFloat(form.amount);
    if (Number.isFinite(amountValue) && amountValue > Number(selectedSource.balance)) {
      setError(`Amount cannot exceed source balance (${formatMoney(selectedSource.balance)}).`);
      return;
    }
    setBusy(true);
    setError('');
    try {
      const result = await submitReplenishmentRequest({
        petty_cash_account_id: parseInt(form.petty_cash_account_id, 10),
        source_account_id: parseInt(form.source_account_id, 10),
        amount: amountValue,
        description: form.description.trim(),
      });
      window.location.href = result.redirect || deskPageUrl('replenishments/index.php');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not submit request.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <div className="exp-create-loading">
        <Loader2 size={22} className="exp-create-spinner" aria-hidden />
        <span>Loading...</span>
      </div>
    );
  }

  const pettyAccounts = init?.petty_accounts || [];
  const lockedPetty = pettyAccounts.find(
    (a) => String(a.id) === String(form.petty_cash_account_id || init?.default_petty_cash_account_id || ''),
  ) || pettyAccounts[0] || null;
  const hasSelectableSource = sourceAccounts.some((a) => Number(a.balance) > 0);

  return (
    <div className="exp-create-shell">
      <div className="exp-create-topbar">
        <a href={deskPageUrl('index.php')} className="exp-desk-action-link" style={{ fontSize: '0.8125rem' }}>
          Back to dashboard
        </a>
      </div>

      {error ? (
        <div className="exp-create-alert exp-create-alert--error" role="alert">{error}</div>
      ) : null}

      {!init?.has_financial_accounts ? (
        <div className="exp-create-alert exp-create-alert--error">
          Set up financial accounts in Balances before requesting a top-up.
        </div>
      ) : (
        <form className="exp-create-layout" onSubmit={onSubmit}>
          <div className="exp-create-main">
            <section className="exp-create-section">
              <div className="exp-create-section-header">
                <h2>Top-up details</h2>
                <p>
                  Your float: {formatMoney(init?.custodian_float ?? 0)}
                  {' | '}
                  Petty GL total: {formatMoney(init?.petty_balance_total ?? 0)}
                </p>
              </div>

              <div className="exp-create-row">
                <label className="exp-create-label">Petty cash account</label>
                <div>
                  <input
                    type="text"
                    className="exp-create-input exp-create-input--readonly"
                    readOnly
                    value={
                      lockedPetty
                        ? `${lockedPetty.name} (${formatMoney(lockedPetty.balance)})`
                        : 'Petty Cash'
                    }
                  />
                  <div className="exp-create-help">
                    Default Balances Petty Cash account. Category spend uses its sub-accounts.
                  </div>
                </div>
              </div>

              <div className="exp-create-row">
                <label className="exp-create-label" htmlFor="pc-rep-source">
                  Source account <span className="req">*</span>
                </label>
                <div>
                  <select
                    id="pc-rep-source"
                    required
                    className="exp-create-select"
                    value={form.source_account_id}
                    onChange={(e) => setForm((f) => ({ ...f, source_account_id: e.target.value }))}
                  >
                    <option value="">
                      {hasSelectableSource ? 'Select account' : 'No funded source accounts'}
                    </option>
                    {sourceAccounts.map((a) => {
                      const funded = Number(a.balance) > 0;
                      return (
                        <option
                          key={a.id}
                          value={a.id}
                          disabled={!funded}
                          className={funded ? undefined : 'pc-source-option--unavailable'}
                        >
                          {funded
                            ? `${a.name} (${formatMoney(a.balance)})`
                            : `${a.name} (${formatMoney(a.balance)}) — unavailable`}
                        </option>
                      );
                    })}
                  </select>
                  <div className="exp-create-help">
                    Accounts with zero balance are marked{' '}
                    <span className="pc-source-unavailable-text">unavailable</span> and cannot be selected.
                  </div>
                </div>
              </div>

              <div className="exp-create-row">
                <label className="exp-create-label" htmlFor="pc-rep-amount">
                  Amount <span className="req">*</span>
                </label>
                <div>
                  <input
                    id="pc-rep-amount"
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
                <label className="exp-create-label" htmlFor="pc-rep-desc">
                  Description <span className="req">*</span>
                </label>
                <div>
                  <textarea
                    id="pc-rep-desc"
                    required
                    rows={3}
                    className="exp-create-textarea"
                    value={form.description}
                    onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
                  />
                </div>
              </div>
            </section>

            <div className="exp-create-actions">
              <a href={deskPageUrl('index.php')} className="exp-create-btn-cancel">Cancel</a>
              <button
                type="submit"
                className="exp-create-btn-save"
                disabled={busy || !init?.can_manage || !hasSelectableSource}
              >
                {busy ? (
                  <>
                    <Loader2 size={18} className="exp-create-spinner" aria-hidden />
                    Submitting...
                  </>
                ) : (
                  'Submit request'
                )}
              </button>
            </div>
          </div>
        </form>
      )}
    </div>
  );
}
