/**
 * Download a sales order/quotation PDF via the legacy print desk in a hidden iframe.
 * print.php posts { type: 'sales-doc-pdf-start' } and { type: 'sales-doc-pdf', ok, error }.
 */
export function downloadSalesDocPdfViaPrintUrl(url, {
  onProgress,
  onSuccess,
  onError,
  timeoutMs = 120000,
} = {}) {
  return new Promise((resolve, reject) => {
    if (!url || url === '#') {
      const err = 'Download link is not available.';
      onError?.(err);
      reject(new Error(err));
      return;
    }

    const iframe = document.createElement('iframe');
    iframe.setAttribute('aria-hidden', 'true');
    iframe.setAttribute('title', 'PDF download');
    iframe.style.cssText = 'position:fixed;width:0;height:0;border:0;left:-9999px;top:0;opacity:0;pointer-events:none;';

    let settled = false;
    let progress = 6;
    const tick = window.setInterval(() => {
      if (progress < 88) {
        progress = Math.min(88, progress + (progress < 40 ? 2.8 : 1.1));
        onProgress?.(
          progress,
          progress < 40 ? 'Loading document...' : 'Generating PDF...',
        );
      }
    }, 380);

    const cleanup = () => {
      window.clearInterval(tick);
      window.removeEventListener('message', onMessage);
      window.clearTimeout(timer);
      try {
        iframe.remove();
      } catch {
        /* ignore */
      }
    };

    const finish = (ok, errorMessage) => {
      if (settled) return;
      settled = true;
      cleanup();
      if (ok) {
        onProgress?.(100, 'Download complete');
        onSuccess?.();
        resolve();
        return;
      }
      const reason = errorMessage || 'Download failed.';
      onError?.(reason);
      reject(new Error(reason));
    };

    const onMessage = (event) => {
      if (event.origin !== window.location.origin) return;
      const data = event.data || {};
      if (data.type === 'sales-doc-pdf-start') {
        progress = Math.max(progress, 45);
        onProgress?.(progress, 'Generating PDF...');
        return;
      }
      if (data.type === 'sales-doc-pdf') {
        finish(!!data.ok, data.error || '');
      }
    };

    window.addEventListener('message', onMessage);
    const timer = window.setTimeout(
      () => finish(false, 'Download timed out. Please try again.'),
      timeoutMs,
    );

    onProgress?.(progress, 'Preparing document...');
    document.body.appendChild(iframe);
    iframe.src = url;
  });
}
