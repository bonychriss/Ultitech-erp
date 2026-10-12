import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { FileDown, FileSpreadsheet, Loader2, Upload } from 'lucide-react';

export default function RevenueExportMenu({
  exportingCsv = false,
  exportingPdf = false,
  csvDisabled = false,
  pdfDisabled = false,
  onExportCsv,
  onExportPdf,
}) {
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState(null);
  const triggerRef = useRef(null);
  const panelRef = useRef(null);

  const busy = exportingCsv || exportingPdf;
  const allDisabled = busy || (csvDisabled && pdfDisabled);

  useLayoutEffect(() => {
    if (!open || !triggerRef.current) {
      setPosition(null);
      return undefined;
    }

    const updatePosition = () => {
      const trigger = triggerRef.current;
      if (!trigger) return;

      const rect = trigger.getBoundingClientRect();
      const panelHeight = panelRef.current?.offsetHeight ?? 96;
      const panelWidth = panelRef.current?.offsetWidth ?? 168;
      const gap = 6;
      const spaceBelow = window.innerHeight - rect.bottom;
      const openUp = spaceBelow < panelHeight + gap + 8 && rect.top > spaceBelow;

      const top = openUp ? rect.top - gap - panelHeight : rect.bottom + gap;
      const left = Math.min(
        Math.max(8, rect.right - panelWidth),
        window.innerWidth - panelWidth - 8,
      );

      setPosition({ top, left, openUp });
    };

    updatePosition();
    window.addEventListener('resize', updatePosition);
    window.addEventListener('scroll', updatePosition, true);
    return () => {
      window.removeEventListener('resize', updatePosition);
      window.removeEventListener('scroll', updatePosition, true);
    };
  }, [open]);

  useEffect(() => {
    if (!open) return undefined;

    const onPointerDown = (event) => {
      const target = event.target;
      if (triggerRef.current?.contains(target) || panelRef.current?.contains(target)) {
        return;
      }
      setOpen(false);
    };
    const onKeyDown = (event) => {
      if (event.key === 'Escape') setOpen(false);
    };

    document.addEventListener('mousedown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('mousedown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [open]);

  return (
    <div className={`rev-desk-export-menu${open ? ' is-open' : ''}`}>
      <button
        ref={triggerRef}
        type="button"
        className="rev-desk-export-btn"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label="Export"
        title="Export"
        disabled={allDisabled}
        onClick={(event) => {
          event.stopPropagation();
          setOpen((value) => !value);
        }}
      >
        {busy ? <Loader2 size={16} className="rev-export-spin" /> : <Upload size={16} strokeWidth={2} />}
      </button>

      {open
        && createPortal(
          <div
            ref={panelRef}
            className={`rev-desk-export-menu-panel${position?.openUp ? ' is-up' : ''}`}
            role="menu"
            style={
              position
                ? { top: position.top, left: position.left, visibility: 'visible' }
                : { top: 0, left: 0, visibility: 'hidden' }
            }
          >
            <button
              type="button"
              role="menuitem"
              className="rev-desk-export-menu-item"
              disabled={csvDisabled || exportingCsv}
              title={csvDisabled ? 'No results to export' : 'Export results as CSV'}
              onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                setOpen(false);
                onExportCsv();
              }}
            >
              {exportingCsv ? (
                <Loader2 size={14} className="rev-export-spin" />
              ) : (
                <FileSpreadsheet size={14} />
              )}
              Export CSV
            </button>
            <button
              type="button"
              role="menuitem"
              className="rev-desk-export-menu-item"
              disabled={pdfDisabled || exportingPdf}
              title={pdfDisabled ? 'No results to export' : 'Export results as PDF'}
              onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                setOpen(false);
                onExportPdf();
              }}
            >
              {exportingPdf ? (
                <Loader2 size={14} className="rev-export-spin" />
              ) : (
                <FileDown size={14} />
              )}
              Export PDF
            </button>
          </div>,
          document.body,
        )}
    </div>
  );
}
