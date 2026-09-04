import { useEffect, useMemo, useState } from 'react';
import { ArrowLeft, Download, Loader2 } from 'lucide-react';
import { deskPageUrl } from '../api/payrollDesk';

function readPayslipMeta() {
  if (typeof window === 'undefined') return null;
  const meta = window.__PAYROLL_PAYSLIP_META__;
  if (meta && typeof meta === 'object') return meta;
  const id = Number(window.__PAYROLL_PAYSLIP_ID__ || new URLSearchParams(window.location.search).get('id') || 0) || 0;
  if (id <= 0) return null;
  const params = new URLSearchParams({ module: 'payroll', id: String(id) });
  return {
    id,
    periodLabel: 'Payslip',
    employeeName: '',
    idLabel: `#${String(id).padStart(5, '0')}`,
    backUrl: deskPageUrl('my_payslips.php'),
    downloadUrl: `./payslip.php?${params.toString()}&download=1`,
    embedUrl: `./payslip.php?${params.toString()}&embed=1`,
  };
}

export default function PayslipViewPage() {
  const meta = useMemo(() => readPayslipMeta(), []);
  const [iframeLoading, setIframeLoading] = useState(true);

  useEffect(() => {
    if (!meta?.id) return undefined;
    document.title = `Payslip${meta.periodLabel ? ` - ${meta.periodLabel}` : ''}`;
    return undefined;
  }, [meta]);

  if (!meta?.id) {
    return (
      <div className="pv-page-root">
        <div className="pv-boot-error" role="alert">
          Payslip not found.
        </div>
      </div>
    );
  }

  return (
    <div className="pv-page-root">
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
            <a
              href={meta.downloadUrl}
              className="pay-desk-btn pay-desk-btn-primary"
              target="_blank"
              rel="noopener noreferrer"
            >
              <Download size={15} aria-hidden="true" />
              Download PDF
            </a>
          </div>
        </div>
      </div>

      <div className="pv-document-stage">
        <div className="pv-sheet-container">
          {iframeLoading && (
            <div className="pv-document-loading" role="status" aria-live="polite">
              <Loader2 className="pay-desk-boot-spinner" aria-hidden="true" />
              <span>Loading payslip...</span>
            </div>
          )}
          <iframe
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
