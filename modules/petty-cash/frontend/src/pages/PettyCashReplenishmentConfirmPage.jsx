import { useEffect, useState } from 'react';
import { Loader2 } from 'lucide-react';
import PettyCashPageShell from '../components/PettyCashPageShell.jsx';
import { approveReplenishmentDetail, fetchReplenishmentDetail, deskPageUrl } from '../api/pettyCashDesk.js';
import { formatMoney, getCfg } from '../utils/format.js';

export default function PettyCashReplenishmentConfirmPage() {
  const repId = parseInt(String(getCfg().rep_id || new URLSearchParams(window.location.search).get('rep_id') || 0), 10);
  const viewOnly = Boolean(getCfg().view_only) || new URLSearchParams(window.location.search).get('view') === '1';
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (repId <= 0) {
      setError('Invalid top-up id.');
      setLoading(false);
      return;
    }
    fetchReplenishmentDetail(repId, viewOnly)
      .then(setData)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [repId, viewOnly]);

  async function onApprove() {
    if (!window.confirm('Approve this top-up? Funds will move in Balances.')) return;
    setBusy(true);
    setError('');
    try {
      const result = await approveReplenishmentDetail(repId);
      window.location.href = result.redirect || deskPageUrl('replenishments/index.php');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Approval failed.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <div className="exp-desk-boot-loading">
        <Loader2 className="exp-desk-boot-spinner" />
        <span>Loading...</span>
      </div>
    );
  }

  const p = data?.preview || {};
  const canApprove = !viewOnly && data?.can_manage && p.can_approve;

  return (
    <PettyCashPageShell title={viewOnly ? 'Top-up request' : 'Confirm top-up'} backHref={deskPageUrl('replenishments/index.php')} backLabel="All top-ups">
      {error ? <div className="exp-desk-flash exp-desk-flash-error" role="alert">{error}</div> : null}
      {!viewOnly && p.insufficient_message && !p.can_approve ? (
        <div className="exp-desk-flash exp-desk-flash-error">{p.insufficient_message}</div>
      ) : null}
      <section className="exp-create-card">
        <p><strong>Reference:</strong> {p.replenishment_number || repId}</p>
        <p><strong>Amount:</strong> {formatMoney(p.amount)}</p>
        <p><strong>Petty account:</strong> {p.petty_cash_account_name}</p>
        <p><strong>Source:</strong> {p.source_account_name}</p>
        <p><strong>Description:</strong> {p.description || '—'}</p>
        {canApprove ? (
          <button type="button" className="exp-desk-btn exp-desk-btn-primary" disabled={busy} onClick={onApprove}>
            {busy ? 'Approving...' : 'Confirm approval'}
          </button>
        ) : null}
      </section>
    </PettyCashPageShell>
  );
}
