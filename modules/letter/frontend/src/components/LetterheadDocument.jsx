function fallbackText(value, placeholder = '-') {
  const text = String(value || '').trim();
  return text !== '' ? text : placeholder;
}

export default function LetterheadDocument({ doc }) {
  const accent = doc.accentColor || '#E6B800';
  const dark = doc.darkColor || '#2f3542';
  const paragraphs = String(doc.body || '')
    .split(/\n\s*\n/)
    .map((p) => p.trim())
    .filter(Boolean);

  return (
    <article
      className="lh-page lh-page--official"
      style={{ '--lh-accent': accent, '--lh-dark': dark }}
    >
      <div className="lh-header-art" aria-hidden="true">
        <div className="lh-shape-gold" />
        <div className="lh-shape-dark" />
      </div>

      <div className="lh-header-meta">
        <div className="lh-header-spacer" aria-hidden="true" />

        <div className="lh-header-right">
          {doc.logoUrl ? (
            <img className="lh-logo-img" src={doc.logoUrl} alt="" />
          ) : (
            <div className="lh-logo-fallback" style={{ background: accent }}>
              {(doc.companyName || 'C').slice(0, 1).toUpperCase()}
            </div>
          )}
          <div className="lh-date-block">
            <span className="lh-meta-label">Date</span>
            <div className="lh-date-value">{doc.letterDateLabel}</div>
          </div>
        </div>
      </div>

      <div className="lh-content">
        <div className="lh-company-line">
          <strong>{doc.companyName || 'Company Name'}</strong>
          {doc.tagline ? <span>{doc.tagline}</span> : null}
        </div>

        <div className="lh-recipient">
          <span className="lh-to-label">To:</span>
          <div className="lh-recipient-name">{fallbackText(doc.recipientName, '[Recipient Name / Title]')}</div>
          {doc.recipientCompany ? <div>{doc.recipientCompany}</div> : null}
          {doc.recipientAddress ? <div>{doc.recipientAddress}</div> : null}
          {doc.recipientCity ? <div>{doc.recipientCity}</div> : null}
        </div>

        <div className="lh-subject">
          <span className="lh-subject-text">{fallbackText(doc.subject, 'SUBJECT OF THE LETTER GOES HERE')}</span>
        </div>

        <div className="lh-salutation">{fallbackText(doc.salutation, 'Dear Sir/Madam,')}</div>

        <div className="lh-body">
          {paragraphs.length > 0 ? (
            paragraphs.map((p) => <p key={p.slice(0, 48)}>{p}</p>)
          ) : (
            <p className="lh-body-placeholder">Start typing your letter content here...</p>
          )}
        </div>

        <div className="lh-signoff">
          <div className="lh-closing">{fallbackText(doc.closing, 'Sincerely,')}</div>
          <div className="lh-sig-line">
            <div className="lh-sign-name">{doc.signName}</div>
            <div className="lh-sign-title">{doc.signTitle}</div>
          </div>
        </div>
      </div>

      <footer className="lh-footer-official">
        <div className="lh-footer-left">
          <i className="fa-solid fa-phone" aria-hidden="true" />
          <span>{fallbackText(doc.footerPhone || doc.senderPhone, '+255 000 000 000')}</span>
        </div>
        <div className="lh-footer-dark" aria-hidden="true" />
        <div className="lh-footer-gold">
          <div>
            <i className="fa-solid fa-envelope" aria-hidden="true" />
            {' '}
            {fallbackText(doc.footerEmail, 'info@company.com')}
          </div>
          <div>
            <i className="fa-solid fa-location-dot" aria-hidden="true" />
            {' '}
            {fallbackText(doc.footerAddress || doc.senderAddress, 'Dar es Salaam, Tanzania')}
          </div>
        </div>
      </footer>
    </article>
  );
}
