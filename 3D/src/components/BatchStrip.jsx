import React from 'react';
import { Check, Copy } from 'lucide-react';

export default function BatchStrip({
  items,
  activeId,
  onSelect,
  isProcessing,
  showApplyBanner,
  onOpenApply,
  onDismissApply
}) {
  if (!items?.length) return null;

  return (
    <div className="batch-strip-panel glass-panel">
      {showApplyBanner && !isProcessing && (
        <div className="batch-apply-banner">
          <div>
            <strong>Apply this look to other photos?</strong>
            <span>Background, adjustments, and turn settings from the photo you’re editing.</span>
          </div>
          <div className="batch-apply-banner-actions">
            <button type="button" className="btn btn-primary btn-sm" onClick={onOpenApply}>
              <Copy size={13} />
              Choose photos
            </button>
            <button type="button" className="btn btn-outline btn-sm" onClick={onDismissApply}>
              Dismiss
            </button>
          </div>
        </div>
      )}

      <div className="batch-strip-label">
        Photos
        <span className="batch-strip-label-right">
          {items.filter((i) => i.status === 'done').length}/{items.length}
          {!isProcessing && items.filter((i) => i.status === 'done').length > 1 && (
            <button type="button" className="batch-apply-link" onClick={onOpenApply}>
              Apply look
            </button>
          )}
        </span>
      </div>
      <div className="batch-strip">
        {items.map((item, index) => (
          <button
            key={item.id}
            type="button"
            className={[
              'batch-thumb',
              item.id === activeId ? 'is-active' : '',
              item.status === 'scanning' ? 'is-scanning' : '',
              item.status === 'queued' ? 'is-queued' : '',
              item.status === 'done' ? 'is-done' : '',
              item.styleApplied ? 'has-style' : ''
            ].join(' ')}
            onClick={() => onSelect?.(item)}
            disabled={isProcessing || item.status === 'queued'}
            title={item.name}
          >
            <img
              src={item.cutoutPreviewUrl || item.previewUrl}
              alt=""
              className={item.cutoutPreviewUrl ? 'checkerboard-bg' : ''}
            />
            {item.status === 'scanning' && (
              <span className="batch-scan" aria-hidden="true">
                <span className="batch-scan-beam" />
              </span>
            )}
            {item.status === 'done' && (
              <span className="batch-check">
                <Check size={10} strokeWidth={3} />
              </span>
            )}
            {item.styleApplied && item.id !== activeId && (
              <span className="batch-style-dot" title="Matching edit style" />
            )}
            <span className="batch-index">{index + 1}</span>
          </button>
        ))}
      </div>
    </div>
  );
}
