export function fileKind(name = '') {
  const lower = String(name).toLowerCase();
  if (/\.(png|jpe?g|gif|webp|bmp)$/i.test(lower)) {
    return { label: 'IMG', tone: 'image' };
  }
  if (/\.(xlsx?|csv|ods)$/i.test(lower)) return { label: 'Exl', tone: 'sheet' };
  if (/\.(pptx?|ppsx?|odp)$/i.test(lower)) return { label: 'PPT', tone: 'ppt' };
  if (/\.(docx?|odt|rtf)$/i.test(lower)) return { label: 'Word', tone: 'doc' };
  if (/\.pdf$/i.test(lower)) return { label: 'PDF', tone: 'pdf' };
  return { label: 'File', tone: 'file' };
}

/**
 * Office-style dog-eared document icon (Word / PPT / Exl / PDF).
 */
export default function FileTypeIcon({ tone = 'file', label = 'File', missing = false }) {
  if (missing) {
    return (
      <div className="inv-gmail-thumb inv-gmail-thumb--empty">
        <i className="fas fa-exclamation-triangle" aria-hidden="true" />
        <span>Unavailable</span>
      </div>
    );
  }

  return (
    <div className={`inv-gmail-thumb inv-file-thumb inv-file-thumb--${tone}`}>
      <div className={`inv-file-icon inv-file-icon--${tone}`} aria-hidden="true">
        <span className="inv-file-icon-sheet">
          <span className="inv-file-icon-fold" />
          <span className={`inv-file-icon-glyph inv-file-icon-glyph--${tone}`} />
          <span className="inv-file-icon-label">{label}</span>
        </span>
      </div>
    </div>
  );
}
