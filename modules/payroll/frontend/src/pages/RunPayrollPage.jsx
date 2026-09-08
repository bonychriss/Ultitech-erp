import { useCallback, useEffect, useState } from 'react';
import {
  ArrowLeft,
  CalendarDays,
  Info,
  Loader2,
  Users,
  Zap,
} from 'lucide-react';
import {
  deskPageUrl,
  fetchRunInit,
  formatMoney,
  generatePayrollRun,
} from '../api/payrollDesk';

const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

export default function RunPayrollPage() {
  const now = new Date();
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(null);
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [year, setYear] = useState(now.getFullYear());

  const loadData = useCallback(async () => {
    setLoading(true);
    setError('');
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
    loadData();
  }, [loadData]);

  const links = init?.links || {};
  const months = init?.months?.length
    ? init.months
    : MONTHS.map((label, i) => ({ value: i + 1, label }));
  const stats = init?.stats || {};
  const missingTables = init?.missingTables || [];
  const setupRequired = missingTables.length > 0;

  async function onSubmit(event) {
    event.preventDefault();
    if (submitting || setupRequired) return;
    setSubmitting(true);
    setError('');
    setSuccess(null);
    try {
      const res = await generatePayrollRun({ month, year });
      setSuccess({
        message: res.message || 'Payroll generated successfully.',
        data: res.data || {},
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to generate payroll.');
    } finally {
      setSubmitting(false);
    }
  }

  if (loading) {
    return (
      <div className="pay-desk-page pay-desk-boot-loading" role="status" aria-live="polite">
        <Loader2 className="pay-desk-boot-spinner" aria-hidden="true" />
        <span>Loading run payroll...</span>
      </div>
    );
  }

  return (
    <div className="pay-desk-page">
      <div className="pay-desk-page-header">
        <div className="pay-desk-page-header-actions">
          <a href={links.dashboard || deskPageUrl('index.php')} className="pay-desk-btn pay-desk-btn-secondary">
            <ArrowLeft size={15} aria-hidden="true" />
            <span className="pay-desk-btn-label-desktop">Dashboard</span>
            <span className="pay-desk-btn-label-mobile">Back</span>
          </a>
        </div>
      </div>

      {setupRequired && (
        <div className="pay-desk-setup" role="alert">
          <h2>Payroll setup required</h2>
          <p>Missing tables: <strong>{missingTables.join(', ')}</strong></p>
          <a href={links.setup || deskPageUrl('setup.php')} className="pay-desk-btn pay-desk-btn-primary">
            Run payroll setup
          </a>
        </div>
      )}

      {error && <div className="pay-desk-flash-error" role="alert">{error}</div>}

      {success && (
        <div className="pay-desk-flash-ok" role="status">
          <span>{success.message}</span>
          <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap', marginTop: '0.5rem' }}>
            {(success.data.viewRunUrl || success.data.runId) && (
              <a
                href={success.data.viewRunUrl || deskPageUrl('view_run.php', { id: success.data.runId })}
                className="pay-desk-btn pay-desk-btn-primary"
              >
                View run
              </a>
            )}
            <a
              href={success.data.dashboardUrl || links.dashboard || deskPageUrl('index.php')}
              className="pay-desk-btn pay-desk-btn-success"
            >
              Go to payroll
            </a>
          </div>
        </div>
      )}

      <section className="pay-desk-kpi-grid" aria-label="Summary">
        <div className="pay-desk-kpi-card">
          <div className="pay-desk-kpi">
            <div className="pay-desk-kpi-icon pay-desk-kpi-icon--violet">
              <Users size={20} aria-hidden="true" />
            </div>
            <div className="pay-desk-kpi-body">
              <div className="pay-desk-kpi-label">staff with salaries</div>
              <div className="pay-desk-kpi-value">{stats.totalSalariedStaff ?? 0}</div>
            </div>
          </div>
        </div>
        <div className="pay-desk-kpi-card">
          <div className="pay-desk-kpi">
            <div className="pay-desk-kpi-icon pay-desk-kpi-icon--indigo">
              <CalendarDays size={20} aria-hidden="true" />
            </div>
            <div className="pay-desk-kpi-body">
              <div className="pay-desk-kpi-label">last run</div>
              <div className="pay-desk-kpi-value">{stats.lastRun?.periodLabel || 'ù'}</div>
              {stats.lastRun?.totalPayout != null && (
                <div className="pay-desk-kpi-helper">{formatMoney(stats.lastRun.totalPayout)}</div>
              )}
            </div>
          </div>
        </div>
      </section>

      <div className="pay-run-form-card">
        <div className="pay-run-form-card-head">
          <Zap size={18} color="#2563eb" aria-hidden="true" />
          Generate monthly payroll
        </div>
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
            className="pay-desk-btn pay-desk-btn-primary pay-run-submit"
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
      </div>
    </div>
  );
}
