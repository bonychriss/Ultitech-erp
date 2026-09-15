import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { FileDown, FileSpreadsheet, Loader2, Upload } from 'lucide-react';

interface ExportMenuProps {
  exportingExcel?: boolean;
  exportingPdf?: boolean;
  excelDisabled?: boolean;
  pdfDisabled?: boolean;
  onExportExcel: () => void;
  onExportPdf: () => void;
}

interface MenuPosition {
  top: number;
  left: number;
  openUp: boolean;
}

export default function ExportMenu({
  exportingExcel = false,
  exportingPdf = false,
  excelDisabled = false,
  pdfDisabled = false,
  onExportExcel,
  onExportPdf,
}: ExportMenuProps) {
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState<MenuPosition | null>(null);
  const triggerRef = useRef<HTMLButtonElement | null>(null);
  const panelRef = useRef<HTMLDivElement | null>(null);

  const busy = exportingExcel || exportingPdf;
  const allDisabled = busy || (excelDisabled && pdfDisabled);

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
        window.innerWidth - panelWidth - 8
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

    const onPointerDown = (event: MouseEvent) => {
      const target = event.target as Node;
      if (triggerRef.current?.contains(target) || panelRef.current?.contains(target)) {
        return;
      }
      setOpen(false);
    };
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false);
    };

    // Close on outside mousedown so menu-item onClick still fires reliably.
    document.addEventListener('mousedown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('mousedown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [open]);

  return (
    <div className={`sms-export-menu${open ? ' is-open' : ''}`}>
      <button
        ref={triggerRef}
        type="button"
        className="sms-export-menu-trigger"
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
        {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Upload className="w-4 h-4" strokeWidth={2} />}
      </button>

      {open &&
        createPortal(
          <div
            ref={panelRef}
            className={`sms-export-menu-panel${position?.openUp ? ' is-up' : ''}`}
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
              className="sms-export-menu-item"
              disabled={excelDisabled || exportingExcel}
              title={
                excelDisabled
                  ? 'No results to export'
                  : 'Export results as Excel'
              }
              onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                setOpen(false);
                onExportExcel();
              }}
            >
              {exportingExcel ? (
                <Loader2 className="w-3.5 h-3.5 animate-spin" />
              ) : (
                <FileSpreadsheet className="w-3.5 h-3.5" />
              )}
              Export Excel
            </button>
            <button
              type="button"
              role="menuitem"
              className="sms-export-menu-item"
              disabled={pdfDisabled || exportingPdf}
              onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                setOpen(false);
                onExportPdf();
              }}
            >
              {exportingPdf ? (
                <Loader2 className="w-3.5 h-3.5 animate-spin" />
              ) : (
                <FileDown className="w-3.5 h-3.5" />
              )}
              Export PDF
            </button>
          </div>,
          document.body
        )}
    </div>
  );
}
