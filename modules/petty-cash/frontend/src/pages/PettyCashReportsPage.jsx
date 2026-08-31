import { Download } from 'lucide-react';
import PettyCashPageShell from '../components/PettyCashPageShell.jsx';
import { deskPageUrl } from '../api/pettyCashDesk.js';

export default function PettyCashReportsPage() {
  return (
    <PettyCashPageShell title="Download report" backHref={deskPageUrl('index.php')} backLabel="Back to dashboard">
      <section className="pc-coming-soon" aria-live="polite">
        <div className="pc-coming-soon__scene" aria-hidden="true">
          <div className="pc-coming-soon__orbit" />
          <div className="pc-coming-soon__pulse" />
          <div className="pc-coming-soon__icon">
            <Download size={34} strokeWidth={1.75} />
          </div>
          <div className="pc-coming-soon__spark pc-coming-soon__spark--1" />
          <div className="pc-coming-soon__spark pc-coming-soon__spark--2" />
          <div className="pc-coming-soon__spark pc-coming-soon__spark--3" />
        </div>
        <p className="pc-coming-soon__eyebrow">Petty cash reports</p>
        <h1 className="pc-coming-soon__title">Feature coming soon</h1>
        <p className="pc-coming-soon__text">
          Downloadable petty cash reports are on the way. You will be able to export voucher and top-up history from here.
        </p>
        <a href={deskPageUrl('index.php')} className="exp-desk-btn exp-desk-btn-primary pc-coming-soon__btn">
          Back to dashboard
        </a>
      </section>
    </PettyCashPageShell>
  );
}
