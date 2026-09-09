import React, { memo, useEffect, useRef } from 'react';

function ScanOverlay({ progress }) {
  const beamRef = useRef(null);
  const labelRef = useRef(null);

  useEffect(() => {
    if (labelRef.current) {
      labelRef.current.textContent =
        progress || 'Isolating the subject… first run may download the AI model';
    }
  }, [progress]);

  useEffect(() => {
    const beam = beamRef.current;
    if (!beam || typeof beam.animate !== 'function') return undefined;

    let anim;
    try {
      anim = beam.animate(
        [
          { transform: 'translate3d(0, -100%, 0)' },
          { transform: 'translate3d(0, 100%, 0)' }
        ],
        {
          duration: 1400,
          iterations: Infinity,
          easing: 'linear'
        }
      );
    } catch {
      beam.style.animation = 'scanSweep 1.4s linear infinite';
      return () => {
        beam.style.animation = '';
      };
    }

    return () => {
      try {
        anim.cancel();
      } catch {
        /* ignore */
      }
    };
  }, []);

  return (
    <div className="scan-overlay" aria-live="polite">
      <div className="scan-veil" />
      <div ref={beamRef} className="scan-beam" />
      <div className="scan-status">
        <p>Scanning & removing background</p>
        <small ref={labelRef}>
          {progress || 'Isolating the subject… first run may download the AI model'}
        </small>
      </div>
    </div>
  );
}

export default memo(ScanOverlay);
