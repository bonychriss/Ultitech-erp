import React, { useRef, useState } from 'react';
import { FileDown, FileSpreadsheet, Loader2, Upload } from 'lucide-react';

interface ExcelToolbarProps {
  onExport?: () => void | Promise<void>;
  onExportTemplate?: () => void | Promise<void>;
  onImport: (file: File) => void | Promise<void>;
  exportLabel?: string;
  templateLabel?: string;
  importLabel?: string;
  disabled?: boolean;
  compact?: boolean;
}

export default function ExcelToolbar({
  onExport,
  onExportTemplate,
  onImport,
  exportLabel = 'Export Excel',
  templateLabel = 'Download template',
  importLabel = 'Import Excel',
  disabled = false,
  compact = false,
}: ExcelToolbarProps) {
  const inputRef = useRef<HTMLInputElement | null>(null);
  const [busy, setBusy] = useState<'export' | 'template' | 'import' | null>(null);

  const run = async (kind: 'export' | 'template' | 'import', fn?: () => void | Promise<void>) => {
    if (!fn || disabled) return;
    setBusy(kind);
    try {
      await fn();
    } finally {
      setBusy(null);
    }
  };

  const btnClass = compact
    ? 'sms-desk-btn sms-desk-btn-secondary sms-desk-btn-sm sms-btn-rounded'
    : 'sms-desk-btn sms-desk-btn-secondary sms-btn-rounded';

  return (
    <div className={`flex flex-wrap items-center gap-2${compact ? '' : ' mb-4'}`}>
      {onExport && (
        <button
          type="button"
          className={btnClass}
          disabled={disabled || busy !== null}
          onClick={() => run('export', onExport)}
        >
          {busy === 'export' ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <FileSpreadsheet className="w-3.5 h-3.5" />}
          <span>{exportLabel}</span>
        </button>
      )}
      {onExportTemplate && (
        <button
          type="button"
          className={btnClass}
          disabled={disabled || busy !== null}
          onClick={() => run('template', onExportTemplate)}
        >
          {busy === 'template' ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <FileDown className="w-3.5 h-3.5" />}
          <span>{templateLabel}</span>
        </button>
      )}
      <button
        type="button"
        className={btnClass}
        disabled={disabled || busy !== null}
        onClick={() => inputRef.current?.click()}
      >
        {busy === 'import' ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Upload className="w-3.5 h-3.5" />}
        <span>{importLabel}</span>
      </button>
      <input
        ref={inputRef}
        type="file"
        accept=".xlsx,.csv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
        className="hidden"
        onChange={async (e) => {
          const file = e.target.files?.[0];
          e.target.value = '';
          if (!file) return;
          setBusy('import');
          try {
            await onImport(file);
          } finally {
            setBusy(null);
          }
        }}
      />
    </div>
  );
}
