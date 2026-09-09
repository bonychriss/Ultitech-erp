import { useEffect, useMemo, useRef, useState } from 'react';
import { ArrowLeft, Download, Loader2 } from 'lucide-react';
import { deskPageUrl, fetchPayslipViewMeta } from '../api/payrollDesk';

function readPayslipMeta() {
  if (typeof window === 'undefined') return null;
  const meta = window.__PAYROLL_PAYSLIP_META__;
  if (meta && typeof meta === 'object') return meta;
  const id = Number(window.__PAYROLL_PAYSLIP_ID__ || new URLSearchParams(window.location.search).get('id') || 0) || 0;
  if (id <= 0) return null;
  const params = new URLSearchParams({ module: 'payroll', id: String(id) });
  const cacheBust = String(Date.now());
  return {
    id,
    periodLabel: 'Payslip',
    employeeName: '',
    idLabel: `#${String(id).padStart(5, '0')}`,
    backUrl: deskPageUrl('my_payslips.php'),
    downloadUrl: `./payslip.php?${params.toString()}&download=1&v=${cacheBust}`,
    embedUrl: `./payslip.php?${params.toString()}&embed=1&v=${cacheBust}`,
  };
}

async function waitForIframePdfReady(frame, attempts = 40) {
  for (let i = 0; i < attempts; i += 1) {
    const win = frame?.contentWindow;
    if (win && typeof win.downloadPayslipPdf === 'function') {
      return win;
    }
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  throw new Error('Payslip PDF is not ready yet.');
}

export default function PayslipViewPage() {
  const seeded = useMemo(() => readPayslipMeta(), []);
  const [meta, setMeta] = useState(seeded);
  const [bootError, setBootError] = useState('');
  const [iframeLoading, setIframeLoading] = useState(true);
  const [downloading, setDownloading] = useState(false);
  const [downloadError, setDownloadError] = useState('');
  const iframeRef = useRef(null);

  useEffect(() => {
    let cancelled = false;
    async function hydrate() {
      if (seeded?.employeeName && seeded?.embedUrl) return;
      const id = Number(seeded?.id || 0);
      if (id <= 0) {
        setBootError('Payslip not found.');
        return;
      }
      try {
        const data = await fetchPayslipViewMeta(id);
        if (!cancelled) setMeta(data);
      } catch (err) {
        if (!cancelled) {
          setBootError(err instanceof Error ? err.message : 'Failed to load payslip.');
        }
      }
    }
    hydrate();
    return () => {
      cancelled = true;
    };
  }, [seeded]);

  useEffect(() => {
    if (!meta?.id) return undefined;
    document.title = `Payslip${meta.periodLabel ? ` - ${meta.periodLabel}` : ''}`;
    return undefined;
  }, [meta]);

  const handleDownload = async (event) => {
    event.preventDefault();
    event.stopPropagation();
    if (downloading) return;

    setDownloadError('');
    setDownloading(true);
    try {
      const frame = iframeRef.current;
      if (!frame) {
        throw new Error('Payslip preview is not loaded.');
      }
      const win = await waitForIframePdfReady(frame);
      await win.downloadPayslipPdf();
    } catch (err) {
      setDownloadError(err instanceof Error ? err.message : 'Failed to download PDF.');
    } finally {
      setDownloading(false);
    }
  };

  if (bootError || !meta?.id) {
    return (
      <div className="pv-page-root">
        <div className="pv-boot-error" role="alert">
          {bootError || 'Payslip not found.'}
        </div>
      </div>
    );
  }

  return (
    <div className={`pv-page-root${downloading ? ' is-downloading' : ''}`}>
      <div className="pv-chrome ov-no-print">
        <div className="pv-control-panel">
          <div className="pv-control-left">
            <a href={meta.backUrl || deskPageUrl('my_payslips.php')} className="pv-back-link">
              <ArrowLeft size={16} aria-hidden="true" />
              Back
            </a>
            <div className="pv-title-block">
              <div className="pv-title">{meta.periodLabel || 'Payslip'}</div>
              <div className="pv-subtitle">
                {[meta.employeeName, meta.idLabel, meta.statusLabel].filter(Boolean).join(' | ')}
              </div>
            </div>
          </div>
          <div className="pv-control-actions">
            <button
              type="button"
              className="pay-desk-btn pay-desk-btn-primary pay-desk-btn--pill"
              onClick={handleDownload}
              disabled={downloading || iframeLoading}
              aria-busy={downloading}
            >
              {downloading ? (
                <Loader2 size={15} className="pay-desk-boot-spinner" aria-hidden="true" />
              ) : (
                <Download size={15} aria-hidden="true" />
              )}
              {downloading ? 'Downloading…' : 'Download PDF'}
            </button>
          </div>
        </div>
        {downloadError ? (
          <div className="pv-download-error" role="alert">
            {downloadError}
          </div>
        ) : null}
      </div>

      <div className="pv-document-stage">
        <div className="pv-sheet-container">
          {iframeLoading && (
            <div className="pv-document-loading" role="status" aria-live="polite">
              <Loader2 className="pay-desk-boot-spinner" aria-hidden="true" />
              <span>Loading payslip...</span>
            </div>
          )}
          {downloading && (
            <div className="pv-download-overlay" role="status" aria-live="polite">
              <Loader2 className="pay-desk-boot-spinner" size={28} aria-hidden="true" />
              <span>Preparing PDF…</span>
            </div>
          )}
          <iframe
            ref={iframeRef}
            title={`Payslip ${meta.periodLabel || meta.id}`}
            src={meta.embedUrl}
            className="pv-document-frame"
            onLoad={(event) => {
              setIframeLoading(false);
              try {
                const frame = event.currentTarget;
                const doc = frame.contentDocument || frame.contentWindow?.document;
                if (!doc) return;
                const height = Math.max(
                  doc.documentElement?.scrollHeight || 0,
                  doc.body?.scrollHeight || 0,
                  900,
                );
                frame.style.height = `${height}px`;
              } catch {
                event.currentTarget.style.height = '1100px';
              }
            }}
          />
        </div>
      </div>
    </div>
  );
}
