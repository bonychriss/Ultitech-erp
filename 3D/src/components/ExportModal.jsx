import React, { useEffect, useState } from 'react';
import { X, Download, Copy, Check, Sparkles } from 'lucide-react';
import { renderCompositeCanvas } from '../utils/imageProcessing';
import { isDesktopApp, saveExportedBlob as saveDesktopBlob } from '../utils/desktop';
import { isMobileApp, saveExportedBlob as saveMobileBlob } from '../utils/mobile';

export default function ExportModal({
  isOpen,
  onClose,
  originalCanvas,
  subjectCanvas,
  bgType,
  bgColor,
  gradientConfig,
  bgImage,
  bgBlur,
  bgOpacity,
  subjectPosition,
  subjectEffects,
  fileName
}) {
  const [format, setFormat] = useState('png');
  const [scale, setScale] = useState(1);
  const [quality, setQuality] = useState(0.92);
  const [isCopied, setIsCopied] = useState(false);
  const [downloadState, setDownloadState] = useState('idle');

  useEffect(() => {
    if (isOpen) {
      setDownloadState('idle');
      setIsCopied(false);
    }
  }, [isOpen]);

  if (!isOpen || !originalCanvas || !subjectCanvas) return null;

  const width = originalCanvas.width * scale;
  const height = originalCanvas.height * scale;

  const getExportCanvas = () => {
    const exportCanvas = document.createElement('canvas');
    exportCanvas.width = width;
    exportCanvas.height = height;

    const scaledPosition = {
      ...subjectPosition,
      x: subjectPosition.x * scale,
      y: subjectPosition.y * scale,
      scale: subjectPosition.scale * scale
    };

    renderCompositeCanvas({
      canvas: exportCanvas,
      subjectCanvas,
      originalCanvas,
      bgType,
      bgColor,
      gradientConfig,
      bgImage,
      bgBlur,
      bgOpacity,
      subjectPosition: scaledPosition,
      subjectEffects,
      showGrid: false
    });

    return exportCanvas;
  };

  const handleDownload = () => {
    if (downloadState !== 'idle') return;
    setDownloadState('loading');
    const started = Date.now();

    const exportCanvas = getExportCanvas();
    const mimeType = format === 'jpeg' ? 'image/jpeg' : format === 'webp' ? 'image/webp' : 'image/png';
    const ext = format === 'jpeg' ? 'jpg' : format;
    const baseName = fileName ? fileName.replace(/\.[^/.]+$/, '') : 'bcut';
    const downloadName = `${baseName}_bg_removed.${ext}`;

    exportCanvas.toBlob(async (blob) => {
      if (!blob) {
        setDownloadState('idle');
        return;
      }

      if (isDesktopApp()) {
        const desktopResult = await saveDesktopBlob(blob, downloadName);
        if (desktopResult === null) {
          setDownloadState('idle');
          return;
        }
        if (!desktopResult) {
          alert('Could not save the file.');
          setDownloadState('idle');
          return;
        }
        const wait = Math.max(0, 900 - (Date.now() - started));
        setTimeout(() => {
          setDownloadState('done');
          setTimeout(() => setDownloadState('idle'), 1800);
        }, wait);
        return;
      }

      if (isMobileApp()) {
        const mobileResult = await saveMobileBlob(blob, downloadName);
        if (mobileResult === null) {
          setDownloadState('idle');
          return;
        }
        if (!mobileResult) {
          alert('Could not save the file on this device.');
          setDownloadState('idle');
          return;
        }
        const wait = Math.max(0, 900 - (Date.now() - started));
        setTimeout(() => {
          setDownloadState('done');
          setTimeout(() => setDownloadState('idle'), 1800);
        }, wait);
        return;
      }

      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = downloadName;
      link.click();
      URL.revokeObjectURL(url);

      const wait = Math.max(0, 900 - (Date.now() - started));
      setTimeout(() => {
        setDownloadState('done');
        setTimeout(() => setDownloadState('idle'), 1800);
      }, wait);
    }, mimeType, quality);
  };

  const handleCopyClipboard = () => {
    const exportCanvas = getExportCanvas();
    exportCanvas.toBlob((blob) => {
      if (!blob) return;
      try {
        navigator.clipboard.write([
          new ClipboardItem({ 'image/png': blob })
        ]);
        setIsCopied(true);
        setTimeout(() => setIsCopied(false), 2000);
      } catch (err) {
        alert('Copying images to clipboard is not supported in this browser window.');
      }
    }, 'image/png');
  };

  const downloadLabel =
    downloadState === 'loading' ? 'Downloading…' :
    downloadState === 'done' ? 'Downloaded' :
    'Download Image File';

  return (
    <div className="export-overlay" onClick={onClose}>
      <div className="export-modal glass-panel" onClick={(e) => e.stopPropagation()}>
        <div className="export-header">
          <div className="export-title">
            <Sparkles size={20} color="#3b82f6" />
            <h3 className="gradient-text">Export Final Image</h3>
          </div>
          <button type="button" className="export-close" onClick={onClose} aria-label="Close">
            <X size={16} />
          </button>
        </div>

        <div className="export-section">
          <span className="export-label">Image file format:</span>
          <div className="export-grid">
            {[
              { id: 'png', name: 'PNG', hint: 'Transparent / HD' },
              { id: 'webp', name: 'WebP', hint: 'Modern Compact' },
              { id: 'jpeg', name: 'JPEG', hint: 'Standard Solid' }
            ].map((f) => (
              <button
                key={f.id}
                type="button"
                className={`export-card ${format === f.id ? 'is-format-active' : ''}`}
                onClick={() => setFormat(f.id)}
              >
                <strong>{f.name}</strong>
                <span>{f.hint}</span>
              </button>
            ))}
          </div>
        </div>

        <div className="export-section">
          <span className="export-label">Export resolution:</span>
          <div className="export-grid">
            {[
              { s: 1, name: '1x Original', size: `${originalCanvas.width}x${originalCanvas.height}` },
              { s: 2, name: '2x High Res', size: `${originalCanvas.width * 2}x${originalCanvas.height * 2}` },
              { s: 4, name: '4x Ultra HD', size: `${originalCanvas.width * 4}x${originalCanvas.height * 4}` }
            ].map((item) => (
              <button
                key={item.s}
                type="button"
                className={`export-card ${scale === item.s ? 'is-size-active' : ''}`}
                onClick={() => setScale(item.s)}
              >
                <strong>{item.name}</strong>
                <span>{item.size}</span>
              </button>
            ))}
          </div>
        </div>

        {(format === 'webp' || format === 'jpeg') && (
          <div className="export-section">
            <div className="export-quality-row">
              <span className="export-label">Quality</span>
              <span className="export-quality-value">{Math.round(quality * 100)}%</span>
            </div>
            <input
              type="range"
              min="0.5"
              max="1.0"
              step="0.05"
              value={quality}
              onChange={(e) => setQuality(Number(e.target.value))}
            />
          </div>
        )}

        <div className="export-actions">
          <button
            type="button"
            className={`export-download ${downloadState !== 'idle' ? `is-${downloadState}` : ''}`}
            onClick={handleDownload}
            disabled={downloadState !== 'idle'}
          >
            <span className="export-download-fill" />
            <span className="export-download-content">
              {downloadState === 'done' ? (
                <Check size={18} className="export-download-check" />
              ) : (
                <span className="export-download-icon">
                  <Download size={18} />
                </span>
              )}
              <span>{downloadLabel}</span>
            </span>
          </button>

          <button
            type="button"
            className="export-copy"
            onClick={handleCopyClipboard}
          >
            {isCopied ? <Check size={16} /> : <Copy size={16} />}
            <span>{isCopied ? 'Copied to Clipboard!' : 'Copy to Clipboard'}</span>
          </button>
        </div>
      </div>
    </div>
  );
}
