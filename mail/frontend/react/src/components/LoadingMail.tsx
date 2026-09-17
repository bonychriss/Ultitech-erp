import { DotLottieReact } from '@lottiefiles/dotlottie-react';
import { loadingAnimUrl } from './SuccessOverlay';

export function LoadingMail({ label = 'Loading Mail...' }: { label?: string }) {
  return (
    <div className="loading loading-mail" role="status" aria-live="polite" aria-busy="true">
      <div className="loading-mail-visual" aria-hidden="true">
        <div className="loading-mail-lottie">
          <DotLottieReact
            src={loadingAnimUrl()}
            loop
            autoplay
            style={{ width: 140, height: 140 }}
          />
        </div>
        <div className="loading-mail-dots">
          <span />
          <span />
          <span />
        </div>
      </div>
      <p className="loading-mail-label">{label}</p>
    </div>
  );
}
