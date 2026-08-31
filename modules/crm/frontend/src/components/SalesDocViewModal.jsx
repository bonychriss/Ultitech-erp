import { useEffect } from 'react';

function toPreviewUrl(downloadUrl) {
  if (!downloadUrl) return '';
  try {
    const url = new URL(downloadUrl, window.location.origin);
    url.searchParams.delete('download');
    url.searchParams.set('embed', '1');
    return `${url.pathname}${url.search}${url.hash}`;
  } catch {
    return '';
  }
}

export function buildSalesDocPreviewUrl(downloadUrl) {
  return toPreviewUrl(downloadUrl);
}

export default function SalesDocViewModal({
  open,
  title,
  previewUrl,
  downloadUrl,
  onDownload,
  onClose,
}) {
  useEffect(() => {
    if (!open) return undefined;

    const onKeyDown = (event) => {
      if (event.key === 'Escape') onClose();
    };

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', onKeyDown);

    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', onKeyDown);
    };
  }, [open, onClose]);

  if (!open || !previewUrl) return null;

  return (
    <div className="crm-modal-overlay crm-modal-overlay--doc" onClick={onClose} role="presentation">
      <div
        className="crm-modal crm-modal--doc-view"
        role="dialog"
        aria-modal="true"
        aria-labelledby="crm-doc-view-title"
        onClick={(event) => event.stopPropagation()}
      >
        <div className="crm-modal-header crm-modal-header--doc">
          <h3 id="crm-doc-view-title" className="crm-modal-title">{title}</h3>
          <div className="crm-modal-doc-actions">
            {downloadUrl ? (
              <button
                type="button"
                className="crm-btn crm-btn-secondary"
                onClick={onDownload}
              >
                <i className="fas fa-download" aria-hidden="true" />
                Download PDF
              </button>
            ) : null}
            <button type="button" className="crm-modal-close" onClick={onClose} aria-label="Close">
              <i className="fas fa-times" aria-hidden="true" />
            </button>
          </div>
        </div>
        <div className="crm-modal-doc-body">
          <iframe
            title={title}
            src={previewUrl}
            className="crm-modal-doc-frame"
          />
        </div>
      </div>
    </div>
  );
}
