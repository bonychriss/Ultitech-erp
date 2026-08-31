import { ArrowLeft, Clock3, Rows3 } from 'lucide-react';

function getBackUrl(): string {
  const boot = window.__RECON_BOOT__;
  if (boot?.backUrl) return boot.backUrl;

  const params = new URLSearchParams(window.location.search);
  const qs = params.toString();
  return `../modules/balances/transactions.php${qs ? `?${qs}` : ''}`;
}

export default function ComingSoonPage() {
  const backUrl = getBackUrl();

  return (
    <div className="rc-page">
      <div className="rc-cs-wrap">
        <div className="rc-cs-panel">
          <div className="rc-cs-orbit" aria-hidden="true" />

          <span className="rc-cs-badge">
            <Clock3 className="w-3.5 h-3.5" aria-hidden="true" />
            Coming Soon
          </span>

          <div className="rc-cs-icon" aria-hidden="true">
            <Rows3 className="w-11 h-11" />
          </div>

          <h1>
            Bank Reconciliation
            <span className="rc-cs-dots" aria-hidden="true">
              <span>.</span>
              <span>.</span>
              <span>.</span>
            </span>
          </h1>

          <p>
            Matching bank, cash, and mobile statements to your ledger is almost ready. You will
            reconcile accounts here with suggested matches and confirmation tools.
          </p>

          <div className="rc-cs-progress" aria-hidden="true">
            <span />
          </div>
          <div className="rc-cs-progress-label">Currently in development</div>

          <a href={backUrl} className="rc-cs-btn">
            <ArrowLeft className="w-4 h-4" aria-hidden="true" />
            Back to Transactions
          </a>
        </div>
      </div>
    </div>
  );
}
