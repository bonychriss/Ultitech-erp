import headerArt from '../assets/letterhead-header.png';
import footerArt from '../assets/letterhead-footer.png';
import ultimateStamp from '../assets/ultimate-stamp.png';

function line(value) {
  return String(value || '').trim();
}

export default function LetterheadDocument({ doc }) {
  const paragraphs = String(doc.body || '')
    .split(/\n\s*\n/)
    .map((p) => p.trim())
    .filter(Boolean);

  const subject = line(doc.subject);
  const refLine = !subject
    ? ''
    : subject.toUpperCase().startsWith('REF:')
      ? subject
      : `REF: ${subject}`;

  const recipientName = line(doc.recipientName);
  const recipientCompany = line(doc.recipientCompany);
  const recipientAddress = line(doc.recipientAddress);
  const recipientCity = line(doc.recipientCity);
  const hasRecipient = recipientName || recipientCompany || recipientAddress || recipientCity;
  const salutation = line(doc.salutation);
  const closing = line(doc.closing);
  const signName = line(doc.signName);
  const signTitle = line(doc.signTitle);

  return (
    <article className="lh-page lh-page--template">
      <header className="lh-template-header">
        <img src={headerArt} alt="" className="lh-template-banner" />
      </header>

      <div className="lh-content lh-content--template">
        <div className="lh-from-block">
          <div>{line(doc.fromCompany) || line(doc.companyName)}</div>
          <div>{line(doc.fromBox)}</div>
          <div>{line(doc.fromCity)}</div>
          <div>{doc.letterDateLabel}</div>
        </div>

        <div className={`lh-recipient lh-recipient--template${hasRecipient ? '' : ' is-empty'}`}>
          {recipientName ? <div className="lh-recipient-name">{recipientName}</div> : null}
          {recipientCompany ? <div>{recipientCompany}</div> : null}
          {recipientAddress ? <div>{recipientAddress}</div> : null}
          {recipientCity ? <div>{recipientCity}</div> : null}
        </div>

        <div className={`lh-salutation${salutation ? '' : ' is-empty'}`}>
          {salutation}
        </div>

        <div className={`lh-subject lh-subject--ref${refLine ? '' : ' is-empty'}`}>
          {refLine ? <span className="lh-subject-text">{refLine}</span> : null}
        </div>

        <div className={`lh-body lh-body--template${paragraphs.length ? '' : ' is-empty'}`}>
          {paragraphs.map((p) => (
            <p key={p.slice(0, 48)}>{p}</p>
          ))}
        </div>

        <div className="lh-signoff lh-signoff--template">
          <div className={`lh-closing${closing ? '' : ' is-empty'}`}>{closing}</div>
          <div className="lh-sign-space" aria-hidden="true" />
          <div className="lh-sign-name">{signName}</div>
          <div className="lh-sign-title">{signTitle}</div>
          <div className="lh-stamp-wrap">
            <img
              src={ultimateStamp}
              alt="Ultimate General Trading stamp"
              className="lh-stamp"
            />
          </div>
        </div>
      </div>

      <footer className="lh-template-footer">
        <img src={footerArt} alt="" className="lh-template-banner" />
      </footer>
    </article>
  );
}
