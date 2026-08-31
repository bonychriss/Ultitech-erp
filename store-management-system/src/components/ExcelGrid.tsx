import React, { useCallback, useMemo, useRef, useState } from 'react';
import {
  AlignLeft,
  Bold,
  Copy,
  Italic,
  Minus,
  Plus,
  Redo2,
  Save,
  Scissors,
  Undo2,
} from 'lucide-react';

export interface ExcelColumn<T> {
  key: string;
  header: string;
  letter?: string;
  width?: string;
  align?: 'left' | 'center' | 'right';
  editable?: boolean | ((row: T) => boolean);
  type?: 'text' | 'number';
  getValue: (row: T) => string | number;
  setValue?: (row: T, value: string) => T;
  cellClass?: (row: T, value: string | number) => string;
  render?: (row: T, value: string | number, editing: boolean) => React.ReactNode;
}

interface ExcelGridProps<T> {
  rows: T[];
  columns: ExcelColumn<T>[];
  rowKey: (row: T) => string;
  sheetName?: string;
  onRowsChange?: (rows: T[]) => void;
  emptyMessage?: string;
  footer?: React.ReactNode;
  ribbonActions?: React.ReactNode;
  minEmptyRows?: number;
  onCreateEmptyRow?: () => T;
  padVisualRows?: number;
}

const COL_LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');

function colLetter(index: number): string {
  if (index < 26) return COL_LETTERS[index];
  return COL_LETTERS[Math.floor(index / 26) - 1] + COL_LETTERS[index % 26];
}

function isEditable<T>(col: ExcelColumn<T>, row: T): boolean {
  if (typeof col.editable === 'function') return col.editable(row);
  return Boolean(col.editable && col.setValue);
}

