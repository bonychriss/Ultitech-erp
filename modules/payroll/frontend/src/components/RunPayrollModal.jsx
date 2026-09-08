import { useCallback, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { Info, Loader2, X, Zap } from 'lucide-react';
import {
  deskPageUrl,
  fetchRunInit,
  generatePayrollRun,
} from '../api/payrollDesk';

const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

export default function RunPayrollModal({ open, onClose, onGenerated }) {
  const now = new Date();
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(null);
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [year, setYear] = useState(now.getFullYear());

  const loadData = useCallback(async () => {
    setLoading(true);
    setError('');
    setSuccess(null);
    try {
      const data = await fetchRunInit();
      setInit(data);
      if (data?.defaults?.month) setMonth(Number(data.defaults.month));
      if (data?.defaults?.year) setYear(Number(data.defaults.year));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load run payroll.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!open) return undefined;

    loadData();

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function handleKeyDown(event) {
      if (event.key === 'Escape' && !submitting) onClose();
    }

    window.addEventListener('keydown', handleKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [open, loadData, onClose, submitting]);

  if (!open) return null;

  const months = init?.months?.length
    ? init.months
    : MONTHS.map((label, i) => ({ value: i + 1, label }));
  const missingTables = init?.missingTables || [];
  const setupRequired = missingTables.length > 0;
  const links = init?.links || {};

  async function onSubmit(event) {
    event.preventDefault();
    if (submitting || setupRequired || loading) return;
    setSubmitting(true);
    setError('');
    setSuccess(null);
    try {
      const res = await generatePayrollRun({ month, year });
      const payload = res.data || {};
      setSuccess({
        message: res.message || 'Payroll generated successfully.',
        data: payload,
      });
      if (typeof onGenerated === 'function') {
        onGenerated(payload);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to generate payroll.');
    } finally {
      setSubmitting(false);
    }
  }

  return createPortal(
    <div
      className="pay-desk-modal-backdrop"
      role="presentation"
      onClick={() => {
        if (!submitting) onClose();
      }}
    >
      <div
        className="pay-desk-modal pay-run-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pay-run-modal-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="pay-salary-edit-modal-head">
          <h2 id="pay-run-modal-title" className="pay-salary-edit-modal-title">
            Generate monthly payroll
          </h2>
          <button
            type="button"
            className="pay-salary-edit-modal-close"
            onClick={onClose}
            aria-label="Close"
            disabled={submitting}
          >
            <X size={18} aria-hidden="true" />
          </button>
        </div>

        <div className="pay-run-modal-body">
          {loading ? (
            <div className="pay-desk-loading" role="status" aria-live="polite">
              <Loader2 className="pay-desk-boot-spinner" size={18} aria-hidden="true" />
              <span>Loading...</span>
            </div>
          ) : (
            <>
              {setupRequired && (
                <div className="pay-desk-setup" role="alert">
                  <h2>Payroll setup required</h2>
                  <p>Missing tables: <strong>{missingTables.join(', ')}</strong></p>
                  <a href={links.setup || deskPageUrl('setup.php')} className="pay-desk-btn pay-desk-btn-primary pay-desk-btn--pill">
                    Run payroll setup
                  </a>
                </div>
              )}

              {error && <div className="pay-desk-flash-error" role="alert">{error}</div>}

              {success && (
                <div className="pay-desk-flash-ok" role="status">
                  <span>{success.message}</span>
                  <div className="pay-run-modal-success-actions">
                    {(success.data.viewRunUrl || success.data.runId) && (
                      <a
                        href={success.data.viewRunUrl || deskPageUrl('view_run.php', { id: success.data.runId })}
                        className="pay-desk-btn pay-desk-btn-primary pay-desk-btn--pill"
                      >
                        View run
                      </a>
                    )}
                    <button type="button" className="pay-desk-btn pay-desk-btn-secondary pay-desk-btn--pill" onClick={onClose}>
                      Close
                    </button>
                  </div>
                </div>
              )}

              {!success && (
                <form className="pay-run-form" onSubmit={onSubmit}>
                  <div className="pay-run-form-grid">
                    <label className="pay-run-field">
                      <span>Month</span>
                      <select
                        value={month}
                        onChange={(e) => setMonth(Number(e.target.value))}
                        required
                        disabled={submitting || setupRequired}
                      >
                        {months.map((m) => (
                          <option key={m.value} value={m.value}>{m.label}</option>
                        ))}
                      </select>
                    </label>
                    <label className="pay-run-field">
                      <span>Year</span>
                      <input
                        type="number"
                        min={2000}
                        max={2100}
                        value={year}
                        onChange={(e) => setYear(Number(e.target.value))}
                        required
                        disabled={submitting || setupRequired}
                      />
                    </label>
                  </div>

                  <div className="pay-run-form-hint">
                    <Info size={15} aria-hidden="true" />
                    <span>
                      Calculates salaries for all active employees with salary records using current tax and settings
                      {init?.company?.name ? ` - ${init.company.name}` : ''}.
                    </span>
                  </div>

                  <button
                    type="submit"
                    className="pay-desk-btn pay-desk-btn-primary pay-desk-btn--pill pay-run-submit"
                    disabled={submitting || setupRequired}
                  >
                    {submitting ? (
                      <>
                        <Loader2 className="pay-desk-boot-spinner" size={16} aria-hidden="true" />
                        Generating...
                      </>
                    ) : (
                      <>
                        <Zap size={16} aria-hidden="true" />
                        Generate draft payroll
                      </>
                    )}
                  </button>
                </form>
              )}
            </>
          )}
        </div>
      </div>
    </div>,
    document.body,
  );
}
