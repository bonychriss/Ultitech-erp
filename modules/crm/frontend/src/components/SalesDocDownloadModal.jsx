import { useEffect, useRef, useState } from 'react';

function useLiveProgress(target, active) {
  const [display, setDisplay] = useState(0);
  const targetRef = useRef(target);
  const activeRef = useRef(active);
  const wasActiveRef = useRef(false);

  targetRef.current = target;
  activeRef.current = active;

  useEffect(() => {
    if (active && !wasActiveRef.current) {
      setDisplay(0);
    }
    wasActiveRef.current = active;

    if (!active) {
      setDisplay(Math.max(0, Math.min(100, Number(target) || 0)));
      return undefined;
    }

    let raf = 0;
    const tick = () => {
      setDisplay((prev) => {
        const goal = Math.max(0, Math.min(100, Number(targetRef.current) || 0));
        let next = prev;

        if (goal > next) {
          const step = Math.max(0.35, (goal - next) * 0.14);
          next = Math.min(goal, next + step);
        } else if (activeRef.current && next < 96 && goal >= next) {
          next = Math.min(96, next + 0.08);
        }

        return Math.round(next * 10) / 10;
      });
      raf = window.requestAnimationFrame(tick);
    };

    raf = window.requestAnimationFrame(tick);
    return () => window.cancelAnimationFrame(raf);
  }, [active, target]);

  return display;
}

export default function SalesDocDownloadModal({
  open,
  title,
  phase,
  progress,
  message,
  onClose,
}) {
  const isLoading = phase === 'downloading';
  const isSuccess = phase === 'success';
  const isError = phase === 'error';
  const targetPct = Math.max(0, Math.min(100, Number(progress) || 0));
  const pct = useLiveProgress(targetPct, open && isLoading);

  useEffect(() => {
    if (!open || isLoading) return undefined;
    const onKeyDown = (event) => {
      if (event.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [open, isLoading, onClose]);

  if (!open) return null;

  return (
    <div
      className="crm-download-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="crm-download-title"
      aria-busy={isLoading}
    >
      <button
        type="button"
        className="crm-download-backdrop"
        aria-label="Close download dialog"
        onClick={isLoading ? undefined : onClose}
        tabIndex={isLoading ? -1 : 0}
      />
      <div className={`crm-download-card crm-download-card--${phase}`}>
        <div className="crm-download-icon-wrap" aria-hidden="true">
          {isSuccess ? (
            <i className="fas fa-check crm-download-check" />
          ) : isError ? (
            <i className="fas fa-exclamation-triangle crm-download-error" />
          ) : (
            <>
              <i className="fas fa-download crm-download-arrow" />
              <span className="crm-download-tray" />
            </>
          )}
        </div>

        <h2 id="crm-download-title" className="crm-download-title">
          {isSuccess ? 'Download complete' : isError ? 'Download failed' : 'Downloading PDF'}
        </h2>

        {title ? <p className="crm-download-doc">{title}</p> : null}
        <p className="crm-download-status">{message}</p>

        {isLoading ? (
          <>
            <div className="crm-download-progress-track">
              <div
                className="crm-download-progress-bar"
                style={{ width: `${pct}%` }}
              />
            </div>
            <p className="crm-download-percent">{Math.round(pct)}%</p>
          </>
        ) : (
          <button type="button" className="crm-btn crm-btn-primary crm-download-close-btn" onClick={onClose}>
            {isSuccess ? 'Done' : 'Close'}
          </button>
        )}
      </div>
    </div>
  );
}
