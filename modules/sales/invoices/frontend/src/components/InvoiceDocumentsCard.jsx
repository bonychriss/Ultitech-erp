import FileTypeIcon, { fileKind } from './FileTypeIcon.jsx';

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

function AttachCard({
  name,
  sub,
  onView,
  downloadHref,
  previewUrl,
  isImage,
  missing,
}) {
  const kind = fileKind(name);
  const open = () => {
    if (missing) return;
    onView?.();
  };

  return (
    <article
      className={`inv-gmail-card inv-file-card${missing ? ' inv-gmail-card--missing' : ''}`}
      title={sub ? `${name}\n${sub}` : name}
      role="listitem"
    >
      <button type="button" className="inv-gmail-card-hit" onClick={open} disabled={!!missing} aria-label={`Open ${name}`}>
        <DocPreviewThumb
          isImage={!!isImage}
          previewUrl={previewUrl || downloadHref || undefined}
          missing={missing}
          kind={kind}
        />
      </button>

      <div className="inv-gmail-card-foot">
        <span className="inv-gmail-fname">{truncateName(name)}</span>
        {!missing && downloadHref ? (
          <a
            href={downloadHref}
            download
            rel="noopener noreferrer"
            className="inv-gmail-foot-action"
            title="Download"
            aria-label={`Download ${name}`}
            onClick={(e) => e.stopPropagation()}
          >
            <i className="fas fa-download" aria-hidden="true" />
          </a>
        ) : null}
      </div>
    </article>
  );
}

export default function InvoiceDocumentsCard({ attachments = [], documents = null }) {
  const list = Array.isArray(attachments) ? attachments : [];
  const hasSupporting = documents?.hasSupporting ?? list.length > 0;
  if (!hasSupporting || list.length === 0) return null;

  const count = Number(documents?.headerCount) || list.length;
  const countLabel = count === 1 ? '1 attachment' : `${count} attachments`;

  return (
    <section className="inv-docs-card no-print" id="attachments" aria-label="Supporting documents">
      <div className="inv-docs-head">
        <h2 className="inv-docs-title">
          Supporting Documents
          <span className="inv-docs-count"> · {countLabel}</span>
        </h2>
      </div>
      <div className="inv-docs-body">
        <div className="inv-gmail-list" role="list">
          {list.map((att, index) => (
            <AttachCard
              key={att.id > 0 ? `att-${att.id}` : `att-${index}-${att.name}`}
              name={att.name || 'Attachment'}
              sub={att.sub || att.fileSizeLabel || att.typeLabel || ''}
              isImage={!!att.isImage}
              missing={!!att.missing}
              previewUrl={att.missing ? undefined : att.proxyLink}
              downloadHref={att.missing ? undefined : att.proxyLink}
              onView={att.missing || !att.proxyLink
                ? undefined
                : () => window.open(att.proxyLink, '_blank', 'noopener,noreferrer')}
            />
          ))}
        </div>
      </div>
    </section>
  );
}
