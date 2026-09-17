import { useEffect, useMemo, useState } from 'react';
import { DotLottieReact } from '@lottiefiles/dotlottie-react';

function animUrl(file: string): string {
  const base =
    (typeof window !== 'undefined' && window.__MAIL_WEB_BASE__
      ? window.__MAIL_WEB_BASE__.replace(/\/$/, '')
      : '') ||
    (import.meta.env.BASE_URL || '/').replace(/\/?$/, '').replace(/\/app$/, '');
  return `${base}/app/animations/${file}`;
}

type Props = {
  title?: string;
  subtitle?: string;
  onDone?: () => void;
  durationMs?: number;
};

export function SuccessOverlay({
  title = 'Welcome!',
  subtitle = 'You are signed in to Mail.',
  onDone,
  durationMs = 2800,
}: Props) {
  const [leaving, setLeaving] = useState(false);
  const src = useMemo(() => animUrl('welcome.lottie'), []);

  useEffect(() => {
    const leaveAt = window.setTimeout(() => setLeaving(true), Math.max(400, durationMs - 350));
    const doneAt = window.setTimeout(() => onDone?.(), durationMs);
    return () => {
      window.clearTimeout(leaveAt);
      window.clearTimeout(doneAt);
    };
  }, [durationMs, onDone]);

  return (
    <div className={`success-overlay${leaving ? ' success-overlay-out' : ''}`} role="status" aria-live="polite">
      <div className="success-overlay-card">
        <div className="success-overlay-lottie">
          <DotLottieReact src={src} loop autoplay style={{ width: 180, height: 180 }} />
        </div>
        <h2>{title}</h2>
        <p>{subtitle}</p>
      </div>
    </div>
  );
}

export function loadingAnimUrl(): string {
  return animUrl('paper-plane.lottie');
}
