import { memo, useEffect, useRef, useState } from 'react';

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

function InvoiceDownloadModal({
  open,
  state,
  progress,
  message,
  fileName,
  onClose,
}) {
  const isLoading = state === 'loading';
  const isSuccess = state === 'success';
  const isError = state === 'error';
  const targetPct = Math.max(0, Math.min(100, Number(progress) || 0));
  const pct = useLiveProgress(targetPct, open && isLoading);

  if (!open) return null;

  return (
    <div
      className="ov-dl-modal"
      role="dialog"
      aria-modal="true"
      aria-labelledby="ov-dl-modal-title"
      aria-busy={isLoading}
    >
      <button
        type="button"
        className="ov-dl-modal-backdrop"
        aria-label="Close download dialog"
        onClick={isLoading ? undefined : onClose}
        tabIndex={isLoading ? -1 : 0}
      />
      <div className={`ov-dl-modal-card ov-dl-modal-card--${state}`}>
        <div className="ov-dl-modal-icon-wrap" aria-hidden="true">
          {isSuccess ? (
            <div className="ov-dl-success-ring">
              <i className="fas fa-check ov-dl-success-check" />
            </div>
          ) : isError ? (
            <div className="ov-dl-error-ring">
              <i className="fas fa-exclamation-triangle ov-dl-error-icon" />
            </div>
          ) : (
            <div className="ov-dl-anim">
              <svg className="ov-dl-anim-svg" viewBox="0 0 64 64" focusable="false">
                <rect className="ov-dl-file-body" x="14" y="8" width="36" height="48" rx="4" />
                <path className="ov-dl-file-fold" d="M38 8v10h10" />
                <line className="ov-dl-file-line" x1="22" y1="28" x2="42" y2="28" />
                <line className="ov-dl-file-line" x1="22" y1="36" x2="42" y2="36" />
                <line className="ov-dl-file-line" x1="22" y1="44" x2="34" y2="44" />
              </svg>
              <div className="ov-dl-arrow-track">
                <i className="fas fa-arrow-down ov-dl-arrow" />
              </div>
              <svg className="ov-dl-ring" viewBox="0 0 100 100" focusable="false">
                <circle className="ov-dl-ring-bg" cx="50" cy="50" r="44" />
                <circle
                  className="ov-dl-ring-fill"
                  cx="50"
                  cy="50"
                  r="44"
                  style={{
                    strokeDashoffset: `${276.46 - (276.46 * pct) / 100}`,
                    transition: 'stroke-dashoffset 0.12s linear',
                  }}
                />
              </svg>
            </div>
          )}
        </div>

        <h2 id="ov-dl-modal-title" className="ov-dl-modal-title">
          {isSuccess ? 'Download complete' : isError ? 'Download failed' : 'Preparing your PDF'}
        </h2>

        <p className="ov-dl-modal-message">{message}</p>

        {fileName && isLoading ? (
          <div className="ov-dl-modal-file">
            <i className="fas fa-file-pdf" aria-hidden="true" />
            <span>{fileName}</span>
          </div>
        ) : null}

        {isLoading ? (
          <div className="ov-dl-progress-block">
            <div className="ov-dl-progress-track">
              <div
                className="ov-dl-progress-fill"
                style={{
                  width: `${pct}%`,
                  transition: 'width 0.12s linear',
                }}
              />
            </div>
            <div className="ov-dl-progress-meta">
              <span>{Math.round(pct)}%</span>
              <span className="ov-dl-progress-shimmer" aria-hidden="true" />
            </div>
          </div>
        ) : null}

        {!isLoading ? (
          <button type="button" className="ov-dl-modal-close" onClick={onClose}>
            {isSuccess ? 'Done' : 'Close'}
          </button>
        ) : null}
      </div>
    </div>
  );
}

export default memo(InvoiceDownloadModal);
