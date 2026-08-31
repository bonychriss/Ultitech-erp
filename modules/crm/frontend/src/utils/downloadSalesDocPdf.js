const PDF_START_TYPES = new Set(['sales-doc-pdf-start', 'delivery-doc-pdf-start']);
const PDF_DONE_TYPES = new Set(['sales-doc-pdf', 'delivery-doc-pdf']);

function statusMessage(progress, phase) {
  if (phase === 'success') return 'Download complete';
  if (progress >= 88) return 'Saving PDF...';
  if (progress >= 40) return 'Building PDF...';
  if (progress >= 18) return 'Loading document...';
  return 'Preparing download...';
}

export function downloadSalesDocPdf(downloadUrl, onProgress) {
  if (!downloadUrl) {
    return Promise.reject(new Error('Download URL is missing.'));
  }

  return new Promise((resolve, reject) => {
    const iframe = document.createElement('iframe');
    iframe.setAttribute('aria-hidden', 'true');
    iframe.tabIndex = -1;
    iframe.style.cssText = 'position:fixed;width:0;height:0;border:0;opacity:0;pointer-events:none;left:-9999px;top:-9999px';

    let settled = false;
    let targetProgress = 6;
    let currentProgress = 0;
    let phase = 'preparing';
    let progressTimer = null;
    let timeoutTimer = null;

    const report = () => {
      if (typeof onProgress === 'function') {
        onProgress(currentProgress, statusMessage(currentProgress, phase), phase);
      }
    };

    const cleanup = () => {
      window.removeEventListener('message', onMessage);
      if (progressTimer) window.clearInterval(progressTimer);
      if (timeoutTimer) window.clearTimeout(timeoutTimer);
      iframe.remove();
    };

    const finish = (ok, error) => {
      if (settled) return;
      settled = true;
      cleanup();
      if (ok) resolve();
      else reject(new Error(error || 'PDF download failed.'));
    };

    const bump = (nextProgress, nextPhase) => {
      targetProgress = Math.max(targetProgress, nextProgress);
      if (nextPhase) phase = nextPhase;
      report();
    };

    progressTimer = window.setInterval(() => {
      if (currentProgress < targetProgress) {
        currentProgress = Math.min(targetProgress, currentProgress + 2.5);
      } else if (phase === 'generating' && currentProgress < 94) {
        currentProgress = Math.min(94, currentProgress + 0.55);
        targetProgress = currentProgress;
      }
      report();
    }, 70);

    const onMessage = (event) => {
      if (event.origin !== window.location.origin) return;
      const type = event.data?.type;
      if (!type) return;

      if (PDF_START_TYPES.has(type)) {
        bump(38, 'generating');
        return;
      }

      if (PDF_DONE_TYPES.has(type)) {
        if (event.data.ok) {
          phase = 'success';
          currentProgress = 100;
          targetProgress = 100;
          report();
          window.setTimeout(() => finish(true), 700);
        } else {
          finish(false, event.data.error || 'PDF generation failed.');
        }
      }
    };

    window.addEventListener('message', onMessage);

    iframe.addEventListener('load', () => {
      bump(20, 'loading');
    });

    document.body.appendChild(iframe);
    iframe.src = downloadUrl;
    report();

    timeoutTimer = window.setTimeout(() => {
      finish(false, 'Download timed out. Please try again.');
    }, 120000);
  });
}
