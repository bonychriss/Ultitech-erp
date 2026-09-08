import { useCallback, useState } from 'react';
import { deskPageUrl, resolvePayslipId } from '../api/payrollDesk';
import EditPayslipModal from '../components/EditPayslipModal.jsx';

export default function EditPayslipPage() {
  const payslipId = resolvePayslipId();
  const [open, setOpen] = useState(true);
  const [runId, setRunId] = useState(0);

  const rememberRunId = useCallback((data) => {
    const nextRunId = Number(data?.payslip?.runId || 0);
    if (nextRunId > 0) setRunId(nextRunId);
  }, []);

  function closeModal() {
    setOpen(false);
    if (runId > 0) {
      window.location.href = deskPageUrl('view_run.php', { id: runId });
      return;
    }
    if (window.history.length > 1) {
      window.history.back();
      return;
    }
    window.location.href = deskPageUrl('index.php');
  }

  return (
    <div className="pay-desk-page">
      <EditPayslipModal
        open={open}
        payslipId={payslipId}
        onClose={closeModal}
        onLoaded={rememberRunId}
        onSaved={(payload) => rememberRunId(payload?.data)}
      />
    </div>
  );
}
