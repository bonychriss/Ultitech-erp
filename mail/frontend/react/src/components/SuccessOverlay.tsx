import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { DotLottieReact, type DotLottie } from '@lottiefiles/dotlottie-react';

function animUrl(file: string): string {
  const base =
    (typeof window !== 'undefined' && window.__MAIL_WEB_BASE__
      ? window.__MAIL_WEB_BASE__.replace(/\/$/, '')
      : '') ||
    (import.meta.env.BASE_URL || '/').replace(/\/?$/, '').replace(/\/app$/, '');
  return `${base}/app/animations/${file}`;
}

export function SuccessOverlay({
  onDone,
}: {
  onDone?: () => void;
}) {
  const [leaving, setLeaving] = useState(false);
  const doneRef = useRef(false);
  const src = useMemo(() => animUrl('welcome.lottie'), []);

  const finish = useCallback(() => {
    if (doneRef.current) return;
    doneRef.current = true;
    setLeaving(true);
    window.setTimeout(() => onDone?.(), 320);
  }, [onDone]);

  const onDotLottieRef = useCallback(
    (dotLottie: DotLottie | null) => {
      if (!dotLottie) return;
      const onComplete = () => finish();
      dotLottie.addEventListener('complete', onComplete);
    },
    [finish],
  );

  // Safety: if complete never fires, still dismiss eventually
  useEffect(() => {
    const t = window.setTimeout(() => finish(), 15000);
    return () => window.clearTimeout(t);
  }, [finish]);

  return (
    <div
      className={`success-overlay${leaving ? ' success-overlay-out' : ''}`}
      role="status"
      aria-live="polite"
      aria-label="Signed in"
    >
      <div className="success-overlay-lottie">
        <DotLottieReact
          src={src}
          autoplay
          loop={false}
          dotLottieRefCallback={onDotLottieRef}
          style={{ width: 360, height: 360 }}
        />
      </div>
    </div>
  );
}

export function loadingAnimUrl(): string {
  return animUrl('paper-plane.lottie');
}
