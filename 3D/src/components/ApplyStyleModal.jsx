import React, { useEffect, useState } from 'react';
import { X, Layers, Check } from 'lucide-react';

export default function ApplyStyleModal({
  isOpen,
  onClose,
  items,
  sourceId,
  onApply
}) {
  const others = (items || []).filter((i) => i.id !== sourceId && i.status === 'done');
  const [selected, setSelected] = useState([]);

  useEffect(() => {
    if (!isOpen) return;
    const ids = (items || [])
      .filter((i) => i.id !== sourceId && i.status === 'done')
      .map((i) => i.id);
    setSelected(ids);
  }, [isOpen, sourceId, items]);

  if (!isOpen || !others.length) return null;

  const toggle = (id) => {
    setSelected((prev) => (
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
    ));
  };

  const allSelected = selected.length === others.length;

  return (
    <div className="apply-style-backdrop" onClick={onClose}>
      <div className="apply-style-modal glass-panel" onClick={(e) => e.stopPropagation()}>
        <div className="apply-style-head">
          <div>
            <h3>Apply this look</h3>
            <p>Use the first edited photo’s background and adjustments on other uploads.</p>
          </div>
          <button type="button" className="btn btn-outline btn-sm" onClick={onClose} aria-label="Close">
            <X size={16} />
          </button>
        </div>

        <div className="apply-style-actions">
          <button
            type="button"
            className="btn btn-primary btn-sm"
            onClick={() => onApply?.(others.map((i) => i.id))}
          >
            <Layers size={14} />
            Apply to all ({others.length})
          </button>
          <button
            type="button"
            className="btn btn-outline btn-sm"
            onClick={() => setSelected(allSelected ? [] : others.map((i) => i.id))}
          >
            {allSelected ? 'Clear selection' : 'Select all'}
          </button>
        </div>

        <p className="apply-style-hint">Or pick which photos should match:</p>

        <div className="apply-style-grid">
          {others.map((item, index) => {
            const on = selected.includes(item.id);
            return (
              <button
                key={item.id}
                type="button"
                className={`apply-style-card ${on ? 'is-selected' : ''}`}
                onClick={() => toggle(item.id)}
              >
                <img
                  src={item.cutoutPreviewUrl || item.previewUrl}
                  alt=""
                  className={item.cutoutPreviewUrl ? 'checkerboard-bg' : ''}
                />
                <span className="apply-style-card-check">
                  {on ? <Check size={12} strokeWidth={3} /> : null}
                </span>
                <span className="apply-style-card-name">
                  {item.name || `Photo ${index + 2}`}
                </span>
              </button>
            );
          })}
        </div>

        <div className="apply-style-foot">
          <button type="button" className="btn btn-outline btn-sm" onClick={onClose}>
            Not now
          </button>
          <button
            type="button"
            className="btn btn-primary btn-sm"
            disabled={!selected.length}
            onClick={() => onApply?.(selected)}
          >
            Apply to {selected.length || 0} selected
          </button>
        </div>
      </div>
    </div>
  );
}
