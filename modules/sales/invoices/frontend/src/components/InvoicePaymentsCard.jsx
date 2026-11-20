function fileKind(name = '') {
  const lower = String(name).toLowerCase();
  if (/\.(png|jpe?g|gif|webp|bmp)$/i.test(lower)) {
    return { label: 'IMG', tone: 'image' };
  }
  if (/\.(xlsx?|csv)$/i.test(lower)) return { label: 'XLS', tone: 'sheet' };
  if (/\.(docx?)$/i.test(lower)) return { label: 'DOC', tone: 'doc' };
  if (/\.pdf$/i.test(lower)) return { label: 'PDF', tone: 'pdf' };
  return { label: 'FILE', tone: 'file' };
}

function pdfPreviewUrl(url) {
  if (!url) return url;
  const base = String(url).split('#')[0];
  return `${base}#page=1&view=FitH&toolbar=0&navpanes=0&scrollbar=0`;
}

function money(value, currency = 'TZS') {
  const n = Number(value || 0);
  const formatted = Number.isFinite(n)
    ? n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    : '0.00';
  return `${currency} ${formatted}`;
}

function formatDate(value) {
  const raw = String(value || '').trim();
  if (!raw) return '—';
  const d = new Date(raw.includes('T') ? raw : raw.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return raw.slice(0, 10);
  return d.toLocaleDateString('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  });
}

function truncateName(name, max = 28) {
  const s = String(name || '');
  if (s.length <= max) return s;
  const dot = s.lastIndexOf('.');
  if (dot > 0 && s.length - dot <= 5) {
    const ext = s.slice(dot);
    const base = s.slice(0, Math.max(1, max - ext.length - 1));
    return `${base}…${ext}`;
  }
  return `${s.slice(0, max - 1)}…`;
}

function paymentInstallmentLabel(index, total) {
  if (total <= 1) return 'Payment proof';
  const ordinals = ['First', 'Second', 'Third', 'Fourth', 'Fifth', 'Sixth', 'Seventh', 'Eighth', 'Ninth', 'Tenth'];
  const head = ordinals[index] || `${index + 1}th`;
  return `${head} payment`;
}

function DocPreviewThumb({ isImage, previewUrl, missing, kindLabel }) {
  if (missing) {
    return (
      <div className="inv-gmail-thumb inv-gmail-thumb--empty">
        <i className="fas fa-exclamation-triangle" aria-hidden="true" />
        <span>Unavailable</span>
      </div>
    );
  }
  if (isImage && previewUrl) {
    return (
      <div className="inv-gmail-thumb inv-gmail-thumb--image">
        <img src={previewUrl} alt="" loading="lazy" />
      </div>
    );
  }
  if (previewUrl && kindLabel === 'PDF') {
    return (
      <div className="inv-gmail-thumb inv-gmail-thumb--pdf">
        <iframe
          src={pdfPreviewUrl(previewUrl)}
          title="PDF preview"
          loading="lazy"
          tabIndex={-1}
          scrolling="no"
        />
      </div>
    );
  }
  return (
    <div className="inv-gmail-thumb inv-gmail-thumb--placeholder">
      <span className={`inv-gmail-thumb-badge inv-gmail-thumb-badge--${String(kindLabel).toLowerCase()}`}>
        {kindLabel}
      </span>
    </div>
  );
}

function PaymentPlainRow({ amountLabel, dateLabel, userLabel, reference }) {
  const meta = [dateLabel, userLabel, reference].filter(Boolean).join(' · ');
  return (
    <article
      className="inv-pay-plain"
      title={`${amountLabel}${meta ? `\n${meta}` : ''}`}
      role="listitem"
    >
      <span className="inv-pay-plain-icon" aria-hidden="true">
        <i className="fas fa-money-bill-wave" />
      </span>
      <div className="inv-pay-plain-body">
        <div className="inv-pay-plain-amount">{amountLabel}</div>
        <div className="inv-pay-plain-meta">
          <span>{dateLabel}</span>
          {userLabel ? (
            <>
              <span className="inv-pay-card-dot" aria-hidden="true">·</span>
              <span>{userLabel}</span>
            </>
          ) : null}
          {reference ? (
            <>
              <span className="inv-pay-card-dot" aria-hidden="true">·</span>
              <span>{reference}</span>
            </>
          ) : null}
        </div>
      </div>
    </article>
  );
}

function PaymentProofCard({
  amountLabel,
  dateLabel,
  userLabel,
  reference,
  attachment,
  caption,
}) {
  // No uploaded SWIFT/slip — show a normal payment row, not a fake "unavailable" file card.
  if (!attachment?.name) {
    return (
      <PaymentPlainRow
        amountLabel={amountLabel}
        dateLabel={dateLabel}
        userLabel={userLabel}
        reference={reference}
      />
    );
  }

  const fileMissing = !!attachment?.missing;
  const href = !attachment?.missing ? (attachment?.proxyLink || null) : null;
  const kind = fileKind(attachment?.name);
  const footName = attachment.name || 'Attachment';
  const titleBits = [amountLabel, dateLabel, userLabel, reference].filter(Boolean).join(' · ');
  const captionText = String(caption || '').trim();

  const open = () => {
    if (!href) return;
    window.open(href, '_blank', 'noopener,noreferrer');
  };

  return (
    <article
      className={`inv-gmail-card${fileMissing ? ' inv-gmail-card--missing' : ''}${captionText ? ' inv-gmail-card--captioned' : ''}`}
      title={titleBits ? `${footName}\n${titleBits}` : footName}
      role="listitem"
    >
      <button
        type="button"
        className="inv-gmail-card-hit"
        onClick={open}
        disabled={!href}
        aria-label={href ? `Open payment proof ${footName}` : `Payment proof ${footName} unavailable`}
      >
        <DocPreviewThumb
          isImage={!!attachment?.isImage || kind.tone === 'image'}
          previewUrl={href || undefined}
          missing={fileMissing}
          kindLabel={kind.label}
        />
      </button>

      <div className="inv-gmail-card-foot">
        <span className={`inv-gmail-type inv-gmail-type--${kind.tone}`} aria-hidden="true">
          {kind.label}
        </span>
        <span className="inv-gmail-fname">{truncateName(footName)}</span>
        {href ? (
          <a
            href={href}
            download
            rel="noopener noreferrer"
            className="inv-gmail-foot-action"
            title="Download"
            aria-label={`Download ${attachment?.name || 'attachment'}`}
            onClick={(e) => e.stopPropagation()}
          >
            <i className="fas fa-download" aria-hidden="true" />
          </a>
        ) : null}
      </div>
      <div className={`inv-gmail-card-caption${captionText ? '' : ' is-empty'}`} aria-hidden={!captionText}>
        {captionText || '\u00a0'}
      </div>
    </article>
  );
}

export default function InvoicePaymentsCard({ payments = [], currency = 'TZS' }) {
  const list = Array.isArray(payments) ? payments : [];
  if (!list.length) return null;

  const cards = [];
  let proofCount = 0;
  list.forEach((payment, paymentIndex) => {
    const amountLabel = money(payment.amount, payment.currency || currency);
    const dateLabel = formatDate(payment.collection_date || payment.created_at);
    const userLabel = payment.user_name || 'System';
    const reference = String(payment.reference || '').trim();
    const attachments = Array.isArray(payment.attachments) ? payment.attachments : [];
    const installment = paymentInstallmentLabel(paymentIndex, list.length);
    const captionParts = [installment, amountLabel, dateLabel];
    if (reference) captionParts.push(`Ref ${reference}`);
    const caption = captionParts.filter(Boolean).join(' · ');

    if (attachments.length) {
      attachments.forEach((att, index) => {
        proofCount += 1;
        cards.push({
          key: att.id > 0 ? `pay-${payment.id}-att-${att.id}` : `pay-${payment.id || 'x'}-att-${index}`,
          amountLabel,
          dateLabel,
          userLabel,
          reference,
          caption,
          attachment: att,
        });
      });
    } else {
      cards.push({
        key: `pay-${payment.id || `${payment.collection_date}-${payment.amount}`}`,
        amountLabel,
        dateLabel,
        userLabel,
        reference,
        caption,
        attachment: null,
      });
    }
  });

  const title = proofCount > 0 ? 'Payment proofs' : 'Recent payments';
  const countLabel = proofCount > 0
    ? `${proofCount}`
    : `${list.length}`;

  return (
    <section className="inv-docs-card ov-payments-card no-print" id="recent-payments" aria-label={title}>
      <div className="inv-docs-head">
        <h2 className="inv-docs-title">
          {title}
          <span className="inv-docs-count"> · {countLabel}</span>
        </h2>
      </div>
      <div className="inv-docs-body">
        <div className="inv-gmail-list" role="list">
          {cards.map((card) => (
            <PaymentProofCard
              key={card.key}
              amountLabel={card.amountLabel}
              dateLabel={card.dateLabel}
              userLabel={card.userLabel}
              reference={card.reference}
              caption={card.caption}
              attachment={card.attachment}
            />
          ))}
        </div>
      </div>
    </section>
  );
}
