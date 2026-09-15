import { useEffect, useState } from 'react';
import { api, type Attachment } from '../api';

type Props = {
  attachments: Attachment[];
};

function shortName(name: string, max = 18) {
  if (name.length <= max) return name;
  const ext = name.includes('.') ? name.slice(name.lastIndexOf('.')) : '';
  const base = ext ? name.slice(0, name.length - ext.length) : name;
  return `${base.slice(0, Math.max(6, max - ext.length - 3))}...${ext}`;
}

function extLabel(a: Attachment) {
  if (a.is_pdf) return 'PDF';
  if (a.is_image) return 'IMG';
  const parts = a.filename.split('.');
  return (parts.pop() || 'FILE').toUpperCase().slice(0, 4);
}

export function GmailAttachments({ attachments }: Props) {
  const [preview, setPreview] = useState<Attachment | null>(null);
  const count = attachments.length;
  const label = count === 1 ? 'One attachment' : `${count} attachments`;

  useEffect(() => {
    if (!preview) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setPreview(null);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [preview]);

  if (!count) return null;

  return (
    <div className="gmail-attach">
      <div className="gmail-attach-head">
        <div className="gmail-attach-meta">
          <strong>{label}</strong>
          <span className="dot">·</span>
          <span className="scanned">
            Scanned by Mail
            <span className="info-dot" title="Attachments are stored securely in your mailbox">
              i
            </span>
          </span>
        </div>
      </div>

      <div className="gmail-attach-cards">
        {attachments.map((a) => (
          <div key={a.id} className="gmail-chip">
            <button
              type="button"
              className="gmail-chip-preview"
              onClick={() => setPreview(a)}
              title={`Open ${a.filename}`}
            >
              {a.is_pdf ? (
                <iframe
                  className="gmail-chip-frame"
                  title={a.filename}
                  src={`${api.attachmentUrl(a.id, 'view')}#toolbar=0&navpanes=0&scrollbar=0`}
                  tabIndex={-1}
                />
              ) : a.is_image ? (
                <img className="gmail-chip-img" src={api.attachmentUrl(a.id, 'view')} alt={a.filename} />
              ) : (
                <div className="gmail-chip-fallback">{extLabel(a)}</div>
              )}
              <div className="gmail-chip-hover">
                <a
                  href={api.attachmentUrl(a.id, 'view')}
                  target="_blank"
                  rel="noreferrer"
                  onClick={(e) => e.stopPropagation()}
                  title="Open"
                >
                  ↗
                </a>
                <a
                  href={api.attachmentUrl(a.id, 'download')}
                  onClick={(e) => e.stopPropagation()}
                  title="Download"
                  download={a.filename}
                >
                  ⬇
                </a>
              </div>
            </button>
            <div className="gmail-chip-foot">
              <span className={`gmail-type ${a.is_pdf ? 'pdf' : a.is_image ? 'img' : 'file'}`}>
                {extLabel(a)}
              </span>
              <span className="gmail-chip-name" title={a.filename}>
                {shortName(a.filename)}
              </span>
              <span className="gmail-dogear" aria-hidden />
            </div>
          </div>
        ))}
      </div>

      {preview ? (
        <div className="attach-viewer" onClick={() => setPreview(null)}>
          <div
            className="attach-viewer-card"
            onClick={(e) => e.stopPropagation()}
            role="dialog"
            aria-modal="true"
            aria-label={preview.filename}
          >
            <div className="attach-viewer-bar">
              <div className="attach-viewer-title">
                <span className={`gmail-type ${preview.is_pdf ? 'pdf' : preview.is_image ? 'img' : 'file'}`}>
                  {extLabel(preview)}
                </span>
                <strong>{preview.filename}</strong>
                <span className="muted">{preview.size_label}</span>
              </div>
              <div className="attach-viewer-tools">
                <a href={api.attachmentUrl(preview.id, 'download')} download={preview.filename}>
                  Download
                </a>
                <a href={api.attachmentUrl(preview.id, 'view')} target="_blank" rel="noreferrer">
                  Open
                </a>
                <button type="button" onClick={() => setPreview(null)} aria-label="Close">
                  ×
                </button>
              </div>
            </div>
            <div className="attach-viewer-body">
              {preview.is_pdf ? (
                <iframe title={preview.filename} src={api.attachmentUrl(preview.id, 'view')} />
              ) : preview.is_image ? (
                <img src={api.attachmentUrl(preview.id, 'view')} alt={preview.filename} />
              ) : (
                <div className="attach-viewer-empty">
                  <p>Preview is not available for this file type.</p>
                  <a className="compose-send" href={api.attachmentUrl(preview.id, 'download')}>
                    Download file
                  </a>
                </div>
              )}
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
