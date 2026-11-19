import FileTypeIcon, { fileKind } from '../../../../invoices/frontend/src/components/FileTypeIcon.jsx';

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

function DocPreviewThumb({ isImage, previewUrl, missing, kind }) {
  if (missing) {
    return <FileTypeIcon missing />;
  }
  if (isImage && previewUrl) {
    return (
      <div className="inv-gmail-thumb inv-gmail-thumb--image">
        <img src={previewUrl} alt="" loading="lazy" />
      </div>
    );
  }
  return <FileTypeIcon tone={kind.tone} label={kind.label} />;
}

function PaymentPlainRow({ amountLabel, dateLabel, userLabel }) {
  const meta = [dateLabel, userLabel].filter(Boolean).join(' · ');
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
        </div>
      </div>
    </article>
  );
}

function PaymentCard({
  amountLabel,
  dateLabel,
  userLabel,
  attachment,
}) {
  const hasFile = Boolean(attachment?.name);
  if (!hasFile) {
    return (
      <PaymentPlainRow
        amountLabel={amountLabel}
        dateLabel={dateLabel}
        userLabel={userLabel}
      />
    );
  }

  const fileMissing = !!attachment?.missing;
  const href = !attachment?.missing ? (attachment?.proxyLink || null) : null;
  const kind = fileKind(attachment?.name);
  const footName = attachment.name || 'Attachment';
  const titleBits = [amountLabel, dateLabel, userLabel].filter(Boolean).join(' · ');

  const open = () => {
    if (!href) return;
    window.open(href, '_blank', 'noopener,noreferrer');
  };

  return (
    <article
      className={`inv-gmail-card inv-file-card${fileMissing ? ' inv-gmail-card--missing' : ''}`}
      title={titleBits ? `${footName}\n${titleBits}` : footName}
      role="listitem"
    >
      <button
        type="button"
        className="inv-gmail-card-hit"
        onClick={open}
        disabled={!href}
        aria-label={href ? `Open ${footName}` : amountLabel}
      >
        <DocPreviewThumb
          isImage={!!attachment?.isImage}
          previewUrl={href || undefined}
          missing={fileMissing}
          kind={kind}
        />
      </button>

      <div className="inv-gmail-card-foot inv-pay-card-foot">
        <div className="inv-pay-card-foot-top">
          <span className="inv-gmail-fname">{truncateName(footName, 28)}</span>
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
        <div className="inv-pay-card-meta">
          <span>{amountLabel}</span>
          <span className="inv-pay-card-dot" aria-hidden="true">·</span>
          <span>{dateLabel}</span>
          {userLabel ? (
            <>
              <span className="inv-pay-card-dot" aria-hidden="true">·</span>
              <span>{userLabel}</span>
            </>
          ) : null}
        </div>
      </div>
    </article>
  );
}

export default function OrderPaymentsCard({ payments = [], currency = 'TZS' }) {
  const list = Array.isArray(payments) ? payments : [];
  if (!list.length) return null;

  const cards = [];
  list.forEach((payment) => {
    const amountLabel = money(payment.amount, payment.currency || currency);
    const dateLabel = formatDate(payment.collection_date || payment.created_at);
    const userLabel = payment.user_name || 'System';
    const attachments = Array.isArray(payment.attachments) ? payment.attachments : [];
    if (attachments.length) {
      attachments.forEach((att, index) => {
        cards.push({
          key: att.id > 0 ? `pay-${payment.id}-att-${att.id}` : `pay-${payment.id || 'x'}-att-${index}`,
          amountLabel,
          dateLabel,
          userLabel,
          attachment: att,
        });
      });
    } else {
      cards.push({
        key: `pay-${payment.id || `${payment.collection_date}-${payment.amount}`}`,
        amountLabel,
        dateLabel,
        userLabel,
        attachment: null,
      });
    }
  });

  const countLabel = list.length === 1 ? '1 payment' : `${list.length} payments`;

  return (
    <section className="inv-docs-card no-print" id="recent-payments" aria-label="Recent payments">
      <div className="inv-docs-head">
        <h2 className="inv-docs-title">
          Recent payments
          <span className="inv-docs-count"> · {countLabel}</span>
        </h2>
      </div>
      <div className="inv-docs-body">
        <div className="inv-gmail-list" role="list">
          {cards.map((card) => (
            <PaymentCard
              key={card.key}
              amountLabel={card.amountLabel}
              dateLabel={card.dateLabel}
              userLabel={card.userLabel}
              attachment={card.attachment}
            />
          ))}
        </div>
      </div>
    </section>
  );
}
