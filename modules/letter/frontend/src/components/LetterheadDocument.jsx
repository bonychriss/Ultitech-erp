import headerArt from '../assets/letterhead-header.png';
import footerArt from '../assets/letterhead-footer.png';
import ultimateStamp from '../assets/ultimate-stamp.png';

function line(value) {
  return String(value || '').trim();
}

function LhEdit({
  value,
  onChange,
  placeholder = '',
  className = '',
  multiline = false,
  rows = 8,
  align = 'left',
}) {
  const shared = {
    className: `lh-edit${multiline ? ' lh-edit--area' : ''} ${className}`.trim(),
    value: value ?? '',
    placeholder,
    onChange: (event) => onChange?.(event.target.value),
    spellCheck: true,
    style: align === 'right' ? { textAlign: 'right' } : align === 'center' ? { textAlign: 'center' } : undefined,
  };

  if (multiline) {
    return <textarea {...shared} rows={rows} />;
  }

  return <input type="text" {...shared} />;
}

export default function LetterheadDocument({ doc, editable = false, onChange }) {
  const set = (key) => (value) => {
    if (!onChange) return;
    onChange(key, value);
  };

  const subject = line(doc.subject);
  const refValue = !subject
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
  const signatureUrl = line(doc.signatureUrl);
  const bodyText = String(doc.body || '');
  const paragraphs = bodyText
    .split(/\n\s*\n/)
    .map((p) => p.trim())
    .filter(Boolean);

  return (
    <article className={`lh-page lh-page--template${editable ? ' is-editable' : ''}`}>
      <header className="lh-template-header">
        <img src={headerArt} alt="" className="lh-template-banner" />
      </header>

      <div className="lh-content lh-content--template">
        <div className="lh-from-block">
          {editable ? (
            <>
              <LhEdit
                value={doc.fromCompany}
                onChange={set('fromCompany')}
                placeholder="COMPANY NAME,"
                align="right"
              />
              <LhEdit
                value={doc.fromBox}
                onChange={set('fromBox')}
                placeholder="P.O.BOX number,"
                align="right"
              />
              <LhEdit
                value={doc.fromCity}
                onChange={set('fromCity')}
                placeholder="CITY, COUNTRY."
                align="right"
              />
              <LhEdit
                value={doc.letterDateLabel}
                onChange={set('letterDateLabel')}
                placeholder="DD-MM-YYYY."
                align="right"
              />
            </>
          ) : (
            <>
              <div>{line(doc.fromCompany) || line(doc.companyName)}</div>
              <div>{line(doc.fromBox)}</div>
              <div>{line(doc.fromCity)}</div>
              <div>{doc.letterDateLabel}</div>
            </>
          )}
        </div>

        <div
          className={`lh-recipient lh-recipient--template${
            editable || hasRecipient ? '' : ' is-empty'
          }`}
        >
          {editable ? (
            <>
              <LhEdit
                className="lh-recipient-name"
                value={doc.recipientName}
                onChange={set('recipientName')}
                placeholder="Recipient name / title,"
              />
              <LhEdit
                value={doc.recipientCompany}
                onChange={set('recipientCompany')}
                placeholder="Recipient company"
              />
              <LhEdit
                value={doc.recipientAddress}
                onChange={set('recipientAddress')}
                placeholder="P.O.BOX number"
              />
              <LhEdit
                value={doc.recipientCity}
                onChange={set('recipientCity')}
                placeholder="CITY, COUNTRY."
              />
            </>
          ) : (
            <>
              {recipientName ? <div className="lh-recipient-name">{recipientName}</div> : null}
              {recipientCompany ? <div>{recipientCompany}</div> : null}
              {recipientAddress ? <div>{recipientAddress}</div> : null}
              {recipientCity ? <div>{recipientCity}</div> : null}
            </>
          )}
        </div>

        <div className={`lh-salutation${editable || salutation ? '' : ' is-empty'}`}>
          {editable ? (
            <LhEdit
              value={doc.salutation}
              onChange={set('salutation')}
              placeholder="Dear Sir/Madam,"
            />
          ) : (
            salutation
          )}
        </div>

        <div className={`lh-subject lh-subject--ref${editable || refValue ? '' : ' is-empty'}`}>
          {editable ? (
            <LhEdit
              className="lh-subject-text lh-edit--subject"
              value={refValue}
              onChange={(value) => {
                const raw = String(value || '').trim();
                const next = raw.toUpperCase().startsWith('REF:')
                  ? raw.slice(4).trim()
                  : raw;
                set('subject')(next);
              }}
              placeholder="REF: SUBJECT"
              align="center"
            />
          ) : refValue ? (
            <span className="lh-subject-text">{refValue}</span>
          ) : null}
        </div>

        <div className={`lh-body lh-body--template${editable || paragraphs.length ? '' : ' is-empty'}`}>
          {editable ? (
            <LhEdit
              multiline
              rows={10}
              value={doc.body}
              onChange={set('body')}
              placeholder="Type the letter body here."
            />
          ) : (
            paragraphs.map((p) => (
              <p key={p.slice(0, 48)}>{p}</p>
            ))
          )}
        </div>

        <div className="lh-signoff lh-signoff--template">
          <div className={`lh-closing${editable || closing ? '' : ' is-empty'}`}>
            {editable ? (
              <LhEdit
                value={doc.closing}
                onChange={set('closing')}
                placeholder="Yours faithfully,"
              />
            ) : (
              closing
            )}
          </div>
          {signatureUrl ? (
            <div className="lh-signature-wrap">
              <img
                src={signatureUrl}
                alt="Signature"
                className="lh-signature"
              />
            </div>
          ) : (
            <div className="lh-sign-space" aria-hidden="true" />
          )}
          {editable ? (
            <>
              <LhEdit
                className="lh-sign-name"
                value={doc.signName}
                onChange={set('signName')}
                placeholder="Signatory name"
              />
              <LhEdit
                className="lh-sign-title"
                value={doc.signTitle}
                onChange={set('signTitle')}
                placeholder="Title"
              />
            </>
          ) : (
            <>
              <div className="lh-sign-name">{signName}</div>
              <div className="lh-sign-title">{signTitle}</div>
            </>
          )}
          {doc.showUltimateStamp ? (
            <div className="lh-stamp-wrap">
              <img
                src={ultimateStamp}
                alt="Ultimate General Trading stamp"
                className="lh-stamp"
              />
            </div>
          ) : null}
        </div>
      </div>

      <footer className="lh-template-footer">
        <div className="lh-page-number" aria-hidden="true">
          Page <span className="lh-page-number-current" /> of <span className="lh-page-number-total" />
        </div>
        <img src={footerArt} alt="" className="lh-template-banner" />
      </footer>
    </article>
  );
}
