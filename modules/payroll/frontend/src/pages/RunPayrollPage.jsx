import { useCallback, useEffect, useState } from 'react';
import { ArrowLeft, CalendarDays, Loader2, Users } from 'lucide-react';
import RunPayrollModal from '../components/RunPayrollModal';
import {
  deskPageUrl,
  fetchRunInit,
  formatMoney,
} from '../api/payrollDesk';

export default function RunPayrollPage() {
  const [init, setInit] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [modalOpen, setModalOpen] = useState(true);

  const loadData = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const data = await fetchRunInit();
      setInit(data);
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
  const stats = init?.stats || {};
  const missingTables = init?.missingTables || [];
  const setupRequired = missingTables.length > 0;

  function closeModal() {
    setModalOpen(false);
    window.location.href = links.dashboard || deskPageUrl('index.php');
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
          {!setupRequired && (
            <button
              type="button"
              className="pay-desk-btn pay-desk-btn-primary"
              onClick={() => setModalOpen(true)}
            >
              Generate payroll
            </button>
          )}
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
              <div className="pay-desk-kpi-value">{stats.lastRun?.periodLabel || '-'}</div>
              {stats.lastRun?.totalPayout != null && (
                <div className="pay-desk-kpi-helper">{formatMoney(stats.lastRun.totalPayout)}</div>
              )}
            </div>
          </div>
        </div>
      </section>

      <RunPayrollModal
        open={modalOpen}
        onClose={closeModal}
        onGenerated={() => {
          loadData();
        }}
      />
    </div>
  );
}
