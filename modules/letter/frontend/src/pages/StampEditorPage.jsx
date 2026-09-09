import { useMemo, useState } from 'react';
import { ExternalLink, Image as ImageIcon } from 'lucide-react';

function readCfg() {
  if (typeof window !== 'undefined' && window.__LETTER_CFG__ && typeof window.__LETTER_CFG__ === 'object') {
    return window.__LETTER_CFG__;
  }
  return {};
}

export default function StampEditorPage() {
  const cfg = useMemo(() => readCfg(), []);
  const editorUrl = String(cfg.stampEditorUrl || '').trim();
  const stampPreviewUrl = String(cfg.stampPreviewUrl || '').trim();
  const [showEditor, setShowEditor] = useState(true);

  return (
    <div className="letter-stamp-page">
      <div className="letter-stamp-toolbar">
        <div className="letter-stamp-toolbar-copy">
          <h2>Stamp</h2>
          <p>Edit company stamp with the BCUT image editor (remove background, clean, export).</p>
        </div>
        <div className="letter-stamp-toolbar-actions">
          <button
            type="button"
            className={`letter-btn letter-btn--pill${showEditor ? '' : ' letter-btn-primary'}`}
            onClick={() => setShowEditor(false)}
          >
            <ImageIcon size={16} />
            Preview
          </button>
          <button
            type="button"
            className={`letter-btn letter-btn--pill${showEditor ? ' letter-btn-primary' : ''}`}
            onClick={() => setShowEditor(true)}
          >
            Open editor
          </button>
          {editorUrl ? (
            <a
              className="letter-btn letter-btn--pill"
              href={editorUrl}
              target="_blank"
              rel="noreferrer"
            >
              <ExternalLink size={16} />
              Full screen
            </a>
          ) : null}
        </div>
      </div>

      {showEditor ? (
        editorUrl ? (
          <div className="letter-stamp-editor-frame-wrap">
            <iframe
              title="Stamp image editor"
              className="letter-stamp-editor-frame"
              src={editorUrl}
              allow="clipboard-read; clipboard-write"
            />
          </div>
        ) : (
          <div className="letter-card letter-stamp-missing">
            <p>Image editor not found. Build BCUT with <code>npm run build</code> in <code>3D/</code>.</p>
          </div>
        )
      ) : (
        <div className="letter-card letter-stamp-preview-card">
          <div className="letter-preview-label">Current letter stamp</div>
          {stampPreviewUrl ? (
            <img src={stampPreviewUrl} alt="Ultimate stamp" className="letter-stamp-preview-img" />
          ) : (
            <p>No stamp preview available.</p>
          )}
          <p className="letter-stamp-hint">
            After editing in BCUT, export a PNG and replace
            {' '}
            <code>letterhead/stamps/ultimate-stamp-white.png</code>
            {' '}
            then rebuild the letter frontend.
          </p>
        </div>
      )}
    </div>
  );
}