export default function ExcelGrid<T>({
  rows,
  columns,
  rowKey,
  sheetName = 'Sheet1',
  onRowsChange,
  emptyMessage = 'No rows',
  footer,
  ribbonActions,
  minEmptyRows = 0,
  onCreateEmptyRow,
  padVisualRows = 12,
}: ExcelGridProps<T>) {
  const tableRef = useRef<HTMLDivElement>(null);
  const [active, setActive] = useState<{ rowId: string; colKey: string } | null>(null);
  const [zoom, setZoom] = useState(100);
  const [ribbonTab, setRibbonTab] = useState<'home' | 'data' | 'view'>('home');

  const displayRows = useMemo(() => {
    if (!onCreateEmptyRow || minEmptyRows <= 0) return rows;
    const padded = [...rows];
    while (padded.length < minEmptyRows) {
      padded.push(onCreateEmptyRow());
    }
    return padded;
  }, [minEmptyRows, onCreateEmptyRow, rows]);

  const updateCell = useCallback(
    (rowId: string, colKey: string, raw: string) => {
      const col = columns.find((c) => c.key === colKey);
      if (!col?.setValue) return;

      const idx = rows.findIndex((row) => rowKey(row) === rowId);
      let nextRows: T[];
      if (idx >= 0) {
        nextRows = rows.map((row) => {
          if (rowKey(row) !== rowId) return row;
          return col.setValue!(row, raw);
        });
      } else if (onCreateEmptyRow) {
        const existing = displayRows.find((row) => rowKey(row) === rowId);
        const base = existing ?? onCreateEmptyRow();
        nextRows = [...rows, col.setValue!(base, raw)];
      } else {
        return;
      }
      onRowsChange?.(nextRows);
    },
    [columns, displayRows, onCreateEmptyRow, onRowsChange, rowKey, rows]
  );

  const editableCells = useMemo(() => {
    const list: { rowId: string; colKey: string }[] = [];
    for (const row of displayRows) {
      const id = rowKey(row);
      for (const col of columns) {
        if (isEditable(col, row)) list.push({ rowId: id, colKey: col.key });
      }
    }
    return list;
  }, [columns, displayRows, rowKey]);

  const focusCell = (rowId: string, colKey: string) => {
    setActive({ rowId, colKey });
    requestAnimationFrame(() => {
      const el = tableRef.current?.querySelector<HTMLInputElement>(
        `[data-excel-cell="${rowId}:${colKey}"]`
      );
      el?.focus();
      el?.select();
    });
  };

  const moveFocus = (dir: 'next' | 'prev' | 'down' | 'up') => {
    if (!active || editableCells.length === 0) return;
    const idx = editableCells.findIndex((c) => c.rowId === active.rowId && c.colKey === active.colKey);
    if (idx < 0) return;
    let next = idx;
    if (dir === 'next') next = Math.min(idx + 1, editableCells.length - 1);
    if (dir === 'prev') next = Math.max(idx - 1, 0);
    if (dir === 'down') {
      const sameCol = editableCells.filter((c) => c.colKey === active.colKey);
      const colIdx = sameCol.findIndex((c) => c.rowId === active.rowId);
      if (colIdx >= 0 && colIdx < sameCol.length - 1) {
        focusCell(sameCol[colIdx + 1].rowId, sameCol[colIdx + 1].colKey);
        return;
      }
      next = Math.min(idx + 1, editableCells.length - 1);
    }
    if (dir === 'up') {
      const sameCol = editableCells.filter((c) => c.colKey === active.colKey);
      const colIdx = sameCol.findIndex((c) => c.rowId === active.rowId);
      if (colIdx > 0) {
        focusCell(sameCol[colIdx - 1].rowId, sameCol[colIdx - 1].colKey);
        return;
      }
      next = Math.max(idx - 1, 0);
    }
    const target = editableCells[next];
    if (target) focusCell(target.rowId, target.colKey);
  };

  const handlePaste = (e: React.ClipboardEvent, startRowId: string, startColKey: string) => {
    const text = e.clipboardData.getData('text/plain');
    if (!text.includes('\t') && !text.includes('\n')) return;
    e.preventDefault();

    const startRowIdx = displayRows.findIndex((r) => rowKey(r) === startRowId);
    const startColIdx = columns.findIndex((c) => c.key === startColKey);
    if (startRowIdx < 0 || startColIdx < 0) return;

    const pasted = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
    if (pasted.length && pasted[pasted.length - 1] === '') pasted.pop();

    let nextRows = [...rows];
    for (let r = 0; r < pasted.length; r += 1) {
      const cells = pasted[r].split('\t');
      let targetRowIdx = startRowIdx + r;
      let targetRow = displayRows[targetRowIdx];
      if (!targetRow && onCreateEmptyRow) {
        const empty = onCreateEmptyRow();
        nextRows = [...nextRows, empty];
        targetRow = empty;
        targetRowIdx = nextRows.length - 1;
      }
      if (!targetRow) break;

      const targetRowId = rowKey(targetRow);
      for (let c = 0; c < cells.length; c += 1) {
        const col = columns[startColIdx + c];
        if (!col?.setValue) continue;
        const current = nextRows.find((row) => rowKey(row) === targetRowId) ?? targetRow;
        if (!isEditable(col, current)) continue;
        nextRows = nextRows.map((row) => {
          if (rowKey(row) !== targetRowId) return row;
          return col.setValue!(row, cells[c].trim());
        });
      }
    }
    onRowsChange?.(nextRows);
  };

  const activeColIndex = active ? columns.findIndex((c) => c.key === active.colKey) : -1;
  const activeRowIndex = active ? displayRows.findIndex((r) => rowKey(r) === active.rowId) : -1;
  const activeCellLabel =
    active && activeColIndex >= 0 && activeRowIndex >= 0
      ? `${columns[activeColIndex].letter || colLetter(activeColIndex)}${activeRowIndex + 1}`
      : '';
  const activeValue =
    active && activeRowIndex >= 0
      ? String(columns.find((c) => c.key === active.colKey)?.getValue(displayRows[activeRowIndex] as T) ?? '')
      : '';

  const numericSum = useMemo(() => {
    if (!active || activeColIndex < 0) return null;
    const col = columns[activeColIndex];
    let sum = 0;
    let count = 0;
    for (const row of displayRows) {
      const raw = col.getValue(row);
      const num = Number(raw);
      if (raw !== '' && !Number.isNaN(num)) {
        sum += num;
        count += 1;
      }
    }
    return count > 0 ? { sum, count } : null;
  }, [active, activeColIndex, columns, displayRows]);

  const renderCell = (row: T, rowIndex: number, col: ExcelColumn<T>, colIndex: number) => {
    const id = rowKey(row);
    const value = col.getValue(row);
    const editable = isEditable(col, row);
    const isActive = active?.rowId === id && active?.colKey === col.key;
    const extraClass = col.cellClass?.(row, value) ?? '';

    return (
      <td
        key={col.key}
        className={`sms-excel-cell${editable ? ' is-editable' : ' is-readonly'}${isActive ? ' is-active' : ''} sms-excel-align-${col.align || 'left'} ${extraClass}`}
        onClick={() => {
          if (editable) {
            focusCell(id, col.key);
          } else {
            setActive({ rowId: id, colKey: col.key });
          }
        }}
      >
        {editable ? (
          col.render ? (
            col.render(row, value, isActive)
          ) : (
            <input
              type={col.type === 'number' ? 'number' : 'text'}
              data-excel-cell={`${id}:${col.key}`}
              value={String(value ?? '')}
              onChange={(e) => updateCell(id, col.key, e.target.value)}
              onFocus={() => setActive({ rowId: id, colKey: col.key })}
              onKeyDown={(e) => {
                if (e.key === 'Tab') {
                  e.preventDefault();
                  moveFocus(e.shiftKey ? 'prev' : 'next');
                } else if (e.key === 'Enter') {
                  e.preventDefault();
                  moveFocus('down');
                } else if (e.key === 'ArrowDown') {
                  e.preventDefault();
                  moveFocus('down');
                } else if (e.key === 'ArrowUp') {
                  e.preventDefault();
                  moveFocus('up');
                }
              }}
              onPaste={(e) => handlePaste(e, id, col.key)}
              className="sms-excel-input"
            />
          )
        ) : (
          <span className="sms-excel-readonly">{col.render ? col.render(row, value, false) : String(value ?? '')}</span>
        )}
      </td>
    );
  };

  return (
    <div className="sms-excel-window" style={{ zoom: zoom / 100 }}>
      <div className="sms-excel-chrome">
      {/* Title bar */}
      <div className="sms-excel-titlebar">
        <div className="sms-excel-titlebar-left">
          <span className="sms-excel-logo" aria-hidden="true">X</span>
          <div className="sms-excel-titlebar-copy">
            <span className="sms-excel-app">Excel</span>
            <span className="sms-excel-filename">{sheetName}.xlsx - Warehouse Stock</span>
          </div>
        </div>
        <div className="sms-excel-window-controls" aria-hidden="true">
          <span className="sms-excel-win-btn" />
          <span className="sms-excel-win-btn" />
          <span className="sms-excel-win-btn sms-excel-win-btn-close" />
        </div>
      </div>

      {/* Quick access toolbar */}
      <div className="sms-excel-qat">
        <button type="button" className="sms-excel-qat-btn" title="Save"><Save className="w-3.5 h-3.5" /></button>
        <button type="button" className="sms-excel-qat-btn" title="Undo"><Undo2 className="w-3.5 h-3.5" /></button>
        <button type="button" className="sms-excel-qat-btn" title="Redo"><Redo2 className="w-3.5 h-3.5" /></button>
      </div>

      {/* Ribbon */}
      <div className="sms-excel-ribbon">
        <div className="sms-excel-ribbon-tabs-row">
          <div className="sms-excel-ribbon-tabs">
            {(['home', 'data', 'view'] as const).map((tab) => (
              <button
                key={tab}
                type="button"
                className={`sms-excel-ribbon-tab${ribbonTab === tab ? ' is-active' : ''}`}
                onClick={() => setRibbonTab(tab)}
              >
                {tab === 'home' ? 'Home' : tab === 'data' ? 'Data' : 'View'}
              </button>
            ))}
          </div>
          {ribbonActions ? <div className="sms-excel-ribbon-actions">{ribbonActions}</div> : null}
        </div>
        <div className="sms-excel-ribbon-body">
          {ribbonTab === 'home' && (
            <>
              <div className="sms-excel-ribbon-group">
                <div className="sms-excel-ribbon-group-items">
                  <button type="button" className="sms-excel-ribbon-btn" title="Paste"><Copy className="w-4 h-4" /><span>Paste</span></button>
                  <button type="button" className="sms-excel-ribbon-btn sms-excel-ribbon-btn-sm" title="Cut"><Scissors className="w-3.5 h-3.5" /></button>
                  <button type="button" className="sms-excel-ribbon-btn sms-excel-ribbon-btn-sm" title="Copy"><Copy className="w-3.5 h-3.5" /></button>
                </div>
                <span className="sms-excel-ribbon-group-label">Clipboard</span>
              </div>
              <div className="sms-excel-ribbon-group">
                <div className="sms-excel-ribbon-group-items">
                  <select className="sms-excel-ribbon-select" defaultValue="Calibri" aria-label="Font">
                    <option>Calibri</option>
                    <option>Arial</option>
                  </select>
                  <select className="sms-excel-ribbon-select sms-excel-ribbon-select-sm" defaultValue="11" aria-label="Font size">
                    <option>11</option>
                    <option>12</option>
                  </select>
                  <button type="button" className="sms-excel-ribbon-btn sms-excel-ribbon-btn-sm" title="Bold"><Bold className="w-3.5 h-3.5" /></button>
                  <button type="button" className="sms-excel-ribbon-btn sms-excel-ribbon-btn-sm" title="Italic"><Italic className="w-3.5 h-3.5" /></button>
                </div>
                <span className="sms-excel-ribbon-group-label">Font</span>
              </div>
              <div className="sms-excel-ribbon-group">
                <div className="sms-excel-ribbon-group-items">
                  <button type="button" className="sms-excel-ribbon-btn sms-excel-ribbon-btn-sm" title="Align left"><AlignLeft className="w-3.5 h-3.5" /></button>
                </div>
                <span className="sms-excel-ribbon-group-label">Alignment</span>
              </div>
            </>
          )}
          {ribbonTab === 'data' && (
            <div className="sms-excel-ribbon-group">
              <div className="sms-excel-ribbon-group-items">
                <span className="sms-excel-ribbon-note">Edit cells directly - Tab to move - Ctrl+V to paste from Excel</span>
              </div>
              <span className="sms-excel-ribbon-group-label">Edit</span>
            </div>
          )}
          {ribbonTab === 'view' && (
            <div className="sms-excel-ribbon-group">
              <div className="sms-excel-ribbon-group-items">
                <button type="button" className="sms-excel-ribbon-btn sms-excel-ribbon-btn-sm" onClick={() => setZoom((z) => Math.max(80, z - 10))}><Minus className="w-3.5 h-3.5" /></button>
                <span className="sms-excel-ribbon-zoom">{zoom}%</span>
                <button type="button" className="sms-excel-ribbon-btn sms-excel-ribbon-btn-sm" onClick={() => setZoom((z) => Math.min(130, z + 10))}><Plus className="w-3.5 h-3.5" /></button>
              </div>
              <span className="sms-excel-ribbon-group-label">Zoom</span>
            </div>
          )}
        </div>
      </div>

      {/* Name box + formula bar */}
      <div className="sms-excel-formula">
        <button type="button" className="sms-excel-fx" title="Insert function">fx</button>
        <span className="sms-excel-name-box">{activeCellLabel || ''}</span>
        <span className="sms-excel-formula-divider" />
        <input
          type="text"
          className="sms-excel-formula-input"
          value={active ? activeValue : ''}
          readOnly={!active}
          placeholder="Select a cell to edit"
          onChange={() => undefined}
        />
      </div>
      </div>

      {/* Grid */}
      <div className="sms-excel-scroll" ref={tableRef}>
        <table className="sms-excel-table">
          <thead>
            <tr className="sms-excel-letters">
              <th className="sms-excel-corner" />
              {columns.map((col, i) => (
                <th key={col.key} style={{ width: col.width }}>{col.letter || colLetter(i)}</th>
              ))}
            </tr>
            <tr className="sms-excel-headers">
              <th className="sms-excel-row-head">#</th>
              {columns.map((col) => (
                <th key={col.key} style={{ width: col.width }}>{col.header}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {displayRows.length === 0 ? (
              <tr>
                <td colSpan={columns.length + 1} className="sms-excel-empty">{emptyMessage}</td>
              </tr>
            ) : (
              displayRows.map((row, rowIndex) => (
                <tr key={rowKey(row)} className="sms-excel-row">
                  <td className="sms-excel-row-num">{rowIndex + 1}</td>
                  {columns.map((col, colIndex) => renderCell(row, rowIndex, col, colIndex))}
                </tr>
              ))
            )}
            {displayRows.length > 0 &&
              Array.from({ length: padVisualRows }).map((_, i) => (
                <tr key={`pad-${i}`} className="sms-excel-row sms-excel-row-pad">
                  <td className="sms-excel-row-num">{displayRows.length + i + 1}</td>
                  {columns.map((col) => (
                    <td key={col.key} className="sms-excel-cell sms-excel-cell-pad" />
                  ))}
                </tr>
              ))}
          </tbody>
        </table>
      </div>

      {/* Sheet tabs (bottom) */}
      <div className="sms-excel-bottom-bar">
        <div className="sms-excel-sheet-tabs">
          <button type="button" className="sms-excel-sheet-nav" aria-label="Previous sheet">&lt;</button>
          <span className="sms-excel-sheet-tab is-active">{sheetName}</span>
          <button type="button" className="sms-excel-sheet-add" aria-label="Add sheet">+</button>
        </div>
        <div className="sms-excel-statusbar">
          <span className="sms-excel-status-item">Ready</span>
          {numericSum && (
            <span className="sms-excel-status-item">
              Sum: {numericSum.sum.toLocaleString()} | Count: {numericSum.count}
            </span>
          )}
          <span className="sms-excel-status-item">{displayRows.length} rows</span>
          <div className="sms-excel-status-zoom">
            <button type="button" onClick={() => setZoom((z) => Math.max(80, z - 10))} aria-label="Zoom out">-</button>
            <span>{zoom}%</span>
            <button type="button" onClick={() => setZoom((z) => Math.min(130, z + 10))} aria-label="Zoom in">+</button>
          </div>
        </div>
      </div>

      {footer && <div className="sms-excel-footer">{footer}</div>}
    </div>
  );
}
