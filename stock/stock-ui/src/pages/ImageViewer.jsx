import React, { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { HiOutlineArrowDownTray, HiOutlineShare, HiOutlineXMark, HiCheck } from 'react-icons/hi2';
import './image-viewer.css';

const EXT_BY_TYPE = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/gif': 'gif',
  'image/webp': 'webp',
  'image/bmp': 'bmp',
  'image/svg+xml': 'svg',
};

function safeFileName(name) {
  return String(name || 'product-image')
    .trim()
    .replace(/[\\/:*?"<>|]+/g, '-')
    .replace(/\s+/g, '-')
    .slice(0, 80) || 'product-image';
}

async function fetchWithProgress(url, onProgress) {
  const res = await fetch(url, { credentials: 'same-origin' });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const total = Number(res.headers.get('content-length')) || 0;
  const type = res.headers.get('content-type') || 'image/jpeg';
  if (!res.body || !res.body.getReader) {
    const blob = await res.blob();
    onProgress(1);
    return blob;
  }
  const reader = res.body.getReader();
  const chunks = [];
  let received = 0;
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    chunks.push(value);
    received += value.length;
    onProgress(total ? Math.min(received / total, 1) : null);
  }
  onProgress(1);
  return new Blob(chunks, { type });
}

/**
 * Full-screen product image viewer with download (progress animation) and share.
 */
export default function ImageViewer({ image, onClose }) {
  const [src, setSrc] = useState('');
  const [zoomed, setZoomed] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const [dl, setDl] = useState({ state: 'idle', progress: 0 });
  const [toast, setToast] = useState('');
  const blobRef = useRef(null);
  const toastTimer = useRef(null);

  const open = Boolean(image);

  useEffect(() => {
    if (!image) return undefined;
    setSrc(image.large || image.src);
    setZoomed(false);
    setLoaded(false);
    setDl({ state: 'idle', progress: 0 });
    blobRef.current = null;
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const onKey = (e) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    return () => {
      document.body.style.overflow = prevOverflow;
      document.removeEventListener('keydown', onKey);
    };
  }, [image, onClose]);

  useEffect(() => () => clearTimeout(toastTimer.current), []);

  if (!open) return null;

  const showToast = (msg) => {
    setToast(msg);
    clearTimeout(toastTimer.current);
    toastTimer.current = setTimeout(() => setToast(''), 2200);
  };

  const getBlob = async (withProgress) => {
    if (blobRef.current) {
      if (withProgress) setDl({ state: 'downloading', progress: 1 });
      return blobRef.current;
    }
    const blob = await fetchWithProgress(src, (p) => {
      if (withProgress) setDl({ state: 'downloading', progress: p });
    });
    blobRef.current = blob;
    return blob;
  };

  const fileNameFor = (blob) => {
    const ext = EXT_BY_TYPE[(blob?.type || '').split(';')[0]] || 'jpg';
    return `${safeFileName(image.code || image.title)}.${ext}`;
  };

  const handleDownload = async () => {
    if (dl.state === 'downloading') return;
    setDl({ state: 'downloading', progress: 0 });
    try {
      const blob = await getBlob(true);
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = fileNameFor(blob);
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 4000);
      setDl({ state: 'done', progress: 1 });
      setTimeout(() => setDl((d) => (d.state === 'done' ? { state: 'idle', progress: 0 } : d)), 2200);
    } catch (_) {
      setDl({ state: 'error', progress: 0 });
      showToast('Download failed. Please try again.');
      setTimeout(() => setDl((d) => (d.state === 'error' ? { state: 'idle', progress: 0 } : d)), 2200);
    }
  };

  const absoluteUrl = (() => {
    try {
      return new URL(src, window.location.href).href;
    } catch {
      return src;
    }
  })();

  const handleShare = async () => {
    const title = image.title || 'Product image';
    const text = image.code ? `${title} (${image.code})` : title;
    try {
      if (navigator.share) {
        try {
          const blob = await getBlob(false);
          const file = new File([blob], fileNameFor(blob), { type: blob.type || 'image/jpeg' });
          if (navigator.canShare && navigator.canShare({ files: [file] })) {
            await navigator.share({ files: [file], title, text });
            return;
          }
        } catch (err) {
          if (err && err.name === 'AbortError') return;
        }
        await navigator.share({ title, text, url: absoluteUrl });
        return;
      }
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(absoluteUrl);
        showToast('Image link copied');
        return;
      }
      window.prompt('Copy image link', absoluteUrl);
    } catch (err) {
      if (err && err.name === 'AbortError') return;
      try {
        await navigator.clipboard.writeText(absoluteUrl);
        showToast('Image link copied');
      } catch {
        showToast('Sharing is not available on this device');
      }
    }
  };

  const pct = dl.progress == null ? null : Math.round((dl.progress || 0) * 100);
  const ringLen = 2 * Math.PI * 10;

  return createPortal(
    <div className="iv-backdrop" role="dialog" aria-modal="true" aria-label={image.title || 'Image viewer'} onClick={onClose}>
      <div className="iv-top" onClick={(e) => e.stopPropagation()}>
        <div className="iv-title-wrap">
          {image.code ? <div className="iv-code">{image.code}</div> : null}
          <div className="iv-title">{image.title}</div>
        </div>
        <button type="button" className="iv-icon-btn" onClick={onClose} aria-label="Close">
          <HiOutlineXMark size={24} />
        </button>
      </div>

      <div className="iv-stage" onClick={(e) => e.stopPropagation()}>
        {!loaded && <div className="iv-spinner" aria-hidden="true" />}
        <img
          src={src}
          alt={image.title || ''}
          className={`iv-img${zoomed ? ' is-zoomed' : ''}${loaded ? ' is-loaded' : ''}`}
          onLoad={() => setLoaded(true)}
          onError={() => {
            if (src !== image.src) setSrc(image.src);
            else setLoaded(true);
          }}
          onClick={() => setZoomed((z) => !z)}
          draggable={false}
        />
      </div>

      <div className="iv-actions" onClick={(e) => e.stopPropagation()}>
        <button
          type="button"
          className={`iv-action iv-action--download is-${dl.state}`}
          onClick={handleDownload}
          disabled={dl.state === 'downloading'}
        >
          <span className="iv-action-icon" aria-hidden="true">
            {dl.state === 'downloading' ? (
              <svg viewBox="0 0 24 24" className={`iv-ring${pct == null ? ' is-indeterminate' : ''}`}>
                <circle cx="12" cy="12" r="10" className="iv-ring-track" />
                <circle
                  cx="12"
                  cy="12"
                  r="10"
                  className="iv-ring-bar"
                  strokeDasharray={ringLen}
                  strokeDashoffset={pct == null ? ringLen * 0.7 : ringLen * (1 - pct / 100)}
                />
              </svg>
            ) : dl.state === 'done' ? (
              <HiCheck size={22} className="iv-check" />
            ) : (
              <HiOutlineArrowDownTray size={22} className="iv-arrow" />
            )}
          </span>
          <span className="iv-action-label">
            {dl.state === 'downloading'
              ? pct == null
                ? 'Downloadingù'
                : `Downloading ${pct}%`
              : dl.state === 'done'
                ? 'Saved'
                : 'Download'}
          </span>
          {dl.state === 'downloading' && (
            <span className="iv-progress" style={{ width: `${pct == null ? 100 : pct}%` }} aria-hidden="true" />
          )}
        </button>
        <button type="button" className="iv-action" onClick={handleShare}>
          <span className="iv-action-icon" aria-hidden="true">
            <HiOutlineShare size={22} />
          </span>
          <span className="iv-action-label">Share</span>
        </button>
      </div>

      {toast ? <div className="iv-toast" role="status">{toast}</div> : null}
    </div>,
    document.body,
  );
}
