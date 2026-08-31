import { deskPageUrl } from '../api/pettyCashDesk.js';

export default function PettyCashPageShell({ title, backHref, backLabel = 'Back to dashboard', children, showTitle = false }) {
  const href = backHref || deskPageUrl('index.php');

  function goBack(e) {
    e.preventDefault();
    if (window.erpNavBack && typeof window.erpNavBack.go === 'function') {
      if (window.erpNavBack.go(href)) return;
    }
    window.location.href = href;
  }

  return (
    <div className="exp-desk-page">
      <div className="exp-desk-page-header" style={{ gridTemplateColumns: '1fr auto' }}>
        <div>
          <a href={href} className="exp-desk-action-link" style={{ fontSize: '0.8125rem' }} onClick={goBack}>
            {backLabel}
          </a>
          {showTitle && title ? (
            <h2 className="exp-desk-form-title" style={{ margin: '0.35rem 0 0', fontSize: '1.25rem' }}>{title}</h2>
          ) : null}
        </div>
      </div>
      {children}
    </div>
  );
}
